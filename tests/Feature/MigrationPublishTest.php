<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\TestCase;

class MigrationPublishTest extends TestCase
{
    public function test_migration_publish_tag_produces_both_migration_files(): void
    {
        $target = database_path('migrations');

        $this->clearPublishedMigrations($target);

        $this->artisan('vendor:publish', [
            '--tag' => 'corevisys-license-migrations',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertNotEmpty(
            glob($target.'/*_create_corevisys_license_cache_table.php'),
            'The create_corevisys_license_cache_table migration was not published.'
        );

        $this->assertNotEmpty(
            glob($target.'/*_add_offline_contract_fields_to_corevisys_license_cache.php'),
            'The add_offline_contract_fields migration was not published.'
        );
    }

    private function clearPublishedMigrations(string $target): void
    {
        foreach (glob($target.'/*_create_corevisys_license_cache_table.php') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($target.'/*_add_offline_contract_fields_to_corevisys_license_cache.php') ?: [] as $file) {
            @unlink($file);
        }
    }
}
