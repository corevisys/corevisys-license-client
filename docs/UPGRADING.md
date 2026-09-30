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

> To be completed by the staged-rollout phase. This section will document the
> ordered upgrade procedure (backup, config clear, migrate, status check,
> route check, activation test, scheduler verification).
