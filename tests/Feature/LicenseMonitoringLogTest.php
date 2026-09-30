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
}
