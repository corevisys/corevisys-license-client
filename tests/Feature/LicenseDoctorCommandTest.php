<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Commands\LicenseDoctorCommand;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LicenseDoctorCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('corevisys:license:doctor', Artisan::all());
    }

    public function test_healthy_environment_exits_zero(): void
    {
        // Migrations ran in defineDatabaseMigrations(): table + columns exist.
        $this->artisan('corevisys:license:doctor')->assertExitCode(0);
    }

    public function test_missing_column_makes_the_check_fail(): void
    {
        Schema::dropIfExists('corevisys_license_cache');

        // A stub table missing nearly every expected column.
        Schema::create('corevisys_license_cache', function ($table) {
            $table->id();
            $table->string('product_code');
        });

        $this->artisan('corevisys:license:doctor')
            ->expectsOutputToContain('is missing')
            ->assertExitCode(1);
    }

    public function test_missing_table_makes_the_check_fail(): void
    {
        Schema::dropIfExists('corevisys_license_cache');

        $this->artisan('corevisys:license:doctor')
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_unreachable_database_reports_failure_without_throwing(): void
    {
        // Simulate a connection failure: point the default connection at a
        // sqlite file that does not exist. The doctor's schema probe must catch
        // it and report a failure (never throw). The default connection is
        // restored before the framework's migration teardown so the teardown
        // itself does not fail.
        $original = config('database.default');

        config()->set('database.connections.broken', [
            'driver' => 'sqlite',
            'database' => 'C:/corevisys-missing-dir-'.uniqid().'/db.sqlite',
            'prefix' => '',
        ]);
        config()->set('database.default', 'broken');
        DB::purge('broken');

        try {
            $this->artisan('corevisys:license:doctor')
                ->expectsOutputToContain('unreachable')
                ->assertExitCode(1);
        } finally {
            config()->set('database.default', $original);
            DB::purge('broken');
        }
    }

    public function test_cache_driver_reports_schema_as_not_applicable(): void
    {
        config()->set('corevisys-license.cache_driver', 'cache');

        $this->artisan('corevisys:license:doctor')
            ->expectsOutputToContain('Not applicable')
            ->assertExitCode(0);
    }

    public function test_output_never_contains_the_license_key(): void
    {
        $sentinel = 'SENTINEL-KEY-doctor-1234';
        config()->set('corevisys-license.license_key', $sentinel);

        $this->artisan('corevisys:license:doctor')
            ->doesntExpectOutputToContain($sentinel)
            ->assertExitCode(0);
    }

    public function test_expected_columns_constant_matches_the_migration(): void
    {
        $this->assertContains('offline_valid_until', LicenseDoctorCommand::EXPECTED_COLUMNS);
        $this->assertContains('last_successful_check_at', LicenseDoctorCommand::EXPECTED_COLUMNS);
        $this->assertContains('key_id', LicenseDoctorCommand::EXPECTED_COLUMNS);
    }

    public function test_packaged_default_fallback_collision_is_reported_as_a_warning(): void
    {
        // Case (b): cache mode, no explicit cache_store, and the app default
        // store coincidentally equals the packaged fallback default ("file").
        // This is tolerated (no throw, exit 0) but must be surfaced as a
        // warning that explains the fallback is effectively disabled.
        config()->set('corevisys-license.cache_driver', 'cache');
        config()->set('corevisys-license.cache_store', null);
        config()->set('corevisys-license.cache_fallback_store', 'file');
        config()->set('cache.default', 'file');

        $this->artisan('corevisys:license:doctor')
            ->expectsOutputToContain('effectively disabled')
            ->assertExitCode(0);
    }
}
