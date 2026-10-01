<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class CrossProductPackageTest extends TestCase
{
    use SignsPayloads;

    private const CONFIGURED_PRODUCT = 'test-product';
    private const FOREIGN_PRODUCT = 'unauthorized-other-product';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('corevisys-license.product_code', self::CONFIGURED_PRODUCT);
    }

    public function test_activation_rejects_response_with_mismatched_product_code(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_foreign_activate',
                'status' => 'active',
                'product_code' => self::FOREIGN_PRODUCT, // Mismatch!
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('ANY-KEY');

        $this->assertFalse($result->success, 'Activation with mismatched product_code must fail.');
        $this->assertSame('product_code_mismatch', $result->errorCode);
    }

    public function test_online_check_rejects_response_with_mismatched_product_code(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_foreign_check',
                'status' => 'active',
                'product_code' => self::FOREIGN_PRODUCT, // Mismatch!
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'Online check with mismatched product_code must yield invalid status.');
        $this->assertSame('product_code_mismatch', $status->status);
    }

    public function test_fast_path_rejects_cached_record_with_mismatched_product_code(): void
    {
        $payload = [
            'license_id' => 'lic_fast_path_foreign',
            'status' => 'active',
            'product_code' => self::FOREIGN_PRODUCT, // Foreign product in signed payload
            'expires_at' => now()->addYear()->toIso8601String(),
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
        $envelope = $this->signedEnvelope($payload);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [
                ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
            ],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put(self::CONFIGURED_PRODUCT, [
            'license_id' => $payload['license_id'],
            'status' => $payload['status'],
            'signed_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $payload['issued_at'],
            'offline_valid_until' => $payload['offline_valid_until'],
            'is_grace_period' => false,
            'expires_at' => $payload['expires_at'],
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // Not due -> exercises fast path
        ]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'Fast path must never serve a record valid when product_code does not match.');
    }

    public function test_offline_grace_path_rejects_cached_record_with_mismatched_product_code(): void
    {
        $payload = [
            'license_id' => 'lic_offline_foreign',
            'status' => 'active',
            'product_code' => self::FOREIGN_PRODUCT, // Foreign product in signed payload
            'expires_at' => now()->addYear()->toIso8601String(),
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
        $envelope = $this->signedEnvelope($payload);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [
                ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
            ],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put(self::CONFIGURED_PRODUCT, [
            'license_id' => $payload['license_id'],
            'status' => $payload['status'],
            'signed_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $payload['issued_at'],
            'offline_valid_until' => $payload['offline_valid_until'],
            'is_grace_period' => false,
            'expires_at' => $payload['expires_at'],
            'last_successful_check_at' => now()->subHours(2),
            'next_check_at' => now()->subMinute(), // Due -> triggers server check, falls back to offline
        ]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'Offline grace path must never serve a record valid when product_code does not match.');
    }

    public function test_matching_product_code_is_accepted_on_activate_and_check(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matching_activate',
                'status' => 'active',
                'product_code' => self::CONFIGURED_PRODUCT, // Matches!
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matching_check',
                'status' => 'active',
                'product_code' => self::CONFIGURED_PRODUCT, // Matches!
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('MATCH-KEY');
        $this->assertTrue($result->success);

        $status = $this->app->make(LicenseClientInterface::class)->check();
        $this->assertTrue($status->valid);
    }
}
