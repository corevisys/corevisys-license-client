<?php

namespace CoreVisys\License\Exceptions;

class LicenseSuspendedException extends LicenseClientException
{
    public function __construct(string $message = 'The license is currently suspended.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'license_suspended', 403, false, $previous);
    }
}
