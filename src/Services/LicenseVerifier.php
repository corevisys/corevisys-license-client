<?php

namespace CoreVisys\License\Services;

use Carbon\Carbon;
use Composer\InstalledVersions;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\DTOs\LicenseStatus;
use CoreVisys\License\Events\LicenseCheckFailed;
use CoreVisys\License\Events\LicenseChecked;
use CoreVisys\License\Events\LicenseExpired as LicenseExpiredEvent;
use CoreVisys\License\Events\LicenseFingerprintChanged;
use CoreVisys\License\Events\LicenseRevoked as LicenseRevokedEvent;
use CoreVisys\License\Events\LicenseServerUnavailable as LicenseServerUnavailableEvent;
use CoreVisys\License\Events\LicenseSuspended as LicenseSuspendedEvent;
use CoreVisys\License\Exceptions\LicenseServerUnavailableException;
use CoreVisys\License\Exceptions\SignatureVerificationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates POST /license/check plus the offline-grace fallback.
 *
 * Fail-closed rules (see README "Offline grace period" section):
 *  - Server reachable + signature valid  -> trust the fresh response.
 *  - Server unreachable + cache within grace window + cache signature
 *    still valid -> trust the cached (last known-good) status.
 *  - Anything else (grace expired, signature invalid/missing, tampered
 *    cache, no cache at all) -> invalid.
 */
class LicenseVerifier
{
    /**
     * Version reported to the licensing server when the installed package
     * version cannot be resolved to a stable release (dev-* checkouts, or a
     * failure while reading Composer's installed package metadata).
     */
    private const FALLBACK_VERSION = '1.0.0';

    /** Memoized result of {@see packageVersion()}. */
    private ?string $resolvedPackageVersion = null;

    public function __construct(
        protected ApiRequestHandler $api,
        protected SignedPayloadVerifier $signatureVerifier,
        protected FingerprintGenerator $fingerprint,
        protected LicenseStorageInterface $storage,
        protected string $productCode,
        protected array $config,
    ) {
    }

    public function check(?string $licenseKey, bool $force = false): LicenseStatus
    {
        $cached = $this->storage->get($this->productCode);

        if (! $force && $cached && ! $this->isDue($cached)) {
            return $this->statusFromCacheRecord($cached, offline: false, alreadyValidated: true);
        }

        if (! $licenseKey) {
            return LicenseStatus::invalid('not_activated');
        }

        try {
            $response = $this->api->post('license/check', [
                'license_key' => $licenseKey,
                'product_code' => $this->productCode,
                'domain' => $this->fingerprint->normalizedDomain(),
                'ip' => request()?->ip() ?? '127.0.0.1',
                'fingerprint' => $this->fingerprint->generate(),
                'cached_license_id' => $cached['license_id'] ?? null,
                'package_version' => $this->packageVersion(),
            ]);

            $this->signatureVerifier->verify($response);

            $status = $this->finalizeOnlineStatus($response, $cached);

            Event::dispatch(new LicenseChecked($status));

            return $status;
        } catch (LicenseServerUnavailableException $e) {
            // Connectivity/rate-limit/5xx failures are transient — these are
            // the only cases allowed to fall back to a still-valid cache.
            Event::dispatch(new LicenseServerUnavailableEvent(null, ['message' => $e->getMessage()]));

            return $this->fallbackToCache($cached, $e->getMessage());
        } catch (SignatureVerificationException $e) {
            $this->log('warning', 'CoreVisys license: signature verification failed.', $e);
            Event::dispatch(new LicenseCheckFailed(null, ['reason' => 'signature_verification_failed']));

            // A response we cannot trust is treated as no response at all —
            // never fall back to a signature failure as if it were valid.
            return LicenseStatus::invalid('signature_verification_failed');
        } catch (\CoreVisys\License\Exceptions\LicenseClientException $e) {
            // A definitive rejection from the server (401/403/404/422/409,
            // e.g. domain mismatch or an activation-limit conflict) is fresh,
            // authoritative "no" — it must never be masked by falling back
            // to a previously cached "yes". check() never throws outward;
            // callers always get a LicenseStatus and can inspect ->status.
            $this->log('warning', 'CoreVisys license: check request rejected.', $e);
            $this->storage->put($this->productCode, [
                'last_error_at' => now(),
                'last_error_message' => $e->getMessage(),
            ]);
            Event::dispatch(new LicenseCheckFailed(null, ['reason' => $e->errorCode()]));

            return LicenseStatus::invalid($e->errorCode());
        }
    }

    protected function finalizeOnlineStatus(LicenseResponse $response, ?array $cached): LicenseStatus
    {
        $status = $response->toLicenseStatus();
        $fingerprint = $this->fingerprint->generate();

        if ($cached && ! empty($cached['fingerprint_hash']) && $cached['fingerprint_hash'] !== $fingerprint) {
            Event::dispatch(new LicenseFingerprintChanged($status));
        }

        $this->storage->put($this->productCode, [
            'license_id' => $status->licenseId,
            'status' => $status->status,
            'license_type' => $status->licenseType,
            'bound_domain' => $status->boundDomain,
            'fingerprint_hash' => $fingerprint,
            'expires_at' => $status->expiresAt,
            'grace_expires_at' => $status->graceExpiresAt,
            'issued_at' => $status->issuedAt,
            'offline_valid_until' => $status->offlineValidUntil,
            'is_grace_period' => $status->isGracePeriod,
            'features' => $status->features,
            'signed_payload' => $response->canonicalDataJson(),
            'signature' => $response->signature,
            'key_id' => $response->keyId,
            'last_successful_check_at' => now(),
            'next_check_at' => now()->addSeconds((int) $this->config['check_interval'] ?? 86400),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        $this->dispatchLifecycleEvents($status);

        return $status;
    }

    protected function fallbackToCache(?array $cached, string $errorMessage): LicenseStatus
    {
        $this->storage->put($this->productCode, [
            'last_error_at' => now(),
            'last_error_message' => $errorMessage,
        ]);

        if (! $cached || ! ($this->config['allow_offline_verification'] ?? true)) {
            return LicenseStatus::invalid('license_server_unavailable');
        }

        if (! $this->withinGracePeriod($cached)) {
            return LicenseStatus::invalid('grace_period_expired');
        }

        // Re-verify the cached signed payload; a manually edited row must
        // never be trusted even inside the grace window.
        if (! $this->cachedSignatureStillValid($cached)) {
            return LicenseStatus::invalid('tampered_cache');
        }

        return $this->statusFromCacheRecord($cached, offline: true, alreadyValidated: true);
    }

    protected function cachedSignatureStillValid(array $cached): bool
    {
        if (empty($cached['signed_payload']) || empty($cached['signature']) || empty($cached['key_id'])) {
            return false;
        }

        $data = json_decode($cached['signed_payload'], true);

        if (! is_array($data)) {
            return false;
        }

        $reconstructed = LicenseResponse::fromArray([
            'success' => true,
            'status' => 'success',
            'data' => $data,
            'signature' => $cached['signature'],
            'key_id' => $cached['key_id'],
        ]);

        try {
            $this->signatureVerifier->verify($reconstructed);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    protected function isDue(array $cached): bool
    {
        if (empty($cached['next_check_at'])) {
            return true;
        }

        return Carbon::parse($cached['next_check_at'])->isPast();
    }

    protected function withinGracePeriod(array $cached): bool
    {
        if (empty($cached['offline_valid_until']) || Carbon::parse($cached['offline_valid_until'])->isPast()) {
            return false;
        }

        if (! empty($cached['expires_at']) && Carbon::parse($cached['expires_at'])->isPast()) {
            return false;
        }

        if (empty($cached['last_successful_check_at'])) {
            return false;
        }

        $graceHours = (int) ($this->config['grace_period'] ?? 72);

        return Carbon::parse($cached['last_successful_check_at'])->addHours($graceHours)->isFuture();
    }

    protected function statusFromCacheRecord(array $cached, bool $offline, bool $alreadyValidated): LicenseStatus
    {
        $status = new LicenseStatus(
            valid: ($cached['status'] ?? null) === 'active'
                && (empty($cached['expires_at']) || Carbon::parse($cached['expires_at'])->isFuture()),
            status: $cached['status'] ?? 'unknown',
            licenseId: $cached['license_id'] ?? null,
            licenseType: $cached['license_type'] ?? null,
            productCode: $cached['product_code'] ?? $this->productCode,
            boundDomain: $cached['bound_domain'] ?? null,
            expiresAt: ! empty($cached['expires_at']) ? Carbon::parse($cached['expires_at']) : null,
            graceExpiresAt: ! empty($cached['grace_expires_at']) ? Carbon::parse($cached['grace_expires_at']) : null,
            features: is_string($cached['features'] ?? null) ? (json_decode($cached['features'], true) ?: []) : ($cached['features'] ?? []),
            checkedAt: ! empty($cached['last_checked_at']) ? Carbon::parse($cached['last_checked_at']) : now(),
            issuedAt: ! empty($cached['issued_at']) ? Carbon::parse($cached['issued_at']) : null,
            offlineValidUntil: ! empty($cached['offline_valid_until']) ? Carbon::parse($cached['offline_valid_until']) : null,
            isGracePeriod: (bool) ($cached['is_grace_period'] ?? false),
            nextCheckAt: ! empty($cached['next_check_at']) ? Carbon::parse($cached['next_check_at']) : null,
            fromCache: true,
            offline: $offline,
            keyId: $cached['key_id'] ?? null,
            signature: $cached['signature'] ?? null,
        );

        if ($alreadyValidated) {
            $this->dispatchLifecycleEvents($status);
        }

        return $status;
    }

    protected function dispatchLifecycleEvents(LicenseStatus $status): void
    {
        match (true) {
            $status->isExpired() => Event::dispatch(new LicenseExpiredEvent($status)),
            $status->isRevoked() => Event::dispatch(new LicenseRevokedEvent($status)),
            $status->isSuspended() => Event::dispatch(new LicenseSuspendedEvent($status)),
            default => null,
        };
    }

    /**
     * Resolve the installed package version for reporting to the server.
     *
     * Reads the Composer runtime version of this package, strips a leading
     * "v", and memoizes the outcome. Any dev-* checkout, a missing/empty
     * value, or a failure while reading the installed metadata falls back to
     * {@see self::FALLBACK_VERSION} so the reported value is always a
     * stable-looking semver string.
     */
    private function packageVersion(): string
    {
        if ($this->resolvedPackageVersion !== null) {
            return $this->resolvedPackageVersion;
        }

        try {
            $pretty = InstalledVersions::getPrettyVersion('corevisys/laravel-license-client');
        } catch (Throwable) {
            return $this->resolvedPackageVersion = self::FALLBACK_VERSION;
        }

        if (! is_string($pretty) || $pretty === '' || str_starts_with($pretty, 'dev-')) {
            return $this->resolvedPackageVersion = self::FALLBACK_VERSION;
        }

        $normalized = ltrim($pretty, 'vV');

        return $this->resolvedPackageVersion = ($normalized === '' ? self::FALLBACK_VERSION : $normalized);
    }

    protected function log(string $level, string $message, ?\Throwable $e = null): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        Log::channel($this->config['logging']['channel'] ?? 'stack')->{$level}($message, [
            'error' => $e?->getMessage(),
        ]);
    }
}
