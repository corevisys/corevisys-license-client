<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Http;

/**
 * Phase 4C Part 2: the fast path (a cached record whose next_check_at is still
 * in the future) must not trust unsigned database columns. It may only serve a
 * record when the cached signed payload verifies against LOCAL key material,
 * the status/expiry come from that verified payload, and the signed
 * offline_valid_until (when present) has not passed. Verifying locally must
 * never trigger a network call, and an unverifiable record is treated as due.
 */
class FastPathTrustTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    /**
     * The 'file' fallback cache store persists on disk between tests, so a key
     * seeded by an earlier test could otherwise leak into the "cold key cache"
     * case and mask a real failure. Flush it before every test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        CacheFacade::store('file')->flush();
    }

    /**
     * Seed a "not yet due" cache record whose signed payload can be trusted
     * offline (unless $seedKeys is false, which simulates a cold key cache).
     *
     * @param  array<string, mixed>  $dataOverrides    Changes applied to the signed payload.
     * @param  array<string, mixed>  $columnOverrides  Unsigned DB columns (what a tamperer edits).
     */
    private function seedNotDueRecord(array $dataOverrides = [], array $columnOverrides = [], bool $seedKeys = true): void
    {
        $data = array_merge([
            'license_id' => 'lic_fast',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $dataOverrides);

        // A null override explicitly removes the field (legacy rows).
        if (array_key_exists('offline_valid_until', $data) && $data['offline_valid_until'] === null) {
            unset($data['offline_valid_until']);
        }

        $envelope = $this->signedEnvelope($data);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);

        if ($seedKeys) {
            $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
            $storage->putPublicKeyMetadata([
                'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
                'revoked_key_ids' => [],
            ], 86400);
        }

        $columns = array_merge([
            'license_id' => $data['license_id'],
            'status' => $data['status'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'] ?? null,
            'is_grace_period' => $data['is_grace_period'] ?? false,
            'expires_at' => $data['expires_at'] ?? null,
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // NOT due — exercises the fast path
        ], $columnOverrides);

        $storage->put(self::PRODUCT, $columns);
    }

    private function client(): LicenseClientInterface
    {
        return $this->app->make(LicenseClientInterface::class);
    }

    public function test_not_due_with_future_offline_boundary_is_valid_without_any_http(): void
    {
        $this->seedNotDueRecord();

        Http::fake();

        $status = $this->client()->check();

        $this->assertTrue($status->valid);
        $this->assertFalse($status->offline);
        $this->assertTrue($status->fromCache);

        // The fast path must never reach the network.
        Http::assertNothingSent();
    }

    public function test_not_due_with_expired_offline_boundary_forces_online_and_follows_the_server(): void
    {
        $this->seedNotDueRecord(['offline_valid_until' => now()->subDay()->toIso8601String()]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_fast',
                'status' => 'expired',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->subDay()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check();

        // The signed offline boundary has passed -> the record is due, so a
        // fresh online check runs and its (authoritative) answer is returned.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/license/check'));

        $this->assertFalse($status->valid);
        $this->assertTrue($status->isExpired());
        $this->assertFalse($status->fromCache);
    }

    public function test_not_due_with_expired_offline_boundary_and_unreachable_server_is_invalid(): void
    {
        $this->seedNotDueRecord(['offline_valid_until' => now()->subDay()->toIso8601String()]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->client()->check();

        // A6 rejects: the signed offline_valid_until is in the past, so the
        // cached record can never be served as valid offline.
        $this->assertFalse($status->valid);
        $this->assertSame('grace_period_expired', $status->status);
    }

    public function test_legacy_record_without_signed_boundary_goes_online(): void
    {
        // Same fixture as before, but the expected outcome is inverted: a
        // verified ACTIVE payload with no SIGNED offline_valid_until cannot be
        // trusted from cache alone (A6). The fast path must decline it and the
        // record must be re-checked online.
        $this->seedNotDueRecord(['offline_valid_until' => null]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_fast',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'license_type' => 'full',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check();

        // The online path is taken and the server's authoritative answer is
        // returned (not the cached record).
        Http::assertSent(fn ($request) => str_contains($request->url(), '/license/check'));

        $this->assertTrue($status->valid);
        $this->assertFalse($status->fromCache);
    }

    public function test_edited_next_check_at_cannot_extend_trust_past_the_signed_boundary(): void
    {
        // Signed payload says the offline boundary is in the past, but the
        // unsigned columns were hand-edited to look freshly valid + not-due.
        $this->seedNotDueRecord(
            ['offline_valid_until' => now()->subDay()->toIso8601String()],
            [
                'offline_valid_until' => now()->addYear()->toIso8601String(),
                'status' => 'active',
                'next_check_at' => '2099-01-01 00:00:00',
            ],
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_fast',
                'status' => 'revoked',
                'product_code' => self::PRODUCT,
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check();

        // next_check_at = 2099 must not extend trust: the signed boundary wins,
        // the record is forced online, and the server's answer is returned.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/license/check'));

        $this->assertFalse($status->valid);
        $this->assertTrue($status->isRevoked());
    }

    public function test_status_column_cannot_override_a_signed_suspension(): void
    {
        // Signed payload says suspended; the unsigned column was edited to say
        // active. The signed payload wins and no server call is needed.
        $this->seedNotDueRecord(
            ['status' => 'suspended'],
            ['status' => 'active'],
        );

        Http::fake();

        $status = $this->client()->check();

        $this->assertFalse($status->valid);
        $this->assertTrue($status->isSuspended());

        Http::assertNothingSent();
    }

    public function test_tampered_signed_payload_on_a_not_due_row_is_rejected(): void
    {
        $data = [
            'license_id' => 'lic_fast',
            'status' => 'revoked',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
        $envelope = $this->signedEnvelope($data);

        // Hand-edit the cached JSON to "active" WITHOUT re-signing, on a row
        // that is still "not due" (next_check_at in the future).
        $tampered = $data;
        $tampered['status'] = 'active';

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);
        $storage->put(self::PRODUCT, [
            'license_id' => $data['license_id'],
            'status' => 'active',
            'signed_payload' => json_encode($tampered, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'], // signature of the *original* revoked payload
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => false,
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // not due
        ]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->client()->check();

        // The tampered payload cannot be trusted from cache; the record is
        // forced online, the server is unreachable, and the A6 path rejects
        // the bad signature rather than serving the hand-edited "active".
        $this->assertFalse($status->valid);
        $this->assertSame('tampered_cache', $status->status);
    }

    public function test_cold_public_key_cache_is_treated_as_due_without_exception(): void
    {
        // No key material cached locally: the fast path cannot verify offline.
        $this->seedNotDueRecord([], [], seedKeys: false);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->client()->check();

        // No exception escapes; the record falls through to the normal path.
        $this->assertFalse($status->valid);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/license/check'));
    }
}
