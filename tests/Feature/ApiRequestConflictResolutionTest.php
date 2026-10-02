<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Exceptions\ActivationLimitExceededException;
use CoreVisys\License\Exceptions\LicenseClientException;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class ApiRequestConflictResolutionTest extends TestCase
{
    use SignsPayloads;

    private ApiRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new ApiRequestHandler(
            'https://license.test',
            'v1',
            ['verify_ssl' => false, 'connection_timeout' => 5, 'retry' => ['times' => 1]]
        );
    }

    #[Test]
    public function test_409_with_activation_limit_exceeded_throws_activation_limit_exceeded_exception(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response([
                'status'     => false,
                'message'    => 'Activation limit reached (1).',
                'error_code' => 'activation_limit_exceeded',
            ], 409),
        ]);

        $this->expectException(ActivationLimitExceededException::class);

        $this->handler->post('license/activate', [
            'license_key' => 'TEST-KEY',
        ]);
    }

    #[Test]
    public function test_409_with_already_deactivated_returns_successful_response(): void
    {
        Http::fake([
            '*/api/v1/license/deactivate' => Http::response([
                'status'     => false,
                'message'    => 'License is already deactivated for this domain.',
                'error_code' => 'already_deactivated',
            ], 409),
        ]);

        $response = $this->handler->post('license/deactivate', [
            'license_key' => 'TEST-KEY',
        ]);

        $this->assertInstanceOf(LicenseResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertSame('success', $response->status);
    }

    #[Test]
    public function test_409_with_other_code_throws_license_client_exception_not_activation_limit(): void
    {
        Http::fake([
            '*/api/v1/license/check' => Http::response([
                'status'     => false,
                'message'    => 'State conflict.',
                'error_code' => 'state_conflict',
            ], 409),
        ]);

        try {
            $this->handler->post('license/check', [
                'license_key' => 'TEST-KEY',
            ]);
            $this->fail('Expected LicenseClientException was not thrown.');
        } catch (ActivationLimitExceededException $e) {
            $this->fail('ActivationLimitExceededException should NOT be thrown for non-activation_limit 409.');
        } catch (LicenseClientException $e) {
            $this->assertSame('state_conflict', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }
    }

    #[Test]
    public function test_client_deactivate_with_already_deactivated_409_does_not_throw_and_returns_true(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'   => 'lic_409',
                'status'       => 'active',
                'product_code' => 'test-product',
                'expires_at'   => now()->addYear()->toIso8601String(),
            ])),
            '*/api/v1/license/deactivate' => Http::response([
                'status'     => false,
                'message'    => 'License is already deactivated for this domain.',
                'error_code' => 'already_deactivated',
            ], 409),
        ]);

        $client = $this->app->make(LicenseClientInterface::class);
        $client->activate('VALID-KEY-1234');

        $this->assertTrue($client->deactivate());
    }
}
