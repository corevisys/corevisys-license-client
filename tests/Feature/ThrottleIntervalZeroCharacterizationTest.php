<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * CHARACTERIZATION — `notifications.throttle_interval = 0`, CURRENT behaviour.
 *
 * The names state the observed behaviour explicitly. `claimThrottleSlot()` does
 * `Cache::add($key, $ts, 0)`; on Laravel's array (and database) store a zero TTL
 * writes the entry as already-expiring, so `add` returns false in the same
 * second and `notifyFailure()` returns before delivering. The interval is
 * therefore not "no throttle" — it suppresses notification entirely.
 *
 * These tests pin the current behaviour so any change of intent (reject 0, or
 * treat 0 as "notify every time") is forced to update them.
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

    public function test_interval_zero_first_failure_is_not_notified(): void
    {
        $this->runFailingCheck();

        // CHARACTERIZED CURRENT BEHAVIOUR: the FIRST failing health check with
        // interval 0 emits NO 'health check failed' notification.
        $this->assertSame(0, $this->countLogs('health check failed'));
    }

    public function test_interval_zero_second_failure_is_not_notified(): void
    {
        $this->runFailingCheck();
        $this->runFailingCheck();

        // CHARACTERIZED CURRENT BEHAVIOUR: the second failure is also NOT
        // notified — a zero interval suppresses, it does not disable throttling.
        $this->assertSame(0, $this->countLogs('health check failed'));
    }
}
