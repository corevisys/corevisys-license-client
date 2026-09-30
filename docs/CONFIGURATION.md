# Configuration Reference

All configuration lives in `config/corevisys-license.php`. Publish it with:

```
php artisan vendor:publish --tag=corevisys-license-config
```

The package is safe under `php artisan config:cache`: every value is read via
`config()` at runtime, and no `env()` call exists outside the config file.

## Policy: secrets

**Real secrets exist only in the server's environment — never in the
repository, CI logs, or published config.** Provide secrets through environment
variables (or your secret manager). The published config file and the
repository must contain placeholders only.

## Security: license key handling

The raw license key is the application's most sensitive value. The package
treats it as a secret everywhere it touches:

- **At rest.** The key is encrypted with `Crypt` (Laravel `APP_KEY`) before it
  is written to the `corevisys_license_cache` table or the cache store. A
  DB/cache dump never exposes the plaintext key while `APP_KEY` is held
  separately. Rotating `APP_KEY` makes previously stored ciphertext
  undecryptable; the stored key then reads as absent and the license must be
  re-activated.
- **The fallback mirror stores no key at all.** When the storage fallback is
  active, the mirrored record carries only the signed payload and metadata —
  the encrypted key column is stripped before the mirror write. The fallback
  exists for offline verification, not key custody.
- **Never in output.** The key never appears in logs, exceptions, command
  output, HTTP responses, Blade views, JS, notifications, or flashed/`old()`
  session data. Free-form text destined for a log or a user-facing surface is
  passed through `CoreVisys\License\Support\LogSanitizer`, and keys are masked
  by `CoreVisys\License\Support\LicenseKeyRedactor`.
- **Activation form.** The key field is `type="password"` with
  `autocomplete="new-password"`. On a failed submit the key is **not**
  re-populated through `old()`/flash — only non-secret fields (the CSRF token)
  survive a re-render, and the validation error message is generic.
- **No caching of activation responses.** The activation screen sets
  `Cache-Control: no-store` and `Pragma: no-cache` so a shared or browser cache
  never retains a page that reflects license state.
- **Boot validation never echoes secrets.** A configuration failure names the
  offending key and the reason, never a configured value.

> The compatibility checker, the doctor command, and the status command report
> the presence and metadata of a key (for example `key_id`) — never the key or
> its ciphertext.

## Keys

| Config key | Env var | Default | Purpose | Production guidance |
|---|---|---|---|---|
| `config_version` | — | `1` | Config schema version the package expects | Leave at the packaged value; bump only on a schema change. |
| `server_url` | `COREVISYS_LICENSE_SERVER_URL` | `https://license.corevisys.com` | License server base URL | Required. Use https. Override only for a self-hosted server. |
| `product_code` | `COREVISYS_PRODUCT_CODE` | — | Product identifier for your app | Required. Set in the environment; never commit. |
| `license_key` | `COREVISYS_LICENSE_KEY` | — | Optional pre-provisioned key | Secret. Prefer the activation screen; never commit the value. |
| `api_version` | `COREVISYS_LICENSE_API_VERSION` | `v1` | API version path segment | Change only when the server negotiates a new version. |
| `client_version` | `COREVISYS_LICENSE_CLIENT_VERSION` | `1.0.0` | Client version reported to server | Keep aligned with the installed package. |
| `required_env_keys` | — | `COREVISYS_PRODUCT_CODE → product_code` | Manifest of required env vars | Read by the checker/doctor; reports names only. |
| `connection_timeout` | `COREVISYS_LICENSE_TIMEOUT` | `10` | Seconds to wait for the server | Keep small so the server never blocks requests. |
| `verify_ssl` | `COREVISYS_LICENSE_VERIFY_SSL` | `true` | Verify server TLS certificate | MUST remain `true`. |
| `max_response_bytes` | `COREVISYS_LICENSE_MAX_RESPONSE_BYTES` | `1048576` | Response body size cap | Raise only if a valid payload exceeds it. |
| `retry.times` | `COREVISYS_LICENSE_RETRY_TIMES` | `3` | Retry attempts for transient failures | Leave as-is unless the server is slow. |
| `retry.base_delay_ms` | `COREVISYS_LICENSE_RETRY_BASE_DELAY_MS` | `250` | Initial backoff | Leave as-is. |
| `retry.max_delay_ms` | `COREVISYS_LICENSE_RETRY_MAX_DELAY_MS` | `4000` | Maximum backoff | Leave as-is. |
| `auto_activate` | `COREVISYS_LICENSE_AUTO_ACTIVATE` | `false` | Activate automatically on boot | Prefer explicit activation. |
| `auto_check` | `COREVISYS_LICENSE_AUTO_CHECK` | `true` | Register the scheduled check | Keep `true`; ensure the scheduler runs. |
| `check_interval` | `COREVISYS_LICENSE_CHECK_INTERVAL` | `86400` | Seconds between scheduled checks | Daily is usually enough. |
| `grace_period` | `COREVISYS_LICENSE_GRACE_PERIOD` | `72` | Local offline grace window (hours) | Keep short; may only shorten the server boundary. |
| `allow_offline_verification` | `COREVISYS_LICENSE_ALLOW_OFFLINE` | `true` | Permit the frozen offline rule | Keep `true` for resilience; `false` forces an online check. |
| `cache_driver` | `COREVISYS_LICENSE_CACHE_DRIVER` | `database` | `database` (table) or `cache` (store) | `database` is durable; `cache` must use a persistent store. |
| `cache_store` | `COREVISYS_LICENSE_CACHE_STORE` | `null` | Cache store used in `cache` mode | Set an explicit persistent store. |
| `cache_fallback_store` | `COREVISYS_LICENSE_CACHE_FALLBACK_STORE` | `file` | Secondary store consulted only when the primary store throws | Must differ from the primary store in `cache` mode. Empty disables the fallback. The packaged default (`file`) is tolerated when it merely coincides with the framework's default cache store; the doctor then warns that the fallback is effectively disabled. |
| `cache_key` | — | `corevisys.license.cache` | Cache-store key for the signed payload | Not a secret. |
| `public_key_cache_key` | — | `corevisys.license.public_key` | Cache-store key for the public key set | Not a secret. |
| `fingerprint.algorithm` | `COREVISYS_LICENSE_FINGERPRINT_ALGO` | `sha256` | Fingerprint hash | `hmac-sha256` needs the secret below. |
| `fingerprint.hmac_secret` | `COREVISYS_LICENSE_FINGERPRINT_SECRET` | — | Secret for `hmac-sha256` | Secret. Server environment only. |
| `fingerprint.include_domain` | — | `true` | Include domain in fingerprint | Keep `true`. |
| `fingerprint.include_ip` | — | `true` | Include IP in fingerprint | Keep `true` unless the app moves hosts often. |
| `fingerprint.include_app_key` | — | `false` | Include `APP_KEY` in fingerprint | Keep `false`; makes rotation brittle. |
| `fingerprint.include_machine_data` | — | `false` | Include machine data | Keep `false` unless required. |
| `fingerprint.strip_www` | — | `true` | Strip leading `www.` | Keep `true`. |
| `signature.algorithm` | `COREVISYS_LICENSE_SIGNATURE_ALGO` | `rsa` | Response signature algorithm | RSA-SHA256 only. Never change. |
| `signature.timestamp_tolerance` | `COREVISYS_LICENSE_TIMESTAMP_TOLERANCE` | `300` | Allowed clock skew (seconds) | Keep tight. |
| `signature.public_key_cache_ttl` | `COREVISYS_LICENSE_PUBLIC_KEY_TTL` | `86400` | Public key cache TTL (seconds) | Shorter = faster revocation, more requests. |
| `middleware.redirect_route` | — | `null` | Route name for denied web requests | Point at a page NOT behind `corevisys.license`. |
| `middleware.abort_status` | — | `403` | Status for denied JSON requests | `403` is the safe default. |
| `middleware.bypass_in_local` | `COREVISYS_LICENSE_BYPASS_LOCAL` | `false` | Skip enforcement when local | MUST remain `false` in production. |
| `ui.enabled` | `COREVISYS_LICENSE_UI_ENABLED` | `true` | Enable the activation screen | Turn off if you build your own UI. |
| `ui.route_prefix` | `COREVISYS_LICENSE_UI_PREFIX` | `license` | Activation route prefix | Keep the activation route open (never behind `corevisys.license`). |
| `ui.route_name` | — | `corevisys.license.activate` | Named activation route | The default deny redirect target. |
| `ui.middleware` | — | `['web']` | Middleware for the activation page | Add `auth` if only admins may activate. |
| `logging.enabled` | `COREVISYS_LICENSE_LOGGING` | `true` | Emit package log events | Keep `true` so operators see issues. |
| `logging.channel` | `COREVISYS_LICENSE_LOG_CHANNEL` | `stack` | Log channel used by the package | Point at a monitored channel. |

## Validation at boot

The resolved config is validated when the provider registers. These values fail
fast with a clear message that never includes a secret:

- `server_url` — must be an absolute `http`/`https` URL with a host.
- `grace_period` — must be a non-negative whole number of hours.
- `cache_driver` — must be `database` or `cache`.
- `signature.algorithm` — must be `rsa`.
- `cache_fallback_store` — when set, must name a configured cache store, and in
  `cache` driver mode must differ from the primary store *after resolution* (an
  explicit `cache_store`, else the application's `cache.default`). The packaged
  default (`file`) is tolerated when it only coincides with the framework's own
  default store, because that is the out-of-the-box state, not a configured
  collision; `corevisys:license:doctor` warns in that case. Empty disables it.

Empty-string environment values are normalized to the documented default (for
example `COREVISYS_LICENSE_SERVER_URL=` still yields the default URL), so only
genuinely malformed **non-empty** values fail. Missing optional values (for
example `license_key`, `cache_store`, `fingerprint.hmac_secret`) are tolerated.

## Storage fallback and the offline rule

`cache_fallback_store` is a **storage location, not a trust shortcut**. When the
primary store (the `corevisys_license_cache` table, or the `cache` store) throws
a connection/query error, reads consult the fallback; a primary that answers
normally — even with "not found" — is authoritative and never falls back, so a
stale mirror can never resurrect a deactivated or cleared license. Anything read
from the fallback is forced through the full frozen offline rule (signature
verification, a future `offline_valid_until`, `expires_at` absent or in the
future, and local grace not expired). Writes mirror to both stores; a failing
store never throws out of the public API, and failures are logged as the
exception class name and code only (never the message, which can contain SQL and
bound values).

## Diagnostics

```
php artisan corevisys:license:doctor
```

Read-only: reports compatibility, config validity, and (in `database` mode) the
presence of the cache table and every expected column. Exits non-zero on any
failed check. It never writes, never calls the network, and never prints a
secret.
