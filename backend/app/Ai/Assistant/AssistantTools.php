<?php

namespace App\Ai\Assistant;

use App\Models\Certificate;
use App\Models\CourseLesson;
use App\Models\Employee;
use App\Models\LessonProgress;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\AnnualHoursService;
use App\Services\Assessment\ArabicText;

/** What the assistant can look up about the person asking — and only about them. Nothing here takes an id from the question. */
class AssistantTools
{
    public function __construct(private readonly AnnualHoursService $hours) {}

    /** @return list<string> the tools a question calls for */
    public function detect(string $question): array
    {
        $q = ' '.ArabicText::normalize($question).' ';
        $has = fn (array $w) => (bool) array_filter($w, fn ($x) => str_contains($q, $x));
        $out = [];
        if ($has(['جدول', 'موعد', 'متي', 'القادمه', 'القادم', 'schedule', 'next session', 'when is', 'when are', 'my sessions', 'timetable'])) {
            $out[] = 'schedule';
        }
        if ($has(['تقدمي', 'نسبه انجاز', 'نسبه التقدم', 'اكملت', 'progress', 'how far', 'completed lessons', 'my courses'])) {
            $out[] = 'progress';
        }
        if ($has(['شهادت', 'شهاده', 'certificate'])) {
            $out[] = 'certificates';
        }
        if ($has(['ساعات', 'ساعاتي', 'تطوير مهني', 'hours', 'cpd', 'professional development'])) {
            $out[] = 'hours';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function run(string $tool, User $user): array
    {
        $emp = Employee::where('user_id', $user->id)->first();
        if (! $emp) {
            return [];
        }

        return match ($tool) {
            'schedule' => $this->schedule($emp),
            'progress' => $this->progress($emp),
            'certificates' => $this->certificates($emp),
            'hours' => $this->hours($emp),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function schedule(Employee $emp): array
    {
        $regs = Registration::where('employee_id', $emp->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING])->get();
        $rows = ProgramSession::with('program:id,title_ar,title_en')->where('starts_at', '>=', now())->where(fn ($q) => $q->whereIn('training_group_id', $regs->pluck('training_group_id')->filter())->orWhere(fn ($w) => $w->whereNull('training_group_id')->whereIn('program_id', $regs->pluck('program_id'))))
            ->where('status', '!=', 'cancelled')->orderBy('starts_at')->limit(3)->get();

        return ['sessions' => $rows->map(fn ($s) => ['program_ar' => $s->program?->title_ar, 'program_en' => $s->program?->title_en, 'starts_at' => $s->starts_at->toIso8601String(), 'mode' => $s->mode, 'location' => $s->location_text])->all()];
    }

    /** @return array<string, mixed> */
    private function progress(Employee $emp): array
    {
        $out = [];
        foreach (Registration::with('program:id,title_ar,title_en')->where('employee_id', $emp->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get() as $r) {
            $total = CourseLesson::where('program_id', $r->program_id)->where('status', 'published')->count();
            if ($total === 0) {
                continue;
            }
            $done = LessonProgress::where('registration_id', $r->id)->where('status', 'completed')->count();
            $out[] = ['program_ar' => $r->program?->title_ar, 'program_en' => $r->program?->title_en, 'completed' => $done, 'total' => $total, 'percent' => (int) round($done / $total * 100)];
        }

        return ['courses' => $out];
    }

    /** @return array<string, mixed> */
    private function certificates(Employee $emp): array
    {
        $rows = Certificate::with('program:id,title_ar,title_en')->where('employee_id', $emp->id)->where('status', 'issued')->latest('issued_at')->limit(5)->get();

        return ['certificates' => $rows->map(fn ($c) => ['program_ar' => $c->program?->title_ar, 'program_en' => $c->program?->title_en, 'issued_at' => $c->issued_at?->toDateString(), 'hours' => $c->hours])->all()];
    }

    /** @return array<string, mixed> */
    private function hours(Employee $emp): array
    {
        $s = $this->hours->summary($emp);

        return ['hours' => ['year' => $s['year'], 'total' => $s['total'], 'target' => $s['target'], 'shortfall' => $s['shortfall']]];
    }

    /** A sentence for each thing found. @param  array<string, mixed>  $data */
    public function describe(array $data, bool $ar): string
    {
        $lines = [];
        foreach ($data['sessions'] ?? [] as $s) {
            $lines[] = ($ar ? 'جلسة «'.$s['program_ar'].'»' : 'Session "'.$s['program_en'].'"').' — '.substr(str_replace('T', ' ', $s['starts_at']), 0, 16).($s['location'] ? ' ('.$s['location'].')' : '');
        }
        if (array_key_exists('sessions', $data) && ! $data['sessions']) {
            $lines[] = $ar ? 'لا توجد جلسات قادمة في جدولك.' : 'You have no upcoming sessions.';
        }
        foreach ($data['courses'] ?? [] as $c) {
            $lines[] = ($ar ? '«'.$c['program_ar'].'»' : '"'.$c['program_en'].'"').': '.$c['completed'].'/'.$c['total'].' ('.$c['percent'].'%)';
        }
        if (array_key_exists('courses', $data) && ! $data['courses']) {
            $lines[] = $ar ? 'لا توجد دورات إلكترونية قيد التقدم.' : 'No online courses in progress.';
        }
        foreach ($data['certificates'] ?? [] as $c) {
            $lines[] = ($ar ? 'شهادة «'.$c['program_ar'].'»' : 'Certificate "'.$c['program_en'].'"').' — '.$c['issued_at'];
        }
        if (array_key_exists('certificates', $data) && ! $data['certificates']) {
            $lines[] = $ar ? 'لم تصدر لك شهادات بعد.' : 'No certificates issued yet.';
        }
        if (isset($data['hours'])) {
            $h = $data['hours'];
            $lines[] = $ar ? "ساعات التطوير المهني لعام {$h['year']}: {$h['total']}".($h['target'] ? " من {$h['target']}" : '') : "Professional development hours for {$h['year']}: {$h['total']}".($h['target'] ? " of {$h['target']}" : '');
        }

        return implode("\n", $lines);
    }
}
