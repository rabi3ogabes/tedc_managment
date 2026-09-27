<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A domain rule was violated (registration closed, not eligible, certificate blocked ...).
 * Rendered as HTTP 422 with a machine-readable code and optional details.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'business_rule', public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'details' => $this->details,
        ], 422);
    }
}
