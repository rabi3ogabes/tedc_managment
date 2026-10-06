<?php

namespace App\Console\Commands;

use App\Security\Siem\SiemForwarder;
use Illuminate\Console\Command;

class SiemFlush extends Command
{
    protected $signature = 'tedc:siem-flush';

    protected $description = 'Send waiting audit records and security events to the SIEM (Phase 17, every minute)';

    public function handle(SiemForwarder $siem): int
    {
        $r = $siem->flush();
        if ($r['sent'] || $r['failed']) {
            $this->line("sent {$r['sent']}, failed {$r['failed']}");
        }

        return self::SUCCESS;
    }
}
