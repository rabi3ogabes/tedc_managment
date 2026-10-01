<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Mail\CertificateMail;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Throwable;

/**
 * Smart Certificate Engine.
 *
 * Before issuing, the engine verifies: approved registration, attendance
 * percentage, required tasks approved and program evaluation submitted.
 * Certificates are rendered as bilingual PDF (Arabic first) with a QR code that
 * points at the public verification page.
 */
class CertificateService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{eligible: bool, checks: array<int, array{key: string, passed: bool, label: string}>} */
    public function requirements(Registration $registration): array
    {
        $registration->loadMissing('program');
        $program = $registration->program;

        $requiredTasks = $program->requires_tasks
            ? Task::where('program_id', $program->id)->where('is_required', true)->pluck('id')
            : collect();
        $approvedTasks = $requiredTasks->isEmpty() ? 0 : TaskSubmission::where('registration_id', $registration->id)
            ->whereIn('task_id', $requiredTasks)
            ->where('status', TaskSubmission::STATUS_APPROVED)
            ->count();
        $evaluationDone = ! $program->requires_evaluation || $registration->evaluation()->exists();

        $checks = [
            [
                'key' => 'registration',
                'passed' => in_array($registration->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true),
                'label' => __('messages.certificate.registration'),
            ],
            [
                'key' => 'attendance',
                'passed' => $registration->attendance_percent >= $program->min_attendance_percent,
                'label' => __('messages.certificate.attendance', ['actual' => round($registration->attendance_percent), 'required' => $program->min_attendance_percent]),
            ],
            [
                'key' => 'tasks',
                'passed' => $approvedTasks >= $requiredTasks->count(),
                'label' => __('messages.certificate.tasks', ['done' => $approvedTasks, 'total' => $requiredTasks->count()]),
            ],
            [
                'key' => 'evaluation',
                'passed' => $evaluationDone,
                'label' => __('messages.certificate.evaluation'),
            ],
        ];
        if ($program->has_course) {
            $checks[] = ['key' => 'course', 'passed' => (bool) $registration->course_completed, 'label' => __('messages.certificate.course', ['percent' => round($registration->course_percent), 'required' => $program->course_completion_percent])];
        }

        return ['eligible' => collect($checks)->every('passed'), 'checks' => $checks];
    }

    /**
     * Synchronises the denormalised flags on the registration used by dashboards and the mobile app.
     */
    public function refreshStatus(Registration $registration): Registration
    {
        $result = $this->requirements($registration);
        $checks = collect($result['checks'])->keyBy('key');

        $status = match (true) {
            $registration->certificate_status === 'issued' => 'issued',
            $result['eligible'] => 'eligible',
            $registration->program->end_date?->isPast() ?? false => 'blocked',
            default => 'pending',
        };

        $registration->update([
            'tasks_completed' => $checks['tasks']['passed'],
            'evaluation_completed' => $checks['evaluation']['passed'],
            'certificate_status' => $status,
        ]);

        return $registration;
    }

    public function issue(Registration $registration, ?User $actor = null): Certificate
    {
        if ($existing = $registration->certificate) {
            return $existing;
        }

        $requirements = $this->requirements($registration);
        if (! $requirements['eligible']) {
            $this->refreshStatus($registration);
            throw new BusinessRuleException(__('messages.certificate.blocked'), 'certificate_blocked', $requirements);
        }

        $certificate = DB::transaction(function () use ($registration, $actor) {
            $program = $registration->program;

            $certificate = Certificate::create([
                'certificate_no' => $this->nextNumber(),
                'verification_code' => strtoupper(Str::random(12)),
                'registration_id' => $registration->id,
                'employee_id' => $registration->employee_id,
                'program_id' => $program->id,
                'issued_at' => now(),
                'hours' => $program->total_hours,
                'status' => 'valid',
                'issued_by' => $actor?->id,
                'meta' => ['attendance_percent' => $registration->attendance_percent],
            ]);

            $registration->update([
                'status' => Registration::STATUS_COMPLETED,
                'completed_at' => $registration->completed_at ?? now(),
                'certificate_status' => 'issued',
            ]);

            $this->creditSkills($registration);

            return $certificate;
        });

        $path = $this->storage->put('certificates', "{$certificate->program_id}/{$certificate->certificate_no}.pdf", $this->render($certificate), 'application/pdf');
        $certificate->update(['file_path' => $path]);

        app(ImpactService::class)->scheduleFollowUps($registration);

        if ($this->downloadable($certificate)) {
            $this->announce($certificate);
        } else {
            $this->notifications->send(
                $registration->employee->user_id,
                'certificate.survey_needed',
                ['ar' => 'شهادتك بانتظار تعبئة الاستبيان', 'en' => 'Your certificate is waiting for the survey'],
                [
                    'ar' => "صدرت شهادة برنامج «{$registration->program->title_ar}». عبّئ استبيان البرنامج لتتمكن من تحميلها.",
                    'en' => "Your certificate for \"{$registration->program->title_en}\" is issued. Fill in the program survey to download it.",
                ],
                ['certificate_id' => $certificate->id, 'program_id' => $certificate->program_id],
            );
        }

        return $certificate;
    }

    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['employee.user', 'employee.school', 'program']);

        // A design made in the template studio wins over the built-in layout.
        $templates = app(CertificateTemplateService::class);
        if ($template = $templates->resolve('trainee', $certificate->program)) {
            return $templates->renderFor($template, $certificate);
        }

        return $this->renderPdf('certificates.pdf', $certificate->verificationUrl(), $certificate->certificate_no, ['certificate' => $certificate]);
    }

    /** Renders a certificate view (shared by trainee and trainer certificates) to PDF bytes. */
    public function renderPdf(string $view, string $verificationUrl, string $title, array $data): string
    {
        $qr = (new QRCode(new QROptions([
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
        ])))->render($verificationUrl);

        $theme = app(ThemeService::class)->get();

        $html = view($view, $data + [
            'qr' => $qr,
            'center' => app(ThemeService::class)->centerName(),
            'logo' => $theme['identity']['logo_ar'] ?? null,
        ])->render();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'tempDir' => storage_path('app/mpdf'),
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /** The trainee may download the certificate only after filling in the program survey. */
    public function downloadable(Certificate $certificate): bool
    {
        return $certificate->status === 'valid' && $certificate->registration()->first()?->evaluation()->exists() === true;
    }

    /**
     * Tells the trainee the certificate can be downloaded — once, as soon as the survey is filled in.
     * Without the survey they are told it is waiting for it.
     */
    public function announce(Certificate $certificate): void
    {
        $certificate->loadMissing(['registration', 'employee', 'program']);
        if ($certificate->status !== 'valid' || $certificate->available_notified_at || ! $certificate->employee->user_id) {
            return;
        }
        if (! $this->downloadable($certificate)) {
            return;
        }

        $certificate->update(['available_notified_at' => now()]);
        $this->notifications->send(
            $certificate->employee->user_id,
            'certificate.available',
            ['ar' => 'شهادتك جاهزة للتحميل', 'en' => 'Your certificate is ready to download'],
            [
                'ar' => "شكرًا لتعبئة الاستبيان. يمكنك الآن تحميل شهادة برنامج «{$certificate->program->title_ar}» من تطبيقك.",
                'en' => "Thank you for the survey. You can now download your certificate for \"{$certificate->program->title_en}\" in the app.",
            ],
            ['certificate_id' => $certificate->id, 'program_id' => $certificate->program_id],
        );
    }

    /** The stored PDF, or a fresh render when the stored file is missing. */
    public function pdf(Certificate $certificate): string
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

    /**
     * E-mails the certificate PDF to its holder and notifies them in the app.
     *
     * @return array{status: 'sent'|'skipped'|'failed', reason?: string}
     */
    public function send(Certificate $certificate, ?User $actor = null, bool $resend = false): array
    {
        $certificate->loadMissing(['employee.user', 'program']);

        if ($certificate->status !== 'valid') {
            return ['status' => 'skipped', 'reason' => 'revoked'];
        }
        if ($certificate->sent_at && ! $resend) {
            return ['status' => 'skipped', 'reason' => 'already_sent'];
        }
        $email = $certificate->employee->user?->email;
        if (! $email) {
            return ['status' => 'skipped', 'reason' => 'no_email'];
        }

        try {
            Mail::to($email)->send(new CertificateMail($certificate, $this->pdf($certificate)));
        } catch (Throwable $e) {
            report($e);
            $certificate->update(['send_error' => mb_substr($e->getMessage(), 0, 500)]);

            return ['status' => 'failed', 'reason' => 'mail_error'];
        }

        $certificate->update([
            'sent_at' => now(), 'sent_count' => $certificate->sent_count + 1, 'sent_to' => $email,
            'sent_by' => $actor?->id, 'send_error' => null,
        ]);

        $this->notifications->send(
            $certificate->employee->user_id,
            'certificate.sent',
            ['ar' => 'وصلتك شهادتك', 'en' => 'Your certificate was sent'],
            ['ar' => "أُرسلت شهادة «{$certificate->program->title_ar}» إلى بريدك الإلكتروني.", 'en' => "The certificate for \"{$certificate->program->title_en}\" was sent to your e-mail."],
            ['certificate_id' => $certificate->id],
        );

        return ['status' => 'sent'];
    }

    public function revoke(Certificate $certificate, string $reason): Certificate
    {
        $certificate->update(['status' => 'revoked', 'revoked_reason' => $reason]);

        return $certificate;
    }

    /**
     * Public verification — exposes only what is printed on the certificate itself.
     */
    public function verify(string $code): ?array
    {
        $certificate = Certificate::with(['employee.user', 'program'])
            ->where('verification_code', strtoupper(trim($code)))
            ->orWhere('certificate_no', strtoupper(trim($code)))
            ->first();

        if (! $certificate) {
            return null;
        }

        return [
            'valid' => $certificate->status === 'valid',
            'status' => $certificate->status,
            'certificate_no' => $certificate->certificate_no,
            'participant' => ['ar' => $certificate->employee->user->name_ar ?? $certificate->employee->user->name, 'en' => $certificate->employee->user->name],
            'program' => ['ar' => $certificate->program->title_ar, 'en' => $certificate->program->title_en],
            'hours' => $certificate->hours,
            'issued_at' => $certificate->issued_at->toDateString(),
            'issuer' => app(ThemeService::class)->centerName(),
        ];
    }

    private function creditSkills(Registration $registration): void
    {
        $employee = $registration->employee;
        $current = $employee->skills()->get()->keyBy('id');

        foreach ($registration->program->skills as $skill) {
            $target = (int) $skill->pivot->target_level;
            $level = (int) ($current->get($skill->id)?->pivot->level ?? 0);

            if ($level < $target) {
                $employee->skills()->syncWithoutDetaching([$skill->id => [
                    'level' => $target,
                    'source' => 'training',
                    'program_id' => $registration->program_id,
                    'verified_at' => now(),
                ]]);
            }
        }
    }

    private function nextNumber(): string
    {
        $prefix = config('tedc.certificates.prefix').'-'.now()->year.'-';

        do {
            $number = $prefix.strtoupper(Str::random(6));
        } while (Certificate::where('certificate_no', $number)->exists());

        return $number;
    }
}
