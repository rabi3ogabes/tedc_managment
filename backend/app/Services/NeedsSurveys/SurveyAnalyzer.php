<?php

namespace App\Services\NeedsSurveys;

use App\Models\JobTitle;
use App\Models\NeedsSurvey;
use App\Models\Program;
use App\Models\School;
use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * Turns survey responses into a report: per-question statistics and a ranked list of
 * training needs. Every answer linked to a skill yields a need signal between 0 and 1:
 *  - competence scales / matrices: a low self-rating is a high need,
 *  - "need" scales / matrices: a high rating is a high need,
 *  - chosen options (single, multiple): 1, ranked options: weighted by position,
 *  - yes / no: "yes" is a need.
 * The need index of a skill is the average signal × 100; respondents with a signal of at
 * least 0.5 are counted as needing training.
 */
class SurveyAnalyzer
{
    public const SEGMENTS = ['school_id', 'job_title_id', 'experience_band', 'specialization', 'nationality'];

    public static function priority(float $index): string
    {
        return match (true) {
            $index >= 70 => 'critical',
            $index >= 50 => 'high',
            $index >= 30 => 'medium',
            default => 'low',
        };
    }

    public static function band(?float $years): string
    {
        foreach (SurveyAudience::EXPERIENCE_BANDS as $label => [$min, $max]) {
            if ((float) $years <= $max && (float) $years >= $min - 0.01) {
                return $label;
            }
        }

        return '16+';
    }

    /** Responses of a survey, optionally filtered by segment values. */
    public function responses(NeedsSurvey $survey, array $filters = []): Collection
    {
        return $survey->responses()
            ->when($filters['school_id'] ?? null, fn ($q, $v) => $q->where('school_id', $v))
            ->when($filters['job_title_id'] ?? null, fn ($q, $v) => $q->where('job_title_id', $v))
            ->when($filters['specialization'] ?? null, fn ($q, $v) => $q->where('specialization', $v))
            ->when($filters['nationality'] ?? null, fn ($q, $v) => $q->where('nationality', $v))
            ->get()
            ->when($filters['experience_band'] ?? null, fn ($c, $band) => $c->filter(fn ($r) => self::band($r->experience_years) === $band)->values());
    }

    public function report(NeedsSurvey $survey, array $filters = [], string $locale = 'ar'): array
    {
        $responses = $this->responses($survey, $filters);
        $recipients = $survey->recipients()->count();
        $answered = $survey->recipients()->whereNotNull('responded_at')->count();

        return [
            'summary' => [
                'recipients' => $recipients,
                'responses' => $survey->responses()->count(),
                'filtered_responses' => $responses->count(),
                'response_rate' => $recipients ? round($answered / $recipients * 100, 1) : 0,
                'avg_duration_seconds' => (int) round((float) $responses->avg('duration_seconds')),
                'last_response_at' => $responses->max('submitted_at'),
                'timeline' => $this->timeline($responses),
            ],
            'questions' => $this->questions($survey, $responses),
            'needs' => $this->needs($survey, $responses, $locale),
            'segments' => $this->segments($survey, $locale),
        ];
    }

    /** Daily response counts for the sparkline. */
    private function timeline(Collection $responses): array
    {
        return $responses->groupBy(fn ($r) => $r->submitted_at->toDateString())
            ->map(fn ($g, $day) => ['date' => $day, 'value' => $g->count()])
            ->sortKeys()->values()->all();
    }

    private function questions(NeedsSurvey $survey, Collection $responses): array
    {
        return $this->questionStats($survey->questions, $responses);
    }

    /**
     * Per-question statistics for any list of questions and any responses that carry `answers`.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    public function questionStats(array $questions, Collection $responses): array
    {
        $out = [];
        foreach ($questions as $q) {
            if ($q['type'] === 'section') {
                continue;
            }
            $values = $responses->map(fn ($r) => $r->answers[$q['id']] ?? null)->filter(fn ($v) => SurveySchema::filled($v))->values();
            $stat = ['id' => $q['id'], 'type' => $q['type'], 'title' => $q['title'], 'answered' => $values->count()];

            switch ($q['type']) {
                case 'single':
                case 'dropdown':
                case 'multiple':
                    $counts = array_fill_keys(array_column($q['options'], 'id'), 0);
                    foreach ($values as $v) {
                        foreach ((array) $v as $id) {
                            if (isset($counts[$id])) {
                                $counts[$id]++;
                            }
                        }
                    }
                    $stat['options'] = array_map(fn ($o) => ['id' => $o['id'], 'label' => $o['label'], 'count' => $counts[$o['id']], 'percent' => $values->count() ? round($counts[$o['id']] / $values->count() * 100, 1) : 0], $q['options']);
                    break;
                case 'ranking':
                    $n = count($q['options']);
                    $stat['options'] = collect($q['options'])->map(function ($o) use ($values, $n) {
                        $score = $values->sum(fn ($order) => ($pos = array_search($o['id'], $order, true)) === false ? 0 : $n - $pos);

                        return ['id' => $o['id'], 'label' => $o['label'], 'score' => $values->count() ? round($score / $values->count(), 2) : 0];
                    })->sortByDesc('score')->values()->all();
                    break;
                case 'yes_no':
                    $yes = $values->filter(fn ($v) => $v === 'yes')->count();
                    $stat['options'] = [
                        ['id' => 'yes', 'label' => 'نعم', 'count' => $yes, 'percent' => $values->count() ? round($yes / $values->count() * 100, 1) : 0],
                        ['id' => 'no', 'label' => 'لا', 'count' => $values->count() - $yes, 'percent' => $values->count() ? round(($values->count() - $yes) / $values->count() * 100, 1) : 0],
                    ];
                    break;
                case 'rating':
                case 'scale':
                case 'number':
                    $stat['average'] = $values->count() ? round($values->avg(), 2) : null;
                    if ($q['type'] !== 'number') {
                        $scale = $q['scale'];
                        $stat['scale'] = $scale;
                        $stat['distribution'] = collect(range($scale['min'], $scale['max']))->map(fn ($p) => ['label' => (string) $p, 'value' => $values->filter(fn ($v) => (int) $v === $p)->count()])->all();
                    }
                    break;
                case 'nps':
                    $promoters = $values->filter(fn ($v) => $v >= 9)->count();
                    $detractors = $values->filter(fn ($v) => $v <= 6)->count();
                    $stat['average'] = $values->count() ? round($values->avg(), 1) : null;
                    $stat['nps'] = $values->count() ? (int) round(($promoters - $detractors) / $values->count() * 100) : null;
                    $stat['distribution'] = collect(range(0, 10))->map(fn ($p) => ['label' => (string) $p, 'value' => $values->filter(fn ($v) => (int) $v === $p)->count()])->all();
                    break;
                case 'matrix':
                    $stat['scale'] = $q['scale'];
                    $stat['mode'] = $q['mode'] ?? 'competence';
                    $stat['rows'] = array_map(function ($row) use ($values) {
                        $scores = $values->map(fn ($v) => $v[$row['id']] ?? null)->filter(fn ($v) => $v !== null);

                        return ['id' => $row['id'], 'label' => $row['label'], 'average' => $scores->count() ? round($scores->avg(), 2) : null, 'answered' => $scores->count()];
                    }, $q['rows']);
                    break;
                default: // text and dates: latest answers
                    $stat['samples'] = $values->reverse()->take(30)->map(fn ($v) => (string) $v)->values()->all();
            }
            $out[] = $stat;
        }

        return $out;
    }

    /** Need signals per skill for each response: [skillKey => [responseIndex => signal]]. */
    public function signals(NeedsSurvey $survey, Collection $responses): array
    {
        $signals = [];
        $meta = [];
        $add = function (array $source, int $i, float $signal) use (&$signals, &$meta) {
            $key = $source['skill_id'] ?? (isset($source['skill_name']) ? 'name:'.mb_strtolower(trim($source['skill_name'])) : null);
            if (! $key) {
                return;
            }
            $meta[$key] ??= ['skill_id' => $source['skill_id'] ?? null, 'skill_name' => $source['skill_name'] ?? $source['label'] ?? $key];
            // Several questions can target the same skill: keep the strongest signal per person.
            $signals[$key][$i] = max($signals[$key][$i] ?? 0, $signal);
        };
        $norm = fn (float $v, array $scale, string $mode) => ($mode === 'need' ? $v - $scale['min'] : $scale['max'] - $v) / max(1, $scale['max'] - $scale['min']);

        foreach ($responses->values() as $i => $response) {
            foreach ($survey->questions as $q) {
                $answer = $response->answers[$q['id']] ?? null;
                if (! SurveySchema::filled($answer)) {
                    continue;
                }
                switch ($q['type']) {
                    case 'matrix':
                        foreach ($q['rows'] as $row) {
                            if (isset($answer[$row['id']])) {
                                $add($row, $i, $norm((float) $answer[$row['id']], $q['scale'], $q['mode'] ?? 'competence'));
                            }
                        }
                        break;
                    case 'rating':
                    case 'scale':
                        $add($q, $i, $norm((float) $answer, $q['scale'], $q['mode'] ?? 'competence'));
                        break;
                    case 'yes_no':
                        $add($q, $i, $answer === 'yes' ? 1.0 : 0.0);
                        break;
                    case 'single':
                    case 'dropdown':
                    case 'multiple':
                        foreach ($q['options'] as $o) {
                            $add($o, $i, in_array($o['id'], (array) $answer, true) ? 1.0 : 0.0);
                        }
                        break;
                    case 'ranking':
                        $n = count($q['options']);
                        foreach ($q['options'] as $o) {
                            $pos = array_search($o['id'], $answer, true);
                            $add($o, $i, $pos === false ? 0.0 : ($n - $pos) / $n);
                        }
                        break;
                }
            }
        }

        return [$signals, $meta];
    }

    private function needs(NeedsSurvey $survey, Collection $responses, string $locale): array
    {
        [$signals, $meta] = $this->signals($survey, $responses);
        $responses = $responses->values();
        $skillIds = array_filter(array_column($meta, 'skill_id'));
        $skills = Skill::whereIn('id', $skillIds)->get()->keyBy('id');
        $programs = Program::query()
            ->whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN, Program::STATUS_IN_PROGRESS, Program::STATUS_DRAFT])
            ->whereHas('skills', fn ($q) => $q->whereIn('skills.id', $skillIds))
            ->with('skills:id')->get(['id', 'code', 'title_ar', 'title_en', 'status', 'start_date']);
        $schoolNames = School::whereIn('id', $responses->pluck('school_id')->filter()->unique())->pluck($locale === 'en' ? 'name_en' : 'name_ar', 'id');
        $titleNames = JobTitle::whereIn('id', $responses->pluck('job_title_id')->filter()->unique())->pluck($locale === 'en' ? 'name_en' : 'name_ar', 'id');
        $existing = $survey->trainingNeeds()->get(['skill_id', 'skill_name', 'school_id']);

        $needs = [];
        foreach ($signals as $key => $byResponse) {
            $considered = count($byResponse);
            if (! $considered) {
                continue;
            }
            $index = array_sum($byResponse) / $considered * 100;
            $inNeed = array_keys(array_filter($byResponse, fn ($s) => $s >= 0.5));
            $skill = $meta[$key]['skill_id'] ? $skills->get($meta[$key]['skill_id']) : null;
            $name = $skill ? ($locale === 'en' ? $skill->name_en : $skill->name_ar) : $meta[$key]['skill_name'];

            $breakdown = function (string $field, $names = null) use ($responses, $byResponse) {
                return collect($byResponse)->groupBy(fn ($s, $i) => $field === 'experience_band' ? self::band($responses[$i]->experience_years) : ($responses[$i]->{$field} ?? '—'), true)
                    ->map(fn ($group, $k) => [
                        'key' => (string) $k,
                        'label' => $names ? ($names[$k] ?? '—') : (string) $k,
                        'index' => round($group->avg() * 100),
                        'in_need' => $group->filter(fn ($s) => $s >= 0.5)->count(),
                        'respondents' => $group->count(),
                    ])->sortByDesc('index')->values()->all();
            };

            $needs[] = [
                'key' => $key,
                'skill_id' => $meta[$key]['skill_id'],
                'skill_name' => $name,
                'category' => $skill?->category,
                'index' => (int) round($index),
                'priority' => self::priority(round($index)),
                'in_need' => count($inNeed),
                'respondents' => $considered,
                'share' => round(count($inNeed) / $considered * 100, 1),
                'by_school' => $breakdown('school_id', $schoolNames),
                'by_job_title' => $breakdown('job_title_id', $titleNames),
                'by_experience' => $breakdown('experience_band'),
                'programs' => $programs->filter(fn ($p) => $meta[$key]['skill_id'] && $p->skills->contains('id', $meta[$key]['skill_id']))
                    ->take(4)->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'title' => $locale === 'en' ? $p->title_en : $p->title_ar, 'status' => $p->status, 'start_date' => $p->start_date?->toDateString()])->values()->all(),
                'generated' => $existing->contains(fn ($n) => $meta[$key]['skill_id'] ? $n->skill_id === $meta[$key]['skill_id'] : $n->skill_name === $meta[$key]['skill_name']),
            ];
        }

        usort($needs, fn ($a, $b) => [$b['index'], $b['in_need']] <=> [$a['index'], $a['in_need']]);

        return $needs;
    }

    /** Filter values available in the report (only those present in responses). */
    private function segments(NeedsSurvey $survey, string $locale): array
    {
        $name = $locale === 'en' ? 'name_en' : 'name_ar';
        $rows = $survey->responses()->get(['school_id', 'job_title_id', 'specialization', 'nationality', 'experience_years']);

        return [
            'schools' => School::whereIn('id', $rows->pluck('school_id')->filter()->unique())->orderBy($name)->get(['id', $name.' as name']),
            'job_titles' => JobTitle::whereIn('id', $rows->pluck('job_title_id')->filter()->unique())->orderBy($name)->get(['id', $name.' as name']),
            'specializations' => $rows->pluck('specialization')->filter()->unique()->sort()->values(),
            'nationalities' => $rows->pluck('nationality')->filter()->unique()->sort()->values(),
            'experience_bands' => $rows->map(fn ($r) => self::band($r->experience_years))->unique()->sort()->values(),
        ];
    }
}
