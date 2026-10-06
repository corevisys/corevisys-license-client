<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Http;

/**
 * A5 on the fast path: a not-yet-due cached record must NOT be served valid
 * when the specific key that signed it is *revoked*, or when its key_id is
 * *unknown* to the cached key set. The fast path verifies locally (no network),
 * so it must fail closed in both cases rather than trusting the record because
 * next_check_at is still in the future.
 */
class FastPathKeyRevocationTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    /**
     * Seed a "not yet due" cache record signed by test-key-1, together with the
     * given cached public-key metadata (available_keys / revoked_key_ids).
     *
     * @param  array<string, mixed>  $dataOverrides
     * @param  array<string, mixed>|null  $keyMeta
     */
    private function seedNotDue(array $dataOverrides = [], ?array $keyMeta = null): void
    {
        $data = array_merge([
            'license_id' => 'lic_revoke',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $dataOverrides);

        $envelope = $this->signedEnvelope($data, 'test-key-1');

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);

        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata($keyMeta ?? [
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put(self::PRODUCT, [
            'license_id' => $data['license_id'],
            'status' => $data['status'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => false,
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // NOT due — exercises the fast path
        ]);
    }

    /**
     * Control: the SAME seeded record, with its key present and NOT revoked,
     * must be served valid from the fast path with the server unreachable.
     * This proves the two negative tests below fail for the revocation / key
     * set reason, not because the record never reached the fast path.
     */
    public function test_fast_path_serves_valid_when_key_is_present_and_not_revoked(): void
    {
        $this->seedNotDue();

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid, 'Control failed: a present, non-revoked key should be served valid on the fast path.');
    }

    public function test_revoked_signing_key_is_not_served_valid_on_the_fast_path(): void
    {
        // The cached key set still *lists* test-key-1 but marks it revoked.
        $this->seedNotDue([], [
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => ['test-key-1'],
        ]);

        // Server unreachable: the decision must come purely from the cache, and
        // local verification must fail closed for a revoked key.
        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'A revoked signing key must never yield a valid fast-path status.');
    }

    public function test_unknown_key_id_is_not_served_valid_on_the_fast_path(): void
    {
        // The cached key set does not contain test-key-1 at all.
        $this->seedNotDue([], [
            'available_keys' => [['key_id' => 'some-other-key', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'An unknown key_id must never yield a valid fast-path status.');
    }
}
