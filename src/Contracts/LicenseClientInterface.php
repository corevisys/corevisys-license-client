<?php

namespace CoreVisys\License\Contracts;

use Carbon\Carbon;
use CoreVisys\License\DTOs\ActivationResult;
use CoreVisys\License\DTOs\LicenseStatus;

interface LicenseClientInterface
{
    public function activate(?string $licenseKey = null): ActivationResult;

    public function check(bool $force = false): LicenseStatus;

    public function isValid(): bool;

    public function isActive(): bool;

    public function isExpired(): bool;

    public function isRevoked(): bool;

    public function isSuspended(): bool;

    public function expiresAt(): ?Carbon;

    public function daysRemaining(): ?int;

    public function feature(string $feature): bool;

    public function features(): array;

    public function deactivate(): bool;

    public function clearCache(): void;

    public function status(): ?LicenseStatus;

    public function fingerprint(): string;

    public function licenseKey(): ?string;
}
