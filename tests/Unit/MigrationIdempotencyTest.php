<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationIdempotencyTest extends TestCase
{
    private function createMigration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000000_create_corevisys_license_cache_table.php';
    }

    private function offlineFieldsMigration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_09_20_000001_add_offline_contract_fields_to_corevisys_license_cache.php';
    }

    public function test_create_migration_runs_twice_safely(): void
    {
        Schema::dropIfExists('corevisys_license_cache');

        $migration = $this->createMigration();
        $migration->up();

        $this->assertTrue(Schema::hasTable('corevisys_license_cache'));

        // Second run must be a guarded no-op, not a "table already exists" error.
        $migration->up();

        $this->assertTrue(Schema::hasTable('corevisys_license_cache'));
    }

    public function test_add_offline_fields_migration_runs_twice_safely(): void
    {
        Schema::dropIfExists('corevisys_license_cache');
        $this->createMigration()->up();

        $migration = $this->offlineFieldsMigration();
        $migration->up();
        $migration->up(); // guarded per-column — must not error

        foreach (['issued_at', 'offline_valid_until', 'is_grace_period'] as $column) {
            $this->assertTrue(Schema::hasColumn('corevisys_license_cache', $column));
        }
    }

    public function test_add_offline_fields_skips_safely_when_base_table_missing(): void
    {
        Schema::dropIfExists('corevisys_license_cache');

        // Must not throw when the base table does not exist.
        $this->offlineFieldsMigration()->up();

        $this->assertFalse(Schema::hasTable('corevisys_license_cache'));
    }

    public function test_partial_state_where_only_some_columns_exist_is_repairable(): void
    {
        Schema::dropIfExists('corevisys_license_cache');

        // Simulate a partially-applied state: base table with only issued_at.
        Schema::create('corevisys_license_cache', function ($table) {
            $table->id();
            $table->string('product_code');
            $table->timestamp('issued_at')->nullable();
        });

        $this->offlineFieldsMigration()->up();

        foreach (['issued_at', 'offline_valid_until', 'is_grace_period'] as $column) {
            $this->assertTrue(Schema::hasColumn('corevisys_license_cache', $column));
        }
    }

    public function test_both_migrations_can_run_in_sequence_twice(): void
    {
        Schema::dropIfExists('corevisys_license_cache');

        foreach ([1, 2] as $pass) {
            $this->createMigration()->up();
            $this->offlineFieldsMigration()->up();
        }

        $this->assertTrue(Schema::hasTable('corevisys_license_cache'));
        $this->assertTrue(Schema::hasColumn('corevisys_license_cache', 'offline_valid_until'));
    }
}
