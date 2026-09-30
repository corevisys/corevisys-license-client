<?php

namespace CoreVisys\License\Support;

use CoreVisys\License\Services\SignedPayloadVerifier;

/**
 * Boot-time validation of the resolved `corevisys-license` config.
 *
 * Only genuinely invalid values fail. Missing or merely-optional values are
 * tolerated (they fall back to documented defaults). Failure messages are
 * written so they never include a secret value — they describe the problem,
 * not the configured data.
 *
 * This validator performs no network or destructive actions.
 */
final class ConfigValidator
{
    /**
     * Validate the resolved config, throwing on the first batch of errors.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>|null  $availableCacheStores  Configured cache store names,
     *                                                         or null to read them from config().
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(array $config, ?array $availableCacheStores = null): void
    {
        $errors = self::validateDetailed($config, $availableCacheStores);

        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'CoreVisys license configuration is invalid: '.implode(' ', $errors).' '.self::RECOVERY_HINT
            );
        }
    }

    /**
     * Redacted, actionable recovery guidance appended to the failure message.
     * It names the affected config keys (never their values) and the concrete
     * steps to recover, so an operator facing a boot failure is not left
     * guessing. The message never includes a secret value.
     */
    private const RECOVERY_HINT =
        'Fix the affected keys in config/corevisys-license.php (or their COREVISYS_* '
        .'environment variables), then run `php artisan config:clear`. To inspect the '
        .'resolved values without booting the app, run `php artisan corevisys:license:doctor`. '
        .'No secret value is ever printed here.';

    /**
     * Collect every validation error without throwing.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>|null  $availableCacheStores
     * @return array<int, string>
     */
    public static function validateDetailed(array $config, ?array $availableCacheStores = null): array
    {
        $errors = [];

        foreach ([
            self::algorithmError($config),
            self::serverUrlError($config),
            self::gracePeriodError($config),
            self::cacheDriverError($config),
            self::fallbackStoreError($config, $availableCacheStores),
        ] as $error) {
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function algorithmError(array $config): ?string
    {
        $algorithm = $config['signature']['algorithm'] ?? 'rsa';

        try {
            SignedPayloadVerifier::validateConfiguration((string) $algorithm);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function serverUrlError(array $config): ?string
    {
        $url = $config['server_url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            // Empty is normalized to the default at config-build time, so an
            // empty value reaching here means the default itself is missing.
            return 'The license server_url is missing or empty. Set COREVISYS_LICENSE_SERVER_URL to the full URL of your license server.';
        }

        if (! self::isValidServerUrl($url)) {
            return 'The license server_url is malformed. Provide an absolute http(s) URL with a host, e.g. https://license.corevisys.com.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function gracePeriodError(array $config): ?string
    {
        $grace = $config['grace_period'] ?? 0;

        if (! self::isIntegerLike($grace)) {
            return 'The license grace_period must be a non-negative whole number of hours.';
        }

        if ((int) $grace < 0) {
            return 'The license grace_period must not be negative.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function cacheDriverError(array $config): ?string
    {
        $driver = $config['cache_driver'] ?? 'database';

        if (! in_array($driver, ['database', 'cache'], true)) {
            return 'The license cache_driver must be one of: database, cache.';
        }

        return null;
    }

    /**
     * When set, cache_fallback_store must name a configured cache store and,
     * in 'cache' driver mode, must differ from the primary store.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>|null  $availableCacheStores
     */
    private static function fallbackStoreError(array $config, ?array $availableCacheStores): ?string
    {
        $fallback = $config['cache_fallback_store'] ?? null;

        if ($fallback === null || (is_string($fallback) && trim($fallback) === '')) {
            return null; // fallback disabled — always valid
        }

        if (! is_string($fallback)) {
            return 'The license cache_fallback_store must be a cache store name, or empty to disable the fallback.';
        }

        $stores = $availableCacheStores ?? self::resolveAvailableStoreNames();

        // Only enforce existence when we actually know the configured stores.
        if ($availableCacheStores !== null && ! in_array($fallback, $stores, true)) {
            return 'The license cache_fallback_store is not a configured cache store.';
        }

        if (($config['cache_driver'] ?? 'database') === 'cache') {
            $primary = self::primaryCacheStoreName($config);

            if ($primary !== null && $primary === $fallback) {
                // The packaged default fallback is "file". An application whose
                // default cache store is also "file" has not *configured* a
                // collision — it is the out-of-the-box state and must be usable
                // without editing the config. Only a deliberately-set fallback
                // that matches the primary store is an error.
                $isPackagedDefault = $fallback === self::PACKAGED_DEFAULT_FALLBACK_STORE
                    && ! self::hasExplicitFallbackSetting();

                if (! $isPackagedDefault) {
                    return 'The license cache_fallback_store must differ from the primary cache store when cache_driver is "cache".';
                }
            }
        }

        return null;
    }

    /** The fallback store name shipped as the packaged default. */
    private const PACKAGED_DEFAULT_FALLBACK_STORE = 'file';

    /**
     * Whether the application actually set COREVISYS_LICENSE_CACHE_FALLBACK_STORE.
     * Reads the raw env only to distinguish "packaged default" from "explicitly
     * configured" — never to obtain a secret.
     */
    private static function hasExplicitFallbackSetting(): bool
    {
        try {
            $value = function_exists('env') ? env('COREVISYS_LICENSE_CACHE_FALLBACK_STORE') : null;

            return $value !== null && $value !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The name of the primary cache store, as resolved by Laravel (so an
     * explicit cache_store, the application default, and a named store all
     * resolve to the same literal store name before comparison).
     *
     * @param  array<string, mixed>  $config
     */
    private static function primaryCacheStoreName(array $config): ?string
    {
        $store = $config['cache_store'] ?? null;

        if (is_string($store) && trim($store) !== '') {
            // Return the configured name verbatim so a same-name comparison
            // against the fallback is possible; store existence is validated
            // separately by the caller.
            return trim($store);
        }

        try {
            if (function_exists('config')) {
                $default = config('cache.default');

                return is_string($default) && $default !== '' ? $default : null;
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * Store names defined in config/cache.php — including names nested under a
     * wrapper key such as "stores" (Laravel resolves config('cache.stores') to
     * the inner definitions, but a hand-rolled cache config may differ).
     *
     * @return array<int, string>
     */
    private static function resolveAvailableStoreNames(): array
    {
        try {
            if (! function_exists('config')) {
                return [];
            }

            $definitions = (array) config('cache.stores', []);
            $names = array_keys($definitions);

            // If the definitions are wrapped one level deep, descend once.
            if ($names === ['stores'] && is_array($definitions['stores'])) {
                $names = array_keys($definitions['stores']);
            }

            return array_values($names);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function isValidServerUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }

    private static function isIntegerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (is_string($value)) {
            return preg_match('/^-?\d+$/', trim($value)) === 1;
        }

        return false;
    }
}
