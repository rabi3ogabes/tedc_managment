<?php

namespace App\Ai\Adaptive;

use App\Ai\AiPolicy;
use App\Models\AdaptiveRule;
use App\Models\CourseLesson;
use App\Models\LearnerMastery;
use App\Models\Registration;
use App\Models\RemedialAssignment;
use App\Models\Skill;
use App\Services\NotificationService;
use Illuminate\Support\Collection;

/**
 * Review lessons for the learners who need them (AI-03). A published review lesson — drafted by RemedialBuilder, checked
 * and published by the trainer — is assigned to a learner whose mastery of its competency falls below the threshold
 * (Settings → AI, or the trainer's own add-lesson rule for that lesson), appears only in their course, and leaves their
 * path once they reach the mastered level. A review lesson published later reaches the learners already below it.
 */
class RemedialAssignments
{
    public function __construct(private readonly AiPolicy $policy, private readonly NotificationService $notifications) {}

    /** After new evidence: assign or clear the review lessons of the competencies that moved. @param  array<string, float>  $mastery skill id => mastery (0..1) */
    public function sync(Registration $r, array $mastery): void
    {
        if ($mastery === []) {
            return;
        }
        foreach ($this->lessons($r->program_id) as $lesson) {
            $skill = (string) $lesson->setting('skill_id');
            if (array_key_exists($skill, $mastery)) {
                $this->apply($r, $lesson, (float) $mastery[$skill]);
            }
        }
    }

    /** A review lesson was published (or moved to another competency): the learners already below the threshold get it. @return int learners it was assigned to */
    public function forLesson(CourseLesson $lesson): int
    {
        if (! $lesson->isRemedial() || $lesson->status !== 'published') {
            return 0;
        }
        $registrations = Registration::where('program_id', $lesson->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->select('id');
        $n = 0;
        foreach (LearnerMastery::where('skill_id', $lesson->setting('skill_id'))->where('evidence_count', '>', 0)->whereIn('registration_id', $registrations)->get() as $row) {
            $r = Registration::find($row->registration_id);
            $n += $r && $this->apply($r, $lesson, (float) $row->mastery) === 'assigned' ? 1 : 0;
        }

        return $n;
    }

    /** @return Collection<string, RemedialAssignment> the learner's open review lessons, by lesson id */
    public function open(Registration $r): Collection
    {
        return RemedialAssignment::with('skill:id,name_ar,name_en')->where('registration_id', $r->id)->whereNull('cleared_at')->get()->keyBy('lesson_id');
    }

    /** Every ordinary lesson is visible; a review lesson only to the learners it is assigned to. */
    public function visible(CourseLesson $lesson, Registration $r): bool
    {
        return ! $lesson->isRemedial() || RemedialAssignment::where('registration_id', $r->id)->where('lesson_id', $lesson->id)->whereNull('cleared_at')->exists();
    }

    /** Why the review lesson is in the learner's path. @return array{skill: array{ar: string, en: string}, mastery: int, threshold: int, mastered_at: int, ar: string, en: string} */
    public function why(RemedialAssignment $a, CourseLesson $lesson): array
    {
        [$below, $mastered] = $this->thresholds($lesson);
        $now = LearnerMastery::where('registration_id', $a->registration_id)->where('skill_id', $a->skill_id)->value('mastery');
        $m = (int) round(((float) ($now ?? $a->mastery ?? 0)) * 100);
        $skill = ['ar' => (string) $a->skill?->name_ar, 'en' => (string) ($a->skill?->name_en ?: $a->skill?->name_ar)];
        $at = (int) round($mastered * 100);

        return ['skill' => $skill, 'mastery' => $m, 'threshold' => (int) round($below * 100), 'mastered_at' => $at,
            'ar' => "أُضيف لك لأن إتقانك لـ«{$skill['ar']}» {$m}%. يختفي من مسارك عندما تبلغ {$at}%.",
            'en' => "Added for you because your mastery of “{$skill['en']}” is {$m}%. It leaves your path once you reach {$at}%."];
    }

    /** @return 'assigned'|'cleared'|null */
    private function apply(Registration $r, CourseLesson $lesson, float $mastery): ?string
    {
        [$below, $mastered] = $this->thresholds($lesson);
        $row = RemedialAssignment::where('registration_id', $r->id)->where('lesson_id', $lesson->id)->first();
        if ($mastery < $below) {
            if ($row && $row->cleared_at === null) {
                return null;
            }
            $values = ['skill_id' => $lesson->setting('skill_id'), 'mastery' => round($mastery, 4), 'assigned_at' => now(), 'cleared_at' => null, 'cleared_mastery' => null];
            $row ? $row->update($values) : RemedialAssignment::createOrFirst(['registration_id' => $r->id, 'lesson_id' => $lesson->id], $values);
            $this->notify($r, $lesson, $mastery, $mastered);

            return 'assigned';
        }
        if ($mastery >= $mastered && $row && $row->cleared_at === null) {
            $row->update(['cleared_at' => now(), 'cleared_mastery' => round($mastery, 4)]);

            return 'cleared';
        }

        return null;
    }

    /** @return array{0: float, 1: float} assigned below, cleared at (0..1); a trainer's add-lesson rule for this lesson sets its own threshold */
    private function thresholds(CourseLesson $lesson): array
    {
        $cfg = $this->policy->adaptive();
        $rule = AdaptiveRule::where('lesson_id', $lesson->id)->where('action', 'add_lesson')->where('is_active', true)->value('remedial_below');
        $below = $rule !== null ? (float) $rule : $cfg['remedial_below'] / 100;

        return [$below, max($below, $cfg['mastered_at'] / 100)];
    }

    /** @return Collection<int, CourseLesson> */
    private function lessons(string $programId): Collection
    {
        return CourseLesson::where('program_id', $programId)->where('status', 'published')->get()->filter(fn (CourseLesson $l) => $l->isRemedial())->values();
    }

    private function notify(Registration $r, CourseLesson $lesson, float $mastery, float $mastered): void
    {
        $r->loadMissing('employee', 'program');
        if (! $r->employee?->user_id) {
            return;
        }
        $skill = Skill::find($lesson->setting('skill_id'));
        $m = (int) round($mastery * 100);
        $at = (int) round($mastered * 100);
        $detail = ['ar' => "«{$lesson->title_ar}» في برنامج «{$r->program?->title_ar}»: إتقانك لـ«{$skill?->name_ar}» {$m}%، ويختفي الدرس من مسارك عندما تبلغ {$at}%.",
            'en' => "“{$lesson->title_en}” in “{$r->program?->title_en}”: your mastery of “{$skill?->name_en}” is {$m}%. It leaves your path once you reach {$at}%."];
        $this->notifications->send($r->employee->user_id, 'course.remedial_assigned', ['ar' => 'أُضيف لك درس مراجعة', 'en' => 'A review lesson was added for you'], $detail,
            ['detail_ar' => $detail['ar'], 'detail_en' => $detail['en'], 'registration_id' => $r->id, 'program_id' => $r->program_id, 'lesson_id' => $lesson->id, 'route' => '/courses/'.$r->id]);
    }
}
