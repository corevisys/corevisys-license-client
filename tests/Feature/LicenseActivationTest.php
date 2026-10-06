<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LicenseActivationTest extends TestCase
{
    use SignsPayloads;

    public function test_shared_response_contract_fixture_matches_client_expectations(): void
    {
        $contract = json_decode(file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/license-response-contract.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'envelope' => ['success', 'status', 'message', 'data', 'signature', 'key_id', 'algorithm'],
            'data' => ['status', 'license_id', 'product_code', 'license_type', 'expires_at', 'features', 'issued_at', 'offline_valid_until', 'is_grace_period'],
        ], $contract);
    }

    public function test_valid_license_activation_succeeds(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'active',
                'type' => 'subscription',
                'product_code' => 'test-product',
                'domain' => 'license.test',
                'expires_at' => now()->addYear()->toIso8601String(),
                'grace_expires_at' => null,
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('VALID-KEY-1234');

        $this->assertTrue($result->success);
        $this->assertTrue($result->status->isActive());
    }

    public function test_rsa_server_response_matches_rsa_client_configuration(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic-rsa',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
            ])),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('RSA-KEY');

        $this->assertTrue($result->success);
    }

    public function test_unsupported_client_algorithm_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported CoreVisys license signature algorithm: ed25519');

        SignedPayloadVerifier::validateConfiguration('ed25519');
    }

    public function test_provider_registration_rejects_unsupported_algorithm(): void
    {
        config()->set('corevisys-license.signature.algorithm', 'ed25519');
        $provider = new \CoreVisys\License\CoreVisysServiceProvider($this->app);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported CoreVisys license signature algorithm: ed25519');

        $provider->register();
    }

    public function test_invalid_license_activation_is_rejected(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License key not found.'], 404),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('BAD-KEY');

        $this->assertFalse($result->success);
    }

    public function test_activation_limit_exceeded_returns_failure(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response([
                'status'     => false,
                'message'    => 'Activation limit exceeded.',
                'error_code' => 'activation_limit_exceeded',
            ], 409),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('OVER-LIMIT-KEY');

        $this->assertFalse($result->success);
        $this->assertSame('activation_limit_exceeded', $result->errorCode);
    }

    public function test_license_key_never_appears_in_logs(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'error'], 500),
        ]);

        $loggedLines = [];

        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$loggedLines) {
            $loggedLines[] = $event->message.' '.json_encode($event->context);
        });

        $this->app->make(LicenseClientInterface::class)->activate('SECRET-KEY-DO-NOT-LOG');

        $this->assertNotEmpty($loggedLines, 'Expected the failed activation to produce at least one log line to check.');

        foreach ($loggedLines as $line) {
            $this->assertStringNotContainsString('SECRET-KEY-DO-NOT-LOG', $line);
        }
    }
}
