<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseStatus;
use CoreVisys\License\Middleware\EnsureValidLicense;
use CoreVisys\License\Support\ConfigValidator;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionObject;

/**
 * Phase 6 / B4 — middleware.excluded_routes and lockout prevention.
 *
 * Proves that corevisys.license (a) is only ever a named alias, never global,
 * (b) lets excluded routes through with NO license check and NO server call,
 * (c) cannot create a redirect loop because the activation route is exempt
 * unconditionally, and (d) still denies a protected route on a genuinely
 * invalid license (web redirect + generic JSON 403 with no secret).
 *
 * The trust/offline verifier is NOT modified or re-tested here; the offline
 * cases reuse the real LicenseClient against a signed cache record, exactly as
 * LicenseOfflineTrustTest does, to keep the middleware's dependency honest.
 */
class MiddlewareExcludedRoutesTest extends TestCase
{
    use SignsPayloads;

    private const PRODUCT = 'test-product';

    protected function defineRoutes($router): void
    {
        $router->middleware('corevisys.license')->get('/protected', fn () => 'protected-ok')->name('protected.area');
        $router->middleware('corevisys.license')->get('/login', fn () => 'login-ok')->name('login');
        $router->middleware('corevisys.license')->get('/logout', fn () => 'logout-ok')->name('logout');
        $router->middleware('corevisys.license')->get('/health', fn () => 'health-ok')->name('health');
        $router->middleware('corevisys.license')->get('/up', fn () => 'up-ok')->name('up');
        $router->middleware('corevisys.license')->get('/reports', fn () => 'reports-ok')->name('reports');
        $router->middleware('corevisys.license')->get('/admin/health/ping', fn () => 'admin-health-ok')->name('admin.health.ping');
    }

    private function mockLicense(bool $valid): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn($valid);
        $mock->shouldReceive('status')->andReturn(LicenseStatus::invalid());
        $mock->shouldReceive('check')->andReturn(LicenseStatus::invalid());
        $this->app->instance(LicenseClientInterface::class, $mock);
    }

    private function serverDown(): void
    {
        Http::fake([
            '*/api/v1/license/check' => Http::response([], 500),
        ]);
    }

    /**
     * Seed the local store with a signed record whose signed payload can be
     * verified from cached key material alone, then take the server offline so
     * the only route to "valid" is the offline path.
     */
    private function seedSignedRecord(
        string $status = 'active',
        ?string $expiresAt = null,
        ?string $offlineValidUntil = null,
        bool $corruptSignature = false,
    ): void {
        $payload = [
            'license_id' => 'lic_mw',
            'status' => $status,
            'product_code' => self::PRODUCT,
            'license_type' => 'full',
            'expires_at' => $expiresAt ?? now()->addYear()->toIso8601String(),
            'features' => [],
            'issued_at' => now()->subMinute()->toIso8601String(),
            'offline_valid_until' => $offlineValidUntil ?? now()->addDays(7)->toIso8601String(),
            'is_grace_period' => false,
        ];

        $envelope = $this->signedEnvelope($payload, corruptSignature: $corruptSignature);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey('test-key-1', $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => 'test-key-1', 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);

        $storage->put(self::PRODUCT, [
            'license_id' => $payload['license_id'],
            'status' => $payload['status'],
            'expires_at' => $payload['expires_at'],
            'offline_valid_until' => $payload['offline_valid_until'],
            'is_grace_period' => false,
            'last_successful_check_at' => now()->subHours(2),
            'next_check_at' => now()->subMinute(), // force due -> online/offline path
            'license_key' => 'CACHED-KEY-0000',
            'signed_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'signature' => $envelope['signature'],
            'key_id' => 'test-key-1',
        ]);
    }

    // --- valid / invalid on a protected route ------------------------------

    public function test_valid_license_allows_a_protected_route(): void
    {
        $this->mockLicense(true);

        $this->get('/protected')->assertOk()->assertSee('protected-ok');
    }

    public function test_invalid_license_redirects_a_web_request_to_the_activation_route(): void
    {
        $this->mockLicense(false);

        $this->get('/protected')
            ->assertRedirect(route('corevisys.license.activate'));
    }

    public function test_invalid_license_returns_a_generic_json_403_without_any_secret(): void
    {
        $this->mockLicense(false);

        $response = $this->getJson('/protected');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'A valid license is required to access this resource.',
                'error_code' => 'invalid_license',
            ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('TEST-KEY-0000', $body, 'The JSON deny body must not leak the configured license key.');
        $this->assertStringNotContainsString('license_key', $body, 'The JSON deny body must not name the license_key field.');
    }

    public function test_expired_signed_license_denies_a_web_request(): void
    {
        $this->seedSignedRecord(status: 'expired', expiresAt: now()->subDay()->toIso8601String());
        $this->serverDown();

        $this->get('/protected')
            ->assertRedirect(route('corevisys.license.activate'));
    }

    public function test_suspended_signed_license_denies_a_web_request(): void
    {
        $this->seedSignedRecord(status: 'suspended');
        $this->serverDown();

        $this->get('/protected')
            ->assertRedirect(route('corevisys.license.activate'));
    }

    // --- default excluded routes stay reachable while invalid --------------

    public function test_default_excluded_auth_and_health_routes_are_reachable_while_invalid(): void
    {
        $this->mockLicense(false);

        $this->get('/login')->assertOk()->assertSee('login-ok');
        $this->get('/logout')->assertOk()->assertSee('logout-ok');
        $this->get('/health')->assertOk()->assertSee('health-ok');
        $this->get('/up')->assertOk()->assertSee('up-ok');
    }

    public function test_activation_get_screen_is_reachable_while_invalid(): void
    {
        $this->mockLicense(false);

        $this->get(route('corevisys.license.activate'))
            ->assertOk()
            ->assertSee('License Activation');
    }

    public function test_activation_post_is_reachable_while_invalid(): void
    {
        $this->mockLicense(false);

        // Reaching the controller (not the middleware) is proven by the
        // controller's own validation error, not a middleware redirect.
        $this->post(route('corevisys.license.activate'), [])
            ->assertSessionHasErrors('license_key');
    }

    // --- custom exclusions by name and by path pattern ---------------------

    public function test_custom_excluded_route_by_name_is_reachable_while_invalid(): void
    {
        config(['corevisys-license.middleware.excluded_routes' => ['reports']]);
        $this->mockLicense(false);

        $this->get('/reports')->assertOk()->assertSee('reports-ok');
        // A route NOT in the list is still denied, proving the list is honoured.
        $this->get('/protected')->assertRedirect(route('corevisys.license.activate'));
    }

    public function test_custom_excluded_route_by_path_pattern_is_reachable_while_invalid(): void
    {
        config(['corevisys-license.middleware.excluded_routes' => ['admin/health/*']]);
        $this->mockLicense(false);

        $this->get('/admin/health/ping')->assertOk()->assertSee('admin-health-ok');
    }

    // --- lockout prevention / activation hard-exemption --------------------

    public function test_activation_route_stays_reachable_when_removed_from_excluded_routes(): void
    {
        // Operator has trimmed the list and no longer names the activation route.
        config(['corevisys-license.middleware.excluded_routes' => ['login']]);
        $this->mockLicense(false);

        $this->get(route('corevisys.license.activate'))
            ->assertOk()
            ->assertSee('License Activation');

        // A denied protected request still resolves to that reachable page,
        // so no loop can form.
        $this->get('/protected')
            ->assertRedirect(route('corevisys.license.activate'));
    }

    public function test_activation_prefix_path_is_hard_exempt_even_behind_the_middleware(): void
    {
        // A route that lives under the activation prefix and IS wrapped by the
        // middleware must still pass, proving the hard-coded path guard (not
        // the config list) prevents the loop.
        config([
            'corevisys-license.ui.route_prefix' => 'lic',
            'corevisys-license.middleware.excluded_routes' => [],
        ]);

        $this->app['router']->middleware('corevisys.license')
            ->get('/lic/activate', fn () => 'license-exempt-ok')
            ->name('synthetic.activation.path');

        $this->mockLicense(false);

        $this->get('/lic/activate')->assertOk()->assertSee('license-exempt-ok');
    }

    public function test_activation_route_name_is_hard_exempt_even_when_not_listed(): void
    {
        // Rename the activation route at runtime and register a middleware-
        // protected route under that exact name; the name guard must exempt it
        // even though excluded_routes falls back to a list that does not name it.
        config([
            'corevisys-license.ui.route_name' => 'custom.activate',
            'corevisys-license.middleware.excluded_routes' => [],
        ]);

        $this->app['router']->middleware('corevisys.license')
            ->get('/custom-activate', fn () => 'custom-exempt-ok')
            ->name('custom.activate');

        $this->mockLicense(false);

        $this->get('/custom-activate')->assertOk()->assertSee('custom-exempt-ok');
    }

    public function test_denied_web_request_following_redirects_ends_on_the_activation_page(): void
    {
        $this->serverDown();

        // No cache + server down => invalid => deny; following the redirect must
        // terminate on a 200 activation page, never loop.
        $this->followingRedirects()
            ->get('/protected')
            ->assertOk()
            ->assertSee('License Activation');
    }

    // --- offline trust interaction (delegated, not re-implemented) ---------

    public function test_server_unavailable_with_a_valid_signed_offline_cache_allows_the_route(): void
    {
        $this->seedSignedRecord();
        $this->serverDown();

        $this->get('/protected')->assertOk()->assertSee('protected-ok');
    }

    public function test_server_unavailable_with_no_cache_denies_the_route(): void
    {
        $this->serverDown();

        $this->get('/protected')->assertRedirect(route('corevisys.license.activate'));
    }

    public function test_server_unavailable_with_a_tampered_cache_denies_the_route(): void
    {
        $this->seedSignedRecord(corruptSignature: true);
        $this->serverDown();

        $this->get('/protected')->assertRedirect(route('corevisys.license.activate'));
    }

    // --- exclusion makes no HTTP call / alias-only registration ------------

    public function test_excluded_route_makes_no_http_call(): void
    {
        Http::fake();

        // Real client; if the guard were absent, isValid() would call check()
        // and hit the server. The excluded route must short-circuit first.
        $this->get('/login')->assertOk()->assertSee('login-ok');

        Http::assertNothingSent();
    }

    public function test_middleware_is_a_named_alias_and_not_in_the_global_stack(): void
    {
        $aliases = $this->app['router']->getMiddleware();
        $this->assertArrayHasKey('corevisys.license', $aliases);
        $this->assertSame(EnsureValidLicense::class, $aliases['corevisys.license']);

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $reflection = new ReflectionObject($kernel);
        $property = $reflection->getProperty('middleware');
        $property->setAccessible(true);
        $global = (array) $property->getValue($kernel);

        $this->assertNotContains(
            EnsureValidLicense::class,
            $global,
            'corevisys.license must be an alias only, never pushed into global middleware.'
        );
    }

    // --- ConfigValidator: excluded_routes shape ----------------------------

    public function test_config_validator_rejects_a_non_array_excluded_routes_value(): void
    {
        $errors = ConfigValidator::validateDetailed(['middleware' => ['excluded_routes' => 'login']]);

        $this->assertContains(
            'The license middleware.excluded_routes must be an array of route-name or path-pattern strings.',
            $errors
        );
    }

    public function test_config_validator_rejects_a_non_string_or_empty_entry(): void
    {
        $errors = ConfigValidator::validateDetailed(['middleware' => ['excluded_routes' => ['login', 123]]]);

        $message = 'The license middleware.excluded_routes must contain only non-empty route-name or path-pattern strings.';
        $this->assertContains($message, $errors);

        // The message must never echo a configured route value.
        foreach ($errors as $error) {
            $this->assertStringNotContainsString('login', $error);
        }

        $errors = ConfigValidator::validateDetailed(['middleware' => ['excluded_routes' => ['ok', '   ']]]);
        $this->assertContains($message, $errors);
    }

    public function test_config_validator_accepts_absent_null_and_empty_excluded_routes(): void
    {
        foreach ([
            [],
            ['middleware' => []],
            ['middleware' => ['excluded_routes' => null]],
            ['middleware' => ['excluded_routes' => []]],
            ['middleware' => ['excluded_routes' => ['login', 'admin/health/*']]],
        ] as $config) {
            $excludedErrors = array_filter(
                ConfigValidator::validateDetailed($config),
                static fn (string $e) => str_contains($e, 'excluded_routes')
            );

            $this->assertSame([], array_values($excludedErrors));
        }
    }

    public function test_empty_excluded_routes_config_falls_back_to_defaults(): void
    {
        config(['corevisys-license.middleware.excluded_routes' => []]);
        $this->mockLicense(false);

        // "empty" means the documented defaults apply, so login is still open.
        $this->get('/login')->assertOk()->assertSee('login-ok');
    }
}
