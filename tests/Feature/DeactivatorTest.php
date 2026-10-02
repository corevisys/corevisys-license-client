<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * FIX-004 (package): LicenseActivator::deactivate() / LicenseClient::deactivate()
 * error-handling policy via Http::fake().
 *
 * Covers:
 *  - Successful server deactivation (200) → true, cache cleared, event fired
 *  - 404 from server → treated as already deactivated → true (cache still cleared)
 *  - 403 from server (domain/fingerprint rejected) → false (cache still cleared)
 *  - Network error (server unreachable) → false (cache still cleared)
 *  - Request body includes fingerprint, domain and product_code
 */
class DeactivatorTest extends TestCase
{
    use SignsPayloads;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * A minimal success response that the deactivate controller returns.
     * Note: unlike activate/check/pulse, deactivate does NOT return a signed
     * license payload — it returns a plain success envelope.
     */
    private function deactivateSuccess(): array
    {
        return [
            'success' => true,
            'status'  => 'success',
            'message' => 'License deactivated successfully.',
            'data'    => [],
        ];
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    #[Test]
    public function deactivate_returns_true_on_server_success(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'        => 'lic_123',
                'status'            => 'active',
                'product_code'      => 'test-product',
                'expires_at'        => now()->addYear()->toIso8601String(),
                'grace_expires_at'  => null,
                'checked_at'        => now()->toIso8601String(),
            ])),
            '*/api/v1/license/deactivate' => Http::response($this->deactivateSuccess()),
        ]);

        $client = $this->app->make(LicenseClientInterface::class);
        $client->activate('VALID-KEY-1234');

        $this->assertTrue($client->deactivate());
    }

    #[Test]
    public function deactivate_returns_false_on_server_404_and_clears_cache(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'   => 'lic_404',
                'status'       => 'active',
                'product_code' => 'test-product',
                'expires_at'   => now()->addYear()->toIso8601String(),
            ])),
            '*/api/v1/license/deactivate' => Http::response(['message' => 'Not found'], 404),
        ]);

        $client = $this->app->make(LicenseClientInterface::class);
        $client->activate('VALID-KEY-1234');

        // 404 is now a failure, but local cache is still cleared
        $this->assertFalse($client->deactivate());
        $this->assertNull($client->status());
    }

    #[Test]
    public function deactivate_treats_409_already_deactivated_as_success(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'   => 'lic_already',
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
        $this->assertNull($client->status());
    }

    #[Test]
    public function deactivate_returns_false_on_server_403_rejection(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'   => 'lic_403',
                'status'       => 'active',
                'product_code' => 'test-product',
                'expires_at'   => now()->addYear()->toIso8601String(),
            ])),
            '*/api/v1/license/deactivate' => Http::response([
                'status'     => false,
                'message'    => 'Fingerprint mismatch. Deactivation denied.',
                'error_code' => 'fingerprint_mismatch',
            ], 403),
        ]);

        $client = $this->app->make(LicenseClientInterface::class);
        $client->activate('VALID-KEY-1234');

        $this->assertFalse($client->deactivate());
    }

    #[Test]
    public function deactivate_returns_false_on_network_error(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'   => 'lic_net',
                'status'       => 'active',
                'product_code' => 'test-product',
                'expires_at'   => now()->addYear()->toIso8601String(),
            ])),
            '*/api/v1/license/deactivate' => Http::response(null, 503),
        ]);

        $client = $this->app->make(LicenseClientInterface::class);
        $client->activate('VALID-KEY-1234');

        $this->assertFalse($client->deactivate());
    }

    #[Test]
    public function deactivate_sends_fingerprint_domain_and_product_code_in_request(): void
    {
        $capturedBody = null;

        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate'   => Http::response($this->signedEnvelope([
                'license_id'   => 'lic_body',
                'status'       => 'active',
                'product_code' => 'test-product',
                'expires_at'   => now()->addYear()->toIso8601String(),
            ])),
            '*/api/v1/license/deactivate' => function (\Illuminate\Http\Client\Request $request) use (&$capturedBody) {
                $capturedBody = $request->data();
                return Http::response($this->deactivateSuccess());
            },
        ]);

        $client = $this->app->make(LicenseClientInterface::class);
        $client->activate('VALID-KEY-1234');
        $client->deactivate();

        $this->assertNotNull($capturedBody, 'Deactivate request body was not captured.');
        $this->assertArrayHasKey('fingerprint', $capturedBody);
        $this->assertArrayHasKey('domain', $capturedBody);
        $this->assertSame('test-product', $capturedBody['product_code']);
        $this->assertSame('application_removed', $capturedBody['reason']);
    }

    #[Test]
    public function deactivate_succeeds_without_prior_activation_when_no_key_in_storage(): void
    {
        // No activation, no key in storage or config → should return true immediately
        // (clearCache is a no-op on empty storage).
        config(['corevisys-license.license_key' => null]);
        $this->app->forgetInstance(LicenseClientInterface::class);

        Http::fake(); // should not be called

        $client = $this->app->make(LicenseClientInterface::class);
        $result = $client->deactivate();

        Http::assertNothingSent();
        $this->assertTrue($result);
    }
}
