<?php

namespace CoreVisys\License;

use CoreVisys\License\Commands\LicenseActivateCommand;
use CoreVisys\License\Commands\LicenseCheckCommand;
use CoreVisys\License\Commands\LicenseClearCacheCommand;
use CoreVisys\License\Commands\LicenseDeactivateCommand;
use CoreVisys\License\Commands\LicenseDoctorCommand;
use CoreVisys\License\Commands\LicenseInstallCommand;
use CoreVisys\License\Commands\LicenseStatusCommand;
use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Http\Controllers\LicenseActivationController;
use CoreVisys\License\Middleware\EnsureLicenseFeature;
use CoreVisys\License\Middleware\EnsureValidLicense;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseActivator;
use CoreVisys\License\Services\LicenseClient;
use CoreVisys\License\Services\LicenseHeartbeat;
use CoreVisys\License\Services\LicenseNotifier;
use CoreVisys\License\Services\LicenseStorage;
use CoreVisys\License\Services\LicenseVerifier;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Support\CompatibilityChecker;
use CoreVisys\License\Support\ConfigValidator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CoreVisysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/corevisys-license.php', 'corevisys-license');
        ConfigValidator::validate(
            (array) $this->app['config']->get('corevisys-license', [])
        );

        $this->app->singleton(LicenseStorageInterface::class, function ($app) {
            return new LicenseStorage($app['config']->get('corevisys-license'));
        });

        $this->app->singleton(FingerprintGenerator::class, function ($app) {
            return new FingerprintGenerator($app['config']->get('corevisys-license.fingerprint', []));
        });

        $this->app->singleton(ApiRequestHandler::class, function ($app) {
            $config = $app['config']->get('corevisys-license');

            return new ApiRequestHandler(
                serverUrl: $config['server_url'],
                apiVersion: $config['api_version'],
                config: $config,
            );
        });

        $this->app->singleton(SignedPayloadVerifier::class, function ($app) {
            $config = $app['config']->get('corevisys-license');

            return new SignedPayloadVerifier(
                config: array_merge($config['signature'] ?? [], ['client_version' => $config['client_version'] ?? '1.0.0']),
                storage: $app->make(LicenseStorageInterface::class),
                serverUrl: $config['server_url'],
                apiVersion: $config['api_version'],
            );
        });

        $this->app->singleton(LicenseActivator::class, function ($app) {
            return new LicenseActivator(
                api: $app->make(ApiRequestHandler::class),
                signatureVerifier: $app->make(SignedPayloadVerifier::class),
                fingerprint: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
            );
        });

        $this->app->singleton(LicenseVerifier::class, function ($app) {
            return new LicenseVerifier(
                api: $app->make(ApiRequestHandler::class),
                signatureVerifier: $app->make(SignedPayloadVerifier::class),
                fingerprint: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
                config: $app['config']->get('corevisys-license'),
            );
        });

        $this->app->singleton(LicenseNotifier::class, function ($app) {
            return new LicenseNotifier((array) $app['config']->get('corevisys-license', []));
        });

        $this->app->singleton(LicenseHeartbeat::class, function ($app) {
            return new LicenseHeartbeat(
                api: $app->make(ApiRequestHandler::class),
                signatureVerifier: $app->make(SignedPayloadVerifier::class),
                fingerprint: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
            );
        });

        $this->app->singleton(LicenseClientInterface::class, function ($app) {
            return new LicenseClient(
                activator: $app->make(LicenseActivator::class),
                verifier: $app->make(LicenseVerifier::class),
                heartbeat: $app->make(LicenseHeartbeat::class),
                fingerprintGenerator: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                apiRequestHandler: $app->make(ApiRequestHandler::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
                configuredLicenseKey: $app['config']->get('corevisys-license.license_key'),
            );
        });

        $this->app->alias(LicenseClientInterface::class, 'corevisys.license');
    }

    public function boot(): void
    {
        $this->bootConfigVersionWarning();

        $this->publishes([
            __DIR__.'/../config/corevisys-license.php' => config_path('corevisys-license.php'),
        ], 'corevisys-license-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_01_01_000000_create_corevisys_license_cache_table.php' =>
                database_path('migrations/2026_01_01_000000_create_corevisys_license_cache_table.php'),
            __DIR__.'/../database/migrations/2026_09_20_000001_add_offline_contract_fields_to_corevisys_license_cache.php' =>
                database_path('migrations/2026_09_20_000001_add_offline_contract_fields_to_corevisys_license_cache.php'),
        ], 'corevisys-license-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'corevisys-license');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'corevisys-license');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/corevisys-license'),
        ], 'corevisys-license-views');

        $this->registerMiddlewareAliases();
        $this->registerActivationRoute();

        if ($this->app->runningInConsole()) {
            $this->commands([
                LicenseInstallCommand::class,
                LicenseActivateCommand::class,
                LicenseCheckCommand::class,
                LicenseDeactivateCommand::class,
                LicenseDoctorCommand::class,
                LicenseStatusCommand::class,
                LicenseClearCacheCommand::class,
            ]);

            $this->app->booted(function () {
                $this->scheduleLicenseCheck();
            });
        }
    }

    protected function registerMiddlewareAliases(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('corevisys.license', EnsureValidLicense::class);
        $router->aliasMiddleware('corevisys.feature', EnsureLicenseFeature::class);
    }

    /**
     * Registers the built-in "activate your license" web screen, so end
     * users (who will never run an artisan command) have somewhere to
     * paste a license key. Controlled entirely by config('corevisys-license.ui'):
     * set ui.enabled to false if you're building your own screen against
     * the CoreVisysLicense facade instead. The route/view are also used
     * as the default redirect target for EnsureValidLicense when
     * middleware.redirect_route is left unset.
     */
    protected function registerActivationRoute(): void
    {
        if (! $this->app['config']->get('corevisys-license.ui.enabled', true)) {
            return;
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        $prefix = trim((string) $this->app['config']->get('corevisys-license.ui.route_prefix', 'license'), '/');
        $routeName = $this->app['config']->get('corevisys-license.ui.route_name', 'corevisys.license.activate');
        $middleware = $this->app['config']->get('corevisys-license.ui.middleware', ['web']);

        Route::group(['middleware' => $middleware], function () use ($prefix, $routeName) {
            Route::get("/{$prefix}/activate", [LicenseActivationController::class, 'show'])->name($routeName);
            Route::post("/{$prefix}/activate", [LicenseActivationController::class, 'store'])->name($routeName.'.store');
        });
    }

    /**
     * Registers a daily (or config-driven) license check on the app's own
     * scheduler, using withoutOverlapping() so a slow check never stacks
     * concurrent requests against the license server.
     */
    protected function scheduleLicenseCheck(): void
    {
        if (! $this->app['config']->get('corevisys-license.auto_check', true)) {
            return;
        }

        if (! $this->app->bound(Schedule::class)) {
            return;
        }

        $intervalSeconds = (int) $this->app['config']->get('corevisys-license.check_interval', 86400);

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $schedule->command('corevisys:license:check')
            ->cron($this->cronExpressionForInterval($intervalSeconds))
            ->withoutOverlapping()
            ->onOneServer();
    }

    protected function cronExpressionForInterval(int $seconds): string
    {
        return match (true) {
            $seconds <= 3600 => '* * * * *', // every minute floor; Laravel's minimum granularity
            $seconds <= 21600 => '0 */6 * * *', // every 6 hours
            default => '0 3 * * *', // daily at 03:00
        };
    }

    /**
     * Non-throwing boot-time guard: compares the application's *published*
     * config_version with the version this package expects. A mismatch (or a
     * missing value in a published config) logs a warning and execution
     * continues. Applications that never published the config are never
     * warned, because the packaged default is already known to be correct.
     */
    protected function bootConfigVersionWarning(): void
    {
        try {
            $this->verifyPublishedConfigVersion(
                $this->configIsPublished(),
                $this->publishedConfigVersion(),
            );
        } catch (\Throwable) {
            // A version check must never prevent the app from booting.
        }
    }

    /**
     * @return bool true when a warning was emitted (i.e. a published config
     *              was present and its version did not match the expectation).
     */
    public function verifyPublishedConfigVersion(bool $configPublished, mixed $publishedVersion): bool
    {
        if (! $configPublished) {
            return false;
        }

        $expected = CompatibilityChecker::EXPECTED_CONFIG_VERSION;

        if ($publishedVersion !== null && (int) $publishedVersion === $expected) {
            return false;
        }

        $this->logWarning('CoreVisys license: config_version mismatch.', [
            'expected' => $expected,
            'actual' => $publishedVersion,
        ]);

        return true;
    }

    protected function logWarning(string $message, array $context = []): void
    {
        try {
            if (! $this->app['config']->get('corevisys-license.logging.enabled', true)) {
                return;
            }

            Log::channel($this->app['config']->get('corevisys-license.logging.channel', 'stack'))
                ->warning($message, $context);
        } catch (\Throwable) {
            // A logging failure must never break boot.
        }
    }

    /**
     * Whether the application has published (or cached) its own config file.
     * When it has not, the packaged defaults are in effect and there is
     * nothing to warn about.
     */
    protected function configIsPublished(): bool
    {
        try {
            if ($this->app->configurationIsCached()) {
                return true;
            }

            return is_file(config_path('corevisys-license.php'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reads the app's *raw* published config_version before the package's
     * merge could mask a missing/different value. Returns null when it is
     * absent (or the file cannot be read), which is treated as a mismatch.
     */
    protected function publishedConfigVersion(): ?int
    {
        try {
            if ($this->app->configurationIsCached()) {
                $value = $this->app['config']->get('corevisys-license.config_version');

                return $value === null ? null : (int) $value;
            }

            $path = config_path('corevisys-license.php');

            if (! is_file($path)) {
                return null;
            }

            $config = require $path;
            $value = is_array($config) ? ($config['config_version'] ?? null) : null;

            return $value === null ? null : (int) $value;
        } catch (\Throwable) {
            return null;
        }
    }
}
