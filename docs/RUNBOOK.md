# Operator Runbook

Day-to-day operation and troubleshooting for the CoreVisys license package.
Everything here is safe to run in production; no command in this document
writes to the license server unless explicitly noted.

## Commands at a glance

| Command | Writes? | Network? | Exit codes |
|---------|---------|----------|------------|
| `php artisan corevisys:license:install` | publishes config/migrations | no | 0 |
| `php artisan corevisys:license:status` | no | no | `0` cached; `1` nothing cached |
| `php artisan corevisys:license:activate` | caches the license | yes | `0` success; `1` failure |
| `php artisan corevisys:license:check` | caches the result; may notify | yes (falls back to cache) | `0` valid; `1` invalid |
| `php artisan corevisys:license:deactivate` | clears cache; calls server | yes | `0` |
| `php artisan corevisys:license:clear-cache` | clears cache | no | `0` |
| `php artisan corevisys:license:doctor` | no | no | `0` all pass; `1` any fail |

## Standard operating procedures

### First-time install

1. `php artisan corevisys:license:install` — publishes `config/corevisys-license.php`
   and the migrations.
2. `php artisan migrate` — creates `corevisys_license_cache` and its columns.
3. Set `COREVISYS_LICENSE_KEY` and `COREVISYS_LICENSE_SERVER_URL` in `.env`.
4. `php artisan corevisys:license:activate --key=…` (or activate from the built-in
   screen at `/license/activate` if `ui.enabled` is true).
5. `php artisan corevisys:license:status` — confirm the cached record is `active`.

### Routine health check

- The package schedules `corevisys:license:check` itself when `auto_check` is true
  (see the schedule section below). You do not need a separate cron entry.
- To force an immediate check: `php artisan corevisys:license:check --force`.
- `php artisan corevisys:license:status` reads only the local cache and never
  contacts the server — use it when the server is unreachable.

### Diagnosing a broken install

Run `php artisan corevisys:license:doctor`. It performs **no writes and no network
calls**. It prints a table of checks:

| Check row | Meaning |
|-----------|---------|
| `compatibility` rows | Running PHP / Laravel / config_version vs the package's supported range. |
| `config_validation` | `ConfigValidator` result for the current config values. |
| `cache_fallback_store` | `warn` only: the fallback store is effectively disabled because it equals the primary. Advisory; does not fail the command. |
| `cache_table` | In `database` mode, that `corevisys_license_cache` exists (and is reachable). |
| `cache_column:*` | A required column is missing — re-run `php artisan migrate`. |
| `storage_schema` | `pass` "Not applicable" when `cache_driver` is `cache`. |

A non-zero exit code means at least one row is `fail`. The doctor never prints the
license key or any secret; database failures are reported as a class name only.

## Configuration caching

`env()`/`getenv()` are **never** called at runtime anywhere under `src/` (a test
enforces this). All environment-derived presence signals are resolved into the
config at config-build time, so `php artisan config:cache` is safe:

1. `php artisan config:cache`
2. `php artisan corevisys:license:status` — confirm the cached record still reads.
3. If the status is wrong after caching, the value was read from the environment
   at runtime rather than baked into the config; re-check the published config.

## Offline grace

The package keeps serving `active` while the server is unreachable, but only until
the **signed** `offline_valid_until` boundary plus the local `grace_period`. Neither
the unsigned status column nor a locally edited `next_check_at` can extend trust
past the signed boundary. See [`TEST_COVERAGE.md`](TEST_COVERAGE.md) for the tests
that prove each rule.

## Key rotation / revocation

- Rotate: the client fetches the new public key by `key_id` on the next check; no
  operator action is required beyond serving the new key from the server.
- Revoke: add the `key_id` to the server's revoked set. The fast path refuses to
  serve `valid` for a revoked or unknown `key_id` and falls through to an online
  check.

## Common failures

| Symptom | Likely cause | Action |
|---------|--------------|--------|
| `status` exits 1 ("No cached license data") | never activated | `corevisys:license:activate` |
| `check` exits 1, status `expired`/`revoked`/`suspended` | server state | resolve with the license server |
| `check` exits 1, log shows `signature_verification_failed` | wrong/rotated public key | confirm the server's key set; the next check refetches |
| `check` exits 1, log shows server unavailable | network outage | verify `server_url`; offline grace keeps the app valid until the signed boundary |
| `doctor` `cache_table` fail "does not exist" | migrations not run | `php artisan migrate` |
| `doctor` `doctor` row about a missing column | partial migration | re-run `php artisan migrate` |
| `doctor` warns "effectively disabled" | fallback == primary store | set a distinct persistent `cache_fallback_store` |
