<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\TestCase;

/**
 * The package must ship only PUBLIC key material. No shipped file (source,
 * config, views, migrations, docs, or the example env) may contain a private
 * key block. Test-only private keys live under tests/ and are intentionally
 * outside the scanned set.
 */
class RepoPrivateKeyScanTest extends TestCase
{
    public function test_no_shipped_file_contains_a_private_key_block(): void
    {
        $root = dirname(__DIR__, 2);

        $directories = ['src', 'resources', 'config', 'database', 'docs'];
        $needles = ['BEGIN PRIVATE KEY', 'BEGIN RSA PRIVATE KEY'];

        $offenders = [];

        foreach ($directories as $dir) {
            $path = $root.'/'.$dir;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = $file->getPathname().' :: '.$needle;
                    }
                }
            }
        }

        $envExample = $root.'/.env.example';

        if (is_file($envExample)) {
            $contents = (string) file_get_contents($envExample);

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = '.env.example :: '.$needle;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Private key material found in shipped files:\n".implode("\n", $offenders)
        );
    }
}
