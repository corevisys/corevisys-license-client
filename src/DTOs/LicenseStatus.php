<?php

namespace CoreVisys\License\DTOs;

use Carbon\Carbon;

/**
 * Immutable snapshot of the current license state, whether derived from a
 * fresh server response or from a validated local cache entry.
 */
final class LicenseStatus
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $status, // active | expired | revoked | suspended | invalid | unknown
        public readonly ?string $licenseId = null,
        public readonly ?string $licenseType = null,
        public readonly ?string $productCode = null,
        public readonly ?string $boundDomain = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly ?Carbon $graceExpiresAt = null,
        public readonly array $features = [],
        public readonly ?Carbon $serverTime = null,
        public readonly ?Carbon $issuedAt = null,
        public readonly ?Carbon $offlineValidUntil = null,
        public readonly bool $isGracePeriod = false,
        public readonly ?Carbon $checkedAt = null,
        public readonly ?Carbon $nextCheckAt = null,
        public readonly bool $fromCache = false,
        public readonly bool $offline = false,
        public readonly ?string $keyId = null,
        public readonly ?string $signature = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function daysRemaining(): ?int
    {
        if (! $this->expiresAt) {
            return null;
        }

        return max(0, (int) Carbon::now()->diffInDays($this->expiresAt, false));
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public static function invalid(string $reason = 'invalid'): self
    {
        return new self(valid: false, status: $reason);
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'status' => $this->status,
            'license_id' => $this->licenseId,
            'license_type' => $this->licenseType,
            'product_code' => $this->productCode,
            'bound_domain' => $this->boundDomain,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'grace_expires_at' => $this->graceExpiresAt?->toIso8601String(),
            'features' => $this->features,
            'server_time' => $this->serverTime?->toIso8601String(),
            'issued_at' => $this->issuedAt?->toIso8601String(),
            'offline_valid_until' => $this->offlineValidUntil?->toIso8601String(),
            'is_grace_period' => $this->isGracePeriod,
            'checked_at' => $this->checkedAt?->toIso8601String(),
            'next_check_at' => $this->nextCheckAt?->toIso8601String(),
            'from_cache' => $this->fromCache,
            'offline' => $this->offline,
        ];
    }
}
