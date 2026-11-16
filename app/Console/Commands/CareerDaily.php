<?php

namespace App\Console\Commands;

use App\Services\AnnualHoursService;
use App\Services\CareerPathEngine;
use App\Services\KnowledgeTransferService;
use App\Services\LicenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:career-daily')]
#[Description('Licence expiry and reminders, career-path evaluation, PD-hours shortfall alerts and knowledge-transfer reminders')]
class CareerDaily extends Command
{
    public function handle(LicenceService $licences, CareerPathEngine $paths, AnnualHoursService $hours, KnowledgeTransferService $kt): int
    {
        $l = $licences->monitor();
        $k = $kt->remind();
        $this->info("Licences expired {$l['expired']}, reminded {$l['reminded']}; paths evaluated {$paths->evaluateAll()}; shortfall alerts {$hours->alertShortfalls()}; KT due {$k['due']}, overdue {$k['overdue']}.");

        return self::SUCCESS;
    }
}
