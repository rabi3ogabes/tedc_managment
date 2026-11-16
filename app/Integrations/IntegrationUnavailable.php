<?php

namespace App\Integrations;

use RuntimeException;

/** Raised when a system is switched off, not set up, or its circuit breaker is open after repeated failures. */
class IntegrationUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'unavailable')
    {
        parent::__construct($message);
    }
}
