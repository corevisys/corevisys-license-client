<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Notifications\LicenseFailureNotification;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 5 — failure notifications from the scheduled health check. Notifications
 * are opt-in (default disabled), throttled per reason, and carry no secrets.
 */
class LicenseNotificationTest extends TestCase
{
    use CapturesLogs;
    use SignsPayloads;

    /**
     * A server-down check with NO cache falls back to 'tampered_cache' the first
     * time, then the persisted last_error row changes the reason on the next
     * call. Tests that need a STABLE, deterministic reason seed a lapsed (but
     * still verifiable) signed cache record so every server-down fallback
     * resolves to 'grace_period_expired'.
     */
    private const STABLE_REASON = 'grace_period_expired';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('corevisys-license.notifications.channels', ['log']);
        $this->captureLogs();
    }

    private function seedLapsedSignedCache(): void
    {
        $data = [
            'license_id' => 'lic_lapsed',
            'status' => 'active',
            'product_code' => 'test-product',
            'license_type' => 'full',
            'expires_at' => now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subDays(10)->toIso8601String(),
            'offline_valid_until' => now()->subDay()->toIso8601String(), // lapsed
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
            'license_key' => 'CACHED-KEY-0000',
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

    private function validEnvelope(): array
    {
        return $this->signedEnvelope([
            'license_id' => 'lic_lapsed',
            'status' => 'active',
            'product_code' => 'test-product',
            'expires_at' => now()->addYear()->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function test_disabled_notifications_are_silent(): void
    {
        config()->set('corevisys-license.notifications.enabled', false);

        Notification::fake();
        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);

        Notification::assertNothingSent();
        $this->assertSame(0, $this->countLogs('health check failed'));
    }

    public function test_enabled_log_channel_writes_exactly_one_error(): void
    {
        config()->set('corevisys-license.notifications.enabled', true);
        config()->set('corevisys-license.notifications.channels', ['log']);

        Notification::fake();
        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);

        $this->assertSame(1, $this->countLogs('health check failed'));

        $entry = $this->findLog('health check failed');
        $this->assertNotNull($entry);
        $this->assertSame('error', $entry->level);
    }

    public function test_throttle_sends_only_one_notification_within_the_window(): void
    {
        config()->set('corevisys-license.notifications.enabled', true);
        config()->set('corevisys-license.notifications.channels', ['mail']);
        config()->set('corevisys-license.notifications.mail_recipients', ['ops@example.com']);
        config()->set('corevisys-license.notifications.throttle_interval', 3600);

        $this->seedLapsedSignedCache();

        Notification::fake();
        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        // Two consecutive failing health checks, SAME reason_code, inside the
        // throttle window.
        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);
        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);

        Notification::assertSentOnDemandTimes(LicenseFailureNotification::class, 1);
        Notification::assertSentOnDemand(
            LicenseFailureNotification::class,
            fn ($notification, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'ops@example.com'
                && ($notification->context['reason_code'] ?? null) === self::STABLE_REASON
        );
    }

    public function test_recovery_is_logged_and_clears_the_throttle(): void
    {
        config()->set('corevisys-license.notifications.enabled', true);
        config()->set('corevisys-license.notifications.channels', ['log']);

        $this->seedLapsedSignedCache();

        // A single closure fake, driven by a flag, so the SAME URL returns
        // down then up then down across the three command runs.
        $down = true;
        Http::fake(function ($request) use (&$down) {
            if (str_contains($request->url(), 'license/check')) {
                return $down
                    ? Http::response('', 500)
                    : Http::response($this->validEnvelope());
            }

            return Http::response($this->publicKeyResponse());
        });

        // 1) Failure — logs once and arms the throttle.
        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);
        $this->assertSame(1, $this->countLogs('health check failed'));

        // 2) Recovery — logged, and the throttle slot is released.
        $down = false;
        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, $this->countLogs('recovered'));

        // 3) Failure after recovery notifies again (throttle was reset).
        $down = true;
        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);
        $this->assertSame(2, $this->countLogs('health check failed'));
    }
}
