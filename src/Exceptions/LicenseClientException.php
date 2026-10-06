<?php

namespace CoreVisys\License\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception for all package exceptions. Carries a machine-readable
 * error code, an HTTP status suggestion, whether the failure is retryable,
 * and (optionally) the original exception that triggered it — never any
 * sensitive request data (license keys, fingerprints, raw HTTP bodies).
 */
class LicenseClientException extends Exception
{
    public function __construct(
        string $userMessage,
        protected string $errorCode = 'license_error',
        protected int $httpStatus = 403,
        protected bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
