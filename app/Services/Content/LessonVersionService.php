<?php

namespace App\Services\Content;

use App\Exceptions\BusinessRuleException;
use App\Models\CourseLesson;
use App\Models\CourseLessonVersion;
use App\Models\LessonProgress;
use App\Models\User;

/** Lesson versions: every published change is a snapshot; learners in progress can keep theirs or move to the new one; old versions can be diffed, restored and archived. */
class LessonVersionService
{
    private const FIELDS = ['title_ar', 'title_en', 'description_ar', 'description_en', 'body_ar', 'body_en', 'duration_seconds', 'settings', 'source', 'file_path', 'file_name', 'file_mime', 'file_size', 'external_url', 'slide_count', 'package_id', 'package_item_id', 'lti_tool_id', 'external_course_id'];

    /** @return array<string, mixed> */
    public function snapshot(CourseLesson $lesson): array
    {
        return ['fields' => $lesson->only(self::FIELDS), 'questions' => $lesson->questions()->get()->map(fn ($q) => $q->only(['type', 'text_ar', 'text_en', 'options', 'points', 'explanation_ar', 'explanation_en', 'sort_order']))->all(),
            'survey_questions' => $lesson->surveyQuestions()->get()->map(fn ($q) => $q->only(['type', 'text_ar', 'text_en', 'options', 'required', 'sort_order']))->all()];
    }

    /** Makes sure the current version has its snapshot (lessons created before versioning get version 1 on first use). */
    public function ensureCurrent(CourseLesson $lesson, ?User $by = null): CourseLessonVersion
    {
        return CourseLessonVersion::firstOrCreate(['lesson_id' => $lesson->id, 'version' => $lesson->version], ['snapshot' => $this->snapshot($lesson), 'note' => null, 'created_by' => $by?->id]);
    }

    /**
     * Publishes the lesson's present content as a new version.
     *
     * @param  'keep'|'move'  $learners  keep: learners already in the lesson stay on their version; move: they continue on the new one
     */
    public function publish(CourseLesson $lesson, ?string $note, string $learners, User $by): CourseLessonVersion
    {
        // The first version of a lesson nobody has used yet is just its present content.
        if (! CourseLessonVersion::where('lesson_id', $lesson->id)->exists()) {
            return $this->ensureCurrent($lesson, $by);
        }
        $current = $this->ensureCurrent($lesson, $by);
        // The content on the lesson row is the working copy: refresh the current snapshot only if nothing has used it yet.
        if (! LessonProgress::where('lesson_id', $lesson->id)->exists() && $current->snapshot !== $this->snapshot($lesson)) {
            $current->update(['snapshot' => $this->snapshot($lesson)]);
        }
        $same = $current->snapshot === $this->snapshot($lesson);
        if ($same) {
            throw new BusinessRuleException(__('messages.lesson.no_changes'), 'no_changes');
        }
        $next = $lesson->version + 1;
        $lesson->update(['version' => $next]);
        $v = CourseLessonVersion::create(['lesson_id' => $lesson->id, 'version' => $next, 'snapshot' => $this->snapshot($lesson->fresh()), 'note' => $note, 'created_by' => $by->id]);

        // Those who started under an older version keep it, or are moved to the new one (completed lessons are left alone).
        if ($learners === 'move') {
            LessonProgress::where('lesson_id', $lesson->id)->where('status', '!=', 'completed')->update(['lesson_version' => $next]);
        } else {
            LessonProgress::where('lesson_id', $lesson->id)->whereNull('lesson_version')->update(['lesson_version' => $next - 1]);
        }

        return $v;
    }

    /** Puts an older version's content back on the lesson as a new version. */
    public function restore(CourseLesson $lesson, int $version, User $by): CourseLessonVersion
    {
        $old = CourseLessonVersion::where('lesson_id', $lesson->id)->where('version', $version)->firstOrFail();
        $this->ensureCurrent($lesson, $by);
        $lesson->update($old->snapshot['fields']);
        $lesson->questions()->delete();
        foreach ($old->snapshot['questions'] as $q) {
            $lesson->questions()->create($q);
        }
        $lesson->surveyQuestions()->delete();
        foreach ($old->snapshot['survey_questions'] as $q) {
            $lesson->surveyQuestions()->create($q);
        }

        return $this->publish($lesson->fresh(), "Restored from version {$version}", 'keep', $by);
    }

    /** @return array{from: int, to: int, changed: list<array<string, mixed>>, questions: array{from: int, to: int}} */
    public function diff(CourseLesson $lesson, int $from, int $to): array
    {
        $a = CourseLessonVersion::where('lesson_id', $lesson->id)->where('version', $from)->firstOrFail()->snapshot;
        $b = CourseLessonVersion::where('lesson_id', $lesson->id)->where('version', $to)->firstOrFail()->snapshot;
        $changed = [];
        foreach (self::FIELDS as $f) {
            if (($a['fields'][$f] ?? null) != ($b['fields'][$f] ?? null)) {
                $changed[] = ['field' => $f, 'from' => $a['fields'][$f] ?? null, 'to' => $b['fields'][$f] ?? null];
            }
        }
        $qa = array_column($a['questions'], 'text_ar');
        $qb = array_column($b['questions'], 'text_ar');

        return ['from' => $from, 'to' => $to, 'changed' => $changed, 'questions' => ['from' => count($qa), 'to' => count($qb), 'added' => array_values(array_diff($qb, $qa)), 'removed' => array_values(array_diff($qa, $qb))]];
    }

    /** What a learner on an older version sees instead of the lesson's current content. @return array<string, mixed>|null */
    public function overlay(CourseLesson $lesson, ?LessonProgress $progress): ?array
    {
        if (! $progress || $progress->lesson_version === null || $progress->lesson_version >= $lesson->version || $progress->status === 'completed') {
            return null;
        }
        $v = CourseLessonVersion::where('lesson_id', $lesson->id)->where('version', $progress->lesson_version)->first();

        return $v?->snapshot;
    }
}
