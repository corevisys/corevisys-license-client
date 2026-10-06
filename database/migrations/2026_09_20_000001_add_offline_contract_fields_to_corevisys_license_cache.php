<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: each column is guarded, and if the base table does not
        // exist yet we skip safely (the create migration has not run).
        if (! Schema::hasTable('corevisys_license_cache')) {
            return;
        }

        Schema::table('corevisys_license_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('corevisys_license_cache', 'issued_at')) {
                $table->timestamp('issued_at')->nullable();
            }
            if (! Schema::hasColumn('corevisys_license_cache', 'offline_valid_until')) {
                $table->timestamp('offline_valid_until')->nullable();
            }
            if (! Schema::hasColumn('corevisys_license_cache', 'is_grace_period')) {
                $table->boolean('is_grace_period')->default(false);
            }
        });
    }

    public function down(): void
    {
        // DESTRUCTIVE: this drops the offline-contract columns and the data
        // they hold (issued_at, offline_valid_until, is_grace_period). Guarded
        // so a re-run or a missing table is a safe no-op. Never run on a live
        // database without a backup.
        if (! Schema::hasTable('corevisys_license_cache')) {
            return;
        }

        Schema::table('corevisys_license_cache', function (Blueprint $table) {
            foreach (['issued_at', 'offline_valid_until', 'is_grace_period'] as $column) {
                if (Schema::hasColumn('corevisys_license_cache', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
