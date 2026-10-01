<?php

namespace CoreVisys\License\Services;

use Carbon\Carbon;
use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\ActivationResult;
use CoreVisys\License\DTOs\LicenseStatus;
use CoreVisys\License\Events\LicenseDeactivated;

/**
 * The single entry point the rest of the application talks to (also bound
 * behind the CoreVisysLicense facade). Delegates activation, verification,
 * and heartbeats to their dedicated services and keeps an in-memory cache
 * of the last computed status for the duration of the request.
 */
class LicenseClient implements LicenseClientInterface
{
    protected ?LicenseStatus $resolvedStatus = null;

    public function __construct(
        protected LicenseActivator $activator,
        protected LicenseVerifier $verifier,
        protected LicenseHeartbeat $heartbeat,
        protected FingerprintGenerator $fingerprintGenerator,
        protected LicenseStorageInterface $storage,
        protected ApiRequestHandler $apiRequestHandler,
        protected string $productCode,
        protected ?string $configuredLicenseKey,
    ) {
    }

    public function activate(?string $licenseKey = null): ActivationResult
    {
        $key = $licenseKey ?? $this->configuredLicenseKey;

        if (! $key) {
            return ActivationResult::failure('No license key was provided or configured.', 'missing_license_key');
        }

        $result = $this->activator->activate($key);

        if ($result->success) {
            $this->resolvedStatus = $result->status;
        }

        return $result;
    }

    public function check(bool $force = false): LicenseStatus
    {
        $this->resolvedStatus = $this->verifier->check($this->licenseKey(), $force);

        return $this->resolvedStatus;
    }

    public function pulse(): ?LicenseStatus
    {
        return $this->heartbeat->send($this->licenseKey());
    }

    public function isValid(): bool
    {
        return $this->currentStatus()->valid;
    }

    public function isActive(): bool
    {
        return $this->currentStatus()->isActive();
    }

    public function isExpired(): bool
    {
        return $this->currentStatus()->isExpired();
    }

    public function isRevoked(): bool
    {
        return $this->currentStatus()->isRevoked();
    }

    public function isSuspended(): bool
    {
        return $this->currentStatus()->isSuspended();
    }

    public function expiresAt(): ?Carbon
    {
        return $this->currentStatus()->expiresAt;
    }

    public function daysRemaining(): ?int
    {
        return $this->currentStatus()->daysRemaining();
    }

    public function feature(string $feature): bool
    {
        return $this->currentStatus()->hasFeature($feature);
    }

    public function features(): array
    {
        return $this->currentStatus()->features;
    }

    public function deactivate(): bool
    {
        $key = $this->licenseKey();

        if (! $key) {
            $this->clearCache();

            return true;
        }

        // Delegate to LicenseActivator which owns the full error-handling policy
        // (404 → already deactivated, 403 → rejected, network → soft-fail).
        $success = $this->activator->deactivate($key);

        $this->clearCache();

        if ($success) {
            \Illuminate\Support\Facades\Event::dispatch(new LicenseDeactivated());
        }

        return $success;
    }

    public function clearCache(): void
    {
        $this->storage->forget($this->productCode);
        $this->resolvedStatus = null;
    }

    public function status(): ?LicenseStatus
    {
        return $this->resolvedStatus;
    }

    public function fingerprint(): string
    {
        return $this->fingerprintGenerator->generate();
    }

    public function licenseKey(): ?string
    {
        $cached = $this->storage->get($this->productCode);
        $decrypted = $cached ? $this->storage->decryptLicenseKey($cached) : null;

        return $decrypted ?? $this->configuredLicenseKey;
    }

    /**
     * Returns the last resolved status, resolving one now (via check())
     * if nothing has been resolved yet this request.
     */
    protected function currentStatus(): LicenseStatus
    {
        return $this->resolvedStatus ??= $this->check();
    }
}
