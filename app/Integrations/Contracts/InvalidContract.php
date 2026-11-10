<?php

namespace App\Integrations\Contracts;

/** A contract or a mapping that cannot be saved: the reasons, so the administrator can correct them. */
final class InvalidContract extends ContractException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('invalid_contract', 'The document cannot be saved: '.implode('; ', array_slice($problems, 0, 5)).(count($problems) > 5 ? '…' : ''), 422);
    }
}
