<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\ConfigValue;
use CoreVisys\License\Tests\TestCase;
use Psr\Log\AbstractLogger;

class ConfigValueTest extends TestCase
{
    private function spyLogger(array &$messages): AbstractLogger
    {
        return new class($messages) extends AbstractLogger
        {
            /** @param array<int, array{level: string, message: string, context: array}> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };
    }

    public function test_new_key_only_returns_new_value_without_warning(): void
    {
        $messages = [];
        $value = ConfigValue::read(
            ['new_key' => 'new-value'],
            'new_key',
            'old_key',
            $this->spyLogger($messages),
        );

        $this->assertSame('new-value', $value);
        $this->assertSame([], $messages);
    }

    public function test_old_key_only_returns_old_value_and_warns(): void
    {
        $messages = [];
        $value = ConfigValue::read(
            ['old_key' => 'legacy-value'],
            'new_key',
            'old_key',
            $this->spyLogger($messages),
        );

        $this->assertSame('legacy-value', $value);
        $this->assertCount(1, $messages);
        $this->assertSame('warning', $messages[0]['level']);
        $this->assertStringContainsString('new_key', $messages[0]['context']['new']);
        $this->assertStringContainsString('old_key', $messages[0]['context']['old']);
    }

    public function test_both_keys_set_new_wins_without_warning(): void
    {
        $messages = [];
        $value = ConfigValue::read(
            ['new_key' => 'new-value', 'old_key' => 'legacy-value'],
            'new_key',
            'old_key',
            $this->spyLogger($messages),
        );

        $this->assertSame('new-value', $value);
        $this->assertSame([], $messages);
    }

    public function test_neither_key_set_returns_null(): void
    {
        $messages = [];
        $value = ConfigValue::read([], 'new_key', 'old_key', $this->spyLogger($messages));

        $this->assertNull($value);
        $this->assertSame([], $messages);
    }

    public function test_deprecation_warning_never_contains_the_value(): void
    {
        $messages = [];
        $sentinel = 'SENTINEL-SECRET-abc123';

        ConfigValue::read(
            ['old_key' => $sentinel],
            'new_key',
            'old_key',
            $this->spyLogger($messages),
        );

        $this->assertStringNotContainsString($sentinel, json_encode($messages));
    }
}
