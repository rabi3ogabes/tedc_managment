<?php

namespace App\Integrations;

use App\Integrations\Ministry\HrSync;
use App\Integrations\Ministry\LicenceSync;
use App\Integrations\Ministry\SaeedTickets;

/** Hands a verified inbound message to the code that understands it. Systems that send nothing we act on are acknowledged and ignored. */
class InboundRouter
{
    /** @param  array<string, mixed>  $payload @return string processed | ignored */
    public function handle(string $source, array $payload): string
    {
        return match ($source) {
            'hr', 'mawared' => app(HrSync::class)->applyInbound($source, $payload),
            'licences' => app(LicenceSync::class)->applyInbound($payload),
            'saaed' => app(SaeedTickets::class)->applyInbound($payload),
            default => 'ignored',
        };
    }
}
