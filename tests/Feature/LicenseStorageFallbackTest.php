<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseStorage;
use CoreVisys\License\Services\LicenseVerifier;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 Part B: the storage fallback chain. The fallback is a storage
 * location, never a trust shortcut — data read from it must pass the full
 * frozen offline rule (A6).
 */
class LicenseStorageFallbackTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    /**
     * The 'file' cache store persists on disk across tests, so an unisolated
     * fallback could leak between cases (masking a real failure or producing a
     * false pass). Flush it before every test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CacheFacade::store('file')->flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function envelopeFor(array $overrides = [], bool $corrupt = false): array
    {
        $data = array_merge([
            'license_id' => 'lic_fb',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $overrides);

        return [$data, $this->signedEnvelope($data, corruptSignature: $corrupt)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function recordFrom(array $data, array $envelope, array $extra = []): array
    {
        return array_merge([
            'product_code' => self::PRODUCT,
            'license_id' => $data['license_id'],
            'status' => $data['status'],
            'encrypted_license_key' => Crypt::encryptString('CACHED-KEY-0000'),
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => $data['is_grace_period'],
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => $data['last_successful_check_at'] ?? now()->subHours(2),
            'next_check_at' => now()->subMinute(),
        ], $extra);
    }

    private function cacheKey(): string
    {
        return config('corevisys-license.cache_key', 'corevisys.license.cache').':'.self::PRODUCT;
    }

    private function publicKeyCacheKey(): string
    {
        return config('corevisys-license.public_key_cache_key', 'corevisys.license.public_key').':test-key-1';
    }

    private function publicKeyMetadataCacheKey(): string
    {
        return config('corevisys-license.public_key_cache_key', 'corevisys.license.public_key').':metadata';
    }

    private function seedPublicKeysInto(string $store): void
    {
        $public = $this->keyPair()['public'];

        CacheFacade::store($store)->forever($this->publicKeyCacheKey(), $public);
        CacheFacade::store($store)->forever($this->publicKeyMetadataCacheKey(), [
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $public]],
            'revoked_key_ids' => [],
        ]);
    }

    private function seedTrustedKeys(LicenseStorage $storage): void
    {
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);
    }

    /** @var array<string, mixed> */
    private array $storageConfig = [];

    private function makeStorage(array $overrides = []): LicenseStorage
    {
        $this->storageConfig = array_merge(config('corevisys-license'), $overrides);

        return new LicenseStorage($this->storageConfig);
    }

    private function bindStorageAndVerifier(LicenseStorage $storage): void
    {
        $this->app->instance(LicenseStorageInterface::class, $storage);

        $verifier = new LicenseVerifier(
            api: $this->app->make(ApiRequestHandler::class),
            signatureVerifier: $this->app->make(SignedPayloadVerifier::class),
            fingerprint: $this->app->make(FingerprintGenerator::class),
            storage: $storage,
            productCode: self::PRODUCT,
            config: $this->storageConfig,
        );

        $this->app->instance(LicenseVerifier::class, $verifier);
    }

    // ---------------------------------------------------------------------
    // Database mode: primary DB failure -> fallback store
    // ---------------------------------------------------------------------

    public function test_primary_db_failure_uses_valid_fallback_payload(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);
        $this->seedTrustedKeys($storage);

        [$data, $envelope] = $this->envelopeFor();
        $storage->put(self::PRODUCT, $this->recordFrom($data, $envelope));

        // Simulate primary DB failure: the table disappears.
        Schema::dropIfExists('corevisys_license_cache');

        $this->bindStorageAndVerifier($storage);

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);
        $this->assertTrue($status->fromCache);
    }

    public function test_primary_db_failure_with_tampered_fallback_payload_is_rejected(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);
        $this->seedTrustedKeys($storage);

        [$data, $envelope] = $this->envelopeFor(['status' => 'revoked']);

        // Hand-edit the fallback record to say "active" without re-signing.
        $tampered = $data;
        $tampered['status'] = 'active';
        CacheFacade::store('file')->forever($this->cacheKey(), $this->recordFrom($data, $envelope, [
            'status' => 'active',
            'signed_payload' => json_encode($tampered, JSON_UNESCAPED_SLASHES),
        ]));

        Schema::dropIfExists('corevisys_license_cache');

        $this->bindStorageAndVerifier($storage);
        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
        $this->assertSame('tampered_cache', $status->status);
    }

    public function test_primary_db_failure_with_expired_offline_valid_until_is_rejected(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);
        $this->seedTrustedKeys($storage);

        [$data, $envelope] = $this->envelopeFor(['offline_valid_until' => now()->subDay()->toIso8601String()]);
        CacheFacade::store('file')->forever($this->cacheKey(), $this->recordFrom($data, $envelope));

        Schema::dropIfExists('corevisys_license_cache');

        $this->bindStorageAndVerifier($storage);
        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
        $this->assertSame('grace_period_expired', $status->status);
    }

    public function test_primary_db_failure_with_local_grace_expired_is_rejected(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);
        $this->seedTrustedKeys($storage);

        [$data, $envelope] = $this->envelopeFor(['last_successful_check_at' => now()->subDays(30)->toIso8601String()]);
        CacheFacade::store('file')->forever($this->cacheKey(), $this->recordFrom($data, $envelope));

        Schema::dropIfExists('corevisys_license_cache');

        $this->bindStorageAndVerifier($storage);
        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
    }

    public function test_primary_db_failure_with_empty_fallback_is_invalid_without_exception(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);

        Schema::dropIfExists('corevisys_license_cache');

        $this->bindStorageAndVerifier($storage);
        Http::fake();

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
    }

    public function test_primary_success_with_not_found_never_uses_the_fallback(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);

        // Fallback holds a perfectly valid record...
        [$data, $envelope] = $this->envelopeFor();
        CacheFacade::store('file')->forever($this->cacheKey(), $this->recordFrom($data, $envelope));

        // ...but the primary table is healthy and empty.
        DB::table('corevisys_license_cache')->delete();

        $record = $storage->get(self::PRODUCT);

        $this->assertNull($record, 'A successful primary "not found" must not consult the fallback.');
    }

    // ---------------------------------------------------------------------
    // Writes and clearing
    // ---------------------------------------------------------------------

    public function test_write_mirrors_to_fallback_even_when_primary_fails(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);

        Schema::dropIfExists('corevisys_license_cache'); // primary write will throw

        [$data, $envelope] = $this->envelopeFor();

        // Must not throw.
        $storage->put(self::PRODUCT, $this->recordFrom($data, $envelope));

        $fallback = CacheFacade::store('file')->get($this->cacheKey());

        $this->assertIsArray($fallback);
        $this->assertSame('active', $fallback['status']);
    }

    public function test_deactivate_and_clear_cache_clear_both_stores(): void
    {
        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);

        [$data, $envelope] = $this->envelopeFor();
        $storage->put(self::PRODUCT, $this->recordFrom($data, $envelope));

        $this->assertNotNull(DB::table('corevisys_license_cache')->where('product_code', self::PRODUCT)->first());
        $this->assertIsArray(CacheFacade::store('file')->get($this->cacheKey()));

        $this->bindStorageAndVerifier($storage);

        $client = $this->app->make(LicenseClientInterface::class);
        Http::fake(['*/api/v1/license/deactivate' => Http::response(['success' => true], 200)]);
        $client->deactivate();

        $this->assertNull(DB::table('corevisys_license_cache')->where('product_code', self::PRODUCT)->first());
        $this->assertNull(CacheFacade::store('file')->get($this->cacheKey()));
    }

    // ---------------------------------------------------------------------
    // Cache mode: primary cache store failure -> different store
    // ---------------------------------------------------------------------

    public function test_cache_mode_primary_store_failure_falls_back_to_another_store(): void
    {
        // Seed a valid record + keys into the fallback 'file' store directly.
        $this->seedPublicKeysInto('file');
        [$data, $envelope] = $this->envelopeFor();
        CacheFacade::store('file')->forever($this->cacheKey(), $this->recordFrom($data, $envelope));

        // Primary (null => default store) throws; fallback 'file' resolves for real.
        $real = $this->app->make('cache');
        CacheFacade::shouldReceive('store')->andReturnUsing(function ($name = null) use ($real) {
            if ($name === null || $name === 'primary') {
                throw new \RuntimeException('primary cache store unavailable');
            }

            return $real->store($name);
        });

        $storage = $this->makeStorage([
            'cache_driver' => 'cache',
            'cache_store' => null,
            'cache_fallback_store' => 'file',
        ]);

        $this->bindStorageAndVerifier($storage);
        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);
    }

    public function test_fallback_warning_logs_class_name_only_and_no_key(): void
    {
        $sentinel = 'SENTINEL-KEY-fallback-9999';

        $captured = [];
        Log::listen(function ($event) use (&$captured) {
            $captured[] = $event->message.' '.json_encode($event->context);
        });

        $real = $this->app->make('cache');
        CacheFacade::shouldReceive('store')->andReturnUsing(function ($name = null) use ($real, $sentinel) {
            if ($name === null || $name === 'primary') {
                throw new \RuntimeException('boom '.$sentinel);
            }

            return $real->store($name);
        });

        $storage = $this->makeStorage([
            'cache_driver' => 'cache',
            'cache_store' => null,
            'cache_fallback_store' => 'file',
        ]);

        // Primary throws, fallback is empty -> graceful null, no exception.
        $this->assertNull($storage->get(self::PRODUCT));

        $log = implode("\n", $captured);

        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringNotContainsString($sentinel, $log);
        $this->assertStringNotContainsString('boom', $log);
    }
}
