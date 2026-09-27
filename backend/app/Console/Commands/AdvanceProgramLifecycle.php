<?php

namespace App\Console\Commands;

use App\Models\Program;
use App\Models\Registration;
use App\Services\CertificateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:program-lifecycle')]
#[Description('Advance program statuses by date and refresh certificate eligibility of ended programs')]
class AdvanceProgramLifecycle extends Command
{
    public function handle(CertificateService $certificates): int
    {
        $opened = Program::where('status', Program::STATUS_PUBLISHED)
            ->whereNotNull('registration_opens_at')->where('registration_opens_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('registration_closes_at')->orWhere('registration_closes_at', '>', now()))
            ->update(['status' => Program::STATUS_REGISTRATION_OPEN]);

        $started = Program::whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN])
            ->whereNotNull('start_date')->whereDate('start_date', '<=', today())
            ->update(['status' => Program::STATUS_IN_PROGRESS]);

        $ended = Program::where('status', Program::STATUS_IN_PROGRESS)
            ->whereNotNull('end_date')->whereDate('end_date', '<', today())
            ->get();

        foreach ($ended as $program) {
            $program->update(['status' => Program::STATUS_COMPLETED]);
            $program->registrations()->where('status', Registration::STATUS_APPROVED)->each(fn ($r) => $certificates->refreshStatus($r));
        }

        $this->info("Opened {$opened}, started {$started}, completed {$ended->count()} programs.");

        return self::SUCCESS;
    }
}
