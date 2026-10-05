<?php

namespace App\Console\Commands;

use App\Services\TrainingGroupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:group-lifecycle')]
#[Description('Move training groups along with their dates: open, ongoing, completed — or incomplete when never held')]
class AdvanceGroupLifecycle extends Command
{
    public function handle(TrainingGroupService $groups): int
    {
        $r = $groups->advance();
        $this->info("Opened {$r['opened']}, started {$r['started']}, completed {$r['completed']}, incomplete {$r['incomplete']} groups.");

        return self::SUCCESS;
    }
}
