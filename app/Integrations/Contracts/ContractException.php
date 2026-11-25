<?php

namespace App\Integrations\Contracts;

use RuntimeException;

/**
 * A call to a system that did not work, in the contract's own terms: a stable code, a message an administrator can act on (never a
 * header, a body or a secret) and whether trying again could help.
 */
class ContractException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 0, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
