<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Mockery;

class MiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('corevisys.license')->get('/protected', fn () => 'ok');
        $router->middleware('corevisys.feature:reports')->get('/reports', fn () => 'reports-ok');
    }

    public function test_middleware_allows_valid_license(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/protected')->assertOk()->assertSee('ok');
    }

    public function test_middleware_blocks_invalid_license_by_redirecting_to_activation_page(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(false);
        $this->app->instance(LicenseClientInterface::class, $mock);

        // By default (ui.enabled = true, no explicit redirect_route), an
        // end user hitting a protected page is sent to the built-in
        // activation screen rather than shown a bare, unfixable 403.
        $this->get('/protected')
            ->assertRedirect(route(config('corevisys-license.ui.route_name')));
    }

    public function test_middleware_returns_json_error_for_invalid_license_on_json_requests(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(false);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->getJson('/protected')
            ->assertStatus(403)
            ->assertJson(['error_code' => 'invalid_license']);
    }

    public function test_middleware_aborts_with_403_when_ui_disabled_and_no_redirect_configured(): void
    {
        config(['corevisys-license.ui.enabled' => false]);

        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(false);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/protected')->assertStatus(403);
    }

    public function test_feature_middleware_blocks_missing_feature(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $mock->shouldReceive('feature')->with('reports')->andReturn(false);
        $mock->shouldReceive('status')->andReturn(null);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/reports')->assertStatus(403);
    }

    public function test_feature_middleware_allows_licensed_feature(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $mock->shouldReceive('feature')->with('reports')->andReturn(true);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/reports')->assertOk()->assertSee('reports-ok');
    }
}
