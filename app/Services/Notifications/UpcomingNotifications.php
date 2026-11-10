<?php

namespace App\Services\Notifications;

use App\Models\ImpactSurvey;
use App\Models\LessonProgress;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainerCertificate;
use App\Services\Channels\NotificationChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The automatic notifications that are still to go out, worked out from the same rules the schedulers use:
 * session reminders and check-in calls, program surveys that open by themselves, impact surveys, course nudges and
 * trainers' certificates. Each item says when, to how many people, what it will say and through which channels.
 */
class UpcomingNotifications
{
    public function __construct(private readonly NotificationTemplates $templates, private readonly NotificationChannels $channels) {}

    /** @return array<string, mixed> */
    public function build(int $days = 14, ?string $type = null, ?string $programId = null): array
    {
        $now = now();
        $until = $now->copy()->addDays(max(1, min(60, $days)))->endOfDay();
        $items = collect()
            ->merge($this->sessionItems($now, $until))
            ->merge($this->surveyItems($now, $until))
            ->merge($this->impactItems($now, $until))
            ->merge($this->courseItems($now, $until))
            ->merge($this->trainerCertificateItems($now, $until));

        $all = $items->filter(fn (array $i) => $i['recipients'] > 0)->sortBy('at')->values();
        $filtered = $all->when($type, fn (Collection $c) => $c->where('type', $type))->when($programId, fn (Collection $c) => $c->filter(fn ($i) => ($i['program']['id'] ?? null) === $programId))->values();
        $catalog = NotificationCatalog::events();

        return [
            'generated_at' => $now->toIso8601String(),
            'days' => $days,
            'summary' => [
                'total' => $filtered->count(), 'recipients' => (int) $filtered->sum('recipients'),
                'next_24h' => $filtered->filter(fn ($i) => Carbon::parse($i['at'])->lte($now->copy()->addDay()))->count(),
                'stopped' => $filtered->where('enabled', false)->count(),
            ],
            'types' => $all->groupBy('type')->map(fn (Collection $g, string $t) => ['type' => $t, 'name_ar' => $catalog[$t]['name_ar'] ?? $t, 'name_en' => $catalog[$t]['name_en'] ?? $t, 'count' => $g->count()])->values(),
            'channels' => $this->channels->status(),
            'items' => $filtered,
        ];
    }

    // Sources ------------------------------------------------------------------------------------------------------

    private function sessionItems(Carbon $now, Carbon $until): Collection
    {
        $opens = (int) config('tedc.attendance.check_in_opens_minutes_before');
        $late = (int) config('tedc.attendance.late_after_minutes');
        $sessions = ProgramSession::with('program:id,code,title_ar,title_en,remote')->where('status', '!=', 'cancelled')
            ->where('ends_at', '>=', $now)->where('starts_at', '<=', $until->copy()->addDay())->orderBy('starts_at')->get();
        $audience = Registration::whereIn('program_id', $sessions->pluck('program_id')->unique())->where('status', Registration::STATUS_APPROVED)->selectRaw('program_id, count(*) n')->groupBy('program_id')->pluck('n', 'program_id');

        $out = collect();
        foreach ($sessions as $s) {
            $n = (int) ($audience[$s->program_id] ?? 0);
            $online = $s->mode === 'online';
            $when = [
                'session.reminder' => $s->status === 'scheduled' && ! Cache::has("reminder:session:{$s->id}") ? max($now, $s->starts_at->copy()->subDay()) : null,
                'session.attendance_open' => $s->starts_at->copy()->subMinutes($online ? (int) ($s->program->remote['join_opens_minutes'] ?? 15) : $opens),
                'session.attendance_missed' => $s->starts_at->copy()->addMinutes($late),
            ];
            foreach ($when as $event => $at) {
                if ($at && $at->gte($now->copy()->subMinutes(2)) && $at->lte($until)) {
                    $out->push($this->item($event, $at, $n, $s->program, ['session' => ['id' => $s->id, 'title' => $s->title_ar, 'mode' => $s->mode, 'starts_at' => $s->starts_at->toIso8601String()]]));
                }
            }
        }

        return $out;
    }

    private function surveyItems(Carbon $now, Carbon $until): Collection
    {
        $survey = app(ProgramSurvey::class);
        $out = collect();
        Program::where('survey_mode', 'auto')->whereNull('survey_opened_at')->whereIn('status', [Program::STATUS_IN_PROGRESS, Program::STATUS_COMPLETED])->get()->each(function (Program $p) use ($survey, $now, $until, $out) {
            $at = $survey->autoOpensAt($p);
            $n = $survey->traineeUserIds($p)->count();
            if ($at && $at->lte($until) && $n > 0) {
                $out->push($this->item('survey.open', max($at, $now), $n, $p));
            }
        });

        return $out;
    }

    private function impactItems(Carbon $now, Carbon $until): Collection
    {
        return ImpactSurvey::with('program:id,code,title_ar,title_en')->where('status', 'scheduled')->whereDate('scheduled_for', '<=', $until->toDateString())->get()
            ->groupBy(fn (ImpactSurvey $s) => $s->program_id.'|'.$s->stage_days.'|'.$s->scheduled_for->toDateString())
            ->map(function (Collection $g) use ($now) {
                $first = $g->first();
                $at = max($now, $first->scheduled_for->copy()->setTime(8, 0));

                return $this->item('impact.survey', $at, $g->count(), $first->program, ['note' => ['ar' => "مرحلة {$first->stage_days} يوماً", 'en' => "{$first->stage_days}-day stage"]]);
            })->values();
    }

    /** Learners who stopped working through an online course are nudged after five quiet days (10:00 every day). */
    private function courseItems(Carbon $now, Carbon $until): Collection
    {
        $days = 5;
        $regs = Registration::with('program:id,code,title_ar,title_en')->where('status', Registration::STATUS_APPROVED)->where('course_completed', false)
            ->whereHas('program', fn ($q) => $q->where('has_course', true)->whereIn('status', ['registration_open', 'in_progress', 'published']))->get(['id', 'program_id', 'created_at']);
        if ($regs->isEmpty()) {
            return collect();
        }
        $last = LessonProgress::whereIn('registration_id', $regs->pluck('id'))->selectRaw('registration_id, max(last_activity_at) m')->groupBy('registration_id')->pluck('m', 'registration_id');

        return $regs->map(function (Registration $r) use ($last, $days, $now) {
            $since = isset($last[$r->id]) ? Carbon::parse($last[$r->id]) : $r->created_at;
            $at = $since->copy()->addDays($days)->setTime(10, 0);
            if ($at->lt($now)) {   // already overdue: it goes out at the next 10:00
                $at = $now->copy()->setTime(10, 0);
                $at->lt($now) && $at->addDay();
            }

            return ['registration' => $r, 'at' => $at];
        })->filter(fn (array $x) => $x['at']->lte($until))->groupBy(fn (array $x) => $x['registration']->program_id.'|'.$x['at']->toDateString())
            ->map(fn (Collection $g) => $this->item('course.nudge', $g->first()['at'], $g->count(), $g->first()['registration']->program))->values();
    }

    private function trainerCertificateItems(Carbon $now, Carbon $until): Collection
    {
        $out = collect();
        ProgramSession::with('program:id,code,title_ar,title_en')->whereNotNull('trainer_id')->where('status', '!=', 'cancelled')->get()->groupBy(fn ($s) => $s->program_id.'|'.$s->trainer_id)
            ->each(function (Collection $g) use ($now, $until, $out) {
                $last = $g->max('ends_at');
                $exists = TrainerCertificate::where('program_id', $g->first()->program_id)->where('trainer_id', $g->first()->trainer_id)->exists();
                if (! $exists && $last->gte($now) && $last->lte($until)) {
                    $out->push($this->item('certificate.trainer_available', $last->copy()->addMinutes(30 - $last->minute % 30), 1, $g->first()->program));
                }
            });

        return $out;
    }

    // Shape --------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function item(string $event, Carbon $at, int $recipients, ?Program $program, array $extra = []): array
    {
        $catalog = NotificationCatalog::events()[$event] ?? [];
        $tpl = $this->templates->all()[$event] ?? null;
        // The wording as the recipients will read it: the placeholders are filled from the program and session.
        $vars = $this->templates->variables($event, array_filter(['program_id' => $program?->id, 'session_id' => $extra['session']['id'] ?? null]), null, $program);
        $say = fn (?string $text, string $lang) => $text === null ? null : $this->templates->render($text, ['name' => $lang === 'ar' ? 'المتدرب' : 'the trainee'] + $vars[$lang]);
        $flags = ['push' => $tpl['push'] ?? true, 'email' => $tpl['email'] ?? true, 'sms' => $tpl['sms'] ?? true];
        $status = $this->channels->status();

        return [
            'id' => md5($event.$at->timestamp.($program?->id ?? '').($extra['session']['id'] ?? '').($extra['note']['en'] ?? '')),
            'type' => $event, 'group' => $catalog['group'] ?? null, 'at' => $at->toIso8601String(), 'recipients' => $recipients,
            'title_ar' => $say($tpl['title_ar'] ?? $catalog['title_ar'] ?? $event, 'ar'), 'title_en' => $say($tpl['title_en'] ?? $catalog['title_en'] ?? $event, 'en'),
            'body_ar' => $say($tpl['body_ar'] ?? $catalog['body_ar'] ?? null, 'ar'), 'body_en' => $say($tpl['body_en'] ?? $catalog['body_en'] ?? null, 'en'),
            'enabled' => (bool) ($tpl['enabled'] ?? true),
            // on = will be used, setup = chosen but the provider is not set up yet, off = switched off for this event
            'channels' => collect(['push', 'email', 'sms'])->mapWithKeys(fn (string $c) => [$c => ! $flags[$c] || ! $status[$c]['on'] ? 'off' : ($status[$c]['ready'] ? 'on' : 'setup')])->all(),
            'program' => $program ? ['id' => $program->id, 'code' => $program->code, 'title' => $program->title_ar] : null,
        ] + $extra;
    }
}
