<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ArtisanCommandsTest extends TestCase
{
    use SignsPayloads;

    public function test_activate_command_reports_success(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_cli',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->artisan('corevisys:license:activate', ['key' => 'CLI-TEST-KEY'])
            ->assertExitCode(0);
    }

    public function test_activate_command_reports_failure(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License not found.'], 404),
        ]);

        $this->artisan('corevisys:license:activate', ['key' => 'BAD-KEY'])
            ->assertExitCode(1);
    }

    public function test_activate_command_never_prints_the_raw_key(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_cli_no_leak',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $sentinel = 'SENTINEL-CLI-KEY-9f3a2b7c';

        $this->artisan('corevisys:license:activate', ['key' => $sentinel])
            ->doesntExpectOutputToContain($sentinel)
            ->assertExitCode(0);
    }

    public function test_status_command_shows_no_cache_warning_when_never_activated(): void
    {
        $this->artisan('corevisys:license:status')
            ->assertExitCode(1);
    }

    public function test_status_command_shows_cached_status(): void
    {
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->put('test-product', [
            'status' => 'active',
            'license_type' => 'subscription',
            'bound_domain' => 'license.test',
            'last_successful_check_at' => now(),
        ]);

        $this->artisan('corevisys:license:status')
            ->assertExitCode(0);
    }

    public function test_clear_cache_command_empties_storage(): void
    {
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->put('test-product', ['status' => 'active']);

        $this->artisan('corevisys:license:clear-cache')->assertExitCode(0);

        $this->assertNull($storage->get('test-product'));
    }

    public function test_deactivate_command_clears_cache_and_calls_server(): void
    {
        Http::fake([
            '*/api/v1/license/deactivate' => Http::response(['success' => true, 'message' => 'ok']),
        ]);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->put('test-product', ['status' => 'active', 'license_key' => 'TO-BE-DEACTIVATED']);

        $this->artisan('corevisys:license:deactivate', ['--force' => true])
            ->assertExitCode(0);

        $this->assertNull($storage->get('test-product'));
    }
}
