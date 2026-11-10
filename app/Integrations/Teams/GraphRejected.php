<?php

namespace App\Integrations\Teams;

use RuntimeException;

/** Graph answered with a client error (4xx other than throttling): the request itself was refused, which is not an outage. */
class GraphRejected extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
