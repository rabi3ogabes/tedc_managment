<?php

namespace App\Services\Assessment;

use App\Models\AssessmentAttempt;
use App\Models\Registration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** What a graded attempt feeds elsewhere: competency evidence from diagnostics, and the knowledge gain between pre and post tests. */
class KnowledgeService
{
    public function afterGraded(AssessmentAttempt $at): void
    {
        $a = $at->assessment;
        if (in_array($a->kind, ['diagnostic', 'comprehensive'], true)) {
            $this->competencyEvidence($at);
        }
    }

    /** Each competency the questions are mapped to gets a level from the share of its questions answered correctly. */
    private function competencyEvidence(AssessmentAttempt $at): void
    {
        $per = [];
        foreach ($at->questions as $item) {
            $ratio = QuestionTypes::get($item['type'])->grade($item['payload'], ($at->answers ?? [])[$item['id']] ?? null);
            if ($ratio['manual']) {
                continue;
            }
            foreach ($item['skill_ids'] ?? [] as $skillId) {
                $per[$skillId][] = $ratio['ratio'];
            }
        }
        $employeeId = $at->registration->employee_id;
        foreach ($per as $skillId => $ratios) {
            $level = max(1, min(5, (int) round(1 + 4 * array_sum($ratios) / count($ratios))));
            DB::table('employee_skills')->updateOrInsert(['employee_id' => $employeeId, 'skill_id' => $skillId], ['id' => (string) Str::uuid(), 'level' => $level, 'source' => 'test', 'program_id' => $at->assessment->program_id, 'verified_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    /** Best score of the pre-test and the post-test per registration, and the gain. @return list<array<string, mixed>> */
    public function gain(string $programId, ?string $groupId = null): array
    {
        $kinds = ['pre_test' => 'pre', 'post_test' => 'post'];
        $rows = [];
        $attempts = AssessmentAttempt::with(['assessment:id,kind,program_id,group_id', 'registration.employee.user:id,name,name_ar'])->where('status', 'graded')
            ->whereHas('assessment', fn ($q) => $q->where('program_id', $programId)->whereIn('kind', array_keys($kinds)))->get();
        foreach ($attempts->groupBy('registration_id') as $rid => $group) {
            $reg = $group->first()->registration;
            if ($groupId && $reg->training_group_id !== $groupId) {
                continue;
            }
            $pre = $group->filter(fn ($a) => $a->assessment->kind === 'pre_test')->max('score_percent');
            $post = $group->filter(fn ($a) => $a->assessment->kind === 'post_test')->max('score_percent');
            $rows[] = ['registration_id' => $rid, 'employee' => $reg->employee->user?->displayName(), 'pre' => $pre, 'post' => $post, 'gain' => $pre !== null && $post !== null ? round($post - $pre, 2) : null,
                'gain_percent' => $pre !== null && $post !== null && $pre > 0 ? round(($post - $pre) / $pre * 100, 1) : null];
        }

        return $rows;
    }

    /** Pre / post scores from real tests for an evaluation (the self-typed values stay only as history). @return array{pre: ?float, post: ?float} */
    public function scoresFor(Registration $r): array
    {
        $best = fn (string $kind) => AssessmentAttempt::where('registration_id', $r->id)->where('status', 'graded')->whereHas('assessment', fn ($q) => $q->where('kind', $kind))->max('score_percent');

        return ['pre' => $best('pre_test'), 'post' => $best('post_test')];
    }
}
