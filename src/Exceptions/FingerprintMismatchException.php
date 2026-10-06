<?php

namespace CoreVisys\License\Exceptions;

class FingerprintMismatchException extends LicenseClientException
{
    public function __construct(string $message = 'The application fingerprint no longer matches the activated license.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'fingerprint_mismatch', 403, false, $previous);
    }
}
