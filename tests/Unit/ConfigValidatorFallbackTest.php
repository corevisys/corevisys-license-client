<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\ConfigValidator;
use CoreVisys\License\Tests\TestCase;

class ConfigValidatorFallbackTest extends TestCase
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
            'cache_store' => null,
            'cache_fallback_store' => 'file',
            'signature' => ['algorithm' => 'rsa'],
        ], $overrides);
    }

    public function test_unknown_fallback_store_fails(): void
    {
        $errors = ConfigValidator::validateDetailed(
            $this->baseConfig(['cache_fallback_store' => 'no_such_store']),
            ['array', 'file', 'redis'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cache_fallback_store', implode(' ', $errors));
    }

    public function test_known_fallback_store_passes(): void
    {
        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig(['cache_fallback_store' => 'file']),
            ['array', 'file', 'redis'],
        ));
    }

    public function test_empty_fallback_disables_it_and_passes(): void
    {
        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig(['cache_fallback_store' => '']),
            ['array'],
        ));

        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig(['cache_fallback_store' => null]),
            ['array'],
        ));
    }

    public function test_fallback_equal_to_primary_store_fails_in_cache_mode(): void
    {
        $errors = ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_driver' => 'cache',
                'cache_store' => 'redis',
                'cache_fallback_store' => 'redis',
            ]),
            ['array', 'file', 'redis'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('differ', strtolower(implode(' ', $errors)));
    }

    public function test_fallback_different_from_primary_passes_in_cache_mode(): void
    {
        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_driver' => 'cache',
                'cache_store' => 'redis',
                'cache_fallback_store' => 'file',
            ]),
            ['array', 'file', 'redis'],
        ));
    }

    public function test_database_mode_allows_any_configured_fallback_store(): void
    {
        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_driver' => 'database',
                'cache_store' => 'redis',
                'cache_fallback_store' => 'redis', // same name is fine for a different driver
            ]),
            ['array', 'file', 'redis'],
        ));
    }

    public function test_explicit_fallback_equal_to_primary_store_fails_without_any_env_var(): void
    {
        // Config-only proof: no environment variable is set, so the presence
        // signal must come from the derived config flag, not an env() lookup.
        $this->assertFalse(
            getenv('COREVISYS_LICENSE_CACHE_FALLBACK_STORE'),
            'This test must run without the env var set.'
        );

        config()->set('cache.default', 'file');

        $errors = ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_driver' => 'cache',
                'cache_store' => null,
                'cache_fallback_store' => 'file',
                'cache_fallback_store_explicit' => true, // explicitly configured
            ]),
            ['array', 'file', 'redis'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('differ', strtolower(implode(' ', $errors)));
    }

    public function test_packaged_default_fallback_matching_the_default_store_is_tolerated(): void
    {
        // Out-of-the-box state: cache mode, no explicit cache_store, and the
        // application's default cache store happens to be "file" (the packaged
        // fallback default). This is not a *configured* collision, so it must
        // boot without editing the config.
        config()->set('cache.default', 'file');

        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_driver' => 'cache',
                'cache_store' => null,
                'cache_fallback_store' => 'file',
            ]),
            ['array', 'file', 'redis'],
        ));
    }
}
