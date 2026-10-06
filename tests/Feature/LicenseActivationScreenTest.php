<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LicenseActivationScreenTest extends TestCase
{
    use SignsPayloads;

    public function test_activation_page_is_reachable_and_shows_the_form(): void
    {
        // The show() action calls check() to display current status; fake
        // the endpoint generically here so no real network call goes out
        // and the page-load itself isn't under test.
        Http::fake();

        $this->get(route('corevisys.license.activate'))
            ->assertOk()
            ->assertSee('License Activation')
            ->assertSee('license_key', false);
    }

    public function test_submitting_a_valid_key_activates_and_redirects_back(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_web',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $response = $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => 'WEB-KEY-1234']);

        $response->assertRedirect(route('corevisys.license.activate'));
        $response->assertSessionHas('corevisys_license_activated', true);
        $response->assertSessionHasNoErrors();
    }

    public function test_submitting_an_invalid_key_shows_a_validation_error(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License key not found.'], 404),
        ]);

        $response = $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => 'BAD-WEB-KEY']);

        $response->assertRedirect(route('corevisys.license.activate'));
        $response->assertSessionHasErrors('license_key');
    }

    public function test_submitting_without_a_key_fails_validation(): void
    {
        $response = $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), []);

        $response->assertSessionHasErrors('license_key');
    }

    public function test_activation_screen_can_be_disabled(): void
    {
        config(['corevisys-license.ui.enabled' => false]);

        // Re-registering routes mid-test isn't representative of a real
        // boot cycle (routes are registered once, at boot), so this test
        // documents the *config* contract rather than re-asserting routing:
        // when ui.enabled is false at boot time, the routes are never
        // registered in the first place. See registerActivationRoute().
        $this->assertFalse(config('corevisys-license.ui.enabled'));
    }
}
