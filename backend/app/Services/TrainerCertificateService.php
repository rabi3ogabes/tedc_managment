<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Trainer;
use App\Models\TrainerCertificate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thank-you certificates for trainers. A trainer earns one for a program when every session assigned to them
 * in it has been delivered (completed, or its end time has passed); the hours printed are the hours they taught.
 */
class TrainerCertificateService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly CertificateService $certificates,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{planned: int, delivered: int, hours: float, complete: bool} */
    public function progress(Trainer $trainer, Program $program): array
    {
        $sessions = ProgramSession::where('program_id', $program->id)->where('trainer_id', $trainer->id)->where('status', '!=', 'cancelled')->get();
        $delivered = $sessions->filter(fn (ProgramSession $s) => $s->status === 'completed' || $s->ends_at->isPast());
        $minutes = $delivered->sum(fn (ProgramSession $s) => $s->durationMinutes());

        return [
            'planned' => $sessions->count(),
            'delivered' => $delivered->count(),
            'hours' => round($minutes / 60, 2),
            'complete' => $sessions->isNotEmpty() && $delivered->count() === $sessions->count(),
        ];
    }

    /** Issues the certificate when the hours are complete (once) and tells the trainer. Returns it, or null while hours remain. */
    public function issueIfComplete(Trainer $trainer, Program $program): ?TrainerCertificate
    {
        if ($existing = TrainerCertificate::where('trainer_id', $trainer->id)->where('program_id', $program->id)->first()) {
            return $existing;
        }
        $progress = $this->progress($trainer, $program);
        if (! $progress['complete']) {
            return null;
        }

        $certificate = TrainerCertificate::create([
            'certificate_no' => config('tedc.certificates.prefix').'-T-'.now()->year.'-'.strtoupper(Str::random(6)),
            'verification_code' => strtoupper(Str::random(12)),
            'trainer_id' => $trainer->id,
            'program_id' => $program->id,
            'issued_at' => now(),
            'hours' => $progress['hours'],
            'status' => 'valid',
            'meta' => ['sessions' => $progress['delivered']],
        ]);

        try {
            $path = $this->storage->put('certificates', "{$program->id}/{$certificate->certificate_no}.pdf", $this->render($certificate), 'application/pdf');
            $certificate->update(['file_path' => $path]);
        } catch (Throwable $e) {
            report($e); // downloads fall back to a fresh render
        }

        if ($trainer->user_id) {
            $this->notifications->send(
                $trainer->user_id,
                'certificate.trainer_available',
                ['ar' => 'شهادة الشكر والتقدير جاهزة', 'en' => 'Your thank-you certificate is ready'],
                [
                    'ar' => "أكملتَ ساعات برنامج «{$program->title_ar}». يمكنك الآن تحميل شهادة الشكر والتقدير من تطبيقك.",
                    'en' => "You completed your hours in \"{$program->title_en}\". You can now download your thank-you certificate in the app.",
                ],
                ['trainer_certificate_id' => $certificate->id, 'program_id' => $program->id],
            );
        }

        return $certificate;
    }

    /** Checks every trainer of a program (or of all programs with delivered sessions). @return int certificates issued */
    public function sync(?Program $program = null): int
    {
        $issued = 0;
        $pairs = ProgramSession::query()
            ->whereNotNull('trainer_id')->where('status', '!=', 'cancelled')
            ->when($program, fn ($q) => $q->where('program_id', $program->id))
            ->select('program_id', 'trainer_id')->distinct()->get();

        foreach ($pairs as $pair) {
            $trainer = Trainer::find($pair->trainer_id);
            $prog = Program::find($pair->program_id);
            if (! $trainer || ! $prog || TrainerCertificate::where('trainer_id', $trainer->id)->where('program_id', $prog->id)->exists()) {
                continue;
            }
            if ($this->issueIfComplete($trainer, $prog)) {
                $issued++;
            }
        }

        return $issued;
    }

    public function render(TrainerCertificate $certificate): string
    {
        $certificate->loadMissing(['trainer.school', 'program']);

        return $this->certificates->renderPdf('certificates.trainer', $certificate->verificationUrl(), $certificate->certificate_no, ['certificate' => $certificate]);
    }

    public function pdf(TrainerCertificate $certificate): string
    {
        try {
            if ($certificate->file_path) {
                return $this->storage->get('certificates', $certificate->file_path);
            }
        } catch (Throwable) {
            // fall through to a fresh render
        }

        return $this->render($certificate);
    }
}
