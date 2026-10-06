<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class TamperMatrixTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    protected function defineRoutes($router): void
    {
        $router->middleware('corevisys.license')->get('/protected', fn () => response()->json(['access' => 'granted']));
    }

    protected function seedTamperedCache(array $signedOverrides = [], array $columnOverrides = []): void
    {
        $data = array_merge([
            'license_id' => 'lic_matrix_1',
            'status' => 'active',
            'product_code' => self::PRODUCT,
            'license_type' => 'subscription',
            'bound_domain' => 'client.example.com',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => ['feature1'],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $signedOverrides);

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
            'product_code' => $data['product_code'],
            'bound_domain' => $data['bound_domain'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => $data['is_grace_period'],
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => now()->subHour(),
            'next_check_at' => now()->addDay(), // default not due (fast path eligible)
        ], $columnOverrides);

        $storage->put(self::PRODUCT, $columns);
    }

    private function client(): LicenseClientInterface
    {
        return $this->app->make(LicenseClientInterface::class);
    }

    // =========================================================================
    // 1. STATUS COLUMN TAMPERING
    // =========================================================================

    public function test_status_column_tamper_to_active_when_server_unreachable(): void
    {
        // Signed is suspended; column tampered to active
        $this->seedTamperedCache(
            signedOverrides: ['status' => 'suspended'],
            columnOverrides: ['status' => 'active']
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        // Fast path
        $this->assertFalse($this->client()->isValid());
        // Check
        $status = $this->client()->check(true);
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isSuspended());
        // Status command
        $this->artisan('corevisys:license:status')->expectsOutputToContain('suspended')->assertExitCode(1);
        // Middleware
        $this->getJson('/protected')->assertStatus(403);
    }

    public function test_status_column_tamper_to_active_when_server_reachable(): void
    {
        // Signed is suspended; column tampered to active; server confirms suspended
        $this->seedTamperedCache(
            signedOverrides: ['status' => 'suspended'],
            columnOverrides: ['status' => 'active']
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matrix_1',
                'status' => 'suspended',
                'product_code' => self::PRODUCT,
            ])),
        ]);

        $status = $this->client()->check(true);
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isSuspended());
        $this->artisan('corevisys:license:status')->expectsOutputToContain('suspended')->assertExitCode(1);
        $this->getJson('/protected')->assertStatus(403);
    }

    // =========================================================================
    // 2. EXPIRES_AT COLUMN TAMPERING
    // =========================================================================

    public function test_expires_at_column_tamper_to_future_when_server_unreachable(): void
    {
        // Signed is expired; column tampered to future
        $this->seedTamperedCache(
            signedOverrides: ['expires_at' => now()->subDay()->toIso8601String()],
            columnOverrides: ['expires_at' => now()->addYear()->toIso8601String()]
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        // Fast path rejects
        $this->assertFalse($this->client()->isValid());
        // Check rejects
        $status = $this->client()->check(true);
        $this->assertFalse($status->valid);
        // Status command shows expired
        $this->artisan('corevisys:license:status')->assertExitCode(1);
        // Middleware denies
        $this->getJson('/protected')->assertStatus(403);
    }

    public function test_expires_at_column_tamper_to_future_when_server_reachable(): void
    {
        // Signed is expired; column tampered to future; server returns expired
        $this->seedTamperedCache(
            signedOverrides: ['expires_at' => now()->subDay()->toIso8601String()],
            columnOverrides: ['expires_at' => now()->addYear()->toIso8601String()]
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matrix_1',
                'status' => 'expired',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->subDay()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check(true);
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isExpired());
        $this->getJson('/protected')->assertStatus(403);
    }

    // =========================================================================
    // 3. NEXT_CHECK_AT COLUMN TAMPERING
    // =========================================================================

    public function test_next_check_at_tampered_to_future_cannot_mask_expired_offline_window_when_unreachable(): void
    {
        // next_check_at tampered to 2099, but signed offline window is expired
        $this->seedTamperedCache(
            signedOverrides: ['offline_valid_until' => now()->subDay()->toIso8601String()],
            columnOverrides: ['next_check_at' => '2099-01-01 00:00:00']
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        // Fast path declines and falls through to offline check which fails closed
        $this->assertFalse($this->client()->isValid());
        $status = $this->client()->check(false);
        $this->assertFalse($status->valid);
        $this->getJson('/protected')->assertStatus(403);
    }

    public function test_next_check_at_tampered_to_past_triggers_online_check_when_reachable(): void
    {
        // next_check_at tampered to past; server reachable -> fresh check runs and server payload governs
        $this->seedTamperedCache(
            signedOverrides: ['status' => 'active'],
            columnOverrides: ['next_check_at' => now()->subHour()->toIso8601String()]
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matrix_1',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->addYear()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check();
        $this->assertTrue($status->valid);
        $this->getJson('/protected')->assertStatus(200);
    }

    // =========================================================================
    // 4. OFFLINE_VALID_UNTIL COLUMN TAMPERING
    // =========================================================================

    public function test_offline_valid_until_column_tamper_to_future_when_server_unreachable(): void
    {
        // Signed has past offline boundary; column tampered to +60 days
        $this->seedTamperedCache(
            signedOverrides: ['offline_valid_until' => now()->subDay()->toIso8601String()],
            columnOverrides: [
                'offline_valid_until' => now()->addDays(60)->toIso8601String(),
                'next_check_at' => now()->subMinute(), // due -> triggers offline evaluation
            ]
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $status = $this->client()->check();
        $this->assertFalse($status->valid);
        $this->assertSame('grace_period_expired', $status->status);
        $this->getJson('/protected')->assertStatus(403);
    }

    public function test_offline_valid_until_column_tamper_when_server_reachable(): void
    {
        // Column tampered, but server is reachable -> server's fresh response overrides
        $this->seedTamperedCache(
            signedOverrides: ['offline_valid_until' => now()->subDay()->toIso8601String()],
            columnOverrides: [
                'offline_valid_until' => now()->addDays(60)->toIso8601String(),
                'next_check_at' => now()->subMinute(),
            ]
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matrix_1',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'expires_at' => now()->addYear()->toIso8601String(),
                'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check(true);
        $this->assertTrue($status->valid);
        $this->getJson('/protected')->assertStatus(200);
    }

    // =========================================================================
    // 5. LICENSE_TYPE COLUMN TAMPERING
    // =========================================================================

    public function test_license_type_column_tamper_follows_only_signed_payload_unreachable(): void
    {
        // Signed is 'subscription'; column tampered to 'enterprise_lifetime'
        $this->seedTamperedCache(
            signedOverrides: ['license_type' => 'subscription'],
            columnOverrides: ['license_type' => 'enterprise_lifetime']
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        // Fast path
        $statusFast = $this->client()->check();
        $this->assertSame('subscription', $statusFast->licenseType);

        // Check path
        $statusCheck = $this->client()->check(true);
        $this->assertSame('subscription', $statusCheck->licenseType);

        // Status command reports signed license_type
        $this->artisan('corevisys:license:status')
            ->expectsOutputToContain('subscription')
            ->doesntExpectOutput('enterprise_lifetime');
    }

    public function test_license_type_column_tamper_follows_only_signed_payload_reachable(): void
    {
        $this->seedTamperedCache(
            signedOverrides: ['license_type' => 'subscription'],
            columnOverrides: ['license_type' => 'enterprise_lifetime']
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matrix_1',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'license_type' => 'subscription',
                'expires_at' => now()->addYear()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check(true);
        $this->assertSame('subscription', $status->licenseType);
    }

    // =========================================================================
    // 6. PRODUCT_CODE COLUMN TAMPERING
    // =========================================================================

    public function test_product_code_column_tamper_rejected_when_server_unreachable(): void
    {
        // Signed has legitimate product; column tampered to 'tampered-product'
        $this->seedTamperedCache(
            signedOverrides: ['product_code' => self::PRODUCT],
            columnOverrides: ['product_code' => 'tampered-product']
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        // Access must adhere to signed product_code: statusFromCacheRecord reads signed product
        $status = $this->client()->check();
        $this->assertSame(self::PRODUCT, $status->productCode);
    }

    public function test_product_code_signed_mismatch_rejected_even_if_column_matches(): void
    {
        // Signed payload has different product; unsigned column tampered to match expected product
        $this->seedTamperedCache(
            signedOverrides: ['product_code' => 'other-product'],
            columnOverrides: ['product_code' => self::PRODUCT]
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        // Fast path declines mismatched signed product_code
        $this->assertFalse($this->client()->isValid());
        $status = $this->client()->check(false);
        $this->assertFalse($status->valid);
        $this->getJson('/protected')->assertStatus(403);
    }

    // =========================================================================
    // 7. BOUND_DOMAIN COLUMN TAMPERING
    // =========================================================================

    public function test_bound_domain_column_tamper_follows_only_signed_payload_unreachable(): void
    {
        // Signed has 'client.example.com'; column tampered to 'attacker.org'
        $this->seedTamperedCache(
            signedOverrides: ['bound_domain' => 'client.example.com'],
            columnOverrides: ['bound_domain' => 'attacker.org']
        );

        Http::fake(['*/api/v1/license/check' => Http::response([], 500)]);

        $statusFast = $this->client()->check();
        $this->assertSame('client.example.com', $statusFast->boundDomain);

        $statusCheck = $this->client()->check(true);
        $this->assertSame('client.example.com', $statusCheck->boundDomain);

        $this->artisan('corevisys:license:status')
            ->expectsOutputToContain('client.example.com')
            ->doesntExpectOutput('attacker.org');
    }

    public function test_bound_domain_column_tamper_follows_only_signed_payload_reachable(): void
    {
        $this->seedTamperedCache(
            signedOverrides: ['bound_domain' => 'client.example.com'],
            columnOverrides: ['bound_domain' => 'attacker.org']
        );

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_matrix_1',
                'status' => 'active',
                'product_code' => self::PRODUCT,
                'bound_domain' => 'client.example.com',
                'expires_at' => now()->addYear()->toIso8601String(),
            ])),
        ]);

        $status = $this->client()->check(true);
        $this->assertSame('client.example.com', $status->boundDomain);
        $this->assertTrue($status->valid);
        $this->getJson('/protected')->assertStatus(200);
    }
}
