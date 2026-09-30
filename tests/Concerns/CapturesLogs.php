<?php

namespace CoreVisys\License\Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/**
 * Captures every Laravel log write (via the MessageLogged event) so a test can
 * assert on the level, message and context of each entry without coupling to a
 * specific Monolog version or a concrete logging channel.
 */
trait CapturesLogs
{
    /** @var array<int, MessageLogged> */
    protected array $capturedLogs = [];

    protected function captureLogs(): void
    {
        $this->capturedLogs = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->capturedLogs[] = $event;
        });
    }

    /**
     * @return array<int, MessageLogged>
     */
    protected function logsAt(string $level): array
    {
        return array_values(array_filter(
            $this->capturedLogs,
            fn (MessageLogged $event) => $event->level === $level
        ));
    }

    protected function findLog(string $needle): ?MessageLogged
    {
        foreach ($this->capturedLogs as $event) {
            if (str_contains($event->message, $needle)) {
                return $event;
            }
        }

        return null;
    }

    protected function countLogs(string $needle): int
    {
        return count(array_filter(
            $this->capturedLogs,
            fn (MessageLogged $event) => str_contains($event->message, $needle)
        ));
    }
}
