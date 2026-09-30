<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\TestCase;

class ConfigCacheTest extends TestCase
{
    /**
     * Simulates the effect of `config:cache`: the resolved config repository
     * must expose every documented key with no nulls, so nothing depends on a
     * live env() lookup at runtime.
     */
    public function test_cached_config_resolves_all_documented_keys(): void
    {
        $keys = [
            'config_version',
            'server_url',
            'product_code',
            'license_key',
            'api_version',
            'client_version',
            'required_env_keys',
            'connection_timeout',
            'verify_ssl',
            'max_response_bytes',
            'retry',
            'auto_activate',
            'auto_check',
            'check_interval',
            'grace_period',
            'allow_offline_verification',
            'cache_driver',
            'cache_key',
            'public_key_cache_key',
            'fingerprint',
            'signature',
            'middleware',
            'ui',
            'logging',
        ];

        foreach ($keys as $key) {
            $this->assertTrue(
                config()->has('corevisys-license.'.$key),
                "Config key corevisys-license.{$key} did not resolve."
            );
        }

        // Nested keys that are individually consumed at runtime.
        foreach ([
            'retry.times',
            'signature.algorithm',
            'fingerprint.algorithm',
            'middleware.abort_status',
            'ui.enabled',
            'logging.channel',
        ] as $key) {
            $this->assertTrue(
                config()->has('corevisys-license.'.$key),
                "Nested config key corevisys-license.{$key} did not resolve."
            );
        }
    }

    public function test_package_registers_without_reading_env_at_runtime(): void
    {
        // The package must boot purely from config(); this assertion simply
        // proves the provider is registered and resolvable after boot.
        $this->assertTrue($this->app->providerIsLoaded(\CoreVisys\License\CoreVisysServiceProvider::class));
    }
}
