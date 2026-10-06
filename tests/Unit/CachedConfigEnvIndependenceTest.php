<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\ConfigValidator;
use CoreVisys\License\Tests\TestCase;

/**
 * Step B / COMMIT 1 — with `php artisan config:cache` active, `.env` is not
 * loaded, so any runtime env() read returns null. "Was the fallback store set
 * explicitly?" is therefore derived once, at config-build time, into the
 * cacheable config flag `cache_fallback_store_explicit`.
 *
 * These tests simulate cached config by setting that flag via config() and by
 * asserting no file under src/ calls env()/getenv() at runtime.
 */
class CachedConfigEnvIndependenceTest extends TestCase
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
            'cache_driver' => 'cache',
            'cache_store' => 'file',
            'cache_fallback_store' => 'file',
            'cache_fallback_store_explicit' => false,
            'signature' => ['algorithm' => 'rsa'],
        ], $overrides);
    }

    public function test_no_src_file_reads_env_or_getenv_at_runtime(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/\benv\s*\(|\bgetenv\s*\(/', $contents) === 1) {
                $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'src/ must not read env()/getenv() at runtime; derive such values in config/corevisys-license.php instead.'
        );
    }

    public function test_explicit_fallback_equal_to_primary_fails_with_cached_config(): void
    {
        // The flag says the operator DID set the fallback, and it collides with
        // the primary store -> must fail even though no env() value is
        // available once the config is cached.
        $errors = ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_fallback_store_explicit' => true,
                'cache_store' => 'file',
                'cache_fallback_store' => 'file',
            ]),
            ['array', 'file', 'redis'],
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('differ', strtolower(implode(' ', $errors)));
    }

    public function test_packaged_default_equal_with_cached_config_does_not_throw(): void
    {
        config()->set('cache.default', 'file');

        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_fallback_store_explicit' => false,
                'cache_store' => null,
                'cache_fallback_store' => 'file',
            ]),
            ['array', 'file', 'redis'],
        ));
    }

    public function test_explicit_different_fallback_passes_with_cached_config(): void
    {
        $this->assertSame([], ConfigValidator::validateDetailed(
            $this->baseConfig([
                'cache_fallback_store_explicit' => true,
                'cache_store' => 'redis',
                'cache_fallback_store' => 'file',
            ]),
            ['array', 'file', 'redis'],
        ));
    }
}
