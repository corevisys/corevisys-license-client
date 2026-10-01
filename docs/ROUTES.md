# Routes, Middleware and Lockout Safety

This package ships one route-enforcement middleware and one optional web
screen. Both are opt-in; nothing is pushed into your application globally.

## The middlewares (aliases only, never global)

| Alias | Class | Registered how |
|---|---|---|
| `corevisys.license` | `CoreVisys\License\Middleware\EnsureValidLicense` | `aliasMiddleware()` only |
| `corevisys.feature` | `CoreVisys\License\Middleware\EnsureLicenseFeature` | `aliasMiddleware()` only |

`CoreVisysServiceProvider::registerMiddlewareAliases()` registers these two
**aliases**. The package never calls `pushMiddleware()` / `prependMiddleware()`,
so neither middleware runs unless a route explicitly opts in with:

```php
Route::middleware('corevisys.license')->group(function () {
    // protected application routes
});

Route::get('/reports', fn () => view('reports'))
    ->middleware(['corevisys.license', 'corevisys.feature:reports']);
```

Because the middleware is alias-only, a mis-configured package can never lock
an application out at the framework level — only routes that asked for the
check are affected.

## What the middleware does on deny

`EnsureValidLicense::handle()` runs in this order:

1. **Local bypass** (`middleware.bypass_in_local`, local env only, always
   logged) — passes through. Never active in production.
2. **Excluded route** — passes through with **no license check and no server
   call** (see below).
3. **`LicenseClientInterface::isValid()`** — passes through when valid.
4. **Deny** (`EnsureValidLicense::deny()`):
   - **JSON request** → `403` (or `middleware.abort_status`) with a generic body:

     ```json
     {
       "message": "A valid license is required to access this resource.",
       "error_code": "invalid_license"
     }
     ```

     The body carries no license key, no `key_id`, and no diagnostic detail.
   - **Web request** → redirect to `middleware.redirect_route`, or, when unset,
     the built-in activation route (`ui.route_name`). If neither is available,
     a bare `403` is returned.
   - **Console** (outside the test runner) → an `InvalidLicenseException`.

## Which routes stay open

An "excluded" route passes through untouched — no license check, no server
call. Exclusions are configured in `middleware.excluded_routes` as route names
and/or `Str::is()` path patterns (see `docs/CONFIGURATION.md`). Each entry is
matched against **both** the route name and the URL path.

The packaged defaults (applied when the config value is absent, `null` or an
empty list) are:

| Excluded entry | Keeps reachable |
|---|---|
| `corevisys.license.activate`, `corevisys.license.activate.store` | The activation screen (GET + POST) |
| `license/activate` | The activation path |
| `login`, `logout` | Conventional auth routes |
| `health`, `up` | Conventional health / liveness probes |

> **Warning — keep this list in sync with your actual route names.** Each
> `excluded_routes` entry is matched against the route **name** and the URL
> **path**. Renaming a host route (or listing a name that does not exist, or a
> stale name from an older release) silently stops matching, so that route
> becomes **protected** the next time it is hit — a common cause of an
> unexpected lockout after a deploy that renamed routes. After any change to
> route names, re-run `php artisan route:list --path=license` and confirm every
> route you intend to keep open is still listed, then `php artisan config:clear`.
> The built-in activation screen is exempt unconditionally (below), so it is the
> one route that cannot be locked out by an out-of-date list.

### The activation route is ALWAYS exempt

Independently of `excluded_routes`, `EnsureValidLicense::isActivationRequest()`
exempts the activation route **unconditionally**, matched by:

- route **name** — the configured `ui.route_name`, its `.store` POST action,
  and any name prefixed from it, and
- **path** — `Str::is('<ui.route_prefix>/activate', $path)`.

So even an operator who deletes the activation entry from
`middleware.excluded_routes` cannot create a redirect loop: a denied web
request always redirects to a page that itself renders (because it is exempt),
which terminates the chain at HTTP 200. Proven by
`tests/Feature/MiddlewareExcludedRoutesTest.php`
(`test_activation_route_stays_reachable_when_removed_from_excluded_routes`,
`test_activation_route_name_is_hard_exempt_even_when_not_listed`,
`test_denied_web_request_following_redirects_ends_on_the_activation_page`).

## How this prevents deployment / admin lockout

The failure mode this guards against: a fresh or lapsed deployment turns on
`corevisys.license`, the license is invalid, and the operator can no longer
reach the login screen or the activation screen to fix it.

The design avoids that three ways:

1. **Alias-only** — only routes that opted in are ever blocked.
2. **Default-open auth/health/activation** — `login`, `logout`, `health`, `up`
   and the activation screen stay reachable while the license is invalid.
3. **Hard-coded activation exemption** — the activation screen is exempt even
   if the operator's `excluded_routes` list is wrong or empty.

Deny does not "fail open" for protected routes (that would defeat
enforcement); it fails **safe toward repair** — the operator can always reach
the activation page.

## Emergency steps (operator runbook)

If a route is unexpectedly blocked, or you need to confirm what is open:

```
# 1. Inspect which routes carry the middleware and their names/paths
php artisan route:list --path=license

# 2. Inspect the resolved middleware config (read-only, no secrets)
php artisan corevisys:license:doctor

# 3. See the current license state (read-only)
php artisan corevisys:license:status

# 4. Force a check against the server (may notify via the scheduled path only)
php artisan corevisys:license:check

# 5. Emergency recovery: temporarily disable enforcement for local repair
#    (local env only; has no effect in production) — or add the affected
#    route to middleware.excluded_routes and run:
php artisan config:clear
```

If the activation screen was disabled (`ui.enabled = false`) and you have no
custom redirect target, a denied web request returns a bare `403`; set
`middleware.redirect_route` to a route that is **not** behind
`corevisys.license`, or re-enable the built-in screen.

> Not covered here: the staged rollout, exhaustive CI matrix, and operational
> hardening of the status/check commands are separate phases and are **not**
> claimed as delivered by this document.
