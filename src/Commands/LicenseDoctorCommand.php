<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Support\CompatibilityChecker;
use CoreVisys\License\Support\ConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only diagnostics: compatibility, config validity, and (in database
 * mode) cache-table schema. It performs no writes, no network calls, and no
 * migrations, and never prints the license key or any secret. Exit code is
 * non-zero if any check reports a failure.
 */
class LicenseDoctorCommand extends Command
{
    protected $signature = 'corevisys:license:doctor';

    protected $description = 'Run read-only compatibility and license-storage diagnostics.';

    public const TABLE = 'corevisys_license_cache';

    /** Columns the create + add-offline-contract migrations are expected to produce. */
    public const EXPECTED_COLUMNS = [
        'id', 'license_id', 'product_code', 'encrypted_license_key', 'status',
        'license_type', 'bound_domain', 'fingerprint_hash', 'expires_at',
        'grace_expires_at', 'issued_at', 'offline_valid_until', 'is_grace_period',
        'features', 'signed_payload', 'signature', 'key_id', 'last_checked_at',
        'next_check_at', 'last_successful_check_at', 'last_error_at',
        'last_error_message', 'created_at', 'updated_at',
    ];

    public function handle(): int
    {
        $config = (array) config('corevisys-license', []);
        $failed = false;

        // Compatibility checks (injected defaults: the running PHP/Laravel).
        $rows = [];
        foreach ((new CompatibilityChecker($config))->run() as $result) {
            $rows[] = [$result['name'], $result['status'], $result['message']];
            $failed = $failed || $result['status'] === 'fail';
        }

        // Config validity.
        $availableStores = array_keys((array) config('cache.stores', []));
        $configErrors = ConfigValidator::validateDetailed($config, $availableStores);

        if ($configErrors === []) {
            $rows[] = ['config_validation', 'pass', 'Configuration values are valid.'];
        } else {
            foreach ($configErrors as $error) {
                $rows[] = ['config_validation', 'fail', $error];
                $failed = true;
            }
        }

        // Advisory (never fails the command): a tolerated fallback/primary
        // store collision silently disables the fallback, which operators
        // should know about even though it is not an error.
        $advisory = $this->fallbackCollisionAdvisory($config);

        if ($advisory !== null) {
            $rows[] = ['cache_fallback_store', 'warn', $advisory];
        }

        // Storage schema (database driver only).
        [$schemaRows, $schemaFailed] = $this->schemaChecks($config);
        $rows = array_merge($rows, $schemaRows);
        $failed = $failed || $schemaFailed;

        $this->table(['Check', 'Status', 'Message'], $rows);

        if ($failed) {
            $this->components->error('One or more CoreVisys license checks failed.');

            return self::FAILURE;
        }

        $this->components->info('All CoreVisys license checks passed.');

        return self::SUCCESS;
    }

    /**
     * Detects the tolerated "fallback equals primary" case in cache mode so
     * the doctor can warn that the fallback is effectively disabled. Returns
     * null when there is nothing to report.
     *
     * @param  array<string, mixed>  $config
     */
    private function fallbackCollisionAdvisory(array $config): ?string
    {
        if (($config['cache_driver'] ?? 'database') !== 'cache') {
            return null;
        }

        $fallback = $config['cache_fallback_store'] ?? null;

        if (! is_string($fallback) || trim($fallback) === '') {
            return null;
        }

        $primary = $config['cache_store'] ?? null;

        if (! is_string($primary) || trim($primary) === '') {
            try {
                $primary = config('cache.default');
            } catch (\Throwable) {
                $primary = null;
            }
        }

        if (! is_string($primary) || $primary !== $fallback) {
            return null;
        }

        // Only the packaged default is tolerated; an explicit setting that
        // collides is a hard validation failure reported separately.
        try {
            $explicit = env('COREVISYS_LICENSE_CACHE_FALLBACK_STORE');
        } catch (\Throwable) {
            $explicit = null;
        }

        if ($explicit !== null && $explicit !== '') {
            return null;
        }

        return 'cache_fallback_store equals the primary cache store ("'.$fallback.'"), so the fallback is effectively disabled. Set a distinct persistent store, or empty to disable it explicitly.';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: array<int, array{0: string, 1: string, 2: string}>, 1: bool}
     */
    private function schemaChecks(array $config): array
    {
        if (($config['cache_driver'] ?? 'database') === 'cache') {
            return [[['storage_schema', 'pass', 'Not applicable — cache_driver is "cache".']], false];
        }

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return [[
                    ['cache_table', 'fail', 'The '.self::TABLE.' table does not exist. Run `php artisan migrate`.'],
                ], true];
            }

            $rows = [['cache_table', 'pass', 'The '.self::TABLE.' table exists.']];
            $failed = false;

            foreach (self::EXPECTED_COLUMNS as $column) {
                if (! Schema::hasColumn(self::TABLE, $column)) {
                    $rows[] = ['cache_column:'.$column, 'fail', 'Expected column '.$column.' is missing.'];
                    $failed = true;
                }
            }

            if (! $failed) {
                $rows[] = ['cache_columns', 'pass', 'All expected columns are present.'];
            }

            return [$rows, $failed];
        } catch (\Throwable $e) {
            // Report class name only — exception messages can contain SQL and
            // bound values (which may include the license key).
            return [[
                ['cache_table', 'fail', 'The license database is unreachable ('.get_class($e).').'],
            ], true];
        }
    }
}
