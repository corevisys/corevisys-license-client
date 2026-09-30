# Changelog

All notable changes to `corevisys/laravel-license-client` are documented
here. This project adheres to [Semantic Versioning](https://semver.org/).

## Not yet done

> Status honesty: Phases 5-9 are **NOT APPROVED and NOT COMPLETE**. None of the
> work in this branch may be described as delivering them.

- Phase 5 (logging/monitoring events, scheduled health check, admin
  notifications with throttling): implemented on this branch, NOT yet approved
  by the reviewer. The `[Unreleased]` entries describe the implementation; they
  are not a claim of delivery until reviewed.
- Phase 6 (route-enforcement lockout-safety: excluded routes, redirect-loop
  guard, offline-under-outage allow): not approved, not complete.
- Phase 7 (exhaustive test-gap fill and CI version matrix): not approved, not
  complete.
- Phase 8 (`corevisys:license:status`/`:check` operational hardening and
  `docs/RUNBOOK.md`): not approved, not complete. `docs/UPGRADING.md` still
  carries a "to be completed" placeholder for the staged upgrade procedure.
- Phase 9 (`docs/ROLLOUT.md` and the staged-rollout guide): not approved, not
  complete.

Some code touching these areas exists in this branch but is UNREVIEWED; it must
not be treated as delivered. The `[Unreleased]` entries below describe only the
approved Phase 1-4 work and the tests that actually prove it.

## [Unreleased]

### Security

- The `cache_fallback_store` presence signal is now derived into a boolean
  config key (`cache_fallback_store_explicit`) at config-build time, so no
  `env()` call remains under `src/` and the decision survives `config:cache`.
- A cache fallback that resolves to the SAME store as the primary is now
  disabled — it cannot survive the primary failure it exists for. The packaged
  default colliding with the app default is tolerated by boot validation and the
  doctor reports why; an explicit collision fails validation.
- Log/error sanitizing: an explicitly supplied secret is now redacted even when
  it lands in an enum / "safe" context key or is shorter than the 24-char token
  heuristic, and the activation failure path passes the submitted license key as
  a known secret so a short key echoed back by a lower layer is stripped from
  both the stored `last_error_message` and the log context.

### Added

- `config_version` config key (integer, default `1`) and an expected-version
  constant (`CompatibilityChecker::EXPECTED_CONFIG_VERSION`). On boot, a
  published config whose `config_version` is missing or differs from the
  expected value logs a warning naming both values, then continues. It never
  throws, and apps that never published the config are not warned.
- `required_env_keys` config map: the env variable names the package needs,
  each mapped to the config key that proves it is present. Reported by name
  and presence only — never values.
- `CoreVisys\License\Support\CompatibilityChecker`: a pure, side-effect-free
  checker returning `[name, status (pass|warn|fail), message]` rows for the
  PHP version, the Laravel major version, `config_version`, and required env
  keys. Version inputs are injectable so tests can simulate mismatches.
- `docs/UPGRADING.md`: upgrade policy, mandatory release-notes review, and a
  pre-upgrade checklist.
- `.env.example`: every license environment variable, grouped (connection,
  credentials, HTTP client, activation/check, cache, grace, fingerprint,
  signature, monitoring, routes/UI) and commented with placeholders only. A
  test fails if any `env()` in the config is missing from it.
- `CoreVisys\License\Support\ConfigValidator`: boot-time validation of the
  resolved config (server URL, grace period, cache driver, signature
  algorithm). Failure messages describe the problem and never include a
  secret value.
- `CoreVisys\License\Support\ConfigValue`: reusable deprecated-name fallback
  reader. Reads the new key, falls back to an old key when only the old one is
  set, and logs a deprecation warning naming the old and new variables (names
  only, never values). No real key is renamed in this release.
- `docs/CONFIGURATION.md`: a table of every config key with env var, default,
  purpose, and production guidance, plus a plain-language description of the
  offline trust windows (which of the signed boundary, signed expiry, and local
  grace wins, and the fact that local grace can only shorten the boundary).
- `tests/Feature/LicenseOfflineTrustTest.php`: acceptance tests proving the
  offline/grace path trusts the signed payload only — a signed `suspended` is not
  overridden by an unsigned `active` column; an unsigned boundary/expiry cannot
  extend (or shorten) signed trust; the local grace window cannot extend a signed
  boundary; and a fallback record is accepted only when it passes the full A6
  rule.
- `cache_fallback_store` config key (env `COREVISYS_LICENSE_CACHE_FALLBACK_STORE`,
  default `file`): a secondary cache store used as a resilient storage location
  when the primary store fails. See the Changed/Fixed notes below and
  `docs/CONFIGURATION.md`.
- `CoreVisys\License\Support\ConfigDefaults`: empty-string env values normalize
  to the documented default, so a blank value never overrides a default.
- `php artisan corevisys:license:doctor`: read-only compatibility, config, and
  cache-schema diagnostics. Exits non-zero on any failed check.
- `.gitignore`: now ignores `.env`, `.env.*` (except `.env.example`), `*.pem`,
  `*.key`, and local license file/cache storage paths.
- `CoreVisys\License\Support\LicenseKeyRedactor`: one choke point for every
  human-facing representation of a key. `mask()` reveals ONLY the trailing four
  characters, and only for keys of 16+ characters (shorter, empty, or null keys
  are fully `[redacted]`; no leading characters are ever shown);
  `fingerprint()` returns a deterministic, key-free HMAC-SHA256 (12 hex chars)
  keyed by the application key, so the same key yields a stable value per
  install but a different value across installs. Unit-tested for short, empty,
  null, and minimum-length keys, and for fingerprint stability/variation.
- `CoreVisys\License\Support\LogSanitizer`: scrubs known secrets, email
  addresses, and long opaque tokens from free-form diagnostic text and (deeply)
  from log context arrays before anything is stored or logged.
- `notifications` config block (`enabled`, `channels`, `mail_recipients`,
  `throttle_interval`) with env vars `COREVISYS_LICENSE_NOTIFICATIONS`,
  `COREVISYS_LICENSE_NOTIFICATION_CHANNELS`,
  `COREVISYS_LICENSE_NOTIFICATION_RECIPIENTS`,
  `COREVISYS_LICENSE_NOTIFICATION_THROTTLE`. Defaults are safe (disabled,
  `log`-only, no recipients, 1-hour throttle).
- `CoreVisys\License\Services\LicenseNotifier` and
  `CoreVisys\License\Notifications\LicenseFailureNotification`: failure
  notifications sent **only** from the scheduled health check, throttled per
  `reason_code` with an atomic cache lock (fails open if the cache is broken),
  carrying no secret. A recovery is logged once and clears the throttle.
- Structured monitoring log events with one entry per event and a `reason_code`:
  activation success (`info`), activation failure (`warning`), license expired
  (`warning`), grace active / grace expired (`warning`), server unavailable
  (`error`), and signature failure (`error`, distinguishing unknown / revoked
  `key_id` in the log's `reason_code`).
- Tests: `tests/Feature/LicenseMonitoringLogTest.php`,
  `tests/Feature/LicenseNotificationTest.php`,
  `tests/Unit/NotificationConfigTest.php`, and the
  `tests/Concerns/CapturesLogs.php` helper.

### Changed

- Every key in `config/corevisys-license.php` is now documented in place with
  its purpose, default, env var name, and production guidance. No default or
  behavior changed.
- Documentation: the client baseline is recorded as 174 tests / 409 assertions
  (previously 153/359 after the Phase 1-4C checkout, 46/81 after Phase 2, and
  45/77 originally).
- `LicenseVerifier` offline-grace log calls now pass a real context array (the
  `log()` helper signature is `(level, message, ?Throwable, array)`); the two
  offline-grace warnings previously passed the context in the exception slot
  and would have raised a `TypeError`. No verification logic changed.
- Monitoring log levels now match the spec: signature-verification failure is
  logged at `error` (was `warning`), and an unreachable server is now logged at
  `error` (it previously emitted only an event); activation success is logged at
  `info`. The returned `LicenseStatus` and all public signatures are unchanged.
- `LogSanitizer::scrubContext` now leaves structured identifier / enum context
  keys untouched (`reason_code`, `previous_reason_code`, `status`,
  `product_code`, `key_id`, `license_id`, `license_type`). Those values are not
  secrets, and token-scrubbing them erased the operator signal
  (e.g. `signature_verification_failed` became `[redacted-token]`).
- Documentation baseline is now 219 tests / 537 assertions (previously 174/409,
  then 153/359, 46/81, and 45/77 originally).

### Fixed

- The storage fallback collision check now compares `cache_fallback_store`
  against the **resolved** primary cache store (an explicit `cache_store`, or
  the application's `cache.default` when `cache_store` is null), matching
  `ConfigValidator` and the doctor command. Previously, with `cache_store`
  unset, a fallback equal to the app's default store was silently written to
  the same store. The packaged default (`file`) colliding with an app default of
  `file` stays tolerated (out-of-the-box state); a deliberately-set collision is
  dropped so the mirror is a no-op. No stored data or trust rule changed.
- Storage resilience: when the primary store (database table, or cache store in
  `cache` mode) throws a connection/query error, `LicenseStorage` now falls back
  to `cache_fallback_store` for reads and mirrors writes to it. A primary that
  answers — even with "not found" — is authoritative and never falls back.
  Records read from the fallback are forced through the full frozen offline rule
  (A6); a failing store never throws out of the public API. Failures are logged
  as the exception class name and code only (never the message, which can
  contain SQL and bound values).
- Both cache migrations are now idempotent (`Schema::hasTable` /
  `Schema::hasColumn` guards) and safe against partial states; destructive
  `down()` methods are guarded and documented.
- `corevisys-license-migrations` now publishes both real migration files. The
  tag previously referenced a non-existent `create_corevisys_license_cache_table.php`
  path (missing the `2026_01_01_000000_` timestamp prefix) and did not publish
  the `add_offline_contract_fields` migration at all, so `vendor:publish` and
  `corevisys:license:install` silently produced no migration files.
- Offline/grace trust now derives entirely from the **signed** payload. The
  offline path (`LicenseVerifier::fallbackToCache`) previously read the
  **unsigned** cache columns — `status`, `expires_at`, and `offline_valid_until`
  — so a hand-edited row could be served as `active`, could extend the offline
  boundary, or (via the unsigned expiry) could shorten trust. It now overlays
  `status`, `expires_at`, and `offline_valid_until` from the verified signed
  payload and evaluates every A6 condition (signed `offline_valid_until` in the
  future; signed `expires_at` absent or in the future; local `grace_period`
  window, anchored to `last_successful_check_at`, not expired) against that
  payload alone. The local grace window can only shorten the outward boundary.
  The shared `withinGracePeriod()` helper and the redundant
  `cachedSignatureStillValid()` were replaced by a single
  `trustedOfflineRecord()` that fails closed. No public signature changed.

### Deprecated

- None. (The deprecated-config fallback mechanism is available but no key is
  renamed in this release.)

### Removed

- None.

### Security

- Fast-path cache trust (security-relevant upgrade). The pre-existing fast path
  (a cached record whose `next_check_at` was still in the future) trusted the
  **unsigned** cache columns — `status`, `expires_at`, and `next_check_at` —
  without re-verifying the cached signature. An attacker with write access to
  the cache store could hand-edit `status` to `active` and push `next_check_at`
  into the future to keep serving a "valid" license with no server contact.
  Addressed in this Unreleased release: the fast path now re-verifies the cached
  signed payload against LOCAL key material, takes `status`/`expires_at` from
  the verified payload instead of the columns, and forces an online re-check
  once the signed `offline_valid_until` has passed. Stated only as far as the
  tests prove it: revoked-key, unknown-key, tampered-payload, and
  edited-`next_check_at`/`status`/`offline_valid_until` cases are covered by
  `tests/Feature/FastPathTrustTest.php`. **Installs upgrading should treat this
  as a security-relevant upgrade.**
- Fast-path signed-boundary rule (security-relevant). The fast path no longer
  reads the **unsigned** `offline_valid_until` column, and a verified `active`
  payload whose **signed** `offline_valid_until` is null or absent is no longer
  served from cache: it is forced onto the normal online path. (A6: a cached
  `status: active` is never sufficient on its own.) Non-active statuses keep
  their previous behaviour. Stated only as far as the tests prove it:
  `tests/Feature/FastPathSignedBoundaryTest.php` covers the future-signed-boundary
  control, the active-without-signed-boundary server-down rejection, and the
  active-without-signed-boundary server-up case following the server's answer.
- The raw license key can no longer reach any operator- or browser-facing
  surface. Concretely:
  - The activation screen's key input is `type="password"` with
    `autocomplete="new-password"`, and the submitted key is excluded from old
    input / flashed session data (`LicenseActivationController` now keeps only
    the CSRF token as safe input). A failed submit is re-rendered with a generic
    message and never echoes the key.
  - `corevisys:license:activate` prompts for the key with a hidden `secret()`
    question when no `key` argument is given, and prints only the redacted mask
    (never the raw key) in its result table.
  - Activation failures surfaced to users are generic
    ("Activation failed. Please check the key and try again."); the server's
    key-bearing validation text is never shown and never logged unredacted.
  - Server/browser responses, Blade views, and inline JS contain no key or
    signature internals; the package ships only a public key (the fixture's
    test private key lives under `tests/`).
  - A dedicated non-disclosure suite drives a distinctive sentinel key through
    the success, failure, and exception paths and asserts it is absent from
    logs, the rendered page, and the session.
- Offline trust boundary (security-relevant). The cached offline path now trusts
 the **signed** payload only (A6). Previously the unsigned columns decided
 validity: a `status` column edited to `active` was served as a valid license,
 an unsigned `offline_valid_until`/`expires_at` could move the boundary, and a
 fallback record bypassed the signed boundary entirely. All of these were
 reachable with write access to the cache store and no server contact.
 `tests/Feature/LicenseOfflineTrustTest.php` proves the signed-only rule for
 both the primary and the fallback store. **Installs upgrading should treat this
 as a security-relevant upgrade.**
- Signed entitlement overlay (security-relevant). On BOTH the fast path and the
 offline path, `features`, `license_id`, `license_type`, `product_code`, and
 `is_grace_period` were still read from the **unsigned** cache columns, so DB
 write access could grant features the server never issued (for example editing
 a `features` JSON column to add a premium capability). All five, plus `status`,
 `expires_at`, `offline_valid_until`, and `issued_at`, are now overlaid from the
 verified signed payload; a field absent from the payload resolves to
 null/empty, never the column. Proven by
 `tests/Feature/FastPathEntitlementTest.php` (fast path, offline path, and the
 fallback store). **Installs upgrading should treat this as a security-relevant
 upgrade.**
- Future-dated `last_successful_check_at` rejected (security-relevant). The
 offline path anchored the local grace window to `last_successful_check_at`; a
 forward-dated value (clock tamper or a forward clock skew) could keep the local
 grace window open indefinitely. A value more than 300 seconds in the future now
 fails the offline path closed. Proven by
 `test_future_dated_last_successful_check_at_is_rejected()` in
 `tests/Feature/LicenseOfflineTrustTest.php`.
- Maximum honour time documented (no behaviour change). The offline path's
  maximum honour time is `min(signed offline_valid_until, last_successful_check_at
  + grace_period)`, evaluated against the VERIFIED signed payload
  (`LicenseVerifier::trustedOfflineRecord()` conditions 2 and 4). The fast path
  applies NO local grace: in normal operation it serves until the signed
  `offline_valid_until` passes, and a DB-write attacker who pins `next_check_at`
  into the future can never extend a still-signed `active` record past its signed
  boundary — the signed-boundary guard forces the normal online path
  (`LicenseVerifier::fastPathStatus()`; proof:
  `test_fresh_last_check_cannot_resurrect_a_past_signed_boundary()` plus the
  five-day offline/fast-path cases in `tests/Feature/LicenseOfflineTrustTest.php`).

### Upgrade notes

- Offline trust on upgrade: the offline/grace path now reads the signed payload
  for `status`, `expires_at`, `offline_valid_until`, `issued_at`, `license_id`,
  `license_type`, `product_code`, `features`, and `is_grace_period`, instead of
  the unsigned columns. A cached row whose **unsigned** columns disagreed with
  its signed payload (for example a hand-edited `features` or `status`) is now
  resolved according to the signed payload, so it may be denied (or lose
  features) where it was previously — incorrectly — allowed. This is the
  intended, fail-closed behavior; no manual action is required. Proven by
  `tests/Feature/LicenseOfflineTrustTest.php` and
  `tests/Feature/FastPathEntitlementTest.php`.
- Database-mode fallback writes: installs using `cache_driver = database` mirror
  every write to `cache_fallback_store` (default `file`) as a resilience mirror.
  The mirror never stores the license key. To disable the mirror, set
  `COREVISYS_LICENSE_CACHE_FALLBACK_STORE` to an empty value.
- Security behavior changes: the activation form no longer repopulates the key
  field after a failed submit (it never did carry the value forward by design,
  but the input is now explicitly excluded), and activation failures show a
  generic message instead of the server's text. If you overrode the activation
  view or controller, port the `type="password"` / `autocomplete="new-password"`
  input and the `only(['_token'])` safe-input exclusion.
- Config changes: a new `config_version` key, a `required_env_keys` map, and a
  `cache_fallback_store` key are added with safe defaults. Republish the config
  (or add the keys to an existing published copy) to pick them up. Existing
  defaults are unchanged. Empty-string env values now fall back to the
  documented default instead of overriding it. Boot now validates `server_url`,
  `grace_period`, `cache_driver`, `signature.algorithm`, and
  `cache_fallback_store`; an invalid value fails fast with a message that
  contains no secrets. A copy of the new `.env.example` is available.
- Env changes: none (no variable renamed). New `.env.example` documents the
  existing variables.
- Middleware changes: none.
- Migration changes: none. However, the `corevisys-license-migrations` publish
  tag is fixed; sites that previously ran `corevisys:license:install` and saw
  no migration published should re-run it (the package ships the migrations via
  `loadMigrationsFrom`, so existing installs already migrated normally).
- Route behavior changes: none.
- Cache behavior on upgrade: installs whose cached rows were written by a
  previous version may lack a **signed** `offline_valid_until`. After upgrading,
  such rows are no longer served from the fast path (see the Security note), so
  the install performs **one online check** the next time it verifies, then
  stores the newly signed boundary. This is expected and is proven by
  `tests/Feature/FastPathSignedBoundaryTest.php`; no manual action is required.
- Offline trust on upgrade: the offline/grace path now reads the signed payload
  instead of the unsigned columns. A cached row whose **unsigned** `status`,
  `expires_at`, or `offline_valid_until` disagreed with its signed payload (for
  example a hand-edited row) is now resolved according to the signed payload, so
  it may be denied where it was previously (incorrectly) allowed. This is the
  intended, fail-closed behavior; no manual action is required. Proven by
  `tests/Feature/LicenseOfflineTrustTest.php`.

## [1.0.1] - 2026-09-29

### Changed

- The `package_version` reported to the licensing server is now resolved at
  runtime from Composer's installed package metadata
  (`Composer\InstalledVersions`) instead of being hard-coded, so it always
  matches the installed release. A leading `v` is stripped, and dev checkouts
  (`dev-*`) or resolution failures fall back to a stable version constant.
  No verification, signature, cache, or offline-grace logic was changed.

### Added

- Test asserting the reported `package_version` matches `^\d+\.\d+\.\d+$`.

### Upgrade notes

- Config changes: none.
- Env changes: none.
- Middleware changes: none.
- Migration changes: none.
- Route behavior changes: none.
- Security note: this release also carries the later Unreleased offline-trust
  hardening (the offline path trusts the signed payload, not the unsigned
  columns). Installs running `1.0.1` should upgrade for that fix; see the
  `[Unreleased]` Security section. No config, env, middleware, migration, or
  route changes were introduced by it.

## [1.0.0] - 2026-09-29

First stable release. Packagist-ready.

### Added

- Client SDK for the CoreVisys licensing platform: activate, verify, and
  monitor software licenses with cryptographically signed responses.
- `CoreVisysLicense` facade and `LicenseClientInterface` contract, bound
  through `CoreVisysServiceProvider` (Laravel package auto-discovery).
- Services: `LicenseClient` (orchestrator), `LicenseActivator`,
  `LicenseVerifier`, `LicenseHeartbeat`, `FingerprintGenerator`,
  `SignedPayloadVerifier` (RSA-SHA256), `LicenseStorage`, and
  `ApiRequestHandler` (timeouts + retry/backoff).
- Offline grace-period verification against the last signed response,
  fail-closed on missing/invalid/tampered signatures.
- Public-key resolution with rotation (`key_id`) and revocation support,
  cached with a configurable TTL.
- Route middleware `corevisys.license` and `corevisys.feature`, plus a
  built-in web activation screen (`GET`/`POST /license/activate`).
- Artisan commands: `corevisys:license:install`, `:activate`, `:check`,
  `:deactivate`, `:status`, and `:clear-cache`.
- Automatic scheduled `corevisys:license:check` using
  `withoutOverlapping()` and `onOneServer()`.
- Publishable config (`corevisys-license-config`), migrations
  (`corevisys-license-migrations`), and views
  (`corevisys-license-views`).
- Encrypted-at-rest license key storage (`Crypt::encryptString`); keys are
  never written to logs or exception messages.
- Eight typed exceptions and ten lifecycle events.

### Notes

- Supports PHP 8.2+, Laravel 10.x / 11.x / 12.x.
- Only RSA-SHA256 response signing is supported.

### Upgrade notes

- Config changes: none (first release).
- Env changes: none (first release).
- Middleware changes: none (first release).
- Migration changes: none (first release).
- Route behavior changes: none (first release).
