<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class KeyRevocationAndAcceptanceTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    public function test_payload_with_revoked_key_id_is_rejected_on_activate(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response([
                'available_keys' => [
                    ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
                ],
                'revoked_key_ids' => ['test-key-1'],
            ]),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_revoked_activate',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ], 'test-key-1')),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('TEST-REVOKED-KEY');

        $this->assertFalse($result->success, 'Activation with a revoked key_id must be rejected.');
    }

    public function test_payload_with_revoked_key_id_is_rejected_on_online_check(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response([
                'available_keys' => [
                    ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
                ],
                'revoked_key_ids' => ['test-key-1'],
            ]),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_revoked_check',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ], 'test-key-1')),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'Online check with a revoked key_id must yield invalid status.');
    }

    public function test_payload_with_revoked_key_id_is_rejected_on_fast_path(): void
    {
        $payload = [
            'license_id' => 'lic_fast_path_revoked',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'expires_at' => now()->addYear()->toIso8601String(),
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
        $envelope = $this->signedEnvelope($payload, 'test-key-1');

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [
                ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
            ],
            'revoked_key_ids' => ['test-key-1'],
        ], 86400);

        $storage->put(self::PRODUCT, [
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

        $this->assertFalse($status->valid, 'Fast path must reject a record signed with a revoked key_id.');
    }

    public function test_payload_with_revoked_key_id_is_rejected_on_offline_path(): void
    {
        $payload = [
            'license_id' => 'lic_offline_revoked',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'expires_at' => now()->addYear()->toIso8601String(),
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
        $envelope = $this->signedEnvelope($payload, 'test-key-1');

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [
                ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
            ],
            'revoked_key_ids' => ['test-key-1'],
        ], 86400);

        $storage->put(self::PRODUCT, [
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

        $this->assertFalse($status->valid, 'Offline fallback path must reject a record signed with a revoked key_id.');
    }

    public function test_payload_with_new_key_id_signed_by_new_test_key_is_accepted(): void
    {
        $newKeyId = 'test-key-2';
        $newPublic = $this->secondaryKeyPair()['public'];

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([
                'key_id' => $newKeyId,
                'public_key' => $newPublic,
                'available_keys' => [
                    ['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']],
                    ['key_id' => $newKeyId, 'public_key' => $newPublic],
                ],
                'revoked_key_ids' => ['test-key-1'],
            ]),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_new_key',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ], $newKeyId, corruptSignature: false, useSecondaryKey: true)),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('VALID-KEY-NEW-KEYPAIR');

        $this->assertTrue($result->success, 'Activation with a valid new key_id signed by new key must succeed.');
        $this->assertTrue($result->status->isActive());
    }
}
