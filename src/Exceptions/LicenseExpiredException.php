<?php

namespace CoreVisys\License\Exceptions;

class LicenseExpiredException extends LicenseClientException
{
    public function __construct(string $message = 'The license has expired.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'license_expired', 403, false, $previous);
    }
}
