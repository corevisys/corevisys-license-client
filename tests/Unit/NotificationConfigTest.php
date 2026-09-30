<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\ConfigValidator;
use CoreVisys\License\Tests\TestCase;

/**
 * Phase 5 — notification configuration defaults, validation, and the explicit
 * decision to reuse `logging.channel` as the spec's "log_channel" (no new key).
 */
class NotificationConfigTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'server_url' => 'https://license.corevisys.com',
            'grace_period' => 72,
            'cache_driver' => 'database',
            'signature' => ['algorithm' => 'rsa'],
        ], $overrides);
    }

    public function test_notification_defaults_are_safe(): void
    {
        $notifications = config('corevisys-license.notifications');

        $this->assertIsArray($notifications);
        $this->assertFalse($notifications['enabled'], 'Notifications must default to disabled.');
        $this->assertSame(['log'], $notifications['channels']);
        $this->assertSame([], $notifications['mail_recipients']);
        $this->assertSame(3600, (int) $notifications['throttle_interval']);
    }

    public function test_log_channel_reuses_the_logging_channel_key(): void
    {
        // 5.1's `log_channel` IS logging.channel — no separate key was added.
        $this->assertSame('stack', config('corevisys-license.logging.channel'));
        $this->assertNull(config('corevisys-license.log_channel'));
    }

    public function test_unsupported_notification_channel_fails_validation(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig([
            'notifications' => ['channels' => ['slack']],
        ]));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('notifications.channels', implode(' ', $errors));
    }

    public function test_negative_throttle_interval_fails_validation(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig([
            'notifications' => ['throttle_interval' => -1],
        ]));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('notifications.throttle_interval', implode(' ', $errors));
    }

    public function test_valid_notification_config_does_not_throw(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig([
            'notifications' => [
                'enabled' => true,
                'channels' => ['log', 'mail'],
                'mail_recipients' => ['ops@example.com'],
                'throttle_interval' => 1800,
            ],
        ]));

        $this->assertSame([], $errors);
    }

    /**
     * Phase 5 checklist: an EMPTY notifications.channels list is valid — it means
     * "use the default" (['log']), not a misconfiguration.
     */
    public function test_empty_notification_channels_is_valid(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig([
            'notifications' => ['enabled' => true, 'channels' => []],
        ]));

        $this->assertSame([], $errors, implode(' ', $errors));
    }
}
