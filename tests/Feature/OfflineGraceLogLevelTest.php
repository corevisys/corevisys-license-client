<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * ITEM 4 — the two OFFLINE fallback log lines had no level / reason_code
 * assertion. (The two lifecycle lines — "the license has expired." and "the
 * license is in its grace period." — are already asserted by
 * LicenseMonitoringLogTest::test_license_expired_logs_one_warning and
 * ::test_grace_period_active_logs_one_warning, so they are NOT duplicated here.)
 *
 * Covered here:
 *  - "offline grace window has expired." -> warning / grace_period_expired
 *  - "serving a cached license within the offline grace window." -> warning / grace_period_active
 */
class OfflineGraceLogLevelTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedSignedCache(array $overrides = []): void
    {
        $data = array_merge([
            'license_id' => 'lic_offline',
            'status' => 'active',
            'product_code' => 'test-product',
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ], $overrides);

        $envelope = $this->signedEnvelope($data, 'test-key-1');

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->put('test-product', [
            'license_id' => $data['license_id'],
            'license_key' => 'CACHED-KEY-0000',
            'status' => $data['status'],
            'signed_payload' => json_encode($data, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
            'issued_at' => $data['issued_at'],
            'offline_valid_until' => $data['offline_valid_until'],
            'is_grace_period' => $data['is_grace_period'],
            'expires_at' => $data['expires_at'],
            'last_successful_check_at' => $overrides['last_successful_check_at'] ?? now()->subHour(),
            'next_check_at' => now()->subMinute(), // due -> normal path
        ]);
    }

    public function test_offline_grace_window_expired_logs_warning_with_reason_code(): void
    {
        $this->seedTrustedKey();
        // A still-verifiable signed payload whose offline boundary has passed.
        $this->seedSignedCache(['offline_valid_until' => now()->subDay()->toIso8601String()]);

        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertFalse($status->valid);
        $this->assertSame('grace_period_expired', $status->status);

        $entry = $this->findLog('grace window has expired');
        $this->assertNotNull($entry, 'Expected the offline-grace-expired log entry.');
        $this->assertSame('warning', $entry->level);
        $this->assertSame('grace_period_expired', $entry->context['reason_code'] ?? null);
    }

    public function test_offline_grace_active_logs_warning_with_reason_code(): void
    {
        $this->seedTrustedKey();
        // A verifiable signed payload still within its windows / grace period.
        $this->seedSignedCache(['last_successful_check_at' => now()->subHour()]);

        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $this->assertTrue($status->valid);
        $this->assertTrue($status->offline);

        $entry = $this->findLog('serving a cached license');
        $this->assertNotNull($entry, 'Expected the offline-grace-active log entry.');
        $this->assertSame('warning', $entry->level);
        $this->assertSame('grace_period_active', $entry->context['reason_code'] ?? null);
    }
}
