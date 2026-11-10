<?php

namespace App\Services\Teaching;

use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Trainer;
use App\Models\TrainerNotice;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\NotificationService;

/**
 * "You are the trainer of …": when a trainer is attached to a programme — on the programme, on one of its sessions or by an
 * approved group assignment — they get one notification (push, e-mail and SMS, as each channel and person allows) saying
 * when it runs, for which group, in which room and under whom, and the same notice waits on their home as a popup until they
 * ask not to see it again. One notice per trainer and group, so saving a programme twice never tells them twice. The training
 * kit belongs to the training centre, and every notice says so.
 */
class TrainerNotices
{
    public const OWNERSHIP_AR = 'الحقيبة التدريبية ملك المركز التدريبي.';

    public const OWNERSHIP_EN = 'The training kit is the property of the training centre.';

    public function __construct(private readonly NotificationService $notifications) {}

    /** Finds everyone teaching the programme who has not been told yet and tells them. @return int how many trainers were notified */
    public function sync(Program $program): int
    {
        if (in_array($program->status, [Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED], true)) {
            return 0;
        }
        $program->unsetRelation('groups')->load('groups');
        $primary = $program->groups->sortBy('sequence')->first();
        $pairs = collect();   // [trainer_id, group_id]
        foreach ($program->trainers()->pluck('trainers.id') as $trainerId) {
            $pairs->push([$trainerId, $primary?->id]);
        }
        foreach (ProgramSession::where('program_id', $program->id)->whereNotNull('trainer_id')->where('status', '!=', 'cancelled')->get(['trainer_id', 'training_group_id']) as $s) {
            $pairs->push([$s->trainer_id, $s->training_group_id ?? $primary?->id]);
        }
        foreach (GroupTrainer::whereIn('group_id', $program->groups->pluck('id'))->where('status', GroupTrainer::APPROVED)->get(['trainer_id', 'group_id']) as $g) {
            $pairs->push([$g->trainer_id, $g->group_id]);
        }

        $n = 0;
        $trainers = Trainer::whereIn('id', $pairs->pluck(0)->unique()->values())->whereNotNull('user_id')->get()->keyBy('id');
        foreach ($pairs->unique(fn ($p) => $p[0].'|'.$p[1]) as [$trainerId, $groupId]) {
            $trainer = $trainers->get($trainerId);
            if ($trainer && $this->assigned($program, $trainer, $groupId ? $program->groups->firstWhere('id', $groupId) : null)) {
                $n++;
            }
        }

        return $n;
    }

    /** Tells one trainer, once per programme group. @return TrainerNotice|null null when they were told before or are not a platform user */
    public function assigned(Program $program, Trainer $trainer, ?TrainingGroup $group = null): ?TrainerNotice
    {
        $user = $trainer->user_id ? User::where('status', 'active')->find($trainer->user_id) : null;
        if (! $user) {
            return null;
        }
        $notice = TrainerNotice::firstOrCreate(['user_id' => $user->id, 'program_id' => $program->id, 'scope_key' => (string) ($group?->id ?? ''), 'kind' => 'assigned'], ['trainer_id' => $trainer->id, 'group_id' => $group?->id]);
        if (! $notice->wasRecentlyCreated) {
            return null;
        }

        $d = $this->details($program, $group);
        $this->notifications->send($user, 'trainer.assigned', ['ar' => 'أُسند إليك برنامج تدريبي', 'en' => 'A programme was assigned to you'],
            ['ar' => $d['text']['ar'], 'en' => $d['text']['en']],
            ['program_id' => $program->id, 'program_code' => $program->code, 'group_id' => $group?->id, 'notice_id' => $notice->id, 'route' => '/admin/teaching', 'detail_ar' => $d['text']['ar'], 'detail_en' => $d['text']['en']]);

        return $notice;
    }

    /**
     * What the trainer needs to know: from, to, the group, the rooms and the supervisor — as data for the popup and as a sentence
     * in both languages for the notification.
     *
     * @return array<string, mixed>
     */
    public function details(Program $program, ?TrainingGroup $group = null): array
    {
        $sessions = ProgramSession::with('room')->where('program_id', $program->id)->where('status', '!=', 'cancelled')
            ->when($group, fn ($q) => $q->where(fn ($w) => $w->where('training_group_id', $group->id)->orWhereNull('training_group_id')))->orderBy('starts_at')->get();
        $tz = config('app.timezone');
        $from = $sessions->first()?->starts_at ?? $program->start_date;
        $to = $sessions->last()?->ends_at ?? $program->end_date;
        $hasSessions = $sessions->isNotEmpty();
        $fmt = fn ($d) => $d ? $d->copy()->timezone($tz)->format($hasSessions ? 'Y-m-d H:i' : 'Y-m-d') : null;
        $rooms = $sessions->map->room->filter()->unique('id')->values()->map(fn ($r) => ['ar' => (string) ($r->name_ar ?: $r->name_en), 'en' => (string) ($r->name_en ?: $r->name_ar)])->all();
        $supervisor = $group?->supervisor ?? $program->coordinator;
        $supervisorName = $supervisor ? ['ar' => $supervisor->displayName('ar'), 'en' => $supervisor->displayName('en')] : null;
        $groupName = $group ? ['ar' => $group->displayTitle('ar'), 'en' => $group->displayTitle('en')] : ['ar' => $program->title_ar, 'en' => $program->title_en ?: $program->title_ar];

        $text = [];
        foreach (['ar', 'en'] as $l) {
            $ar = $l === 'ar';
            $t = $program->{"title_$l"} ?: $program->title_ar;
            $parts = [$ar ? "تم إسنادك مدرباً لبرنامج «{$t}»" : "You were assigned as the trainer of \"{$t}\""];
            if ($from && $to) {
                $parts[] = $ar ? 'من '.$fmt($from).' إلى '.$fmt($to) : 'from '.$fmt($from).' to '.$fmt($to);
            } elseif ($from) {
                $parts[] = ($ar ? 'ابتداءً من ' : 'starting ').$fmt($from);
            }
            if ($group) {
                $parts[] = ($ar ? 'للمجموعة «' : 'for group "').$groupName[$l].($ar ? '»' : '"');
            }
            if ($program->delivery_mode === 'online') {
                $parts[] = $ar ? 'عن بُعد' : 'online';
            } elseif ($rooms) {
                $parts[] = ($ar ? 'في القاعة ' : 'in room ').implode($ar ? '، ' : ', ', array_map(fn ($r) => $r[$l], $rooms));
            }
            if ($supervisorName) {
                $parts[] = ($ar ? 'والمشرف ' : 'supervised by ').$supervisorName[$l];
            }
            $text[$l] = implode($ar ? '، ' : ', ', $parts).'. '.($ar ? self::OWNERSHIP_AR : self::OWNERSHIP_EN);
        }

        return ['from' => $from?->copy()->timezone($tz)->toIso8601String(), 'to' => $to?->copy()->timezone($tz)->toIso8601String(), 'has_times' => $hasSessions, 'group' => $groupName, 'rooms' => $rooms, 'mode' => $program->delivery_mode,
            'supervisor' => $supervisorName, 'sessions' => $sessions->count(), 'text' => $text];
    }

    // ───────────────────────────── the popup on the trainer's home

    /** The notices still to show: not dismissed, programme still ahead or running. @return list<array<string, mixed>> */
    public function popup(User $user): array
    {
        $rows = TrainerNotice::with(['program.coordinator', 'group.supervisor'])
            ->where('user_id', $user->id)->whereNull('dismissed_at')->whereHas('program', fn ($q) => $q->whereNotIn('status', [Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED, Program::STATUS_COMPLETED]))
            ->latest()->limit(20)->get();
        TrainerNotice::whereIn('id', $rows->whereNull('seen_at')->pluck('id'))->update(['seen_at' => now()]);

        return $rows->map(function (TrainerNotice $n) {
            $d = $this->details($n->program, $n->group);

            return ['id' => $n->id, 'program' => ['id' => $n->program->id, 'code' => $n->program->code, 'title_ar' => $n->program->title_ar, 'title_en' => $n->program->title_en ?: $n->program->title_ar]] + $d
                + ['created_at' => $n->created_at?->toIso8601String(), 'ownership' => ['ar' => self::OWNERSHIP_AR, 'en' => self::OWNERSHIP_EN]];
        })->values()->all();
    }

    public function dismiss(User $user, string $noticeId): void
    {
        TrainerNotice::where('user_id', $user->id)->whereKey($noticeId)->update(['dismissed_at' => now()]);
    }

    public function dismissAll(User $user): int
    {
        return TrainerNotice::where('user_id', $user->id)->whereNull('dismissed_at')->update(['dismissed_at' => now()]);
    }
}
