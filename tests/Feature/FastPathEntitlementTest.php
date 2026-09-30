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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Entitlement fields (features, license_type, license_id, product_code,
 * is_grace_period) are SIGNED data. They must come from the verified signed
 * payload on BOTH the fast path and the offline path — never from the unsigned
 * cache columns — because DB write access to a column would otherwise grant
 * features the server never issued.
 *
 * Written FIRST, before the overlay change, so each assertion fails against the
 * pre-change behaviour of fastPathStatus() / trustedOfflineRecord().
 */
class FastPathEntitlementTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    protected function setUp(): void
    {
        parent::setUp();

        CacheFacade::store('file')->flush();
    }

    /**
     * Seed a cache row whose signed payload and unsigned columns can be set
     * independently, with the record NOT due (exercises the fast path).
     *
     * @param  array<string, mixed>  $dataOverrides
     * @param  array<string, mixed>  $columnOverrides
     */
    private function seedNotDueRecord(array $dataOverrides = [], array $columnOverrides = []): void
    {
        $data = array_merge([
            'license_id' => 'lic_signed',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $dataOverrides);

        $envelope = $this->signedEnvelope($data);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $columns = array_merge([
            'license_id' => $data['license_id'],
            'status' => $data['status'],
            'license_type' => $data['license_type'],
            'product_code' => self::PRODUCT,
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => $data['is_grace_period'],
            'expires_at' => $data['expires_at'],
            'features' => $data['features'],
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // NOT due -> fast path
        ], $columnOverrides);

        $storage->put(self::PRODUCT, $columns);
    }

    /**
     * Seed a DUE record with the same signed-vs-unsigned split, then make the
     * server unreachable so the offline path runs.
     *
     * @param  array<string, mixed>  $dataOverrides
     * @param  array<string, mixed>  $columnOverrides
     */
    private function seedDueRecord(array $dataOverrides = [], array $columnOverrides = []): void
    {
        $this->seedNotDueRecord($dataOverrides, array_merge(['next_check_at' => now()->subMinute()], $columnOverrides));
    }

    private function client(): LicenseClientInterface
    {
        return $this->app->make(LicenseClientInterface::class);
    }

    // ------------------------------------------------------------------
    // Fast path
    // ------------------------------------------------------------------

    public function test_fast_path_signed_features_win_over_unsigned_column(): void
    {
        $this->seedNotDueRecord(
            ['features' => ['a']],
            ['features' => ['a', 'b', 'c']],
        );

        Http::fake();

        $status = $this->client()->check();

        $this->assertTrue($status->valid);
        $this->assertTrue($status->hasFeature('a'));
        $this->assertFalse($status->hasFeature('b'), 'A feature absent from the signed payload must not be granted.');
        $this->assertFalse($status->hasFeature('c'), 'Signed features must override the unsigned column entirely.');
        $this->assertSame(['a'], $status->features);
    }

    public function test_fast_path_signed_license_type_and_id_win_over_unsigned_columns(): void
    {
        $this->seedNotDueRecord(
            ['license_type' => 'trial', 'license_id' => 'lic_signed'],
            ['license_type' => 'full', 'license_id' => 'lic_column'],
        );

        Http::fake();

        $status = $this->client()->check();

        $this->assertSame('trial', $status->licenseType, 'license_type must come from the signed payload.');
        $this->assertSame('lic_signed', $status->licenseId, 'license_id must come from the signed payload.');
    }

    // ------------------------------------------------------------------
    // Offline path
    // ------------------------------------------------------------------

    public function test_offline_path_signed_features_win_over_unsigned_column(): void
    {
        $this->seedDueRecord(
            ['features' => ['a']],
            ['features' => ['a', 'b']],
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->client()->check();

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);
        $this->assertTrue($status->hasFeature('a'));
        $this->assertFalse($status->hasFeature('b'), 'The offline path must not grant an unsigned feature.');
        $this->assertSame(['a'], $status->features);
    }

    public function test_offline_path_signed_license_type_and_id_win_over_unsigned_columns(): void
    {
        $this->seedDueRecord(
            ['license_type' => 'trial', 'license_id' => 'lic_signed'],
            ['license_type' => 'full', 'license_id' => 'lic_column'],
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->client()->check();

        $this->assertSame('trial', $status->licenseType);
        $this->assertSame('lic_signed', $status->licenseId);
    }

    // ------------------------------------------------------------------
    // Fallback store
    // ------------------------------------------------------------------

    public function test_fallback_store_signed_features_win_over_unsigned_column(): void
    {
        $storage = new LicenseStorage(array_merge(config('corevisys-license'), [
            'cache_driver' => 'database',
            'cache_fallback_store' => 'file',
        ]));

        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $data = [
            'license_id' => 'lic_signed',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => ['a'],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];
        $envelope = $this->signedEnvelope($data);

        // The fallback mirror holds the record with an unsigned features column
        // padded with 'b'.
        CacheFacade::store('file')->forever(
            config('corevisys-license.cache_key', 'corevisys.license.cache').':'.self::PRODUCT,
            [
                'product_code' => self::PRODUCT,
                'license_id' => $data['license_id'],
                'status' => $data['status'],
                'license_type' => $data['license_type'],
                'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
                'signature' => $envelope['signature'],
                'key_id' => 'test-key-1',
                'issued_at' => $data['issued_at'],
                'offline_valid_until' => $data['offline_valid_until'],
                'is_grace_period' => false,
                'expires_at' => $data['expires_at'],
                'features' => ['a', 'b'],
                'last_successful_check_at' => now()->subHour(),
                'next_check_at' => now()->subMinute(),
            ]
        );

        Schema::dropIfExists('corevisys_license_cache'); // primary DB failure

        $this->app->instance(LicenseStorageInterface::class, $storage);
        $this->app->instance(LicenseVerifier::class, new LicenseVerifier(
            api: $this->app->make(ApiRequestHandler::class),
            signatureVerifier: $this->app->make(SignedPayloadVerifier::class),
            fingerprint: $this->app->make(FingerprintGenerator::class),
            storage: $storage,
            productCode: self::PRODUCT,
            config: array_merge(config('corevisys-license'), [
                'cache_driver' => 'database',
                'cache_fallback_store' => 'file',
            ]),
        ));

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->client()->check(true);

        $this->assertTrue($status->valid);
        $this->assertTrue($status->hasFeature('a'));
        $this->assertFalse($status->hasFeature('b'), 'A fallback record must not grant an unsigned feature.');
        $this->assertSame(['a'], $status->features);
    }

    // ------------------------------------------------------------------
    // Positive control
    // ------------------------------------------------------------------

    public function test_positive_control_signed_features_are_honoured(): void
    {
        $this->seedNotDueRecord(['features' => ['a', 'b']]);

        Http::fake();

        $status = $this->client()->check();

        $this->assertTrue($status->hasFeature('a'));
        $this->assertTrue($status->hasFeature('b'));
        $this->assertSame(['a', 'b'], $status->features);
    }
}
