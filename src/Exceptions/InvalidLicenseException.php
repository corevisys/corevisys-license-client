<?php

namespace CoreVisys\License\Exceptions;

class InvalidLicenseException extends LicenseClientException
{
    public function __construct(string $message = 'The license is not valid.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'invalid_license', 403, false, $previous);
    }
}
