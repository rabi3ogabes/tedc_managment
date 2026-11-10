<?php

namespace App\Console\Commands;

use App\Models\TrainingGroup;
use App\Services\EvaluationService;
use App\Services\SatisfactionAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:evaluations-hourly')]
#[Description('Hand out the end-of-group evaluation forms, remind and expire them, and re-check the satisfaction alerts')]
class EvaluationsHourly extends Command
{
    public function handle(EvaluationService $evaluations, SatisfactionAlertService $alerts): int
    {
        $assigned = 0;
        TrainingGroup::where('status', 'completed')->where('updated_at', '>=', now()->subDays(60))->each(function (TrainingGroup $g) use ($evaluations, &$assigned) {
            $assigned += $evaluations->assignForGroup($g);
        });
        $r = $evaluations->remindAndExpire();
        $this->info("Assigned {$assigned}, reminded {$r['reminded']}, expired {$r['expired']}, alerts ".$alerts->sweep().'.');

        return self::SUCCESS;
    }
}
