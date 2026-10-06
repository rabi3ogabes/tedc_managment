<?php

namespace App\Integrations;

/** Hands a verified inbound message to the code that understands it. Systems that send nothing we act on are acknowledged and ignored. */
class InboundRouter
{
    /** @param  array<string, mixed>  $payload @return string processed | ignored */
    public function handle(string $source, array $payload): string
    {
        return match ($source) {
            'hr', 'mawared' => app(\App\Integrations\Ministry\HrSync::class)->applyInbound($source, $payload),
            'licences' => app(\App\Integrations\Ministry\LicenceSync::class)->applyInbound($payload),
            'saaed' => app(\App\Integrations\Ministry\SaeedTickets::class)->applyInbound($payload),
            default => 'ignored',
        };
    }
}
