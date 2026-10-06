<?php

namespace CoreVisys\License\Exceptions;

class ActivationLimitExceededException extends LicenseClientException
{
    public function __construct(string $message = 'This license has reached its activation limit.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 'activation_limit_exceeded', 409, false, $previous);
    }
}
