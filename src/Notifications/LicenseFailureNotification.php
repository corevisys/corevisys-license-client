<?php

namespace CoreVisys\License\Notifications;

use CoreVisys\License\Support\LogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A licence health-check failure notification.
 *
 * Carries ONLY non-secret fields (reason_code, product_code, status, timestamp)
 * and runs every value through {@see LogSanitizer} defensively. It never carries
 * the licence key, the signature, or the server's message text.
 */
class LicenseFailureNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public array $context)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reason = (string) ($this->context['reason_code'] ?? 'unknown');
        $status = (string) ($this->context['status'] ?? 'unknown');
        $product = (string) ($this->context['product_code'] ?? 'unknown');
        $time = (string) ($this->context['timestamp'] ?? '');

        return (new MailMessage)
            ->subject('CoreVisys license health check failed: '.LogSanitizer::scrubMessage($reason))
            ->line('The CoreVisys license health check failed.')
            ->line('Reason: '.LogSanitizer::scrubMessage($reason))
            ->line('Product: '.LogSanitizer::scrubMessage($product))
            ->line('Status: '.LogSanitizer::scrubMessage($status))
            ->line('Time: '.LogSanitizer::scrubMessage($time));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->context;
    }
}
