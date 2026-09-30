<?php

/*
| Empty-string environment values are treated as "unset" so the documented
| default always applies (e.g. COREVISYS_LICENSE_SERVER_URL= still yields the
| default). Evaluated when the config is built, so it is config:cache safe.
*/
$unset = static fn (mixed $value, mixed $default): mixed
    => \CoreVisys\License\Support\ConfigDefaults::normalize($value, $default);

/*
| Parse a comma-separated env string (or an array) into a clean list of
| non-empty string values. An empty/unset value yields an empty array so the
| caller can apply a documented default.
*/
$list = static function (mixed $value): array {
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        $parts = $value;
    } elseif (is_string($value)) {
        $parts = explode(',', $value);
    } else {
        return [];
    }
    $parts = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $parts);

    return array_values(array_filter($parts, static fn ($v) => is_string($v) && $v !== ''));
};

return [

    /*
    |--------------------------------------------------------------------------
    | Package Compatibility
    |--------------------------------------------------------------------------
    | Purpose:  The config schema version this package expects.
    | Default:  1
    | Env var:  (none — not environment-driven)
    | Production guidance: Leave this at the packaged value. Bump it only when
    |   the shape or meaning of keys in this file changes. On boot the package
    |   compares the application's *published* value with its own expected
    |   constant (CompatibilityChecker::EXPECTED_CONFIG_VERSION) and logs a
    |   warning if they differ. It never throws at boot.
    */
    'config_version' => 1,

    /*
    |--------------------------------------------------------------------------
    | CoreVisys License Server
    |--------------------------------------------------------------------------
    | server_url: absolute http(s) URL of the CoreVisys license server. Required.
    |   Malformed or empty values fail fast at boot.
    |   Default: https://license.corevisys.com
    |   Env:     COREVISYS_LICENSE_SERVER_URL
    |   Production: only override if you run your own license server; always
    |     use https in production.
    */
    'server_url' => $unset(env('COREVISYS_LICENSE_SERVER_URL'), 'https://license.corevisys.com'),

    /*
    | product_code: the product identifier issued for your application. Required.
    |   Default: (none)
    |   Env:     COREVISYS_PRODUCT_CODE
    |   Production: set this in the server environment; never hardcode it in the repo.
    */
    'product_code' => env('COREVISYS_PRODUCT_CODE'),

    /*
    | license_key: optional pre-provisioned license key used when the activation
    |   screen is disabled and you supply the key from the environment.
    |   Default: (none)
    |   Env:     COREVISYS_LICENSE_KEY
    |   Production: this is a secret. If present it is stored encrypted at rest.
    |     Prefer activation for end-user installs. Never commit the real value.
    */
    'license_key' => env('COREVISYS_LICENSE_KEY'),

    /*
    | api_version: license server API version segment used in request paths.
    |   Default: v1
    |   Env:     COREVISYS_LICENSE_API_VERSION
    |   Production: only change when the server negotiates a new version.
    */
    'api_version' => env('COREVISYS_LICENSE_API_VERSION', 'v1'),

    /*
    | client_version: sent to the server so it can reason about client capability.
    |   Default: 1.0.0
    |   Env:     COREVISYS_LICENSE_CLIENT_VERSION
    |   Production: keep aligned with the installed package version.
    */
    'client_version' => env('COREVISYS_LICENSE_CLIENT_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Required Environment Keys
    |--------------------------------------------------------------------------
    | Purpose:  Env variable names this package cannot operate without, each
    |           mapped to the config key that proves the value is present.
    | Default:  COREVISYS_PRODUCT_CODE -> product_code
    | Env var:  (none — this is a manifest, not a value)
    | Production guidance: The compatibility checker and the doctor command read
    |   these names from here rather than hardcoding them, and report presence
    |   by key name only — never values. The license key is intentionally NOT
    |   listed as required because it may be supplied at activation time.
    */
    'required_env_keys' => [
        'COREVISYS_PRODUCT_CODE' => 'product_code',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    | connection_timeout: seconds to wait for the license server.
    |   Default: 10 | Env: COREVISYS_LICENSE_TIMEOUT
    |   Production: keep small enough that a slow server never blocks requests.
    */
    'connection_timeout' => env('COREVISYS_LICENSE_TIMEOUT', 10),

    /*
    | verify_ssl: verify the license server TLS certificate.
    |   Default: true | Env: COREVISYS_LICENSE_VERIFY_SSL
    |   Production: MUST remain true. Disabling it breaks the trust chain.
    */
    'verify_ssl' => env('COREVISYS_LICENSE_VERIFY_SSL', true),

    /*
    | max_response_bytes: hard cap on the response body size.
    |   Default: 1048576 (1 MiB) | Env: COREVISYS_LICENSE_MAX_RESPONSE_BYTES
    |   Production: raise only if a legitimate signed payload exceeds the cap.
    */
    'max_response_bytes' => env('COREVISYS_LICENSE_MAX_RESPONSE_BYTES', 1_048_576),

    /*
    | retry.*: transient-failure retry policy for server calls.
    |   Defaults: times 3, base_delay_ms 250, max_delay_ms 4000
    |   Env: COREVISYS_LICENSE_RETRY_TIMES / _RETRY_BASE_DELAY_MS / _RETRY_MAX_DELAY_MS
    |   Production: leave as-is unless the server is unusually slow.
    */
    'retry' => [
        'times' => env('COREVISYS_LICENSE_RETRY_TIMES', 3),
        'base_delay_ms' => env('COREVISYS_LICENSE_RETRY_BASE_DELAY_MS', 250),
        'max_delay_ms' => env('COREVISYS_LICENSE_RETRY_MAX_DELAY_MS', 4000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activation / Check Behavior
    |--------------------------------------------------------------------------
    | auto_activate: activate automatically on boot when a key is available.
    |   Default: false | Env: COREVISYS_LICENSE_AUTO_ACTIVATE
    |   Production: prefer explicit activation; enable only for unattended installs.
    */
    'auto_activate' => env('COREVISYS_LICENSE_AUTO_ACTIVATE', false),

    /*
    | auto_check: register the scheduled license check.
    |   Default: true | Env: COREVISYS_LICENSE_AUTO_CHECK
    |   Production: keep true and ensure the scheduler runs, so expiry is noticed.
    */
    'auto_check' => env('COREVISYS_LICENSE_AUTO_CHECK', true),

    /*
    | check_interval: seconds between scheduled checks.
    |   Default: 86400 | Env: COREVISYS_LICENSE_CHECK_INTERVAL
    |   Production: 86400 (daily) is usually enough; shorter increases server load.
    */
    'check_interval' => env('COREVISYS_LICENSE_CHECK_INTERVAL', 86400), // seconds

    /*
    | grace_period: local offline grace window, in hours. Must be a non-negative
    |   whole number. It may only SHORTEN the server-issued offline_valid_until
    |   boundary; it can never extend that server boundary.
    |   Default: 72 | Env: COREVISYS_LICENSE_GRACE_PERIOD
    |   Production: keep it short; a long grace weakens revocation responsiveness.
    */
    'grace_period' => $unset(env('COREVISYS_LICENSE_GRACE_PERIOD'), 72), // hours

    /*
    | allow_offline_verification: permit the frozen offline rule when the server
    |   is unreachable. Even when true, a cached "active" is never sufficient on
    |   its own — signature and time windows must still pass.
    |   Default: true | Env: COREVISYS_LICENSE_ALLOW_OFFLINE
    |   Production: keep true for resilience; set false if you require an online
    |   check on every request (accepting outages as hard failures).
    */
    'allow_offline_verification' => env('COREVISYS_LICENSE_ALLOW_OFFLINE', true),

    /*
    |--------------------------------------------------------------------------
    | Local Cache Storage
    |--------------------------------------------------------------------------
    | cache_driver: where the signed license state is stored.
    |   'database' (default) uses the corevisys_license_cache table;
    |   'cache' uses a Laravel cache store (see cache_store).
    |   Default: database | Env: COREVISYS_LICENSE_CACHE_DRIVER
    |   Production: database is durable and survives cache flushes; cache mode is
    |     lighter but must point at a persistent store, not an array/null store.
    */
    'cache_driver' => $unset(env('COREVISYS_LICENSE_CACHE_DRIVER'), 'database'),

    /*
    | cache_store: the Laravel cache store used when cache_driver is 'cache'.
    |   Default: null (the application's default cache store)
    |   Env: COREVISYS_LICENSE_CACHE_STORE
    |   Production: set an explicit persistent store (e.g. redis/file) so license
    |     state is not lost when the default store changes.
    */
    'cache_store' => $unset(env('COREVISYS_LICENSE_CACHE_STORE'), null), // null = default store

    /*
    | cache_fallback_store: a secondary Laravel cache store used as a storage
    |   location when the PRIMARY store fails with a connection/query error.
    |   It is NEVER a trust shortcut — data read from it must still pass full
    |   signature and offline (A6) verification. Writes mirror to it; reads
    |   consult it only when the primary throws.
    |   Default: file
    |   Env: COREVISYS_LICENSE_CACHE_FALLBACK_STORE
    |   Production: point at a persistent store (file/redis). In 'cache' driver
    |     mode it MUST differ from cache_store. Set empty to disable the fallback.
    */
    'cache_fallback_store' => $unset(env('COREVISYS_LICENSE_CACHE_FALLBACK_STORE'), 'file'),

    /*
    | cache_key: cache-store key holding the signed license payload (cache mode).
    |   Default: corevisys.license.cache | Env: (none)
    |   Production: change only to avoid a collision; it is not a secret.
    */
    'cache_key' => 'corevisys.license.cache',

    /*
    | public_key_cache_key: cache-store key holding the published public key set.
    |   Default: corevisys.license.public_key | Env: (none)
    |   Production: change only to avoid a collision; it is not a secret.
    */
    'public_key_cache_key' => 'corevisys.license.public_key',

    /*
    |--------------------------------------------------------------------------
    | Fingerprinting
    |--------------------------------------------------------------------------
    | fingerprint.algorithm: how the machine/app fingerprint is hashed.
    |   Default: sha256 (or hmac-sha256) | Env: COREVISYS_LICENSE_FINGERPRINT_ALGO
    |   Production: hmac-sha256 requires hmac_secret; prefer it for tamper resistance.
    |
    | fingerprint.hmac_secret: secret for hmac-sha256.
    |   Default: (none) | Env: COREVISYS_LICENSE_FINGERPRINT_SECRET
    |   Production: a secret — set in the server environment only, never in the repo.
    |
    | fingerprint.include_* / strip_www: which attributes feed the fingerprint and
    |   whether a leading "www." is stripped from the domain. Defaults are shown.
    |   Production: keep include_app_key/include_machine_data false unless required,
    |   as they make the fingerprint brittle across key rotation or hardware changes.
    */
    'fingerprint' => [
        'algorithm' => env('COREVISYS_LICENSE_FINGERPRINT_ALGO', 'sha256'), // sha256 | hmac-sha256
        'hmac_secret' => env('COREVISYS_LICENSE_FINGERPRINT_SECRET'),
        'include_domain' => true,
        'include_ip' => true,
        'include_app_key' => false,
        'include_machine_data' => false,
        'strip_www' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature Verification
    |--------------------------------------------------------------------------
    | signature.algorithm: response signature algorithm. RSA-SHA256 only.
    |   Default: rsa | Env: COREVISYS_LICENSE_SIGNATURE_ALGO
    |   Production: never change. Any value other than "rsa" fails fast at boot;
    |     the client will not accept Ed25519 or any other scheme.
    |
    | signature.timestamp_tolerance: seconds of clock skew allowed for replays.
    |   Default: 300 | Env: COREVISYS_LICENSE_TIMESTAMP_TOLERANCE
    |   Production: keep tight; widen only to cover known clock drift.
    |
    | signature.public_key_cache_ttl: seconds a published key set is cached.
    |   Default: 86400 | Env: COREVISYS_LICENSE_PUBLIC_KEY_TTL
    |   Production: shorter means revocations take effect sooner but adds requests.
    */
    'signature' => [
        'algorithm' => env('COREVISYS_LICENSE_SIGNATURE_ALGO', 'rsa'), // rsa only
        'timestamp_tolerance' => env('COREVISYS_LICENSE_TIMESTAMP_TOLERANCE', 300), // seconds
        'public_key_cache_ttl' => env('COREVISYS_LICENSE_PUBLIC_KEY_TTL', 86400), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | middleware.redirect_route: route name to redirect denied web requests to.
    |   Default: null (falls back to the built-in activation route)
    |   Env var: (none)
    |   Production: point this at a page that is itself NOT behind
    |     corevisys.license, to avoid a redirect loop.
    |
    | middleware.abort_status: HTTP status for denied JSON requests.
    |   Default: 403 | Env var: (none)
    |   Production: 402 is also accepted by some apps; 403 is the safe default.
    |
    | middleware.bypass_in_local: skip enforcement when the app is local.
    |   Default: false | Env: COREVISYS_LICENSE_BYPASS_LOCAL
    |   Production: MUST remain false in production.
    */
    'middleware' => [
        'redirect_route' => null,
        'abort_status' => 403,
        'bypass_in_local' => env('COREVISYS_LICENSE_BYPASS_LOCAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in Activation Screen
    |--------------------------------------------------------------------------
    | Purpose:  An optional plain web page — a license-key input and an
    |           "Activate" button — that POSTs to CoreVisysLicense::activate().
    |           End users installing your application never run artisan commands.
    | Defaults: enabled true, route_prefix "license", route_name
    |           "corevisys.license.activate", middleware ["web"].
    | Env vars: COREVISYS_LICENSE_UI_ENABLED / COREVISYS_LICENSE_UI_PREFIX.
    | Production guidance: Add 'auth' (or your own gate) to ui.middleware if only
    |   logged-in admins should be allowed to (re-)activate. The activation page
    |   must never sit behind corevisys.license, or denials would loop.
    */
    'ui' => [
        'enabled' => env('COREVISYS_LICENSE_UI_ENABLED', true),
        'route_prefix' => env('COREVISYS_LICENSE_UI_PREFIX', 'license'),
        'route_name' => 'corevisys.license.activate',
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | logging.enabled: emit package log events at all.
    |   Default: true | Env: COREVISYS_LICENSE_LOGGING
    |   Production: keep true so operators can see activation/verification issues.
    |
    | logging.channel: log channel used by the package.
    |   Default: stack | Env: COREVISYS_LICENSE_LOG_CHANNEL
    |   Production: point this at a monitored channel; never log secret values.
    */
    'logging' => [
        'enabled' => env('COREVISYS_LICENSE_LOGGING', true),
        'channel' => env('COREVISYS_LICENSE_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Notifications
    |--------------------------------------------------------------------------
    | Sent ONLY from the scheduled health check (corevisys:license:check) — never
    | from the verifier, the middleware, or the request path. The Phase 5 spec's
    | "log_channel" IS `logging.channel` above; there is no separate key.
    |
    | notifications.enabled: master switch. Default: false (notifications are
    |   opt-in; the package only logs until an operator enables them).
    |   Env: COREVISYS_LICENSE_NOTIFICATIONS
    | notifications.channels: delivery channels. Allowed: log, mail. No other
    |   channel (and no third-party notifier) is supported. Default: ['log'].
    |   Env: COREVISYS_LICENSE_NOTIFICATION_CHANNELS (comma-separated)
    | notifications.mail_recipients: recipients used when 'mail' is enabled.
    |   Default: [] (none). Env: COREVISYS_LICENSE_NOTIFICATION_RECIPIENTS
    | notifications.throttle_interval: seconds between repeated notifications
    |   for the SAME failure reason_code. Default: 3600.
    |   Env: COREVISYS_LICENSE_NOTIFICATION_THROTTLE
    */
    'notifications' => [
        'enabled' => env('COREVISYS_LICENSE_NOTIFICATIONS', false),
        'channels' => ($list(env('COREVISYS_LICENSE_NOTIFICATION_CHANNELS')) ?: ['log']),
        'mail_recipients' => $list(env('COREVISYS_LICENSE_NOTIFICATION_RECIPIENTS')),
        'throttle_interval' => env('COREVISYS_LICENSE_NOTIFICATION_THROTTLE', 3600),
    ],

];
