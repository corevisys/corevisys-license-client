<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\ConfigValidator;
use CoreVisys\License\Tests\TestCase;

class ConfigValidatorTest extends TestCase
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
            'signature' => ['algorithm' => 'rsa'],
        ], $overrides);
    }

    public function test_valid_config_does_not_throw(): void
    {
        ConfigValidator::validate($this->baseConfig());

        $this->assertSame([], ConfigValidator::validateDetailed($this->baseConfig()));
    }

    public function test_missing_optional_values_do_not_throw(): void
    {
        // No license_key, no cache_store, no hmac_secret — all optional.
        ConfigValidator::validate($this->baseConfig());

        $this->assertTrue(true);
    }

    public function test_unsupported_algorithm_fails_with_expected_message(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig([
            'signature' => ['algorithm' => 'ed25519'],
        ]));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unsupported CoreVisys license signature algorithm', implode(' ', $errors));
    }

    public function test_malformed_server_url_fails(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig(['server_url' => 'not a url']));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('server_url', implode(' ', $errors));
    }

    public function test_empty_server_url_fails(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig(['server_url' => '   ']));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('server_url', implode(' ', $errors));
    }

    public function test_negative_grace_period_fails(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig(['grace_period' => -1]));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('grace_period', implode(' ', $errors));
    }

    public function test_non_integer_grace_period_fails(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig(['grace_period' => 'soon']));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('grace_period', implode(' ', $errors));
    }

    public function test_unsupported_cache_driver_fails(): void
    {
        $errors = ConfigValidator::validateDetailed($this->baseConfig(['cache_driver' => 'redis']));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('cache_driver', implode(' ', $errors));
    }

    public function test_cache_driver_cache_is_accepted(): void
    {
        $this->assertSame([], ConfigValidator::validateDetailed($this->baseConfig(['cache_driver' => 'cache'])));
    }

    public function test_multiple_invalid_values_throw_one_exception_listing_every_failure(): void
    {
        $sentinel = 'SENTINEL-SECRET-7c1e4d';

        try {
            ConfigValidator::validate($this->baseConfig([
                'server_url' => 'not a url',                // malformed server url
                'grace_period' => -5,                       // negative grace period
                'cache_driver' => 'redis',                  // unsupported driver
                'signature' => ['algorithm' => 'ed25519'],  // unsupported algorithm
                'license_key' => $sentinel,                 // secret that must NOT appear
                'fingerprint' => ['hmac_secret' => $sentinel],
            ]));

            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();

            // A single exception carries every distinct failure.
            $this->assertStringContainsString('signature algorithm', $message);
            $this->assertStringContainsString('server_url', $message);
            $this->assertStringContainsString('grace_period', $message);
            $this->assertStringContainsString('cache_driver', $message);

            // ...and never a configured secret value.
            $this->assertStringNotContainsString($sentinel, $message);
        }
    }

    public function test_exception_message_never_contains_a_secret_value(): void
    {
        $sentinel = 'SENTINEL-SECRET-9f3a2b';

        try {
            ConfigValidator::validate($this->baseConfig([
                'license_key' => $sentinel,
                'cache_driver' => 'redis',
                'fingerprint' => ['hmac_secret' => $sentinel],
            ]));

            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString($sentinel, $e->getMessage());
        }
    }

    public function test_provider_registration_rejects_malformed_server_url(): void
    {
        config()->set('corevisys-license.server_url', 'not a url');
        $provider = new \CoreVisys\License\CoreVisysServiceProvider($this->app);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('server_url');

        $provider->register();
    }
}
