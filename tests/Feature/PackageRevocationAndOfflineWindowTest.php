<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class PackageRevocationAndOfflineWindowTest extends TestCase
{
    use SignsPayloads;

    protected function defineRoutes($router): void
    {
        $router->middleware('corevisys.license')->get('/protected', fn () => 'ok');
    }

    protected function seedActiveLicense(): void
    {
        $data = [
            'license_id' => 'lic_active_1',
            'status' => 'active',
            'product_code' => 'test-product',
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];

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
            'license_key' => 'ACTIVE-TEST-KEY',
            'status' => 'active',
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => false,
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => now()->subHours(2),
            'next_check_at' => now()->subMinute(),
        ]);
    }

    public function test_revocation_reaches_package_blocking_check_status_command_and_middleware(): void
    {
        $this->seedActiveLicense();

        // Server answers with 403 Invalid License Key (revoked terminal status)
        Http::fake([
            '*/api/v1/license/check' => Http::response([
                'status' => false,
                'message' => 'Invalid License Key',
                'error_code' => 'invalid_license_key',
            ], 403),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);

        // 1. Package check
        $status = $client->check(true);
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isRevoked());

        // 2. Status command
        $this->artisan('corevisys:license:status')
            ->expectsOutputToContain('revoked')
            ->expectsOutputToContain('no') // In Grace Window = no
            ->assertExitCode(1);

        // 3. License middleware
        $this->getJson('/protected')
            ->assertStatus(403)
            ->assertJson(['error_code' => 'invalid_license']);
    }

    public function test_suspended_via_check_blocks_check_status_command_and_middleware(): void
    {
        $this->seedActiveLicense();

        Http::fake([
            '*/api/v1/license/check' => Http::response([
                'status' => false,
                'message' => 'License has been Suspended. Contact Support.',
            ], 403),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);

        // 1. Package check
        $status = $client->check(true);
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isSuspended());

        // 2. Status command
        $this->artisan('corevisys:license:status')
            ->expectsOutputToContain('suspended')
            ->expectsOutputToContain('no')
            ->assertExitCode(1);

        // 3. License middleware
        $this->getJson('/protected')
            ->assertStatus(403)
            ->assertJson(['error_code' => 'invalid_license']);
    }

    public function test_suspended_via_pulse_blocks_check_status_command_and_middleware(): void
    {
        $this->seedActiveLicense();

        // Server returns 200 with suspended payload on pulse (no offline_valid_until)
        Http::fake([
            '*/api/v1/license/pulse' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_active_1',
                'status' => 'suspended',
                'product_code' => 'test-product',
                'offline_valid_until' => null,
            ])),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);

        // 1. Pulse
        $pulseStatus = $client->pulse();
        $this->assertNotNull($pulseStatus);
        $this->assertFalse($pulseStatus->valid);
        $this->assertTrue($pulseStatus->isSuspended());
        $this->assertFalse($client->isValid());

        // 2. Status command
        $this->artisan('corevisys:license:status')
            ->expectsOutputToContain('suspended')
            ->expectsOutputToContain('no')
            ->assertExitCode(1);

        // 3. License middleware
        $this->getJson('/protected')
            ->assertStatus(403)
            ->assertJson(['error_code' => 'invalid_license']);
    }

    public function test_expired_without_grace_via_check_blocks_check_status_command_and_middleware(): void
    {
        $this->seedActiveLicense();

        Http::fake([
            '*/api/v1/license/check' => Http::response([
                'status' => false,
                'message' => 'License Expired',
            ], 403),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);

        // 1. Package check
        $status = $client->check(true);
        $this->assertFalse($status->valid);
        $this->assertTrue($status->isExpired());

        // 2. Status command
        $this->artisan('corevisys:license:status')
            ->expectsOutputToContain('expired')
            ->expectsOutputToContain('no')
            ->assertExitCode(1);

        // 3. License middleware
        $this->getJson('/protected')
            ->assertStatus(403)
            ->assertJson(['error_code' => 'invalid_license']);
    }

    public function test_server_refusal_never_grants_subsequent_offline_grace(): void
    {
        $this->seedActiveLicense();

        // First, server returns definite refusal (403 Revoked)
        Http::fake([
            '*/api/v1/license/check' => Http::response([
                'status' => false,
                'message' => 'Invalid License Key',
                'error_code' => 'invalid_license_key',
            ], 403),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);
        $refusedStatus = $client->check(true);
        $this->assertFalse($refusedStatus->valid);

        // Now, simulate server down / unreachable (500 error)
        Http::fake([
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        // Second check with server unreachable MUST NEVER grant offline grace after definite refusal
        $afterDownStatus = $client->check(true);
        $this->assertFalse($afterDownStatus->valid);
        $this->assertFalse($afterDownStatus->offline);
    }

    public function test_server_unreachable_allows_offline_grace_when_window_open(): void
    {
        $this->seedActiveLicense();

        // Server unreachable without prior refusal
        Http::fake([
            '*/api/v1/license/check' => Http::response([], 503),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);
        $status = $client->check(true);

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);
        $this->assertTrue($status->fromCache);
    }

    public function test_signed_payload_for_non_active_license_does_not_extend_offline_valid_until(): void
    {
        $this->seedActiveLicense();

        // Even if server returns a pulse payload for suspended that claims a future offline_valid_until
        Http::fake([
            '*/api/v1/license/pulse' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_active_1',
                'status' => 'suspended',
                'product_code' => 'test-product',
                'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            ])),
        ]);

        /** @var LicenseClientInterface $client */
        $client = $this->app->make(LicenseClientInterface::class);
        $client->pulse();

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $cached = $storage->get('test-product');

        // Package MUST NOT extend or store future offline_valid_until for non-active licenses
        $this->assertNull($cached['offline_valid_until']);
        $this->assertNull($cached['last_successful_check_at']);
    }
}
