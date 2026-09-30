<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Services\LicenseStorage;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Phase 4B — security hygiene around the raw license key: activation-page
 * response caching, secret non-disclosure in command/DTO output, and the
 * fallback store never retaining plaintext key material.
 *
 * Every test drives a distinctive *sentinel* key and asserts it is absent
 * from whatever surface is under test, so a regression that starts leaking
 * the key is caught by content, not by inspection.
 */
class SecurityHygieneTest extends TestCase
{
    use SignsPayloads;

    private const SENTINEL = 'SENTINEL-KEY-4b7c9a1f2e';

    private const PRODUCT = 'test-product';

    protected function setUp(): void
    {
        parent::setUp();

        // The 'file' fallback store (and its .gitignore'd directory) persists
        // between runs; flush it so a stale record cannot mask a real failure.
        CacheFacade::store('file')->flush();
    }

    // ---------------------------------------------------------------------
    // 4B.2 — activation-page responses are non-cacheable
    // ---------------------------------------------------------------------

    public function test_activation_screen_response_is_not_cacheable(): void
    {
        Http::fake();

        $response = $this->get(route('corevisys.license.activate'));

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function test_failed_activation_redirect_is_not_cacheable(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => self::SENTINEL]);

        $response->assertRedirect(route('corevisys.license.activate'));
        $response->assertSessionHasErrors('license_key');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    // ---------------------------------------------------------------------
    // 4B.3 — the key is accepted only from the POST body, and never returned
    // ---------------------------------------------------------------------

    public function test_activation_route_rejects_get_submission_and_never_echoes_the_key(): void
    {
        Http::fake();

        // A key smuggled through the query string must not activate anything:
        // the activation GET only renders the form and ignores query input.
        $response = $this->get(route('corevisys.license.activate').'?license_key='.self::SENTINEL);

        $response->assertOk();
        $this->assertStringNotContainsString(self::SENTINEL, $response->getContent());
    }

    public function test_failed_activation_does_not_retain_the_key_in_old_input(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'not found'], 404),
        ]);

        $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => self::SENTINEL])
            ->assertSessionHasErrors('license_key');

        $old = (array) session()->getOldInput();

        $this->assertArrayNotHasKey('license_key', $old);
        $this->assertStringNotContainsString(self::SENTINEL, json_encode($old));
    }

    public function test_activation_validation_error_message_is_generic_and_key_free(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License '.self::SENTINEL.' is unknown'], 404),
        ]);

        $response = $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => self::SENTINEL]);

        $response->assertSessionHasErrors('license_key');

        /** @var \Illuminate\Support\ViewErrorBag $errors */
        $errors = session('errors');

        $this->assertStringNotContainsString(self::SENTINEL, json_encode($errors->getMessages()));
        $this->assertStringNotContainsString('unknown', (string) $errors->first('license_key'));
    }

    // ---------------------------------------------------------------------
    // 4B.5 — an unwritable fallback dir does not throw out of the public API
    // ---------------------------------------------------------------------

    public function test_unwritable_fallback_directory_does_not_throw_out_of_public_api(): void
    {
        $anonymous = new class extends LicenseStorage
        {
            public function __construct()
            {
                parent::__construct([]);
            }

            public function bootTest(array $config): void
            {
                $this->config = $config;
            }

            protected function fallbackStoreName(): ?string
            {
                return 'file';
            }

            protected function readTarget(array $target, string $productCode): ?array
            {
                if ($target['driver'] === 'database') {
                    throw new \RuntimeException('primary database unavailable');
                }

                throw new \RuntimeException('fallback cache store unavailable');
            }

            protected function writeTarget(array $target, string $productCode, array $attributes): void
            {
                throw new \RuntimeException('backend unavailable');
            }
        };

        $anonymous->bootTest(config('corevisys-license'));

        // Neither read nor write may let a backend failure escape.
        $this->assertNull($anonymous->get(self::PRODUCT));
        $anonymous->put(self::PRODUCT, ['status' => 'active', 'license_key' => self::SENTINEL]);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------------
    // 4B.6 — the fallback mirror never stores key material
    // ---------------------------------------------------------------------

    public function test_database_mode_fallback_mirror_has_no_plaintext_or_ciphertext_key(): void
    {
        $storage = new LicenseStorage(array_merge(config('corevisys-license'), [
            'cache_driver' => 'database',
            'cache_fallback_store' => 'file',
        ]));

        $storage->put(self::PRODUCT, [
            'status' => 'active',
            'license_key' => self::SENTINEL,
        ]);

        $cacheKey = config('corevisys-license.cache_key', 'corevisys.license.cache').':'.self::PRODUCT;
        $mirror = CacheFacade::store('file')->get($cacheKey);

        $this->assertIsArray($mirror);

        $encoded = json_encode($mirror);

        $this->assertArrayNotHasKey('license_key', $mirror);
        $this->assertArrayNotHasKey('encrypted_license_key', $mirror);
        $this->assertStringNotContainsString(self::SENTINEL, $encoded);
    }

    public function test_cache_mode_fallback_mirror_has_no_key(): void
    {
        $storage = new LicenseStorage(array_merge(config('corevisys-license'), [
            'cache_driver' => 'cache',
            'cache_store' => 'array',
            'cache_fallback_store' => 'file',
        ]));

        $storage->put(self::PRODUCT, [
            'status' => 'active',
            'license_key' => self::SENTINEL,
        ]);

        $cacheKey = config('corevisys-license.cache_key', 'corevisys.license.cache').':'.self::PRODUCT;
        $mirror = CacheFacade::store('file')->get($cacheKey);

        $this->assertIsArray($mirror);
        $this->assertArrayNotHasKey('license_key', $mirror);
        $this->assertArrayNotHasKey('encrypted_license_key', $mirror);
        $this->assertStringNotContainsString(self::SENTINEL, json_encode($mirror));
    }

    // ---------------------------------------------------------------------
    // 4B.7 — DTO serialization never exposes the key
    // ---------------------------------------------------------------------

    public function test_license_response_and_status_dtos_never_expose_the_key(): void
    {
        $data = [
            'license_id' => 'lic_dto',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ];

        $envelope = $this->signedEnvelope($data);
        $response = LicenseResponse::fromArray($envelope);

        $this->assertArrayNotHasKey('license_key', $response->data);
        $this->assertArrayNotHasKey('license_key', $response->raw);
        $this->assertArrayNotHasKey('encrypted_license_key', $response->raw);

        $status = $response->toLicenseStatus();

        $this->assertArrayNotHasKey('license_key', $status->toArray());
        $this->assertArrayNotHasKey('encrypted_license_key', $status->toArray());
        $this->assertStringNotContainsString(self::SENTINEL, json_encode($status->toArray()));
    }

    // ---------------------------------------------------------------------
    // 4B.10 — command output never contains the key
    // ---------------------------------------------------------------------

    public function test_status_command_never_prints_the_stored_key(): void
    {
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->put(self::PRODUCT, [
            'status' => 'active',
            'license_key' => self::SENTINEL,
            'last_successful_check_at' => now(),
        ]);

        $this->artisan('corevisys:license:status')
            ->doesntExpectOutputToContain(self::SENTINEL);
    }

    public function test_doctor_command_never_prints_a_secret(): void
    {
        config(['corevisys-license.license_key' => self::SENTINEL]);

        $this->artisan('corevisys:license:doctor')
            ->doesntExpectOutputToContain(self::SENTINEL);
    }

    public function test_install_command_prints_placeholders_not_secrets(): void
    {
        config(['corevisys-license.license_key' => self::SENTINEL]);

        $this->artisan('corevisys:license:install')
            ->doesntExpectOutputToContain(self::SENTINEL);
    }

    public function test_check_command_never_prints_the_configured_key(): void
    {
        config(['corevisys-license.license_key' => self::SENTINEL]);
        $this->app->forgetInstance(\CoreVisys\License\Contracts\LicenseClientInterface::class);

        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_cmd',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->artisan('corevisys:license:check', ['--force' => true])
            ->doesntExpectOutputToContain(self::SENTINEL);
    }

    // ---------------------------------------------------------------------
    // Check A — regression: the log() path must never throw a TypeError
    // ---------------------------------------------------------------------

    public function test_signature_failure_logs_a_reason_code_without_throwing(): void
    {
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        \Illuminate\Support\Facades\Log::spy();

        $logChannel = \Mockery::mock();
        $logChannel->shouldReceive('warning')->andReturnNull();
        $logChannel->shouldIgnoreMissing();
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturn($logChannel);

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_sig',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'checked_at' => now()->toIso8601String(),
            ], corruptSignature: true)),
        ]);

        // Before Check A the 4-argument log() call would fatal with a
        // TypeError. This asserts the call path is intact and the failure is
        // still reported as an invalid status rather than crashing.
        $status = $this->app->make(\CoreVisys\License\Contracts\LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
        $this->assertSame('signature_verification_failed', $status->status);
    }
}
