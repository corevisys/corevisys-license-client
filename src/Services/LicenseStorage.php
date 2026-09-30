<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Support\LogSanitizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Local persistence for verified license state and the cached CoreVisys
 * public key(s). Two primary backends are supported: "database" (the
 * corevisys_license_cache table) and "cache" (Laravel's cache store).
 * The license key itself is always stored encrypted and is never logged.
 *
 * Resilience: an optional secondary cache store (config cache_fallback_store)
 * mirrors every write. Reads consult it ONLY when the primary store throws a
 * connection/query error — a successful primary "not found" is authoritative
 * and never falls back, so a stale mirror can never resurrect a deactivated or
 * cleared license. Data read from the fallback is marked with
 * {@see self::ORIGIN_MARKER} so the verifier applies the full frozen offline
 * rule (A6) rather than the "fresh enough" fast path. The fallback is a storage
 * location, never a trust shortcut.
 */
class LicenseStorage implements LicenseStorageInterface
{
    protected const TABLE = 'corevisys_license_cache';

    /**
     * Marks a record as having come from the fallback store. The name is
     * deliberately backend-neutral: it is set on records read from ANY
     * fallback, not only a Laravel cache store, and the verifier treats its
     * presence as "apply the full frozen offline rule (A6)".
     */
    public const ORIGIN_MARKER = '__fallback_origin';

    /** The fallback store name shipped as the packaged default. */
    protected const PACKAGED_DEFAULT_FALLBACK_STORE = 'file';

    public function __construct(protected array $config)
    {
    }

    public function get(string $productCode): ?array
    {
        $primary = $this->primaryRecordTarget();

        try {
            // A primary that answers — even with "not found" — is authoritative.
            return $this->readTarget($primary, $productCode);
        } catch (\Throwable $e) {
            $this->logStoreFailure('read', $e);
        }

        $fallback = $this->fallbackStoreName();

        if ($fallback === null) {
            return null;
        }

        try {
            $record = $this->readTarget(['driver' => 'cache', 'store' => $fallback], $productCode);
        } catch (\Throwable $e) {
            $this->logStoreFailure('read', $e);

            return null;
        }

        if ($record === null) {
            return null;
        }

        $this->logFallbackUsed('read');
        $record[self::ORIGIN_MARKER] = true;

        return $record;
    }

    public function put(string $productCode, array $attributes): void
    {
        if (isset($attributes['license_key'])) {
            $attributes['encrypted_license_key'] = $attributes['license_key']
                ? Crypt::encryptString($attributes['license_key'])
                : null;
            unset($attributes['license_key']);
        }

        // Never persist the internal origin marker.
        unset($attributes[self::ORIGIN_MARKER]);

        $attributes['product_code'] = $productCode;
        $attributes['last_checked_at'] = $attributes['last_checked_at'] ?? now();

        try {
            $this->writeTarget($this->primaryRecordTarget(), $productCode, $attributes);
        } catch (\Throwable $e) {
            $this->logStoreFailure('write', $e);
        }

        $fallback = $this->fallbackStoreName();

        if ($fallback !== null) {
            // The fallback is a plain mirror, not a secret store: strip any
            // encryption ciphertext derived from the raw license key before it
            // is written. The fallback record only ever carries the signed
            // payload, so the offline rule (A6) can still run against it
            // without persisting key material in a second location.
            unset($attributes['encrypted_license_key']);

            try {
                $this->writeTarget(['driver' => 'cache', 'store' => $fallback], $productCode, $attributes);
            } catch (\Throwable $e) {
                $this->logStoreFailure('write', $e);
            }
        }
    }

    public function forget(string $productCode): void
    {
        try {
            $this->forgetTarget($this->primaryRecordTarget(), $productCode);
        } catch (\Throwable $e) {
            $this->logStoreFailure('forget', $e);
        }

        $fallback = $this->fallbackStoreName();

        if ($fallback !== null) {
            try {
                $this->forgetTarget(['driver' => 'cache', 'store' => $fallback], $productCode);
            } catch (\Throwable $e) {
                $this->logStoreFailure('forget', $e);
            }
        }
    }

    public function getPublicKey(string $keyId): ?string
    {
        $value = $this->readCacheValue($this->publicKeyCacheKey($keyId));

        return is_string($value) ? $value : null;
    }

    public function putPublicKey(string $keyId, string $publicKeyPem, int $ttlSeconds): void
    {
        $this->writeCacheValue($this->publicKeyCacheKey($keyId), $publicKeyPem, $ttlSeconds);
    }

    public function getPublicKeyMetadata(): ?array
    {
        $metadata = $this->readCacheValue($this->publicKeyMetadataCacheKey());

        return is_array($metadata) ? $metadata : null;
    }

    public function putPublicKeyMetadata(array $metadata, int $ttlSeconds): void
    {
        $this->writeCacheValue($this->publicKeyMetadataCacheKey(), $metadata, $ttlSeconds);
    }

    /**
     * Decrypt the stored license key from a raw cache record, returning
     * null if there isn't one or it fails to decrypt (e.g. APP_KEY rotated
     * or the row was tampered with).
     */
    public function decryptLicenseKey(?array $record): ?string
    {
        if (! $record || empty($record['encrypted_license_key'])) {
            return null;
        }

        try {
            return Crypt::decryptString($record['encrypted_license_key']);
        } catch (DecryptException) {
            return null;
        }
    }

    protected function usingDatabase(): bool
    {
        return ($this->config['cache_driver'] ?? 'database') === 'database';
    }

    /**
     * The primary record target: the database table, or a cache store.
     *
     * @return array{driver: string, store: string|null}
     */
    protected function primaryRecordTarget(): array
    {
        return $this->usingDatabase()
            ? ['driver' => 'database', 'store' => null]
            : ['driver' => 'cache', 'store' => $this->cacheStoreName()];
    }

    /**
     * The configured fallback cache store name, or null when disabled or when
     * it would be identical to the primary store in 'cache' mode.
     *
     * The comparison uses the *resolved* primary store name — an explicit
     * cache_store, or the application's default cache store — so a fallback
     * that merely equals the default store is detected too. This mirrors
     * {@see \CoreVisys\License\Support\ConfigValidator} and the doctor command.
     * ANY collision with the primary store disables the fallback: a fallback
     * pointing at the same store is a no-op, never a second storage location.
     * The packaged default ("file") colliding with an app default of "file" is
     * tolerated by validation (boot does not throw) but is still disabled, and
     * the doctor reports why; a deliberately-set collision fails validation.
     */
    protected function fallbackStoreName(): ?string
    {
        $name = $this->config['cache_fallback_store'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        if (! $this->usingDatabase() && $name === $this->resolvedPrimaryCacheStoreName()) {
            // A fallback that reads and writes the SAME store as the primary
            // adds no resilience — a primary failure takes the fallback down
            // with it — so it is DISABLED rather than kept. This is not an
            // error (boot does not throw); the doctor reports why. A collision
            // the operator set explicitly fails validation instead.
            return null;
        }

        return $name;
    }

    protected function cacheStoreName(): ?string
    {
        $store = $this->config['cache_store'] ?? null;

        return (is_string($store) && trim($store) !== '') ? $store : null;
    }

    /**
     * The name the primary cache store resolves to: an explicit cache_store, or
     * the application's default cache store. Returns null only when neither is
     * known, in which case no same-name collision can be asserted.
     */
    protected function resolvedPrimaryCacheStoreName(): ?string
    {
        $explicit = $this->cacheStoreName();

        if ($explicit !== null) {
            return $explicit;
        }

        try {
            if (function_exists('config')) {
                $default = config('cache.default');

                return (is_string($default) && $default !== '') ? $default : null;
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * Whether the application explicitly set COREVISYS_LICENSE_CACHE_FALLBACK_STORE.
     * Reads the cache-safe config flag derived at config-build time (never an
     * environment lookup at runtime, which is null once the config is cached) —
     * the flag is a boolean presence signal, never a secret value.
     */
    protected function hasExplicitFallbackSetting(): bool
    {
        return ($this->config['cache_fallback_store_explicit'] ?? false) === true;
    }

    protected function cacheKey(string $productCode): string
    {
        return ($this->config['cache_key'] ?? 'corevisys.license.cache').':'.$productCode;
    }

    protected function publicKeyCacheKey(string $keyId): string
    {
        return ($this->config['public_key_cache_key'] ?? 'corevisys.license.public_key').':'.$keyId;
    }

    protected function publicKeyMetadataCacheKey(): string
    {
        return ($this->config['public_key_cache_key'] ?? 'corevisys.license.public_key').':metadata';
    }

    /**
     * @param  array{driver: string, store: string|null}  $target
     */
    protected function readTarget(array $target, string $productCode): ?array
    {
        if ($target['driver'] === 'database') {
            return $this->getFromDatabase($productCode);
        }

        $value = Cache::store($target['store'])->get($this->cacheKey($productCode));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array{driver: string, store: string|null}  $target
     */
    protected function writeTarget(array $target, string $productCode, array $attributes): void
    {
        if ($target['driver'] === 'database') {
            $this->putInDatabase($productCode, $attributes);

            return;
        }

        $this->putInCacheStore($target['store'], $productCode, $attributes);
    }

    /**
     * @param  array{driver: string, store: string|null}  $target
     */
    protected function forgetTarget(array $target, string $productCode): void
    {
        if ($target['driver'] === 'database') {
            DB::table(self::TABLE)->where('product_code', $productCode)->delete();

            return;
        }

        Cache::store($target['store'])->forget($this->cacheKey($productCode));
    }

    /**
     * Read a cache-backed value (public key / metadata) with fallback: the
     * primary is tried first, then the fallback when the primary throws or has
     * no value. Public keys are additive and still checked against the
     * authoritative key metadata, so consulting the fallback on a miss is safe.
     */
    protected function readCacheValue(string $key): mixed
    {
        try {
            $value = Cache::store($this->cacheStoreName())->get($key);

            if ($value !== null) {
                return $value;
            }
        } catch (\Throwable $e) {
            $this->logStoreFailure('read', $e);
        }

        $fallback = $this->fallbackStoreName();

        if ($fallback === null) {
            return null;
        }

        try {
            return Cache::store($fallback)->get($key);
        } catch (\Throwable $e) {
            $this->logStoreFailure('read', $e);

            return null;
        }
    }

    protected function writeCacheValue(string $key, mixed $value, int $ttlSeconds): void
    {
        try {
            Cache::store($this->cacheStoreName())->put($key, $value, $ttlSeconds);
        } catch (\Throwable $e) {
            $this->logStoreFailure('write', $e);
        }

        $fallback = $this->fallbackStoreName();

        if ($fallback !== null) {
            try {
                Cache::store($fallback)->put($key, $value, $ttlSeconds);
            } catch (\Throwable $e) {
                $this->logStoreFailure('write', $e);
            }
        }
    }

    protected function getFromDatabase(string $productCode): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('product_code', $productCode)
            ->orderByDesc('id')
            ->first();

        return $row ? (array) $row : null;
    }

    protected function putInDatabase(string $productCode, array $attributes): void
    {
        $existing = DB::table(self::TABLE)->where('product_code', $productCode)->orderByDesc('id')->first();

        // Only touch `features` when the caller actually provided it — a
        // partial update (e.g. just recording last_error_message) must
        // never silently wipe out a previously stored features list.
        if (array_key_exists('features', $attributes)) {
            $attributes['features'] = json_encode($attributes['features']);
        }

        $attributes['updated_at'] = now();

        if ($existing) {
            DB::table(self::TABLE)->where('id', $existing->id)->update($this->serializeDatesForDatabase($attributes));
        } else {
            $attributes['created_at'] = now();
            DB::table(self::TABLE)->insert($this->serializeDatesForDatabase($attributes));
        }
    }

    /**
     * PDO cannot bind DateTimeInterface objects (Carbon included) directly —
     * it throws "Object of class Carbon\Carbon could not be converted to
     * string". Every column that may carry a Carbon instance is normalized
     * to a plain datetime string before the raw insert/update.
     */
    protected function serializeDatesForDatabase(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $attributes[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $attributes;
    }

    protected function putInCacheStore(?string $store, string $productCode, array $attributes): void
    {
        // No natural TTL here — the license lifecycle (grace period, expiry)
        // governs validity, not the cache store's own expiration.
        $repository = Cache::store($store);
        $existing = $repository->get($this->cacheKey($productCode), []);
        $repository->forever(
            $this->cacheKey($productCode),
            array_merge(is_array($existing) ? $existing : [], $attributes)
        );
    }

    /**
     * Log a storage failure as class name + code ONLY. The exception message
     * is deliberately never logged: QueryException messages contain SQL and
     * bound values, and the bound values can include the license key.
     */
    protected function logStoreFailure(string $operation, \Throwable $e): void
    {
        $this->logWarning('CoreVisys license: license store operation failed.', [
            'operation' => $operation,
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);
    }

    protected function logFallbackUsed(string $operation): void
    {
        $this->logWarning('CoreVisys license: primary store unavailable; using fallback store.', [
            'operation' => $operation,
        ]);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        try {
            // Defense in depth: even though callers pass only non-secret
            // context (exception class/code, operation name), scrub anyway so
            // a future caller cannot leak a key through this path.
            Log::channel($this->config['logging']['channel'] ?? 'stack')->warning(
                LogSanitizer::scrubMessage($message),
                LogSanitizer::scrubContext($context)
            );
        } catch (\Throwable) {
            // Logging must never break the license flow.
        }
    }
}
