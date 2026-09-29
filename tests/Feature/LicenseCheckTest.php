<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LicenseCheckTest extends TestCase
{
    use SignsPayloads;

    protected function seedCache(array $overrides = []): void
    {
        $data = array_merge([
            'license_id' => 'lic_123',
            'status' => 'active',
            'product_code' => 'test-product',
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $overrides);

        $envelope = $this->signedEnvelope($data);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put('test-product', [
            'license_id' => $data['license_id'],
            'license_key' => 'CACHED-KEY-0000',
            'status' => $data['status'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => $data['is_grace_period'],
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => $overrides['last_successful_check_at'] ?? now()->subHours(2),
            'next_check_at' => now()->subMinute(), // force due
        ]);
    }

    public function test_expired_license_is_invalid(): void
    {
        $this->seedCache();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'expired',
                'product_code' => 'test-product',
                'expires_at' => now()->subDay()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
        $this->assertTrue($status->isExpired());
    }

    public function test_revoked_license_is_invalid(): void
    {
        $this->seedCache();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'revoked',
                'product_code' => 'test-product',
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
        $this->assertTrue($status->isRevoked());
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->seedCache();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'active',
                'product_code' => 'test-product',
                'checked_at' => now()->toIso8601String(),
            ], corruptSignature: true)),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
        $this->assertSame('signature_verification_failed', $status->status);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->seedCache();

        Http::fake([
            '*/api/v1/license/check' => Http::response([
                'success' => true,
                'status' => 'success',
                'data' => ['status' => 'active', 'product_code' => 'test-product'],
                // no signature / key_id
            ]),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
    }

    public function test_offline_grace_period_keeps_license_valid(): void
    {
        $this->seedCache(['last_successful_check_at' => now()->subHour()]);

        Http::fake([
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);
        $this->assertTrue($status->fromCache);
    }

    public function test_offline_grace_period_expired_invalidates_license(): void
    {
        $this->seedCache(['last_successful_check_at' => now()->subDays(30)]);

        Http::fake([
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
    }

    public function test_tampered_cached_response_is_rejected(): void
    {
        // Seed a legitimately-signed *revoked* record, then hand-edit the
        // cached JSON to say "active" without re-signing it — the stale
        // signature must no longer match and the tamper must be caught.
        $this->seedCache(['status' => 'revoked', 'last_successful_check_at' => now()->subHour()]);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $record = $storage->get('test-product');
        $tampered = json_decode($record['signed_payload'], true);
        $tampered['status'] = 'active'; // pretend a revoked cache was hand-edited back to active
        $storage->put('test-product', array_merge($record, [
            'signed_payload' => json_encode($tampered),
        ]));

        Http::fake([
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
        $this->assertSame('tampered_cache', $status->status);
    }

    public function test_api_timeout_falls_back_to_cache_within_grace(): void
    {
        $this->seedCache(['last_successful_check_at' => now()->subMinutes(10)]);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->offline);
    }

    public function test_suspended_license_is_invalid(): void
    {
        $this->seedCache();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'suspended',
                'product_code' => 'test-product',
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
        $this->assertTrue($status->isSuspended());
    }

    public function test_domain_mismatch_is_rejected_during_check(): void
    {
        $this->seedCache();

        Http::fake([
            '*/api/v1/license/check' => Http::response(['message' => 'Domain does not match the activated license.'], 422),
        ]);

        // A rejected (non-2xx, non-5xx) response is neither a signed success
        // nor a server-unavailable condition, so it must not fall back to
        // the (still-valid) cache — check() never throws outward, it just
        // reports invalid with the server's rejection reason.
        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid);
        $this->assertSame('request_rejected', $status->status);
    }

    public function test_fingerprint_change_dispatches_event_but_does_not_by_itself_invalidate(): void
    {
        \Illuminate\Support\Facades\Event::fake([\CoreVisys\License\Events\LicenseFingerprintChanged::class]);

        // Seed a cache whose fingerprint_hash deliberately does not match
        // what FingerprintGenerator will compute for this test environment.
        $this->seedCache();
        $storage = $this->app->make(\CoreVisys\License\Contracts\LicenseStorageInterface::class);
        $record = $storage->get('test-product');
        $storage->put('test-product', array_merge($record, ['fingerprint_hash' => 'stale-fingerprint-value']));

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'active',
                'product_code' => 'test-product',
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->app->make(LicenseClientInterface::class)->check();

        \Illuminate\Support\Facades\Event::assertDispatched(\CoreVisys\License\Events\LicenseFingerprintChanged::class);
    }

    public function test_public_key_rotation_is_supported(): void
    {
        $this->seedCache();

        // The fresh response is signed with a *different* key pair under a
        // new key_id ("key-2") — the client must fetch and use that new
        // public key rather than reusing the one cached under "test-key-1".
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse('key-2', useSecondaryKey: true)),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'active',
                'product_code' => 'test-product',
                'checked_at' => now()->toIso8601String(),
            ], keyId: 'key-2', useSecondaryKey: true)),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid);
    }

    public function test_rate_limited_response_is_handled_without_crashing(): void
    {
        $this->seedCache(['last_successful_check_at' => now()->subMinutes(5)]);

        Http::fake([
            '*/api/v1/license/check' => Http::response(['message' => 'Too many requests'], 429),
        ]);

        // A 429 is treated as server-unavailable (retryable) and should
        // fall back to the still-valid cache rather than throwing out.
        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);
    }

    public function test_reported_package_version_is_a_stable_semver_string(): void
    {
        $this->seedCache();

        $capturedBody = null;

        Http::fake([
            '*/api/v1/license/check' => function (\Illuminate\Http\Client\Request $request) use (&$capturedBody) {
                $capturedBody = $request->data();

                return Http::response($this->signedEnvelope([
                    'license_id' => 'lic_123',
                    'status' => 'active',
                    'product_code' => 'test-product',
                    'checked_at' => now()->toIso8601String(),
                ]));
            },
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid);
        $this->assertIsArray($capturedBody);
        $this->assertArrayHasKey('package_version', $capturedBody);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $capturedBody['package_version']);
    }
}

