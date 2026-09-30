<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Http;

/**
 * Phase 4C Part 2 follow-up: a verified, locally-signed ACTIVE payload whose
 * signed offline_valid_until is ABSENT must not be served from the fast path.
 * The unsigned database column is never a source of trust, so the record must
 * be forced onto the normal online path.
 */
class FastPathSignedBoundaryTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    protected function setUp(): void
    {
        parent::setUp();

        // The 'file' fallback store persists between runs; flush it so a stale
        // key set from an earlier test cannot mask a real failure.
        CacheFacade::store('file')->flush();
    }

    /**
     * Seed a "not yet due" record. $dataOverrides drive the SIGNED payload;
     * offline_valid_until may be removed to model a legacy row. The unsigned
     * offline_valid_until column is set independently so a test can prove it is
     * never consulted by the fast path.
     *
     * @param  array<string, mixed>  $dataOverrides
     * @param  array<string, mixed>  $columnOverrides
     */
    private function seedNotDue(array $dataOverrides = [], array $columnOverrides = []): void
    {
        $data = array_merge([
            'license_id' => 'lic_boundary',
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

        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put(self::PRODUCT, array_merge([
            'license_id' => $data['license_id'],
            'status' => $data['status'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'] ?? null,
            'is_grace_period' => false,
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // NOT due — exercises the fast path
        ], $columnOverrides));
    }

    private function client(): LicenseClientInterface
    {
        return $this->app->make(LicenseClientInterface::class);
    }

    /**
     * Control: a signed offline_valid_until in the future is honoured on the
     * fast path with no network call.
     */
    public function test_signed_offline_boundary_in_the_future_is_honoured_offline(): void
    {
        $this->seedNotDue();

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->client()->check();

        $this->assertTrue($status->valid);
        $this->assertTrue($status->fromCache);
        Http::assertNothingSent();
    }

    /**
     * An active payload WITHOUT a signed offline_valid_until must not be served
     * from cache when the server cannot be reached. The unsigned column is set
     * to null so the A6 offline path also rejects cleanly.
     */
    public function test_active_payload_without_signed_boundary_is_not_served_when_server_is_down(): void
    {
        $this->seedNotDue(['offline_valid_until' => null], ['offline_valid_until' => null]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->client()->check();

        $this->assertFalse(
            $status->valid,
            'An active payload without a signed offline boundary must not be trusted from cache.',
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), '/license/check'));
    }

    /**
     * An active payload WITHOUT a signed offline_valid_until must go online and
     * follow the server's authoritative answer.
     */
    public function test_active_payload_without_signed_boundary_goes_online_and_follows_the_server(): void
    {
        $this->seedNotDue(['offline_valid_until' => null], ['offline_valid_until' => null]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_boundary',
                'status' => 'revoked',
                'product_code' => self::PRODUCT,
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/license/check'));
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isRevoked());
        $this->assertFalse($status->fromCache);
    }
}
