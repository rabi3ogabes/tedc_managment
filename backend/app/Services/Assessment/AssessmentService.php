<?php

namespace App\Services\Assessment;

use App\Exceptions\BusinessRuleException;
use App\Models\Assessment;
use App\Models\AssessmentSection;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Building an assessment: sections with fixed questions or random draws by category and difficulty, validation and publishing. */
class AssessmentService
{
    /** @param  list<array<string, mixed>>  $sections */
    public function replaceSections(Assessment $a, array $sections): Assessment
    {
        DB::transaction(function () use ($a, $sections) {
            $a->sections()->delete();
            foreach (array_values($sections) as $i => $s) {
                AssessmentSection::create(['assessment_id' => $a->id, 'sort_order' => $i, 'title' => $s['title'] ?? null, 'selection' => $s['selection'] ?? 'fixed', 'bank_id' => $s['bank_id'] ?? null, 'category_ids' => $s['category_ids'] ?? null,
                    'difficulty_mix' => $s['difficulty_mix'] ?? null, 'count' => $s['count'] ?? null, 'points_per_question' => $s['points_per_question'] ?? null, 'question_ids' => $s['question_ids'] ?? null]);
            }
        });

        return $a->fresh('sections');
    }

    /** What the sections can deliver: per section how many questions exist against how many are needed. */
    public function validate(Assessment $a): array
    {
        $a->loadMissing('sections');
        $report = [];
        $valid = $a->sections->isNotEmpty();
        $points = 0.0;
        $questions = 0;
        foreach ($a->sections as $s) {
            [$needed, $available, $shortages, $pts] = $this->coverage($s);
            $ok = $needed > 0 && $shortages === [];
            $valid = $valid && $ok;
            $points += $pts;
            $questions += $needed;
            $report[] = ['id' => $s->id, 'selection' => $s->selection, 'needed' => $needed, 'available' => $available, 'ok' => $ok, 'shortages' => $shortages];
        }
        $needsCode = $a->delivery === 'in_center' && $a->access_code_mode === 'none';
        if ($needsCode) {
            $valid = false;
        }

        return ['valid' => $valid, 'total_points' => round($points, 2), 'questions' => $questions, 'sections' => $report, 'issues' => array_values(array_filter([$needsCode ? 'access_code_required' : null, $a->sections->isEmpty() ? 'no_sections' : null]))];
    }

    public function publish(Assessment $a): Assessment
    {
        $v = $this->validate($a);
        if (! $v['valid']) {
            throw new BusinessRuleException(__('messages.assessment.not_ready'), 'assessment_not_ready', $v);
        }
        $a->update(['status' => 'published']);

        return $a;
    }

    /**
     * Draws the questions of one section: fixed ids, or a random pick honouring the category and difficulty mix.
     *
     * @return Collection<int, Question>
     */
    public function draw(AssessmentSection $s): Collection
    {
        if ($s->selection === 'fixed') {
            $ids = $s->question_ids ?? [];

            return Question::whereIn('id', $ids)->get()->sortBy(fn ($q) => array_search($q->id, $ids))->values();
        }
        $base = fn () => Question::where('bank_id', $s->bank_id)->where('status', 'active')->when($s->category_ids, fn ($q, $c) => $q->whereIn('category_id', $c));
        $mix = $s->difficulty_mix ?: [];
        if ($mix) {
            $picked = collect();
            foreach ($mix as $difficulty => $n) {
                $picked = $picked->merge($base()->where('difficulty', $difficulty)->inRandomOrder()->limit((int) $n)->get());
            }

            return $picked->shuffle()->values();
        }

        return $base()->inRandomOrder()->limit((int) ($s->count ?? 0))->get();
    }

    /** @return array{0: int, 1: int, 2: list<array<string, mixed>>, 3: float} needed, available, shortages, points */
    private function coverage(AssessmentSection $s): array
    {
        if ($s->selection === 'fixed') {
            $ids = $s->question_ids ?? [];
            $found = Question::whereIn('id', $ids)->get();

            return [count($ids), $found->count(), $found->count() === count($ids) ? [] : [['difficulty' => null, 'needed' => count($ids), 'available' => $found->count()]], $found->sum(fn ($q) => $s->points_per_question ?? $q->points)];
        }
        $base = fn () => Question::where('bank_id', $s->bank_id)->where('status', 'active')->when($s->category_ids, fn ($q, $c) => $q->whereIn('category_id', $c));
        $mix = $s->difficulty_mix ?: [];
        if (! $mix) {
            $need = (int) ($s->count ?? 0);
            $have = $base()->count();

            return [$need, $have, $need > $have ? [['difficulty' => null, 'needed' => $need, 'available' => $have]] : [], $need * (float) ($s->points_per_question ?? 1)];
        }
        $short = [];
        $need = 0;
        $have = 0;
        $pts = 0.0;
        foreach ($mix as $difficulty => $n) {
            $avail = $base()->where('difficulty', $difficulty)->count();
            $need += (int) $n;
            $have += min($avail, (int) $n);
            $pts += (int) $n * (float) ($s->points_per_question ?? ($base()->where('difficulty', $difficulty)->avg('points') ?? 1));
            if ($avail < (int) $n) {
                $short[] = ['difficulty' => $difficulty, 'needed' => (int) $n, 'available' => $avail];
            }
        }

        return [$need, $have, $short, $pts];
    }
}
