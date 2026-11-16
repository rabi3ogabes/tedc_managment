<?php

namespace App\Console\Commands;

use App\Services\Notifications\ProgramSurvey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:open-surveys')]
#[Description('Open the program surveys that are set to open automatically some hours after the program ends, and notify the trainees')]
class OpenProgramSurveys extends Command
{
    public function handle(ProgramSurvey $surveys): int
    {
        $this->info('Opened '.$surveys->openDue().' program survey(s).');

        return self::SUCCESS;
    }
}
