<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * `notifications.throttle_interval = 0` means "throttle disabled".
 *
 * An interval of 0 must be treated as NO throttle: every failing health check
 * delivers a notification, including two failures with the same reason in the
 * same instant. (Before the fix, `claimThrottleSlot()` passed a 0 TTL to
 * `Cache::add()`, which the base cache Repository rejects with `false` for any
 * TTL <= 0, so the throttle slot was never claimed and NOTHING was delivered.)
 *
 * Positive intervals are unchanged and are covered by LicenseNotificationTest.
 */
class ThrottleIntervalZeroCharacterizationTest extends TestCase
{
    use CapturesLogs;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('corevisys-license.notifications.enabled', true);
        config()->set('corevisys-license.notifications.channels', ['log']);
        config()->set('corevisys-license.notifications.throttle_interval', 0);
        $this->captureLogs();
    }

    private function runFailingCheck(): void
    {
        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);
    }

    public function test_interval_zero_first_failure_is_notified(): void
    {
        $this->runFailingCheck();

        // INVERSE OF THE OLD CHARACTERIZATION: interval 0 disables throttling,
        // so the first failing check IS delivered.
        $this->assertSame(1, $this->countLogs('health check failed'));
    }

    public function test_interval_zero_second_failure_is_also_notified(): void
    {
        $this->runFailingCheck();
        $this->runFailingCheck();

        // INVERSE OF THE OLD CHARACTERIZATION: with no throttle both failures
        // are delivered, so a zero interval does not suppress notification.
        $this->assertSame(2, $this->countLogs('health check failed'));
    }
}
