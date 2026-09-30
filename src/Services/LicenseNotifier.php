<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\DTOs\LicenseStatus;
use CoreVisys\License\Notifications\LicenseFailureNotification;
use CoreVisys\License\Support\LogSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Deliverable of the scheduled license health check ONLY.
 *
 * It is never called from the verifier, the middleware, or the request path.
 * Its single purpose is to notify an operator when the health check fails and
 * to record exactly one log entry when the license recovers.
 *
 * Guarantees:
 *  - never throws (all cache / notification failures are swallowed and logged
 *    as class name + code only, never getMessage());
 *  - sends at most one notification per failure reason_code within
 *    notifications.throttle_interval seconds (atomic Cache::add lock);
 *  - carries no secret: only reason_code, product_code, status and timestamp,
 *    every value routed through {@see LogSanitizer}.
 */
class LicenseNotifier
{
    public function __construct(protected array $config = [])
    {
    }

    /**
     * Notify (subject to the throttle) that the health check failed.
     */
    public function notifyFailure(LicenseStatus $status, string $reasonCode): void
    {
        $settings = $this->settings();

        if (! ($settings['enabled'] ?? false)) {
            return;
        }

        // Throttle: at most one notification per reason_code per interval.
        if (! $this->claimThrottleSlot($reasonCode, $settings)) {
            return;
        }

        $context = $this->context($status, $reasonCode);
        $channels = $this->channels($settings);

        foreach ($channels as $channel) {
            try {
                if ($channel === 'log') {
                    $this->deliverToLog($context);
                } elseif ($channel === 'mail') {
                    $this->deliverToMail($context, $settings);
                }
            } catch (\Throwable $e) {
                // A notifier must never break the scheduler: log class + code only.
                $this->logFailure('notification delivery failed', $e);
            }
        }
    }

    /**
     * Record that a previously-failing license is healthy again. Logs exactly
     * one info entry and clears the throttle so the next failure notifies
     * immediately.
     */
    public function recordRecovery(): void
    {
        $settings = $this->settings();

        if (! ($settings['enabled'] ?? false)) {
            return;
        }

        try {
            $previous = Cache::pull($this->reasonKey());
        } catch (\Throwable $e) {
            $this->logFailure('recovery bookkeeping failed', $e);

            return;
        }

        if ($previous === null || $previous === '') {
            return;
        }

        try {
            Cache::forget($this->throttleKey((string) $previous));
        } catch (\Throwable $e) {
            $this->logFailure('recovery bookkeeping failed', $e);
        }

        $this->logChannel()?->info(
            LogSanitizer::scrubMessage('CoreVisys license: health check recovered.'),
            LogSanitizer::scrubContext([
                'reason_code' => 'license_recovered',
                'previous_reason_code' => (string) $previous,
            ])
        );
    }

    /**
     * Atomically claim the throttle slot for a reason code. Returns true when a
     * notification may be sent, false when one was already sent inside the
     * window. On a cache failure it fails OPEN (returns true) so a broken cache
     * never silently suppresses an alert.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function claimThrottleSlot(string $reasonCode, array $settings): bool
    {
        $interval = (int) ($settings['throttle_interval'] ?? 3600);
        $interval = max(0, $interval);

        try {
            $claimed = Cache::add($this->throttleKey($reasonCode), now()->timestamp, $interval);

            if ($claimed) {
                Cache::forever($this->reasonKey(), $reasonCode);
            }

            return (bool) $claimed;
        } catch (\Throwable $e) {
            $this->logFailure('throttle check failed', $e);

            return true; // fail open
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    protected function channels(array $settings): array
    {
        $channels = $settings['channels'] ?? ['log'];

        if (! is_array($channels) || $channels === []) {
            return ['log'];
        }

        return array_values(array_filter($channels, static fn ($c) => is_string($c) && $c !== ''));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function deliverToLog(array $context): void
    {
        $this->logChannel()?->error(
            LogSanitizer::scrubMessage('CoreVisys license: health check failed.'),
            $context
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $settings
     */
    protected function deliverToMail(array $context, array $settings): void
    {
        $recipients = $settings['mail_recipients'] ?? [];

        if (! is_array($recipients)) {
            return;
        }

        foreach ($recipients as $recipient) {
            if (! is_string($recipient) || trim($recipient) === '') {
                continue;
            }

            Notification::route('mail', $recipient)
                ->notify(new LicenseFailureNotification($context));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(LicenseStatus $status, string $reasonCode): array
    {
        return [
            'reason_code' => $reasonCode,
            'product_code' => $status->productCode,
            'status' => $status->status,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected function throttleKey(string $reasonCode): string
    {
        return 'corevisys.license.notify.throttle.'.$reasonCode;
    }

    protected function reasonKey(): string
    {
        return 'corevisys.license.notify.last_reason';
    }

    protected function logChannel(): ?\Psr\Log\LoggerInterface
    {
        try {
            if (! config('corevisys-license.logging.enabled', true)) {
                return null;
            }

            return Log::channel(config('corevisys-license.logging.channel', 'stack'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the notification settings live (so tests and config changes are
     * honoured), falling back to the construction-time config.
     *
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        try {
            if (function_exists('config')) {
                $live = config('corevisys-license.notifications');

                if (is_array($live)) {
                    return $live;
                }
            }
        } catch (\Throwable) {
            // fall through to the construction-time config
        }

        $fallback = $this->config['notifications'] ?? [];

        return is_array($fallback) ? $fallback : [];
    }

    protected function logFailure(string $message, \Throwable $e): void
    {
        try {
            $this->logChannel()?->error(
                LogSanitizer::scrubMessage('CoreVisys license: '.$message.'.'),
                [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]
            );
        } catch (\Throwable) {
            // absolutely never throw from the notifier
        }
    }
}
