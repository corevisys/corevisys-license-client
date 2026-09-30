<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: safe to re-run and safe against partial states. If the
        // table already exists (re-run, or a partially-applied deploy), skip.
        if (Schema::hasTable('corevisys_license_cache')) {
            return;
        }

        Schema::create('corevisys_license_cache', function (Blueprint $table) {
            $table->id();
            $table->string('license_id')->nullable()->index();
            $table->string('product_code')->index();
            $table->text('encrypted_license_key')->nullable();
            $table->string('status')->default('unknown')->index();
            $table->string('license_type')->nullable();
            $table->string('bound_domain')->nullable();
            $table->string('fingerprint_hash')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_expires_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('offline_valid_until')->nullable();
            $table->boolean('is_grace_period')->default(false);
            $table->json('features')->nullable();
            $table->longText('signed_payload')->nullable();
            $table->text('signature')->nullable();
            $table->string('key_id')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_successful_check_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            // One cache row per product per install is the norm, but we don't
            // hard-unique product_code alone since multi-tenant setups may
            // keep historical rows; the storage layer always reads the latest.
        });
    }

    public function down(): void
    {
        // DESTRUCTIVE: dropping the table permanently deletes ALL cached
        // license state. This is recoverable only by re-activating or
        // re-checking against the license server. Never run this on a live
        // database without a backup.
        Schema::dropIfExists('corevisys_license_cache');
    }
};
