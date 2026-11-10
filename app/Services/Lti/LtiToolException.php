<?php

namespace App\Services\Lti;

use RuntimeException;

/** A launch TEDC refuses. The key names a message in messages.lti_tool, shown to the person in their language. */
class LtiToolException extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly ?string $detail = null)
    {
        parent::__construct(trans('messages.lti_tool.'.$key, [], 'en').($detail ? " ({$detail})" : ''));
    }
}
