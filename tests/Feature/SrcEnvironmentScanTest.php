<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\TestCase;

/**
 * Regression guard for the config:cache contract: no production source file
 * under src/ may call env() or getenv() at runtime. Once the config is cached
 * the environment is not loaded, so such a call would silently read null.
 * Presence signals must be derived into the config at config-build time
 * instead.
 */
class SrcEnvironmentScanTest extends TestCase
{
    public function test_no_src_file_calls_env_or_getenv(): void
    {
        $root = dirname(__DIR__, 2).'/src';
        $needles = ['env(', 'getenv('];

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $lines = explode("\n", (string) file_get_contents($file->getPathname()));

            foreach ($lines as $number => $line) {
                foreach ($needles as $needle) {
                    if (str_contains($line, $needle)) {
                        $offenders[] = $file->getPathname().':'.($number + 1).' :: '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "env()/getenv() call found under src/ (breaks config:cache):\n".implode("\n", $offenders)
        );
    }
}
