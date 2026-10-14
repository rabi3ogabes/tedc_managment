<?php

namespace App\Security\Sso;

use RuntimeException;

/** A SAML message TEDC will not accept: malformed, unsolicited, invalid (signature, audience, time…), replayed, or a bad logout. */
class SamlRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($reason.($detail !== '' ? ': '.$detail : ''));
    }
}
