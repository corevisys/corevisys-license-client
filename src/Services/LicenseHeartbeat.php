<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseStatus;
use CoreVisys\License\Exceptions\LicenseClientException;

/**
 * Sends a lightweight heartbeat (POST /license/pulse) so CoreVisys can
 * track live installs without running a full /check. Never throws on
 * failure — a missed pulse degrades gracefully to "try again next tick".
 */
class LicenseHeartbeat
{
    public function __construct(
        protected ApiRequestHandler $api,
        protected SignedPayloadVerifier $signatureVerifier,
        protected FingerprintGenerator $fingerprint,
        protected LicenseStorageInterface $storage,
        protected string $productCode,
    ) {
    }

    public function send(?string $licenseKey): ?LicenseStatus
    {
        if (! $licenseKey) {
            return null;
        }

        $cached = $this->storage->get($this->productCode);

        try {
            $response = $this->api->post('license/pulse', [
                'license_key' => $licenseKey,
                'product_code' => $this->productCode,
                'domain' => $this->fingerprint->normalizedDomain(),
                'ip' => request()?->ip() ?? '127.0.0.1',
                'fingerprint' => $this->fingerprint->generate(),
                'package_version' => '1.0.0',
                'application_version' => config('app.version', '1.0.0'),
                'license_id' => $cached['license_id'] ?? null,
            ]);

            $this->signatureVerifier->verify($response);

            $status = $response->toLicenseStatus();

            if (empty($status->productCode) || $status->productCode !== $this->productCode) {
                return null;
            }

            $isActive = $status->status === 'active';
            $offlineUntil = $isActive ? $status->offlineValidUntil : null;
            $lastSuccessfulCheck = $isActive ? now() : null;

            $this->storage->put($this->productCode, [
                'status' => $status->status,
                'expires_at' => $status->expiresAt,
                'grace_expires_at' => $status->graceExpiresAt,
                'issued_at' => $status->issuedAt,
                'offline_valid_until' => $offlineUntil,
                'is_grace_period' => $status->isGracePeriod,
                'features' => $status->features,
                'signed_payload' => $response->canonicalDataJson(),
                'signature' => $response->signature,
                'key_id' => $response->keyId,
                'last_successful_check_at' => $lastSuccessfulCheck,
            ]);

            return $status;
        } catch (LicenseClientException $e) {
            $refusedStatus = match (true) {
                stripos($e->getMessage(), 'suspended') !== false || $e->errorCode() === 'license_suspended' => 'suspended',
                stripos($e->getMessage(), 'expired') !== false || $e->errorCode() === 'license_expired' => 'expired',
                stripos($e->getMessage(), 'revoked') !== false || $e->errorCode() === 'license_revoked' || $e->errorCode() === 'invalid_license_key' => 'revoked',
                default => $e->errorCode() ?: 'request_rejected',
            };

            $this->storage->put($this->productCode, [
                'status' => $refusedStatus,
                'signed_payload' => null,
                'signature' => null,
                'key_id' => null,
                'offline_valid_until' => null,
                'last_successful_check_at' => null,
                'next_check_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => \CoreVisys\License\Support\LogSanitizer::scrubMessage($e->getMessage(), [$licenseKey]),
            ]);

            return LicenseStatus::invalid($refusedStatus);
        }
    }
}
