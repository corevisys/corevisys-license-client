<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\ConfigDefaults;
use CoreVisys\License\Tests\TestCase;

/**
 * Phase 2 follow-up: an empty environment value must be treated as "unset" so
 * the documented default applies. Only non-empty malformed values may fail.
 */
class ConfigDefaultsTest extends TestCase
{
    public function test_empty_string_falls_back_to_the_default(): void
    {
        $this->assertSame('https://license.corevisys.com', ConfigDefaults::normalize('', 'https://license.corevisys.com'));
        $this->assertSame(72, ConfigDefaults::normalize('', 72));
        $this->assertSame('database', ConfigDefaults::normalize('', 'database'));
    }

    public function test_whitespace_only_falls_back_to_the_default(): void
    {
        $this->assertSame(72, ConfigDefaults::normalize('   ', 72));
    }

    public function test_null_falls_back_to_the_default(): void
    {
        $this->assertSame('rsa', ConfigDefaults::normalize(null, 'rsa'));
    }

    public function test_non_empty_values_are_returned_unchanged(): void
    {
        $this->assertSame('https://lic.example.com', ConfigDefaults::normalize('https://lic.example.com', 'https://default'));
        $this->assertSame(0, ConfigDefaults::normalize(0, 72));
        $this->assertSame('cache', ConfigDefaults::normalize('cache', 'database'));
    }

    public function test_packaged_server_url_default_applies_when_env_is_unset(): void
    {
        // Evaluate the config file directly (as `config:cache` would): with the
        // env var unset, an empty string must normalize to the documented
        // default rather than resolving to null/empty.
        $config = require dirname(__DIR__, 2).'/config/corevisys-license.php';

        $this->assertSame('https://license.corevisys.com', $config['server_url']);
        $this->assertSame('database', $config['cache_driver']);
        $this->assertSame(72, $config['grace_period']);
    }

    public function test_malformed_non_empty_url_still_fails_validation(): void
    {
        $errors = \CoreVisys\License\Support\ConfigValidator::validateDetailed([
            'server_url' => 'not a valid url',
            'grace_period' => 72,
            'cache_driver' => 'database',
            'signature' => ['algorithm' => 'rsa'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('server_url', implode(' ', $errors));
    }
}
