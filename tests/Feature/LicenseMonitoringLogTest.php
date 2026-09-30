<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Phase 5 — structured logging. One entry per event, at the level the spec
 * requires, with a reason_code, and never the raw key.
 */
class LicenseMonitoringLogTest extends TestCase
{
    use CapturesLogs;
    use SignsPayloads;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->captureLogs();
    }

    private function seedTrustedKey(): void
    {
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);
    }

    public function test_activation_success_logs_exactly_one_info_entry(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_log',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('LOG-SENTINEL-KEY');

        $this->assertTrue($result->success);
        $this->assertSame(1, $this->countLogs('activated successfully'));

        $entry = $this->findLog('activated successfully');
        $this->assertNotNull($entry);
        $this->assertSame('info', $entry->level);
        $this->assertSame('license_activated', $entry->context['reason_code'] ?? null);
        $this->assertSame('test-product', $entry->context['product_code'] ?? null);
    }

    public function test_activation_failure_logs_one_warning_with_a_reason_code(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License key not found.'], 404),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('LOG-SENTINEL-KEY');

        $this->assertFalse($result->success);
        $this->assertSame(1, $this->countLogs('activation failed'));

        $entry = $this->findLog('activation failed');
        $this->assertNotNull($entry);
        $this->assertSame('warning', $entry->level);
        $this->assertArrayHasKey('reason_code', $entry->context);
    }

    public function test_license_expired_logs_one_warning(): void
    {
        $this->seedTrustedKey();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_log',
                'status' => 'expired',
                'product_code' => 'test-product',
                'expires_at' => now()->subDay()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
        $this->assertSame(1, $this->countLogs('has expired'));

        $entry = $this->findLog('has expired');
        $this->assertNotNull($entry);
        $this->assertSame('warning', $entry->level);
        $this->assertSame('license_expired', $entry->context['reason_code'] ?? null);
    }

    public function test_grace_period_active_logs_one_warning(): void
    {
        $this->seedTrustedKey();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_log',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addDay()->toIso8601String(),
                'is_grace_period' => true,
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertSame(1, $this->countLogs('grace period'));

        $entry = $this->findLog('grace period');
        $this->assertNotNull($entry);
        $this->assertSame('warning', $entry->level);
        $this->assertSame('grace_period_active', $entry->context['reason_code'] ?? null);
    }

    public function test_server_unavailable_logs_an_error(): void
    {
        Http::fake([
            '*/api/v1/license/check' => Http::response('', 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);

        $entry = $this->findLog('server unavailable');
        $this->assertNotNull($entry);
        $this->assertSame('error', $entry->level);
        $this->assertSame('license_server_unavailable', $entry->context['reason_code'] ?? null);
    }

    public function test_signature_verification_failure_logs_an_error(): void
    {
        $this->seedTrustedKey();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_log',
                'status' => 'active',
                'product_code' => 'test-product',
                'checked_at' => now()->toIso8601String(),
            ], corruptSignature: true)),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
        $this->assertSame('signature_verification_failed', $status->status);

        $entry = $this->findLog('signature verification failed');
        $this->assertNotNull($entry);
        $this->assertSame('error', $entry->level);
        $this->assertSame('signature_verification_failed', $entry->context['reason_code'] ?? null);
    }

    /**
     * Phase 5 checklist: a SUCCESSFUL check must not emit an info-level entry.
     * There is no "license checked" info line — info is reserved for activation.
     */
    public function test_normal_online_check_emits_no_info_level_log(): void
    {
        $this->seedTrustedKey();

        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_info',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertTrue($status->valid);
        $this->assertSame([], $this->logsAt('info'));
    }

    /**
     * Phase 5 checklist: a fast-path hit (cached, not due, locally verifiable)
     * must also emit no info-level entry.
     */
    public function test_fast_path_hit_emits_no_info_level_log(): void
    {
        $data = [
            'license_id' => 'lic_fast',
            'status' => 'active',
            'product_code' => 'test-product',
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];

        $envelope = $this->signedEnvelope($data, 'test-key-1');

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put('test-product', [
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
            'next_check_at' => now()->addDay(), // not due -> fast path
        ]);

        Http::fake([
            '*/api/v1/license/public-key' => Http::response([], 500),
            '*/api/v1/license/check' => Http::response([], 500),
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check();

        $this->assertTrue($status->valid, 'Expected the fast path to serve the record as valid.');
        $this->assertSame([], $this->logsAt('info'));
    }
}
