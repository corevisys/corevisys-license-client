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
use CoreVisys\License\Support\LogSanitizer;
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

    /**
     * Tolerance for a last_successful_check_at that appears slightly in the
     * future (clock skew between the app host and the DB/cache host). Anything
     * beyond this is treated as a forward-dated tamper and fails the offline
     * path closed, so the local grace window can never be renewed by editing
     * the row.
     */
    private const LAST_CHECK_FUTURE_SKEW_SECONDS = 300;

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

        // A record served from the *fallback* store is a storage location, not
        // a trust shortcut. It must pass the full frozen offline rule (A6) via
        // the offline path, never the "fast path" below — which trusts a record
        // only because its signature verifies against locally cached key
        // material.
        $fromFallback = is_array($cached) && ! empty($cached[LicenseStorage::ORIGIN_MARKER]);

        if (! $force && $cached && ! $fromFallback && ! $this->isDue($cached)) {
            $fastPath = $this->fastPathStatus($cached);

            if ($fastPath !== null) {
                return $fastPath;
            }

            // Otherwise the cached record could not be trusted from local
            // material alone (cold key cache, missing/tampered payload, or the
            // signed offline_valid_until has passed): fall through and take the
            // normal path, exactly as if the record were due.
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
            Event::dispatch(new LicenseServerUnavailableEvent(null, [
                'reason_code' => 'license_server_unavailable',
                'message' => LogSanitizer::scrubMessage($e->getMessage()),
            ]));

            return $this->fallbackToCache($cached, $e->getMessage());
        } catch (SignatureVerificationException $e) {
            $this->log('warning', 'CoreVisys license: signature verification failed.', $e, [
                'reason_code' => 'signature_verification_failed',
            ]);
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
            $this->log('warning', 'CoreVisys license: check request rejected.', $e, [
                'reason_code' => $e->errorCode(),
            ]);
            $this->storage->put($this->productCode, [
                'last_error_at' => now(),
                'last_error_message' => LogSanitizer::scrubMessage($e->getMessage()),
            ]);
            Event::dispatch(new LicenseCheckFailed(null, ['reason' => $e->errorCode()]));

            return LicenseStatus::invalid($e->errorCode());
        }
    }

    /**
     * The fast path: a cached record whose next_check_at is still in the future
     * may be trusted WITHOUT a server round trip — but only when all of the
     * following hold, otherwise the caller treats the record as due:
     *
     *  1. The cached signed response still verifies against LOCAL key material
     *     (never a network refresh). A cold key cache, an unknown/revoked
     *     key_id, or a tampered payload therefore forces the normal path.
     *  2. Status and expires_at come from the *verified* signed payload, not
     *     the unsigned database columns (a hand-edited column cannot extend
     *     trust).
     *  3. When the signed payload carries offline_valid_until and it is in the
     *     past, the record is due regardless of next_check_at — an edited
     *     next_check_at can never extend trust beyond the signed boundary.
     *  4. A verified ACTIVE payload must carry a signed offline_valid_until that
     *     is still in the future. The unsigned offline_valid_until column is
     *     NEVER consulted, and an active payload with a null/absent signed
     *     boundary is not served from cache (A6) — it takes the normal path.
     *     Non-active statuses keep their previous behaviour.
     *
     * Returns null to mean "treat as due / take the normal path".
     *
     * @param  array<string, mixed>  $cached
     */
    protected function fastPathStatus(array $cached): ?LicenseStatus
    {
        $data = $this->verifiedSignedPayload($cached);

        if ($data === null) {
            return null;
        }

        // The signed payload is the ONLY source of the offline boundary; the
        // unsigned offline_valid_until column is never consulted here.
        $signedOfflineUntil = $this->parseDate($data['offline_valid_until'] ?? null);

        // (3) A signed boundary that has already passed forces an online
        // re-check, even when the unsigned next_check_at still claims the
        // record is not due — an edited next_check_at can never extend trust
        // beyond the signed boundary.
        if ($signedOfflineUntil !== null && $signedOfflineUntil->isPast()) {
            return null;
        }

        $status = (string) ($data['status'] ?? 'unknown');

        // (4) A verified ACTIVE payload with no signed offline boundary cannot
        // be trusted from cache alone (A6): take the normal online path. A
        // non-active status (suspended, revoked, expired, ...) keeps its
        // previous behaviour and is returned as-is (invalid) without a round
        // trip.
        if ($signedOfflineUntil === null && $status === 'active') {
            return null;
        }

        // (2) Status, expiry, the reported boundary AND every signed entitlement
        // field (license_id, license_type, product_code, features,
        // is_grace_period) come from the *verified* payload, never the unsigned
        // columns — the signed payload wins. A field absent from the payload
        // resolves to null/empty, never the column.
        $record = $this->applySignedEntitlements($cached, $data);

        $resolved = $this->statusFromCacheRecord($record, offline: false, alreadyValidated: false);

        $this->dispatchLifecycleEvents($resolved);

        // A license the signed payload reports as still active is a cached,
        // self-validating fact and needs no server round trip; anything else
        // is returned as-is (invalid) rather than being masked as valid.
        if ($resolved->valid) {
            Event::dispatch(new LicenseChecked($resolved));
        }

        return $resolved;
    }

    /**
     * Decode and verify the cached signed response using LOCAL key material
     * only (no network call). Returns the verified data array, or null when the
     * record lacks the needed material or cannot be verified locally.
     *
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>|null
     */
    protected function verifiedSignedPayload(array $cached): ?array
    {
        $reconstructed = $this->reconstructSignedResponse($cached);

        if ($reconstructed === null) {
            return null;
        }

        try {
            if (! $this->signatureVerifier->verifyLocally($reconstructed)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $reconstructed->data;
    }

    /**
     * Rebuild the LicenseResponse envelope from a cached record's stored signed
     * payload, signature and key_id. Returns null when any is missing or the
     * payload cannot be decoded into a valid envelope.
     *
     * @param  array<string, mixed>  $cached
     */
    protected function reconstructSignedResponse(array $cached): ?LicenseResponse
    {
        if (empty($cached['signed_payload']) || empty($cached['signature']) || empty($cached['key_id'])) {
            return null;
        }

        $data = json_decode($cached['signed_payload'], true);

        if (! is_array($data)) {
            return null;
        }

        try {
            return LicenseResponse::fromArray([
                'success' => true,
                'status' => 'success',
                'data' => $data,
                'signature' => $cached['signature'],
                'key_id' => $cached['key_id'],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse a date-like value (string or Carbon) into a Carbon instance, or
     * null when absent/unparseable. Never throws.
     */
    protected function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
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
            'last_error_message' => LogSanitizer::scrubMessage($errorMessage),
        ]);

        if (! $cached || ! ($this->config['allow_offline_verification'] ?? true)) {
            return LicenseStatus::invalid('license_server_unavailable');
        }

        // The offline rule (A6) is evaluated against the VERIFIED signed
        // payload ONLY. The unsigned cache columns never carry or extend
        // trust: a hand-edited status, expires_at or offline_valid_until
        // cannot widen the offline window or flip an invalid license to valid.
        $record = $this->trustedOfflineRecord($cached);

        if ($record === null) {
            // Distinguish the two operator-visible cases WITHOUT trusting the
            // unsigned columns: a still-verifiable signed payload whose windows
            // have closed is a lapsed grace/offline window; anything else is a
            // tampered or unverifiable cache.
            if ($this->verifiedSignedPayload($cached) !== null) {
                $this->log('warning', 'CoreVisys license: offline grace window has expired.', null, $this->recordContext($cached, 'grace_period_expired'));

                return LicenseStatus::invalid('grace_period_expired');
            }

            return LicenseStatus::invalid('tampered_cache');
        }

        $this->log('warning', 'CoreVisys license: serving a cached license within the offline grace window.', null, $this->recordContext($cached, 'grace_period_active'));

        return $this->statusFromCacheRecord($record, offline: true, alreadyValidated: true);
    }

    /**
     * Safe, redacted identifiers for a cached record — never the raw key.
     *
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    protected function recordContext(array $cached, string $reasonCode): array
    {
        return [
            'reason_code' => $reasonCode,
            'license_id' => $cached['license_id'] ?? null,
            'product_code' => $cached['product_code'] ?? $this->productCode,
            'key_id' => $cached['key_id'] ?? null,
        ];
    }

    /**
     * Apply the full frozen offline rule (A6) to a cached record and return a
     * copy whose status, expiry and offline boundary come from the VERIFIED
     * signed payload — or null when any condition fails.
     *
     * Conditions (all required):
     *  1. The cached signed payload still verifies against LOCAL key material
     *     (no network refresh) — a cold key cache, an unknown/revoked key_id,
     *     or a tampered payload fails closed.
     *  2. The signed payload carries offline_valid_until in the future.
     *  3. The signed expires_at is absent, or in the future.
     *  4. The local grace window (grace_period hours from the last successful
     *     check) has not expired. It can only shorten the boundary, never
     *     extend it.
     *
     * The unsigned cache columns are never read here.
     *
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>|null
     */
    protected function trustedOfflineRecord(array $cached): ?array
    {
        $data = $this->verifiedSignedPayload($cached);

        if ($data === null) {
            return null;
        }

        $expiresAt = $this->parseDate($data['expires_at'] ?? null);
        $offlineUntil = $this->parseDate($data['offline_valid_until'] ?? null);

        // (2) A signed boundary in the past — or absent — means no offline
        // trust; the unsigned offline_valid_until column is never consulted.
        if ($offlineUntil === null || $offlineUntil->isPast()) {
            return null;
        }

        // (3) A signed expiry in the past is not rescued by the offline window.
        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        // (4) The local grace window, anchored to the last successful check,
        // must still be open. It can only shorten the boundary above.
        if (empty($cached['last_successful_check_at'])) {
            return null;
        }

        $lastCheck = Carbon::parse($cached['last_successful_check_at']);

        // A future-dated last_successful_check_at (clock tamper, or a forward
        // skew beyond a small tolerance) would otherwise push the grace
        // window's end indefinitely into the future. Reject it: the window may
        // only ever shorten the signed boundary, never be renewed by editing
        // the row.
        if ($lastCheck->isAfter(now()->addSeconds(self::LAST_CHECK_FUTURE_SKEW_SECONDS))) {
            return null;
        }

        $graceHours = (int) ($this->config['grace_period'] ?? 72);

        if ($lastCheck->copy()->addHours($graceHours)->isPast()) {
            return null;
        }

        // Overlay every SIGNED field (status, expiry, boundary, license_id,
        // license_type, product_code, features, is_grace_period) from the
        // verified payload; the unsigned columns never decide entitlement.
        return $this->applySignedEntitlements($cached, $data);
    }

    /**
     * Overlay the SIGNED data fields onto a cache record so the unsigned
     * columns can never supply (or withhold) entitlement. A field absent from
     * the signed payload resolves to null/empty, never the column.
     *
     * Signed fields (frozen contract A2): status, license_id, product_code,
     * license_type, expires_at, features, issued_at, offline_valid_until,
     * is_grace_period.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applySignedEntitlements(array $record, array $data): array
    {
        $record['status'] = (string) ($data['status'] ?? 'unknown');
        $record['expires_at'] = $this->parseDate($data['expires_at'] ?? null);
        $record['offline_valid_until'] = $this->parseDate($data['offline_valid_until'] ?? null);
        $record['issued_at'] = $this->parseDate($data['issued_at'] ?? null);
        $record['license_id'] = $data['license_id'] ?? null;
        $record['license_type'] = $data['license_type'] ?? null;
        $record['product_code'] = $data['product_code'] ?? $this->productCode;
        $record['features'] = $this->normalizeFeatures($data['features'] ?? null);
        $record['is_grace_period'] = (bool) ($data['is_grace_period'] ?? false);

        return $record;
    }

    /**
     * Coerce a signed features value to an array. Accepts an array as-is, a
     * JSON string (decoded), or anything else (empty array). Never falls back
     * to the unsigned column.
     *
     * @return array<int, mixed>
     */
    protected function normalizeFeatures(mixed $features): array
    {
        if (is_string($features)) {
            $decoded = json_decode($features, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($features) ? $features : [];
    }

    protected function isDue(array $cached): bool
    {
        if (empty($cached['next_check_at'])) {
            return true;
        }

        return Carbon::parse($cached['next_check_at'])->isPast();
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

        $context = [
            'license_id' => $status->licenseId,
            'product_code' => $status->productCode,
            'key_id' => $status->keyId,
        ];

        if ($status->isExpired()) {
            $this->log('warning', 'CoreVisys license: the license has expired.', null, $context + ['reason_code' => 'license_expired']);
        } elseif ($status->isGracePeriod) {
            $this->log('warning', 'CoreVisys license: the license is in its grace period.', null, $context + ['reason_code' => 'grace_period_active']);
        }
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

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, ?\Throwable $e = null, array $context = []): void
    {
        if (! ($this->config['logging']['enabled'] ?? true)) {
            return;
        }

        if ($e !== null) {
            $context['error'] = $e->getMessage();
        }

        Log::channel($this->config['logging']['channel'] ?? 'stack')->{$level}(
            LogSanitizer::scrubMessage($message),
            LogSanitizer::scrubContext($context)
        );
    }
}
