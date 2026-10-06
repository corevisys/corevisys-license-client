<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\CompatibilityChecker;
use CoreVisys\License\Tests\TestCase;

class CompatibilityCheckerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function checker(array $config = [], ?string $php = null, ?string $laravel = null): CompatibilityChecker
    {
        return new CompatibilityChecker(array_merge([
            'config_version' => CompatibilityChecker::EXPECTED_CONFIG_VERSION,
            'product_code' => 'test-product',
            'required_env_keys' => ['COREVISYS_PRODUCT_CODE' => 'product_code'],
        ], $config), $php ?? PHP_VERSION, $laravel ?? '12.0.0');
    }

    /**
     * @param  array<int, array{name: string, status: string, message: string}>  $results
     * @return array{name: string, status: string, message: string}
     */
    private function find(array $results, string $name): array
    {
        foreach ($results as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("No compatibility check result named {$name}.");
    }

    public function test_matching_config_version_passes(): void
    {
        $row = $this->find($this->checker()->run(), 'config_version');

        $this->assertSame('pass', $row['status']);
    }

    public function test_mismatched_config_version_warns(): void
    {
        $row = $this->find($this->checker(['config_version' => 2])->run(), 'config_version');

        $this->assertSame('warn', $row['status']);
        $this->assertStringContainsString('2', $row['message']);
        $this->assertStringContainsString((string) CompatibilityChecker::EXPECTED_CONFIG_VERSION, $row['message']);
    }

    public function test_missing_config_version_warns(): void
    {
        // Built directly (not via the helper) so no default config_version
        // key is merged back in — this simulates a published config that
        // predates the key entirely.
        $checker = new CompatibilityChecker([
            'product_code' => 'test-product',
            'required_env_keys' => [],
        ], PHP_VERSION, '12.0.0');

        $row = $this->find($checker->run(), 'config_version');

        $this->assertSame('warn', $row['status']);
    }

    public function test_missing_required_env_key_is_reported_by_name_only(): void
    {
        $results = $this->checker(['product_code' => null])->run();
        $row = $this->find($results, 'env_key:COREVISYS_PRODUCT_CODE');

        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('COREVISYS_PRODUCT_CODE', $row['message']);
    }

    public function test_present_required_env_key_passes(): void
    {
        $row = $this->find($this->checker()->run(), 'env_key:COREVISYS_PRODUCT_CODE');

        $this->assertSame('pass', $row['status']);
    }

    public function test_unsupported_php_version_fails(): void
    {
        $row = $this->find($this->checker([], '8.1.0')->run(), 'php_version');

        $this->assertSame('fail', $row['status']);
    }

    public function test_supported_php_version_passes(): void
    {
        $row = $this->find($this->checker([], '8.2.12')->run(), 'php_version');

        $this->assertSame('pass', $row['status']);
    }

    public function test_supported_laravel_majors_pass(): void
    {
        foreach (['10.0.0', '11.5.0', '12.3.1'] as $version) {
            $row = $this->find($this->checker([], null, $version)->run(), 'laravel_version');

            $this->assertSame('pass', $row['status'], "Laravel {$version} should be supported.");
        }
    }

    public function test_unsupported_laravel_version_fails(): void
    {
        $row = $this->find($this->checker([], null, '13.0.0')->run(), 'laravel_version');

        $this->assertSame('fail', $row['status']);
    }

    public function test_checker_output_never_contains_env_values(): void
    {
        $results = $this->checker(['product_code' => 'SENTINEL-PRODUCT-VALUE-9f3'])->run();

        $this->assertStringNotContainsString('SENTINEL-PRODUCT-VALUE-9f3', json_encode($results));
    }

    public function test_passes_returns_false_when_a_check_fails(): void
    {
        $this->assertFalse($this->checker([], '7.4.0')->passes());
    }

    public function test_passes_returns_true_with_warnings_only(): void
    {
        $this->assertTrue($this->checker(['config_version' => 2])->passes());
    }
}
