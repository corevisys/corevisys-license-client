# Changelog

All notable changes to `corevisys/laravel-license-client` are documented
here. This project adheres to [Semantic Versioning](https://semver.org/).

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
