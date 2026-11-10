<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\NeedsSurveyResponse;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingKit;

/**
 * The training journey on one screen for the main dashboard: eight steps from the training need to the certificate,
 * each with its live numbers, what needs attention, and the latest notifications that step produced (in Arabic or English).
 */
class ProcessTracker
{
    /** Notification types that belong to each step. */
    private const TYPES = [
        'needs' => ['needs_survey.invite', 'needs_survey.reminder'],
        'plan' => ['program.invite', 'trainer.assigned'],
        'kit' => ['kit.assigned', 'kit.comment', 'kit.review'],
        'register' => ['registration.new_pending', 'registration.pending', 'registration.waitlisted'],
        'approve' => ['registration.approved', 'registration.rejected', 'program.assigned', 'registration.cancelled'],
        'deliver' => ['session.reminder', 'session.attendance_open', 'session.attendance_missed'],
        'evaluate' => ['survey.open', 'impact.survey', 'task.approved', 'task.needs_changes', 'course.completed', 'course.nudge'],
        'certify' => ['certificate.issued', 'certificate.available', 'certificate.survey_needed', 'certificate.sent', 'certificate.trainer_available'],
    ];

    /** @return array<string, mixed> */
    public function build(string $locale = 'ar'): array
    {
        $now = now();
        $day = [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        $en = $locale === 'en';
        $ts = fn (string $ar, string $en_) => $en ? $en_ : $ar;

        $kits = TrainingKit::query()->where('status', '!=', TrainingKit::ARCHIVED);
        $regs = Registration::query();
        $todaySessions = ProgramSession::whereBetween('starts_at', $day)->where('status', '!=', 'cancelled');
        $present = Attendance::whereIn('program_session_id', (clone $todaySessions)->select('id'))->whereIn('status', ['present', 'late'])->count();
        $expected = Registration::whereIn('program_id', (clone $todaySessions)->select('program_id'))->where('status', Registration::STATUS_APPROVED)->count();
        $evaluations = Evaluation::count();
        $pendingSurvey = Certificate::where('status', 'valid')->whereHas('registration', fn ($q) => $q->whereDoesntHave('evaluation'))->count();

        $steps = [
            ['key' => 'needs', 'icon' => 'ClipboardList', 'link' => '/admin/needs',
                'title' => $ts('الاحتياج', 'Need'), 'text' => $ts('استبانات الاحتياج التدريبي', 'Training-needs surveys'),
                'value' => NeedsSurveyResponse::count(), 'unit' => $ts('إجابة', 'answers'), 'alert' => null],
            ['key' => 'plan', 'icon' => 'CalendarRange', 'link' => '/admin/programs',
                'title' => $ts('التخطيط', 'Plan'), 'text' => $ts('البرامج: حضوري وعن بُعد ومدمج', 'Programs: in person, online, hybrid'),
                'value' => Program::whereNotIn('status', [Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED])->count(), 'unit' => $ts('برنامج', 'programs'),
                'alert' => ($n = Program::where('status', Program::STATUS_DRAFT)->count()) ? $ts("{$n} مسودة", "{$n} drafts") : null,
                'split' => ['in_person' => Program::where('delivery_mode', 'in_person')->count(), 'online' => Program::where('delivery_mode', 'online')->count(), 'hybrid' => Program::where('delivery_mode', 'hybrid')->count()]],
            ['key' => 'kit', 'icon' => 'PackageCheck', 'link' => '/admin/kits',
                'title' => $ts('الحقيبة', 'Kit'), 'text' => $ts('إعداد الحقيبة التدريبية ومراجعتها', 'Preparing and reviewing the kit'),
                'value' => (clone $kits)->count(), 'unit' => $ts('حقيبة', 'kits'),
                'alert' => ($n = (clone $kits)->where('status', TrainingKit::IN_REVIEW)->count()) ? $ts("{$n} بالمراجعة", "{$n} in review") : null],
            ['key' => 'register', 'icon' => 'UserPlus', 'link' => '/admin/registrations',
                'title' => $ts('التسجيل', 'Register'), 'text' => $ts('طلبات التسجيل والإسناد', 'Registrations and assignments'),
                'value' => (clone $regs)->count(), 'unit' => $ts('تسجيل', 'registrations'),
                'alert' => ($n = (clone $regs)->where('status', Registration::STATUS_PENDING)->count()) ? $ts("{$n} بانتظار الاعتماد", "{$n} awaiting approval") : null],
            ['key' => 'approve', 'icon' => 'BadgeCheck', 'link' => '/admin/registrations',
                'title' => $ts('الاعتماد', 'Approve'), 'text' => $ts('اعتماد المتدربين وقائمة الانتظار', 'Approved trainees and the waiting list'),
                'value' => (clone $regs)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->count(), 'unit' => $ts('معتمد', 'approved'),
                'alert' => ($n = (clone $regs)->where('status', Registration::STATUS_WAITLISTED)->count()) ? $ts("{$n} في الانتظار", "{$n} waiting") : null],
            ['key' => 'deliver', 'icon' => 'Presentation', 'link' => '/admin/calendar',
                'title' => $ts('التنفيذ', 'Deliver'), 'text' => $ts('جلسات اليوم (٨ ص – ١ م) والحضور', "Today's sessions (8 am – 1 pm) and attendance"),
                'value' => (clone $todaySessions)->count(), 'unit' => $ts('جلسة اليوم', 'sessions today'),
                'alert' => $expected > 0 ? $ts("حضور {$present} من {$expected}", "{$present} of {$expected} present") : null],
            ['key' => 'evaluate', 'icon' => 'Star', 'link' => '/admin/analytics',
                'title' => $ts('التقييم', 'Evaluate'), 'text' => $ts('استبيان البرنامج والمهام', 'Program survey and tasks'),
                'value' => $evaluations, 'unit' => $ts('تقييم', 'evaluations'),
                'alert' => ($avg = Evaluation::avg('satisfaction_score')) !== null ? $ts('الرضا '.round($avg).'٪', 'Satisfaction '.round($avg).'%') : null],
            ['key' => 'certify', 'icon' => 'Award', 'link' => '/admin/certificates',
                'title' => $ts('الشهادة', 'Certify'), 'text' => $ts('إصدار الشهادات وتحميلها', 'Issuing and downloading certificates'),
                'value' => Certificate::where('status', 'valid')->count(), 'unit' => $ts('شهادة', 'certificates'),
                'alert' => $pendingSurvey ? $ts("{$pendingSurvey} بانتظار الاستبيان", "{$pendingSurvey} waiting for the survey") : null],
        ];

        $recent = AppNotification::with('user:id,name,name_ar')->whereIn('type', collect(self::TYPES)->flatten()->all())->latest('created_at')->limit(400)->get();

        foreach ($steps as $i => &$step) {
            $step['order'] = $i + 1;
            $step['recent'] = $recent->filter(fn (AppNotification $n) => in_array($n->type, self::TYPES[$step['key']], true))->take(3)->map(fn (AppNotification $n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $en ? $n->title_en : $n->title_ar, 'body' => $en ? $n->body_en : $n->body_ar,
                'to' => $n->user?->displayName($locale), 'at' => $n->created_at->toIso8601String(),
            ])->values()->all();
            $step['events'] = $recent->filter(fn (AppNotification $n) => in_array($n->type, self::TYPES[$step['key']], true))->count();
        }

        return ['generated_at' => $now->toIso8601String(), 'steps' => $steps];
    }
}
