<?php

namespace CoreVisys\License\DTOs;

final class ActivationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly ?LicenseStatus $status = null,
        public readonly ?string $errorCode = null,
    ) {
    }

    public static function success(string $message, LicenseStatus $status): self
    {
        return new self(success: true, message: $message, status: $status);
    }

    public static function failure(string $message, ?string $errorCode = null): self
    {
        return new self(success: false, message: $message, errorCode: $errorCode);
    }
}
