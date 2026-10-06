<?php

namespace CoreVisys\License\Exceptions;

class SignatureVerificationException extends LicenseClientException
{
    public function __construct(string $message = 'The license response signature could not be verified.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'signature_verification_failed', 403, false, $previous);
    }
}
