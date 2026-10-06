<?php

namespace CoreVisys\License\Exceptions;

class LicenseRevokedException extends LicenseClientException
{
    public function __construct(string $message = 'The license has been revoked.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'license_revoked', 403, false, $previous);
    }
}
