<?php

namespace App\Ai\Adaptive;

use App\Models\AdaptiveRule;
use App\Models\AuditLog;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LearnerMastery;
use App\Models\LessonProgress;
use App\Models\Registration;
use App\Services\CourseService;

/**
 * A learner's path through a course. The trainer sets rules per competency: a module the learner has mastered is skipped (test-out), a remedial lesson is
 * added when mastery is low. Each step in the path says why. Skipping is recorded as completed lessons and written to the audit log.
 */
class AdaptivePath
{
    public function __construct(private readonly CourseService $course) {}

    /** @return array<string, mixed> */
    public function for(Registration $r): array
    {
        $rules = AdaptiveRule::with('skill:id,name_ar,name_en')->where('program_id', $r->program_id)->where('is_active', true)->get();
        $mastery = LearnerMastery::where('registration_id', $r->id)->pluck('mastery', 'skill_id');
        $evidence = LearnerMastery::where('registration_id', $r->id)->pluck('evidence_count', 'skill_id');
        $skipped = [];
        $added = [];
        foreach ($rules as $rule) {
            $m = $mastery[$rule->skill_id] ?? null;
            $skill = ['ar' => $rule->skill?->name_ar, 'en' => $rule->skill?->name_en];
            if ($m === null) {
                continue;
            }
            if ($rule->action === 'skip_module' && $rule->module_id && $m >= $rule->skip_at) {
                $skipped[$rule->module_id] = ['module_id' => $rule->module_id, 'skill' => $skill, 'mastery' => round($m, 2), 'threshold' => $rule->skip_at, 'reason' => 'mastered'];
            }
            if ($rule->action === 'add_lesson' && $rule->lesson_id && $m < $rule->remedial_below && ($evidence[$rule->skill_id] ?? 0) > 0) {
                $added[$rule->lesson_id] = ['lesson_id' => $rule->lesson_id, 'skill' => $skill, 'mastery' => round($m, 2), 'threshold' => $rule->remedial_below, 'reason' => 'weak'];
            }
        }
        $modules = CourseModule::with(['lessons' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order')])->where('program_id', $r->program_id)->orderBy('sort_order')->get();
        $done = LessonProgress::where('registration_id', $r->id)->where('status', 'completed')->pluck('lesson_id')->all();
        $out = [];
        foreach ($modules as $mod) {
            $skip = $skipped[$mod->id] ?? null;
            $out[] = ['module_id' => $mod->id, 'title_ar' => $mod->title_ar, 'title_en' => $mod->title_en, 'state' => $skip ? 'skipped' : 'required', 'why' => $skip,
                'lessons' => $mod->lessons->map(fn (CourseLesson $l) => ['id' => $l->id, 'title_ar' => $l->title_ar, 'title_en' => $l->title_en, 'completed' => in_array($l->id, $done, true), 'remedial' => isset($added[$l->id]), 'why' => $added[$l->id] ?? null])->all()];
        }

        return ['modules' => $out, 'skipped' => array_values($skipped), 'remedial' => array_values($added), 'mastery' => $mastery->map(fn ($v) => round($v, 2))->all()];
    }

    /** Marks the lessons of mastered modules as completed (once) and recomputes the course. @return int lessons newly completed */
    public function apply(Registration $r): int
    {
        $path = $this->for($r);
        $n = 0;
        $lessonIds = [];
        foreach ($path['skipped'] as $s) {
            $lessons = CourseLesson::where('module_id', $s['module_id'])->where('status', 'published')->get();
            foreach ($lessons as $l) {
                $p = LessonProgress::firstOrNew(['lesson_id' => $l->id, 'registration_id' => $r->id]);
                if ($p->status === 'completed') {
                    continue;
                }
                $p->fill(['employee_id' => $r->employee_id, 'lesson_version' => $l->version ?? 1, 'status' => 'completed', 'percent' => 100, 'completed_at' => now(), 'first_opened_at' => $p->first_opened_at ?? now(), 'last_activity_at' => now()])->save();
                $lessonIds[] = $l->id;
                $n++;
            }
        }
        if ($n) {
            AuditLog::create(['user_id' => null, 'action' => 'adaptive_skip', 'auditable_type' => Registration::class, 'auditable_id' => $r->id, 'new_values' => ['lessons' => $lessonIds, 'modules' => array_column($path['skipped'], 'module_id')]]);
            $this->course->recompute($r);
        }

        return $n;
    }
}
