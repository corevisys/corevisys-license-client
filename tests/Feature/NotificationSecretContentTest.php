<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Notifications\LicenseFailureNotification;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * A1 — secret-free content across the three sinks that matter.
 *
 * (A) The SUBMITTED license key must never appear in a captured log entry, in
 *     the stored last_error_message, or in the failure notification.
 * (B) The submitted key must never reach the notification; and the notifier's
 *     OWN log entries must never carry the server message phrase either.
 *
 * The two scenarios are deliberately separated:
 *   - A submitted key is a KNOWN SECRET and must always be redacted.
 *   - An arbitrary server-message phrase is NOT asserted on the verifier's
 *     "check request rejected" diagnostics, because redacted server
 *     diagnostics are intended (the phrase is not a secret).
 */
class NotificationSecretContentTest extends TestCase
{
    use CapturesLogs;

    private const LONG_KEY = 'SENTINEL-NOTIFY-LEAK-3c9d8e7f';

    private const SHORT_KEY = 'ABCD-EFGH-IJKL-MNOP'; // 19 chars

    private const SERVER_PHRASE = 'SERVER-MSG-PHRASE-7781';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set('corevisys-license.notifications.enabled', true);
        config()->set('corevisys-license.notifications.channels', ['log', 'mail']);
        config()->set('corevisys-license.notifications.mail_recipients', ['ops@example.com']);
        config()->set('corevisys-license.notifications.throttle_interval', 3600);
        $this->captureLogs();
    }

    /**
     * @return array<int, \Illuminate\Log\Events\MessageLogged>
     */
    private function notifierLogs(): array
    {
        $needles = ['health check failed', 'throttle check failed', 'notification delivery failed', 'recovered'];

        return array_values(array_filter($this->capturedLogs, function ($entry) use ($needles) {
            foreach ($needles as $needle) {
                if (str_contains($entry->message, $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function assertNotificationClean(string ...$forbidden): void
    {
        Notification::assertSentOnDemand(
            LicenseFailureNotification::class,
            function (LicenseFailureNotification $notification, $channels, $notifiable) use ($forbidden) {
                $mail = $notification->toMail($notifiable);

                $haystack = (string) $mail->subject;
                foreach (array_merge($mail->introLines, $mail->outroLines) as $line) {
                    $haystack .= "\n".(string) $line;
                }
                $haystack .= "\n".json_encode($notification->context);

                foreach ($forbidden as $value) {
                    $this->assertStringNotContainsString($value, $haystack);
                }

                return true;
            }
        );
    }

    public function test_submitted_short_key_is_never_logged_stored_or_notified(): void
    {
        config()->set('corevisys-license.license_key', self::SHORT_KEY);

        Notification::fake();
        Http::fake([
            '*/api/v1/license/check' => Http::response(
                ['message' => 'Rejected: key '.self::SHORT_KEY.' is not valid.'],
                422
            ),
        ]);

        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);

        Notification::assertSentOnDemandTimes(LicenseFailureNotification::class, 1);
        $this->assertNotificationClean(self::SHORT_KEY);

        // No captured log entry may contain the submitted key.
        foreach ($this->capturedLogs as $entry) {
            $encoded = $entry->message.' '.json_encode($entry->context);
            $this->assertStringNotContainsString(self::SHORT_KEY, $encoded);
        }

        // The stored diagnostic must not contain the submitted key either.
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $record = $storage->get('test-product');
        $this->assertStringNotContainsString(self::SHORT_KEY, json_encode($record));
    }

    public function test_long_key_and_server_phrase_never_reach_notification_or_notifier_logs(): void
    {
        config()->set('corevisys-license.license_key', self::LONG_KEY);

        Notification::fake();
        Http::fake([
            '*/api/v1/license/check' => Http::response(
                ['message' => 'Rejected: key '.self::LONG_KEY.' ('.self::SERVER_PHRASE.') is not valid.'],
                422
            ),
        ]);

        $this->artisan('corevisys:license:check', ['--force' => true])->assertExitCode(1);

        Notification::assertSentOnDemandTimes(LicenseFailureNotification::class, 1);
        $this->assertNotificationClean(self::LONG_KEY, self::SERVER_PHRASE);

        // The notifier's OWN entries must carry neither the key nor the phrase.
        foreach ($this->notifierLogs() as $entry) {
            $encoded = $entry->message.' '.json_encode($entry->context);
            $this->assertStringNotContainsString(self::LONG_KEY, $encoded);
            $this->assertStringNotContainsString(self::SERVER_PHRASE, $encoded);
        }

        // NOTE: the verifier's "check request rejected" diagnostic is NOT
        // asserted for SERVER_PHRASE — redacted server diagnostics are intended
        // there (the phrase is not a secret). In this setup that entry is
        // emitted by LicenseVerifier, not by LicenseNotifier.
    }
}
