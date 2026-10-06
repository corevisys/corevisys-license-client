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
        // The status command uses cachedStatus() which requires a verified
        // signed payload — seed a properly signed record so signature
        // verification succeeds and the command exits 0.
        $data = [
            'license_id'         => 'lic_status_cmd',
            'status'             => 'active',
            'product_code'       => 'test-product',
            'license_type'       => 'subscription',
            'bound_domain'       => 'license.test',
            'expires_at'         => now()->addYear()->toIso8601String(),
            'issued_at'          => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'features'           => [],
            'is_grace_period'    => false,
        ];

        $envelope = $this->signedEnvelope($data);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys'  => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);
        $storage->put('test-product', [
            'license_id'              => $data['license_id'],
            'license_key'             => 'STATUS-CMD-KEY',
            'status'                  => $data['status'],
            'license_type'            => $data['license_type'],
            'bound_domain'            => $data['bound_domain'],
            'signed_payload'          => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature'               => $envelope['signature'],
            'key_id'                  => 'test-key-1',
            'expires_at'              => $data['expires_at'],
            'issued_at'               => $data['issued_at'],
            'offline_valid_until'     => $data['offline_valid_until'],
            'is_grace_period'         => $data['is_grace_period'],
            'last_successful_check_at' => now()->subHours(2),
            'next_check_at'           => now()->addHour(), // not yet due
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

    public function test_activate_command_prompts_for_a_hidden_key_and_does_not_print_it(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_prompt',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $sentinel = 'SENTINEL-PROMPT-KEY-2f8a1c3d';

        // No 'key' argument: the command must fall back to the hidden secret()
        // prompt, and neither the prompt nor the result may print the key.
        $this->artisan('corevisys:license:activate')
            ->expectsQuestion('License key (input is hidden)', $sentinel)
            ->doesntExpectOutputToContain($sentinel)
            ->assertExitCode(0);
    }

    public function test_activate_command_does_not_print_a_key_echoing_server_message(): void
    {
        // The server returns a signed-but-rejected envelope whose (unsigned)
        // message echoes the submitted key. It must not reach the terminal.
        $envelope = $this->signedEnvelope([
            'license_id' => 'lic_cmd_echo',
            'status' => 'active',
            'product_code' => 'test-product',
            'expires_at' => now()->addYear()->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ]);
        $envelope['success'] = false;
        $envelope['status'] = 'error';
        $envelope['message'] = 'License key SENTINEL-CMD-KEY-7d1e4f22 is not valid.';

        Http::fake(['*/api/v1/license/activate' => Http::response($envelope)]);

        $this->artisan('corevisys:license:activate', ['key' => 'SENTINEL-CMD-KEY-7d1e4f22'])
            ->doesntExpectOutputToContain('SENTINEL-CMD-KEY-7d1e4f22')
            ->assertExitCode(1);
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
