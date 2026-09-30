<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\CoreVisysServiceProvider;
use CoreVisys\License\Support\CompatibilityChecker;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Log;

class ConfigVersionBootWarningTest extends TestCase
{
    /**
     * Capture every log line emitted while the callback runs.
     *
     * @return array<int, string>
     */
    private function captureLogs(callable $callback): array
    {
        $lines = [];

        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message.' '.json_encode($event->context);
        });

        $callback();

        return $lines;
    }

    public function test_mismatched_config_version_logs_warning_with_both_values_and_does_not_throw(): void
    {
        $provider = new CoreVisysServiceProvider($this->app);

        $logs = $this->captureLogs(fn () => $provider->verifyPublishedConfigVersion(true, 999));

        $this->assertNotEmpty($logs, 'Expected a config_version mismatch to be logged.');
        $combined = strtolower(implode(' ', $logs));
        $this->assertStringContainsString('config_version', $combined);
        $this->assertStringContainsString('999', $combined);
        $this->assertStringContainsString((string) CompatibilityChecker::EXPECTED_CONFIG_VERSION, $combined);
    }

    public function test_missing_config_version_behaves_like_a_mismatch(): void
    {
        $provider = new CoreVisysServiceProvider($this->app);

        $warned = $provider->verifyPublishedConfigVersion(true, null);

        $this->assertTrue($warned);
    }

    public function test_matching_config_version_does_not_warn(): void
    {
        $provider = new CoreVisysServiceProvider($this->app);

        $warned = $provider->verifyPublishedConfigVersion(true, CompatibilityChecker::EXPECTED_CONFIG_VERSION);

        $this->assertFalse($warned);
    }

    public function test_no_warning_when_config_was_never_published(): void
    {
        $provider = new CoreVisysServiceProvider($this->app);

        $warned = $provider->verifyPublishedConfigVersion(false, null);

        $this->assertFalse($warned);
    }

    public function test_boot_completes_without_exception(): void
    {
        $provider = new CoreVisysServiceProvider($this->app);

        $provider->boot();

        $this->assertTrue(true);
    }
}
