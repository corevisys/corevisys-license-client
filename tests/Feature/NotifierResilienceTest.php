<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\DTOs\LicenseStatus;
use CoreVisys\License\Services\LicenseNotifier;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * The notifier must never break the health check. Its own cache (throttle /
 * bookkeeping) and its delivery channels are all wrapped: a failure is logged
 * as the exception class name + code ONLY — never getMessage() — and the
 * command still returns its normal exit code.
 */
class NotifierResilienceTest extends TestCase
{
    use CapturesLogs;

    private const SENTINEL_KEY = 'COREVISYS-SENTINEL-KEY-1234-ABCD';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('corevisys-license.notifications.enabled', true);
        $this->captureLogs();
    }

    private function notifier(): LicenseNotifier
    {
        return new LicenseNotifier([
            'notifications' => config('corevisys-license.notifications'),
        ]);
    }

    /**
     * ITEM 1a (cache throws): the throttle cache throwing must be swallowed, the
     * alert must still be attempted (fail open), and the log line must carry the
     * exception class name and code only — never the message.
     */
    public function test_throttle_cache_failure_is_swallowed_and_logs_class_and_code_only(): void
    {
        config()->set('corevisys-license.notifications.channels', ['log']);

        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache store down: '.self::SENTINEL_KEY, 7));

        $this->notifier()->notifyFailure(
            new LicenseStatus(valid: false, status: 'grace_period_expired', productCode: 'test-product'),
            'grace_period_expired'
        );

        $entry = $this->findLog('throttle check failed');
        $this->assertNotNull($entry, 'Expected the throttle failure to be logged.');
        $this->assertSame('error', $entry->level);
        $this->assertSame(\RuntimeException::class, $entry->context['exception'] ?? null);
        $this->assertSame(7, $entry->context['code'] ?? null);

        $encoded = json_encode($entry->context);
        $this->assertStringNotContainsString(self::SENTINEL_KEY, $encoded);
        $this->assertStringNotContainsString('cache store down', $encoded);

        // Fail open: the failure alert is still delivered via the log channel.
        $this->assertNotNull($this->findLog('health check failed'));
    }

    /**
     * ITEM 1b (throttle store throws): a broken throttle store must NOT suppress
     * the notification — claimThrottleSlot fails open and the notification is
     * still attempted.
     */
    public function test_throttle_store_failure_still_attempts_the_notification(): void
    {
        config()->set('corevisys-license.notifications.channels', ['mail']);
        config()->set('corevisys-license.notifications.mail_recipients', ['ops@example.com']);

        Notification::fake();
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache store down', 7));

        $this->notifier()->notifyFailure(
            new LicenseStatus(valid: false, status: 'grace_period_expired', productCode: 'test-product'),
            'grace_period_expired'
        );

        Notification::assertSentOnDemandTimes(\CoreVisys\License\Notifications\LicenseFailureNotification::class, 1);
        $this->assertNotNull($this->findLog('throttle check failed'));
    }

    /**
     * ITEM 1a (mail throws): a throwing delivery channel must not make
     * corevisys:license:check throw; the command keeps its normal exit code and
     * the failure is logged as class name + code only.
     */
    public function test_health_check_does_not_throw_when_notification_delivery_fails(): void
    {
        config()->set('corevisys-license.notifications.channels', ['mail']);
        config()->set('corevisys-license.notifications.mail_recipients', ['ops@example.com']);

        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        // AnonymousNotifiable::notify() delegates to app(Dispatcher::class)->send(),
        // so a throwing Dispatcher models any transport failure at delivery time.
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $dispatcher->shouldReceive('send')->andThrow(new \RuntimeException('smtp transport down: '.self::SENTINEL_KEY, 42));
        Notification::swap($dispatcher);

        // The command must complete (no exception) with the normal failure code.
        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);

        $entry = $this->findLog('notification delivery failed');
        $this->assertNotNull($entry, 'Expected the delivery failure to be logged.');
        $this->assertSame('error', $entry->level);
        $this->assertSame(\RuntimeException::class, $entry->context['exception'] ?? null);
        $this->assertSame(42, $entry->context['code'] ?? null);

        $encoded = json_encode($entry->context);
        $this->assertStringNotContainsString(self::SENTINEL_KEY, $encoded);
        $this->assertStringNotContainsString('smtp transport down', $encoded);
    }

    /**
     * ITEM 1c: the notification context carries exactly the four non-secret
     * fields — reason_code, product_code, status, timestamp — and no sentinel key
     * or free-form server message.
     */
    public function test_notification_context_carries_only_the_four_non_secret_fields(): void
    {
        config()->set('corevisys-license.notifications.channels', ['log']);

        $this->notifier()->notifyFailure(
            new LicenseStatus(valid: false, status: 'grace_period_expired', productCode: 'test-product'),
            'grace_period_expired'
        );

        $entry = $this->findLog('health check failed');
        $this->assertNotNull($entry);

        $context = $entry->context;
        $this->assertSame(
            ['reason_code', 'product_code', 'status', 'timestamp'],
            array_keys($context)
        );
        $this->assertSame('grace_period_expired', $context['reason_code']);
        $this->assertSame('test-product', $context['product_code']);
        $this->assertSame('grace_period_expired', $context['status']);
        $this->assertIsString($context['timestamp']);

        $this->assertArrayNotHasKey('message', $context);
        $this->assertArrayNotHasKey('error', $context);
        $this->assertStringNotContainsString(self::SENTINEL_KEY, json_encode($context));
    }
}
