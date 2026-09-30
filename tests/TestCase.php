<?php

namespace CoreVisys\License\Tests;

use CoreVisys\License\CoreVisysServiceProvider;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CoreVisysServiceProvider::class];
    }

    /**
     * A2 — shared isolation. The package's default cache store and its on-disk
     * 'file' fallback store survive between tests within a run, so a record,
     * public-key set, or notification throttle entry written by one test could
     * leak into another. Flush both before every test so the suite is
     * order-independent. (The in-memory database and array stores are already
     * per-test.)
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::store('file')->flush();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('corevisys-license.server_url', 'https://license.test');
        $app['config']->set('corevisys-license.product_code', 'test-product');
        $app['config']->set('corevisys-license.license_key', 'TEST-KEY-0000');
        $app['config']->set('corevisys-license.verify_ssl', true);
        $app['config']->set('corevisys-license.retry.times', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
