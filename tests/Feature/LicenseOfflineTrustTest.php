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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Acceptance tests for the frozen offline rule (Section A6).
 *
 * These prove that the offline/grace path bases trust on the *signed* payload
 * only. The unsigned cache columns (status, expires_at, offline_valid_until)
 * must never carry or extend trust, and a primary-store failure must not
 * lengthen the offline boundary.
 *
 * Written FIRST, before any implementation change, so each assertion fails
 * against the pre-fix behaviour of LicenseVerifier::fallbackToCache() /
 * withinGracePeriod().
 */
class LicenseOfflineTrustTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    protected function setUp(): void
    {
        parent::setUp();

        // The 'file' cache store persists on disk across tests; flush it to
        // keep fallback assertions isolated.
        CacheFacade::store('file')->flush();
    }

    /**
     * Seed a cache record whose signed payload and unsigned columns are set
     * INDEPENDENTLY — the two must be able to disagree so we can prove which
     * one the verifier trusts.
     *
     * @param  array<string, mixed>  $signed    Fields placed in the signed payload.
     * @param  array<string, mixed>  $columns   Fields written to the unsigned row.
     */
    protected function seedCache(array $signed = [], array $columns = []): void
    {
        $payload = array_merge([
            'license_id' => 'lic_123',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $signed);

        $envelope = $this->signedEnvelope($payload);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        // Unsigned columns default to mirroring the signed payload, then the
        // caller can override any of them to create a signed/unsigned mismatch.
        $row = array_merge([
            'license_id' => $payload['license_id'],
            'status' => $payload['status'],
            'expires_at' => $payload['expires_at'],
            'offline_valid_until' => $payload['offline_valid_until'],
            'is_grace_period' => $payload['is_grace_period'],
            'last_successful_check_at' => now()->subHours(2),
            'next_check_at' => now()->subMinute(), // force due -> normal online path
        ], $columns);

        $storage->put(self::PRODUCT, array_merge($row, [
            'license_key' => 'CACHED-KEY-0000',
            'signed_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
        ]));
    }

    protected function serverDown(): void
    {
        Http::fake([
            '*/api/v1/license/check' => Http::response([], 500),
        ]);
    }

    // (a) The unsigned status must not be trusted: a signed "suspended"
    //     payload whose unsigned column says "active" must stay invalid.
    public function test_signed_suspended_is_not_overridden_by_unsigned_active_column(): void
    {
        $this->seedCache(
            signed: ['status' => 'suspended'],
            columns: ['status' => 'active'],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'A signed suspended payload must not be trusted as active.');
        $this->assertTrue($status->isSuspended(), 'The resolved status must come from the signed payload.');
    }

    // (b) The unsigned offline_valid_until must not EXTEND trust: a signed
    //     boundary already in the past means no offline trust, even when the
    //     unsigned column claims a future boundary.
    public function test_signed_past_offline_boundary_is_not_extended_by_unsigned_column(): void
    {
        $this->seedCache(
            signed: ['offline_valid_until' => now()->subDay()->toIso8601String()],
            columns: ['offline_valid_until' => now()->addDays(30)->toIso8601String()],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'An unsigned column must never extend a signed offline boundary.');
    }

    // (c) The unsigned expires_at must not extend trust: a signed expiry in
    //     the past means the cached license is expired, even when the unsigned
    //     column claims a future expiry.
    public function test_signed_past_expiry_is_not_extended_by_unsigned_column(): void
    {
        $this->seedCache(
            signed: ['expires_at' => now()->subDay()->toIso8601String()],
            columns: ['expires_at' => now()->addYear()->toIso8601String()],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'An unsigned column must never extend a signed expiry.');
    }

    // (d) The local grace window must not EXTEND the signed boundary: even
    //     within the local grace period, a signed boundary in the past means
    //     the offline path yields no validity.
    public function test_local_grace_window_cannot_extend_the_signed_boundary(): void
    {
        $this->seedCache(
            signed: ['offline_valid_until' => now()->subDay()->toIso8601String()],
            columns: [
                'offline_valid_until' => now()->subDay()->toIso8601String(),
                // Recent enough that the *local* grace window is still open.
                'last_successful_check_at' => now()->subMinutes(5),
            ],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'Local grace must only shorten the signed boundary, never extend it.');
    }

    // (e) A tampered unsigned offline_valid_until (edited to look expired) must
    //     NOT shorten the signed trust: the record is due, the server is
    //     unreachable, and the SIGNED (future) boundary still governs.
    public function test_tampered_unsigned_boundary_does_not_shorten_offline_trust(): void
    {
        $this->seedCache(
            signed: ['offline_valid_until' => now()->addDays(7)->toIso8601String()],
            columns: [
                'offline_valid_until' => now()->subDay()->toIso8601String(), // tampered
                'next_check_at' => now()->subMinute(),                      // due -> online attempt
            ],
        );

        Http::fake([
            '*/api/v1/license/check' => function () {
                throw new ConnectionException('server unreachable in test');
            },
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        // The online attempt happened (offline=true) and, on failure, the
        // offline rule used the SIGNED boundary — so the tampered unsigned
        // column neither denied nor extended trust.
        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline, 'A cache result must be flagged offline.');
    }

    // (f) A primary-store failure must not lengthen the offline boundary: the
    //     primary DB is gone, and the fallback record's signed boundary is in
    //     the past, so the result must fail closed.
    public function test_primary_store_failure_does_not_extend_offline_boundary(): void
    {
        $data = array_merge($this->basePayload(), [
            'offline_valid_until' => now()->subDay()->toIso8601String(),
        ]);
        $envelope = $this->signedEnvelope($data);

        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);
        $this->seedTrustedKeys($storage);

        // A fallback record whose UNSIGNED column claims a future boundary
        // while the signed boundary is already in the past.
        CacheFacade::store('file')->forever($this->cacheKey(), array_merge($this->recordFrom($data, $envelope), [
            'offline_valid_until' => now()->addDays(30)->toIso8601String(),
            'last_successful_check_at' => now()->subMinutes(5),
        ]));

        Schema::dropIfExists('corevisys_license_cache'); // simulate primary DB failure

        $this->bindStorageAndVerifier($storage);
        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid, 'A fallback record with an expired signed boundary must fail closed.');
    }

    // (g) The fallback is a storage location, not a trust shortcut: a fallback
    //     record that still passes the full A6 rule (signed, unexpired) may be
    //     served, flagged offline.
    public function test_fallback_record_passing_full_offline_rule_is_accepted(): void
    {
        [$data, $envelope] = $this->envelopeForActive();

        $storage = $this->makeStorage(['cache_driver' => 'database', 'cache_fallback_store' => 'file']);
        $this->seedTrustedKeys($storage);

        CacheFacade::store('file')->forever($this->cacheKey(), $this->recordFrom($data, $envelope));

        Schema::dropIfExists('corevisys_license_cache'); // simulate primary DB failure

        $this->bindStorageAndVerifier($storage);
        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertTrue($status->valid, 'A fallback record that passes the full A6 rule must be accepted.');
        $this->assertTrue($status->offline);
    }

    // (d) A signed ACTIVE payload with NO signed offline_valid_until must not be
    //     rescued by a future-looking unsigned column: the unsigned boundary is
    //     never read, so an absent signed boundary means no offline trust.
    public function test_signed_active_with_absent_boundary_is_not_extended_by_unsigned_column(): void
    {
        $this->seedCache(
            signed: ['offline_valid_until' => null], // absent in the signed payload
            columns: ['offline_valid_until' => now()->addDays(90)->toIso8601String()],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'A signed active payload with no signed offline boundary must not be trusted offline.');
    }

    // (e) A future-dated last_successful_check_at must not be trusted: it is a
    //     clock-tamper/forward-skew that would otherwise keep the local grace
    //     window open indefinitely. A small skew is tolerated.
    public function test_future_dated_last_successful_check_at_is_rejected(): void
    {
        $this->seedCache(
            signed: ['offline_valid_until' => now()->addDays(7)->toIso8601String()],
            columns: ['last_successful_check_at' => now()->addDays(2)], // beyond a small skew
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'A future-dated last_successful_check_at must not extend the local grace window.');
    }

    // Boundary bound: even with last_successful_check_at refreshed to NOW (the
    // local grace window wide open), a SIGNED offline_valid_until in the past
    // is never resurrected. Trust can never exceed the signed boundary.
    public function test_fresh_last_check_cannot_resurrect_a_past_signed_boundary(): void
    {
        $this->seedCache(
            signed: ['offline_valid_until' => now()->subDay()->toIso8601String()],
            columns: [
                'offline_valid_until' => now()->subDay()->toIso8601String(),
                'last_successful_check_at' => now(), // refreshed -> grace wide open
            ],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertFalse($status->valid, 'Trust must never exceed the signed offline_valid_until, even with a fresh last check.');
    }

    // -----------------------------------------------------------------------

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

    private function seedTrustedKeys(LicenseStorage $storage): void
    {
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);
    }

    private function cacheKey(): string
    {
        return config('corevisys-license.cache_key', 'corevisys.license.cache').':'.self::PRODUCT;
    }

    private function basePayload(): array
    {
        return [
            'license_id' => 'lic_123',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function envelopeForActive(): array
    {
        $data = $this->basePayload();

        return [$data, $this->signedEnvelope($data)];
    }

    private function recordFrom(array $data, array $envelope): array
    {
        return [
            'product_code' => self::PRODUCT,
            'license_id' => $data['license_id'],
            'status' => $data['status'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => $data['is_grace_period'],
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => now()->subHours(2),
            'next_check_at' => now()->subMinute(),
        ];
    }

    // -----------------------------------------------------------------------
    // P1: a 5-day-old cached payload that is still within the signed boundary
    // and the local grace window.
    //
    // NOTE ON assertFreshTimestamp: verifyResponse() calls it for BOTH the
    // online and the local verify path (SignedPayloadVerifier.php:134), and it
    // reads checked_at/server_time under signature.timestamp_tolerance (300s),
    // returning early when BOTH are absent. The cached payload here carries
    // NEITHER, so assertFreshTimestamp is a no-op and must not reject the
    // cached envelope. The tests below are written as regression guards against
    // a future change that makes the local path enforce a freshness window.
    // -----------------------------------------------------------------------

    public function test_five_day_old_cached_payload_is_valid_when_boundary_and_grace_are_open(): void
    {
        $this->seedCache(
            signed: [
                'issued_at' => now()->subDays(5)->toIso8601String(),
                'offline_valid_until' => now()->addDays(2)->toIso8601String(),
            ],
            columns: [
                'issued_at' => now()->subDays(5)->toIso8601String(),
                'offline_valid_until' => now()->addDays(2)->toIso8601String(),
                'last_successful_check_at' => now()->subHours(2),
                'next_check_at' => now()->subMinute(), // due -> offline path when the server is down
            ],
        );

        $this->serverDown();

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid, 'A still-signed cached payload within the signed boundary and the local grace window must be valid offline.');
        $this->assertTrue($status->offline, 'The status must be resolved from the offline path.');
    }

    public function test_five_day_old_cached_payload_is_valid_on_the_fast_path_when_not_due(): void
    {
        $this->seedCache(
            signed: [
                'issued_at' => now()->subDays(5)->toIso8601String(),
                'offline_valid_until' => now()->addDays(2)->toIso8601String(),
            ],
            columns: [
                'issued_at' => now()->subDays(5)->toIso8601String(),
                'offline_valid_until' => now()->addDays(2)->toIso8601String(),
                'last_successful_check_at' => now()->subHours(2),
                'next_check_at' => now()->addDay(), // NOT due -> fast path
            ],
        );

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid, 'A not-yet-due signed cached payload within its signed boundary must be valid on the fast path.');
        $this->assertTrue($status->fromCache, 'A fast-path hit is served from cache.');
    }
}
