<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Tests\TestCase;

class EnvExampleTest extends TestCase
{
    /**
     * Every `env('NAME')` in the config file must be documented in .env.example
     * so the template never drifts behind the config.
     */
    public function test_env_example_contains_every_config_env_var(): void
    {
        $configPath = dirname(__DIR__, 2).'/config/corevisys-license.php';
        $envExamplePath = dirname(__DIR__, 2).'/.env.example';

        $this->assertFileExists($configPath);
        $this->assertFileExists($envExamplePath);

        $config = (string) file_get_contents($configPath);
        $envExample = (string) file_get_contents($envExamplePath);

        preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $config, $matches);
        $envVars = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($envVars, 'Expected to find env() calls in the config file.');

        foreach ($envVars as $name) {
            $this->assertStringContainsString(
                $name,
                $envExample,
                "Env var {$name} is used in the config but missing from .env.example."
            );
        }
    }

    public function test_env_example_has_no_real_secret_values(): void
    {
        $envExample = (string) file_get_contents(dirname(__DIR__, 2).'/.env.example');

        // Sensitive keys must be present but empty (placeholders only).
        foreach (['COREVISYS_LICENSE_KEY', 'COREVISYS_LICENSE_FINGERPRINT_SECRET'] as $key) {
            $this->assertStringContainsString($key.'=', $envExample);
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'=\s*$/m',
                $envExample,
                "{$key} must be an empty placeholder in .env.example."
            );
        }
    }
}
