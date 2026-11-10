<?php

namespace App\Console\Commands;

use App\Services\TrainerCertificateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:trainer-certificates')]
#[Description('Issue thank-you certificates to trainers who completed their hours, and notify them')]
class IssueTrainerCertificates extends Command
{
    public function handle(TrainerCertificateService $service): int
    {
        $this->info('Issued '.$service->sync().' trainer certificate(s).');

        return self::SUCCESS;
    }
}
