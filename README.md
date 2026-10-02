# CoreVisys Laravel License Client

Official client SDK for the **CoreVisys** licensing platform. Install it in
any Laravel application to activate, verify, and monitor a software license
issued by your CoreVisys server — with cryptographically signed responses,
offline grace-period support, and fail-closed behavior throughout.

```
Customer Laravel App
        |
        | corevisys/laravel-license-client
        |
        | HTTPS + Signed API Request
        v
CoreVisys License Server
        |
        v
License Database
```

Supports **Laravel 10, 11, 12** and **PHP 8.2+**.

---

## 1. Installation

```bash
composer require corevisys/laravel-license-client
php artisan corevisys:license:install
php artisan migrate
```

`corevisys:license:install` publishes the config file and the
`corevisys_license_cache` migration, and prints the `.env` variables you
need (below).

### Upgrading

To upgrade the package to the latest version:

```bash
composer update corevisys/laravel-license-client
php artisan migrate
php artisan corevisys:license:doctor
```

If the release introduces configuration updates:
1. Review changes against your published `config/corevisys-license.php`, or republish using:
   ```bash
   php artisan vendor:publish --tag=corevisys-license-config --force
   ```
2. Run `php artisan corevisys:license:doctor` to verify PHP/Laravel compatibility, configuration validity, and database schema status.
3. Refresh cached license status:
   ```bash
   php artisan corevisys:license:check --force
   ```


## 2. Environment Setup

The package defaults `server_url` to CoreVisys's own hosted license server,
`https://license.corevisys.com` — you only need to set
`COREVISYS_LICENSE_SERVER_URL` if you're running your own license server
instead.

```env
# COREVISYS_LICENSE_SERVER_URL=https://license.corevisys.com   # default — override only if self-hosting
COREVISYS_PRODUCT_CODE=my-product
COREVISYS_LICENSE_KEY=XXXX-XXXX-XXXX-XXXX
COREVISYS_LICENSE_API_VERSION=v1
COREVISYS_LICENSE_TIMEOUT=10
COREVISYS_LICENSE_VERIFY_SSL=true
COREVISYS_LICENSE_AUTO_ACTIVATE=false
COREVISYS_LICENSE_AUTO_CHECK=true
COREVISYS_LICENSE_CHECK_INTERVAL=86400
COREVISYS_LICENSE_GRACE_PERIOD=72
COREVISYS_LICENSE_ALLOW_OFFLINE=true
```

**Never** commit `COREVISYS_LICENSE_KEY` to source control, and never expose
it to client-side JavaScript. See `config/corevisys-license.php` for every
tunable option (retry/backoff, fingerprint composition, cache backend,
middleware behavior, logging channel).

## 3. License Activation

```bash
php artisan corevisys:license:activate
# or activate a specific key:
php artisan corevisys:license:activate "XXXX-XXXX-XXXX-XXXX"
```

Or programmatically, e.g. from an installer wizard:

```php
use CoreVisys\License\Facades\CoreVisysLicense;

$result = CoreVisysLicense::activate($licenseKeyFromUserInput);

if ($result->success) {
    // $result->status is a LicenseStatus DTO
} else {
    // $result->message, $result->errorCode
}
```

Activation collects the domain and a **fingerprint** (a one-way hash of the
domain, server IP, product code and environment — the raw inputs never
leave the server) and sends them to `POST /license/activate` along with the
license key.

### Built-in web activation screen (for end users)

Real end users of your application will never run an `artisan` command.
For them, the package ships a ready-made web page — a license-key input
and an "Activate" button — enabled by default at:

```
GET  /license/activate   (shows the form + current status)
POST /license/activate   (submits the key)
```

Nothing to wire up: as long as `corevisys-license.ui.enabled` is `true`
(the default), the route and view are registered automatically. When
`EnsureValidLicense` blocks an unlicensed request and no explicit
`middleware.redirect_route` is configured, it redirects the user straight
to this screen instead of showing a bare, unfixable 403 — so a typical
flow is: user opens the app → gets redirected to `/license/activate` →
pastes their key → redirected back in.

Configuration (`config/corevisys-license.php`):

```php
'ui' => [
    'enabled' => env('COREVISYS_LICENSE_UI_ENABLED', true),
    'route_prefix' => env('COREVISYS_LICENSE_UI_PREFIX', 'license'), // -> /license/activate
    'route_name' => 'corevisys.license.activate',
    'middleware' => ['web'], // add 'auth' here to restrict to logged-in admins
],
```

To restyle it, publish the view and edit the Blade file directly:

```bash
php artisan vendor:publish --tag=corevisys-license-views
# edit resources/views/vendor/corevisys-license/activate.blade.php
```

Prefer to build your own screen instead? Set `ui.enabled` to `false` and
call `CoreVisysLicense::activate($key)` from your own controller/route —
see the programmatic example above.

## 4. Programmatic Verification

```php
use CoreVisys\License\Facades\CoreVisysLicense;

CoreVisysLicense::isValid();       // bool
CoreVisysLicense::isActive();
CoreVisysLicense::isExpired();
CoreVisysLicense::isRevoked();
CoreVisysLicense::isSuspended();
CoreVisysLicense::expiresAt();     // ?Carbon
CoreVisysLicense::daysRemaining(); // ?int
CoreVisysLicense::feature('reports'); // bool
CoreVisysLicense::features();      // array
CoreVisysLicense::status();        // ?LicenseStatus (last resolved)
CoreVisysLicense::fingerprint();   // string
CoreVisysLicense::licenseKey();    // ?string (decrypted from cache, or config)

// Force a fresh online check instead of using the cached/interval-gated result:
$status = CoreVisysLicense::check(force: true);
```

The dependency-injection alternative:

```php
use CoreVisys\License\Contracts\LicenseClientInterface;

public function __construct(private LicenseClientInterface $license) {}
```

## 5. Middleware Usage

```php
use Illuminate\Support\Facades\Route;

Route::middleware('corevisys.license')->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

Behavior:
- Valid license → request continues.
- Invalid license → `403` (or your configured `middleware.redirect_route`
  for web requests, a JSON error for `expectsJson()` requests, or a thrown
  `InvalidLicenseException` on the CLI).
- `middleware.bypass_in_local` can only ever bypass in the `local`
  environment — it is hard-disabled in `production` and every bypass is
  logged as a warning.

## 6. Feature Checking

> [!NOTE]
> **Server Status Notice:** Feature gating is not active yet on the server side (the license server currently returns an empty `features: []` array in its payload). While the client-side `corevisys.feature` middleware and `CoreVisysLicense::feature()` methods are fully implemented and tested, feature checks will fail closed (deny access) until the server-side feature entitlement schema and admin management are activated in a future release. Do not rely on feature-based access controls in production until server entitlement provisioning is enabled.

Gate individual features carried in the license's `features` array:

```php
Route::middleware('corevisys.feature:reports')->group(function () {
    Route::get('/reports', ReportsController::class);
});
```

Denied access fires a `LicenseFeatureDenied` event before returning `403`.

## 7. Scheduled Verification

The package registers `corevisys:license:check` on your application's own
scheduler automatically (governed by `auto_check` and `check_interval`),
using `withoutOverlapping()` and `onOneServer()` so concurrent workers never
double up requests to the license server. You can also add it yourself in
`routes/console.php` for finer control:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('corevisys:license:check')->daily();
```

For lightweight "is this install still alive" telemetry between full
checks, call `CoreVisysLicense::pulse()` — wire it into `POST
/license/pulse`, e.g. from a scheduled job every few minutes. Pulses never
throw; a failed pulse just waits for the next scheduled `check()`.

## 8. Offline Grace Period

If the CoreVisys server is unreachable, the package falls back to the last
**signed** response it received, for up to `grace_period` hours past the
last successful check:

| Situation | Result |
|---|---|
| Server reachable, signature valid | Trust the fresh response |
| Server unreachable, cache within grace window, cached signature still valid | Trust the cached status |
| Server unreachable, grace window expired | **Invalid** |
| Signature missing or invalid (fresh or cached) | **Invalid** — fail closed |
| Cached row hand-edited (signature no longer matches) | **Invalid** — tamper detected |
| License expired / revoked / suspended (fresh or cached) | **Invalid** |

Offline mode is intentionally *not* unlimited — it reduces friction from
transient outages without becoming a permanent bypass.

## 9. Error Handling

```php
use CoreVisys\License\Exceptions\{
    InvalidLicenseException,
    LicenseExpiredException,
    LicenseRevokedException,
    LicenseSuspendedException,
    LicenseServerUnavailableException,
    SignatureVerificationException,
    FingerprintMismatchException,
    ActivationLimitExceededException,
};

try {
    // ...
} catch (LicenseServerUnavailableException $e) {
    // $e->isRetryable() === true
    report($e);
}
```

Every exception exposes `errorCode()`, `httpStatus()`, `isRetryable()`, and
the original exception via `getPrevious()`. None of them ever include the
raw license key or request payload in their message.

## 10. Deactivation

```bash
php artisan corevisys:license:deactivate
```

```php
CoreVisysLicense::deactivate(); // bool
```

Sends `POST /license/deactivate` (reason: `application_removed`) and clears
the local cache regardless of whether the server call succeeds, so an
uninstall is never blocked by network issues.

## 11. Testing

The package ships Pest/PHPUnit-compatible tests under `tests/`, using
`Http::fake()` — **no test ever calls a live CoreVisys server**. Representative
coverage includes: valid/invalid/expired/revoked/suspended activation and
check flows, domain/fingerprint mismatch, activation-limit handling, signed
response validation (valid, missing, invalid, tampered-cache), public-key
resolution, timeouts and 5xx/429 handling, offline grace period (active and
expired), middleware (license + feature), encrypted storage, and
"license key never logged" assertions.

```bash
composer install
vendor/bin/phpunit
# or, if you prefer Pest:
vendor/bin/pest
```

Extend `tests/Feature` and `tests/Unit` with your own product-specific
scenarios as needed — the `SignsPayloads` trait in `tests/Concerns` makes it
easy to fabricate correctly-signed (or deliberately corrupted) server
responses.

## 12. Security Limitations

This package raises the cost of casual license bypass; it cannot make
piracy impossible for a sufficiently motivated attacker with local code
execution. In particular:

- It relies on the customer's PHP runtime not being tampered with at a
  lower level than the package itself.
- Offline mode necessarily trusts a locally stored (but signed) response
  for the grace window.
- There is no attempt at binary obfuscation, since this is open PHP source
  running inside the customer's own application.

Combine it with server-side license enforcement in CoreVisys itself
(activation limits, domain binding, revocation) for real protection —
this package is the client half of that story, not a substitute for it.

## 13. Version Compatibility

| Package | Laravel | PHP |
|---|---|---|
| ^1.0 | 10.x, 11.x, 12.x | 8.2+ |

## 14. API Contract

Base URL: `{server_url}/api/{api_version}`

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/license/activate` | First-time (or re-)activation |
| POST | `/license/check` | Full status verification |
| POST | `/license/pulse` | Lightweight heartbeat |
| POST | `/license/deactivate` | Release this activation |
| GET  | `/license/public-key` | Fetch the signing public key (cached, supports rotation via `key_id`) |

Every successful response is an envelope of the shape:

```json
{
  "success": true,
  "message": "...",
  "data": { "...": "license fields..." },
  "signature": "base64-signature-over-canonical(data)",
  "key_id": "current-key-id"
}
```

The signature covers the **key-sorted, canonical JSON encoding of `data`
only** — the client reconstructs that exact JSON before calling
`openssl_verify()` with RSA-SHA256. Only RSA-SHA256 signing is supported.
Ed25519 support was evaluated and removed to keep the trust boundary to a
single, production-verified algorithm path.

---

## Package Structure

```
src/
    CoreVisysServiceProvider.php
    Facades/CoreVisysLicense.php
    Contracts/{LicenseClientInterface,LicenseStorageInterface}.php
    Services/
        LicenseClient.php          # orchestrator behind the facade
        LicenseActivator.php       # POST /license/activate
        LicenseVerifier.php        # POST /license/check + offline fallback
        LicenseHeartbeat.php       # POST /license/pulse
        FingerprintGenerator.php
        SignedPayloadVerifier.php  # RSA-SHA256 signature checks
        LicenseStorage.php         # database or cache-store backend
        ApiRequestHandler.php      # shared HTTP transport (timeouts, retry/backoff, status handling)
    Middleware/{EnsureValidLicense,EnsureLicenseFeature}.php
    Commands/License{Install,Activate,Check,Deactivate,Status,ClearCache}Command.php
    Http/Controllers/LicenseActivationController.php  # built-in web activation screen
    DTOs/{LicenseResponse,LicenseStatus,ActivationResult}.php
    Exceptions/  (8 typed exceptions, all extending LicenseClientException)
    Events/      (10 lifecycle events)
config/corevisys-license.php
database/migrations/create_corevisys_license_cache_table.php
resources/lang/en/license.php
resources/views/activate.blade.php  # publishable via corevisys-license-views
tests/{Feature,Unit,Concerns}
```

## Design Rules This Package Follows

- Never talks to CoreVisys's database directly — API only.
- The customer application's local database/cache is never treated as a
  trusted source of truth by itself; it is only trusted when its signature
  still verifies.
- License keys are encrypted at rest (`Crypt::encryptString`) and are never
  written to logs or included in exception messages.
- Signature failures are always fail-closed.
- No hidden backdoors, hardcoded bypasses, or undocumented overrides.
- All configuration is publishable; no business logic lives in controllers
  (there are no controllers — only a facade, services, and middleware).
