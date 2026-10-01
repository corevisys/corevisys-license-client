# Upgrading CoreVisys Laravel License Client

## Guiding principle

This package is the **application's licensing trust layer**, not a simple
helper library. It decides whether the host application is allowed to run,
and it verifies cryptographically signed responses from the CoreVisys license
server. Treat it as a **platform dependency** and upgrade it deliberately —
the same way you would upgrade the framework or a payment SDK — not as a
point release you pull in without reading the notes.

## Mandatory rule

**Review `CHANGELOG.md` and the release notes before every upgrade.** Do not
upgrade blind. A change to response shape, field names, signing algorithm,
key metadata, or offline semantics must update the client, the server, the
shared fixture, and both test suites in the same change, and must be
validated by live round-trip evidence — never by suite results alone.

## Pre-upgrade checklist

1. Record the currently installed version:
   `composer show corevisys/laravel-license-client`
2. Read `CHANGELOG.md` for every version between your current version and the
   target, paying attention to each entry's **Upgrade notes**.
3. Diff your published `config/corevisys-license.php` against the package's
   `config/corevisys-license.php` (new keys, changed defaults, `config_version`).
   Compare it for reference against the package's `.env.example`, and see
   `docs/CONFIGURATION.md` for every key, its env var, default, and production
   guidance. Boot now validates `server_url`, `grace_period`, `cache_driver`,
   and `signature.algorithm`; ensure those are valid before upgrading.
4. Check for renamed env variables (old names remain as deprecated fallbacks
   where applicable — see the relevant changelog entry).
5. Check for custom route or middleware overrides in the host app that may
   conflict with package-registered routes or middleware aliases.
6. Confirm the license cache storage exists: the `corevisys_license_cache`
   database table (default `database` driver) or the configured cache store.
7. Confirm the scheduler is present if `auto_check` is enabled, so the
   scheduled `corevisys:license:check` continues to run.

## Migration safety

Upgrading a licensed install touches the license cache table, so treat
migrations as production risk:

1. **Take a database backup first** and confirm it restores. The migration steps
   are additive, but a backup is your only rollback for data.
2. **Preview the SQL**: run `php artisan migrate --pretend` and read every
   statement before running it for real.
3. **Never run `migrate:fresh`** (or any destructive reset such as `db:wipe`,
   `migrate:refresh`, or manually dropping tables) against a live database.
   Those commands delete the license cache and, on a locked install, can leave
   the application unable to run.
4. The package ships its migrations via `loadMigrationsFrom()`. Publishing them
   with `vendor:publish --tag=corevisys-license-migrations` **and** using
   `loadMigrationsFrom()` together is safe: the migrations are idempotent
   (guarded with `Schema::hasTable` / `Schema::hasColumn`), so re-running or a
   partially-applied state does not error.
5. Both migrations are upgrade-safe: the create migration skips if the table
   already exists, and the add-columns migration skips missing columns and is a
   safe no-op when the base table does not exist. Their `down()` methods are
   destructive (they drop the table/columns and the cached state they hold),
   are guarded, and should only be run after a backup.

Pre-flight (read-only):

```
php artisan migrate:status
php artisan migrate --pretend
php artisan corevisys:license:doctor   # compatibility + cache schema, no writes
```

## Published views and config

`vendor:publish` copies the packaged files into the application once; it does
**not** keep them in sync afterwards. After an upgrade:

1. If you published the activation view
   (`vendor:publish --tag=corevisys-license-views`), the copy under
   `resources/views/vendor/corevisys-license/` is **yours** and is never
   overwritten. Re-publish it to pick up security-relevant markup changes
   (for example the `Cache-Control: no-store` behaviour and the secret-safe
   form handling documented in `docs/CONFIGURATION.md`). Diff the packaged view
   against your copy before replacing it, so local styling is not lost.
2. If you published the config (`vendor:publish --tag=corevisys-license-config`),
   the published `config/corevisys-license.php` is likewise frozen at the
   version you published. A new release that adds keys or bumps `config_version`
   will log a boot-time warning until you re-publish or manually merge the new
   keys. Merge deliberately; do not blindly overwrite.

> **Caution — `vendor:publish --force`.** `--force` overwrites the published
> copies in place, destroying any local edits to the config or the view with no
> prompt and no backup. Prefer a normal publish into a clean checkout, diff it
> against your published files, and merge by hand. If you must use `--force`,
> commit or back up the published files first.

## Upgrade steps

Run these in order. Nothing here writes to the license server, and the two
`--pretend`/`doctor` commands are read-only. For the full staged rollout with a
canary host and rollback triggers, see [`ROLLOUT.md`](ROLLOUT.md).

1. **Back up.** Confirm the database backup restores. See *Migration safety*.
2. **Freeze config.** On the deployment target run
   `php artisan config:clear` so no stale cached config survives the upgrade
   (the package never reads `env()` at runtime, so `config:cache` is safe once
   the values are final — see step 6).
3. **Install the new code**: `composer require corevisys/laravel-license-client:<target>`.
4. **Publish/merge** the config and migrations if you publish them:
   `php artisan vendor:publish --tag=corevisys-license-config` (merge, do not
   overwrite) and `--tag=corevisys-license-migrations`. Diff before replacing.
5. **Migrate**: `php artisan migrate --pretend`, read it, then
   `php artisan migrate`. Never `migrate:fresh`/`migrate:refresh` on a live DB.
6. **Re-cache config** (if you cache it): `php artisan config:cache`.
7. **Diagnose**: `php artisan corevisys:license:doctor` — expect exit `0`.
8. **Status**: `php artisan corevisys:license:status` — confirm the cached
   record is intact and `active` (this reads local state only).
9. **Routes**: `php artisan route:list --path=license` — confirm the activation
   screen is registered and, if enforcement is in use, that the routes carrying
   `corevisys.license` are the ones you expect.
10. **Activation test**: activate one canary/staging host end-to-end, then run
    the post-upgrade smoke checklist in [`ROLLOUT.md`](ROLLOUT.md).
11. **Scheduler**: confirm `php artisan schedule:list` shows
    `corevisys:license:check` when `auto_check` is enabled.

## Upgrading from 1.0.1

1.0.1 → this release is a **security-relevant** upgrade: the fast path and the
offline path now trust only the **signed** payload, not the unsigned cache
columns. Read `CHANGELOG.md` → *Security* before upgrading.

What changes for an install coming from 1.0.1:

- **A hand-edited or stale cache row may now be denied (or lose features)**
  where 1.0.1 served it. This is intended and fail-closed; no manual data
  migration is needed. The next successful online check rewrites a clean,
  signed row.
- **A verified `active` payload with no signed `offline_valid_until`** is no
  longer served offline; it is forced onto the online path.
- **A forward-dated `last_successful_check_at`** (more than 300s in the future)
  now fails the offline path closed.
- **New config keys** (`config_version`, `cache_fallback_store`,
  `middleware.excluded_routes`, `notifications.*`, `required_env_keys`) default
  safely. If you published the config, merge them or expect a boot-time
  `config_version` warning until you do.
- **No public method signature changed.** The trust rules changed, not the API.
- **Route gating is new (alias-only).** Nothing is enforced until a route opts
  in with `corevisys.license`. See [`ROUTES.md`](ROUTES.md) for the default-open
  `excluded_routes` list so you do not lock yourself out of the activation
  screen or the auth/health routes.

Ordered steps: follow *Upgrade steps* above. After step 8, run the post-upgrade
smoke checklist in [`ROLLOUT.md`](ROLLOUT.md) on one canary host before rolling
to the fleet.

## Response contract changes

The wire contract between the client and the CoreVisys license server is frozen
in [`tests/Fixtures/license-response-contract.json`](../tests/Fixtures/license-response-contract.json)
and asserted by `LicenseActivationTest::test_shared_response_contract_fixture_matches_client_expectations`.

- **Envelope** fields (exact, in order): `success`, `status`, `message`, `data`,
  `signature`, `key_id`, `algorithm`.
- **`data`** fields (exact, in order): `status`, `license_id`, `product_code`,
  `license_type`, `expires_at`, `features`, `issued_at`, `offline_valid_until`,
  `is_grace_period`.

`data` is the canonicalised, signed object: keys are sorted and encoded with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` before signing, and the client
verifies against exactly that byte sequence. Anything outside `data` (`message`,
for example) is **unsigned** and must never drive trust.

**A contract change is a four-part, single-change operation.** When any envelope
or `data` field is added, removed, renamed, or has its type changed, the same
change must update, together: (1) the client (parsing in
`LicenseResponse`/DTOs and the verifier's canonicalisation), (2) the server that
emits the envelope, (3) the shared fixture
`tests/Fixtures/license-response-contract.json`, and (4) both test suites (the
client fixture assertion and the server's own) — then be validated by **live
round-trip evidence** against the real server, never by suite results alone (the
`live`-group test is excluded from the default suite by design; see
[`TEST_COVERAGE.md`](TEST_COVERAGE.md)).

Removing or renaming a `data` field is a breaking change for a pinned client: an
older client that still expects the field will fail verification. Treat removing
a field as a major-version event; add new fields additively (the client overlays
only the fields it knows, unknown fields are ignored, and a field absent from
`data` resolves to null, never to an unsigned column).

## What this release does not verify

The test suite proves the client's behaviour in-process (see
[`TEST_COVERAGE.md`](TEST_COVERAGE.md)). It does **not** prove, on its own:

- a live activate → check → pulse round trip against the real license server
  (the `live`-group test is excluded from the default suite and requires
  `COREVISYS_TEST_LICENSE_KEY` + `COREVISYS_TEST_SERVER_URL`),
- `php artisan config:cache` on a real host, or
- `corevisys:license:doctor` against a live production database.

Those three must be signed off by the staged rollout, not by this document.
