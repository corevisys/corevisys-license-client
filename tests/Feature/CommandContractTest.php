<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Command-level contract for the operator-facing license commands:
 * registration, exit codes, and non-disclosure of the raw key in output.
 * All network access is faked; nothing here reaches a real server.
 */
class CommandContractTest extends TestCase
{
    use SignsPayloads;

    private const SENTINEL = 'SENTINEL-CMD-KEY-c0ffee42';

    public function test_status_check_and_doctor_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('corevisys:license:status', $commands);
        $this->assertArrayHasKey('corevisys:license:check', $commands);
        $this->assertArrayHasKey('corevisys:license:doctor', $commands);
    }

    public function test_check_command_exits_zero_for_a_valid_license_and_prints_no_key(): void
    {
        $this->seedStoredLicense();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_cmd_valid',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'offline_valid_until' => now()->addDays(7)->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->artisan('corevisys:license:check', ['--force' => true])
            ->doesntExpectOutputToContain(self::SENTINEL)
            ->assertExitCode(0);
    }

    public function test_check_command_exits_one_for_an_invalid_license_and_prints_no_key(): void
    {
        $this->seedStoredLicense();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_cmd_valid',
                'status' => 'expired',
                'product_code' => 'test-product',
                'expires_at' => now()->subDay()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->artisan('corevisys:license:check', ['--force' => true])
            ->doesntExpectOutputToContain(self::SENTINEL)
            ->assertExitCode(1);
    }

    public function test_status_command_exits_zero_and_prints_no_key_once_cached(): void
    {
        $this->seedStoredLicense();

        $this->artisan('corevisys:license:status')
            ->doesntExpectOutputToContain(self::SENTINEL)
            ->assertExitCode(0);
    }

    /**
     * A stored, signed "active" record plus the matching public key, so the
     * check command can go online and still verify. The stored key is the
     * sentinel, to prove it never reaches the terminal.
     */
    private function seedStoredLicense(): void
    {
        config()->set('corevisys-license.license_key', self::SENTINEL);

        $data = [
            'license_id' => 'lic_cmd_valid',
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
            'license_key' => self::SENTINEL,
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
        ]);
    }
}
