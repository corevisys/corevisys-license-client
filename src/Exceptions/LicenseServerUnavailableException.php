<?php

namespace CoreVisys\License\Exceptions;

class LicenseServerUnavailableException extends LicenseClientException
{
    public function __construct(string $message = 'The license server is currently unavailable.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'license_server_unavailable', 503, true, $previous);
    }
}
