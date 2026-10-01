# Staged Rollout Guide

How to roll a new release of the license client to a fleet without locking
yourself out. This document is deliberately conservative: a licensing upgrade
can stop an application from running, so it is rolled out like a framework or
payment-SDK upgrade, not like a leaf package.

Companion documents: [`UPGRADING.md`](UPGRADING.md) (what changes and the
ordered steps), [`RUNBOOK.md`](RUNBOOK.md) (day-to-day commands),
[`ROUTES.md`](ROUTES.md) (lockout safety), [`TEST_COVERAGE.md`](TEST_COVERAGE.md)
(what the tests do and do not prove).

## Rollout principles

1. **One canary first.** Upgrade a single non-critical host and keep it there
   long enough to observe a full check cycle before touching anything else.
2. **Config is frozen before code lands.** Run `config:clear` before deploying
   and `config:cache` after, so no stale cached config survives.
3. **Migrations are additive and backed up.** Never `migrate:fresh` /
   `migrate:refresh` / `db:wipe` on a live database.
4. **The activation screen stays reachable.** `middleware.excluded_routes`
   defaults open; verify with `route:list` before enabling enforcement widely.
5. **Rollback is a code rollback plus a cache clear** — the license cache is
   additive, so downgrading the code does not require reverting the migrations.

## Stages

### Stage 0 — Pre-flight (no change)

On the target environment, without deploying:

```
php artisan corevisys:license:status      # expect exit 0 and a cached record
php artisan corevisys:license:doctor      # expect exit 0 (read-only)
php artisan migrate:status                # note pending migrations
php artisan route:list --path=license     # note the activation + protected routes
php artisan schedule:list                 # note corevisys:license:check
```

Record the baseline. Any pre-existing failure here is not caused by the
upgrade; fix it or account for it before proceeding.

### Stage 1 — Canary

1. Deploy the new code to one host.
2. `php artisan config:clear`
3. `php artisan migrate --pretend` → read → `php artisan migrate`
4. `php artisan config:cache` (only if you cache config)
5. `php artisan corevisys:license:doctor` → expect exit `0`
6. Run the **post-upgrade smoke checklist** below on the canary.
7. Leave the canary for at least one full `check_interval` (or run
   `php artisan corevisys:license:check --force` to force the cycle) and confirm
   the logs show a normal check with no `signature_verification_failed` and no
   server-unavailable errors.

### Stage 2 — Expand

Roll to a second, larger cohort (for example 10% of hosts) using the same
per-host sequence. Watch for:

- a rise in `signature_verification_failed` logs,
- a rise in "server unavailable" errors,
- any host whose `status`/`doctor` command starts exiting non-zero,
- a spike in activation redirects at the web edge (a sign of a lockout).

### Stage 3 — Fleet

Once two cohorts are clean for a full cycle, roll to the remainder. Keep the
canary on the new version so a regression is attributable.

## Rollback triggers and procedure

**Trigger** the rollback if any of the following is observed after Stage 1:

- Applications deny protected routes that were allowed before the upgrade.
- The activation screen is unreachable (`403` with no redirect target).
- `doctor` fails with a cache-table/column error that `migrate` did not resolve.
- A sustained rise in `signature_verification_failed` attributable to the
  upgrade (for example a key-set that the new client no longer accepts).

**Procedure**

1. `php artisan config:clear` on the affected hosts.
2. Redeploy the previous code version. The license cache schema is additive, so
   the old code continues to read the existing table.
3. `php artisan corevisys:license:status` → confirm the cached record is intact.
4. If enforcement is blocking access during the incident, add the affected
   route to `middleware.excluded_routes` and `config:clear`, or set
   `middleware.bypass_in_local` on a local environment for emergency repair.
5. Capture the failing `doctor`/`status` output and the relevant log window
   before rolling forward again.

## Post-upgrade smoke checklist

Run this on each canary (and at least once per cohort). Every item is
read-only or a single explicit check except where noted.

- [ ] `php artisan config:clear` has been run before the new code booted, and
      `config:cache` re-run after the config values were final.
- [ ] `php artisan migrate --pretend` output was reviewed; `migrate` completed
      with no errors.
- [ ] `php artisan corevisys:license:doctor` exits `0`.
- [ ] `php artisan corevisys:license:status` exits `0` and shows the expected
      `active` record, `Expires At`, and `Offline Grace Until`.
- [ ] `php artisan route:list --path=license` shows the activation screen, and
      `login`/`logout`/`health`/`up` are in `middleware.excluded_routes`.
- [ ] Activate one fresh host key end-to-end (activate → status) and confirm it
      succeeds; the raw key never appears in output or logs.
- [ ] `php artisan corevisys:license:check --force` exits `0` and the logs show
      no `signature_verification_failed` and no server-unavailable error.
- [ ] `php artisan schedule:list` shows `corevisys:license:check` when
      `auto_check` is enabled.
- [ ] A denied protected route (if enforcement is on) redirects to a page that
      renders (HTTP 200) — no redirect loop.
- [ ] With the license server deliberately unreachable on a host holding a
      valid signed cache, the application stays up until the signed
      `offline_valid_until`, then denies — no indefinite offline trust.

## What the test suite does not cover (rollout-owned)

These are explicitly **not** proven by the automated suite and must be signed
off by this staged rollout (also noted in [`UPGRADING.md`](UPGRADING.md)):

- a live activate → check → pulse round trip against the real license server,
- `php artisan config:cache` on a real host,
- `corevisys:license:doctor` against a live production database.
