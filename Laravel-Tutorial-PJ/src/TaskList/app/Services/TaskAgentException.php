<?php

namespace App\Services;

use RuntimeException;

class TaskAgentException extends RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'SERVICE_UNAVAILABLE', public int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
