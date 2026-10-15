<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\JobTitle;
use App\Models\School;
use App\Models\Skill;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveySubmission;
use App\Models\TrainingNeed;
use App\Models\User;
use App\Services\NeedsSurveys\SurveyAnalyzer;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\SurveyDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Survey Studio v2, Gate 6: results on the engine. Per-question statistics with segment filters under the survey's
 * threshold rule (a segment smaller than the threshold is neither offered nor shown unless the viewer may see identified
 * answers of an identified survey), individual answers gated by `surveys.results.identified`, comparisons across surveys
 * that share questions, exports through the shared document exporter, the needs report for needs surveys, and the
 * overview of the whole studio.
 */
class SurveyAnalytics
{
    public const COMPARABLE = ['rating', 'scale', 'nps', 'likert', 'single', 'dropdown', 'yes_no', 'multiple', 'matrix'];

    public function __construct(private readonly SurveyAnalyzer $analyzer, private readonly ReportExporter $exporter) {}

    // Submissions ----------------------------------------------------------------------------------------------------

    /** The survey's own answers (never the context answers of evaluation assignments), optionally one version and a segment. */
    public function submissions(Survey $survey, array $filters = [], ?int $version = null): Collection
    {
        $q = $survey->submissions()->whereNull('context_id')->orderBy('submitted_at');
        if ($version) {
            $q->where('version_id', $survey->versions()->where('number', $version)->value('id') ?? '0');
        }
        $rows = $q->get();
        foreach (SurveyPrivacy::SEGMENT_FIELDS as $f) {
            if (($filters[$f] ?? '') !== '') {
                $rows = $rows->filter(fn (SurveySubmission $s) => (string) ($s->segment_snapshot[$f] ?? '') === (string) $filters[$f]);
            }
        }
        if (($filters['source'] ?? '') !== '') {
            $rows = $rows->where('source', $filters['source']);
        }

        return $rows->values();
    }

    public function threshold(Survey $survey): int
    {
        return max(2, (int) $survey->setting('segment_threshold', config('tedc.surveys.segment_threshold', 5)));
    }

    // Results --------------------------------------------------------------------------------------------------------

    public function results(Survey $survey, array $filters = [], ?int $version = null, bool $canIdentify = false, string $locale = 'ar'): array
    {
        $survey->loadMissing('currentVersion');
        $ver = $version ? $survey->versions()->where('number', $version)->first() : $survey->currentVersion;
        $questions = $ver?->questions ?? $survey->questions ?? [];
        $all = $this->submissions($survey, [], $version);
        $filtered = $this->isFiltered($filters) ? $this->submissions($survey, $filters, $version) : $all;
        $threshold = $this->threshold($survey);
        $open = $canIdentify && $survey->isIdentified();
        $hidden = $this->isFiltered($filters) && $filtered->count() > 0 && $filtered->count() < $threshold && ! $open;

        $participants = $survey->participants()->count();
        $answered = $survey->participants()->whereNotNull('responded_at')->count();
        $versions = $survey->versions()->orderBy('number')->get(['id', 'number', 'published_at']);
        $byVersion = $all->countBy('version_id');

        $summary = [
            'participants' => $participants, 'answered' => $answered, 'response_rate' => $participants ? round($answered / $participants * 100, 1) : null,
            'submissions' => $all->count(), 'filtered' => $filtered->count(), 'edited' => $all->where('edit_count', '>', 0)->count(),
            'avg_duration_seconds' => (int) round((float) $filtered->whereNotNull('duration_seconds')->avg('duration_seconds')),
            'last_response_at' => $all->max('submitted_at'), 'first_response_at' => $all->min('submitted_at'),
            'timeline' => $all->groupBy(fn ($s) => $s->submitted_at->toDateString())->map(fn ($g, $day) => ['date' => $day, 'value' => $g->count()])->sortKeys()->values()->all(),
            'by_source' => $all->countBy('source')->all(),
            'versions' => $versions->map(fn ($v) => ['number' => $v->number, 'published_at' => $v->published_at, 'submissions' => $byVersion[$v->id] ?? 0])->all(),
            'version' => $ver?->number, 'threshold' => $threshold, 'hidden' => $hidden, 'privacy_mode' => $survey->privacy_mode,
        ];
        $stats = $hidden ? [] : $this->analyzer->questionStats($this->answerable($questions), $filtered);
        if (! $hidden) {
            $stats = $this->withFiles($survey, $stats, $filtered);
        }
        $needs = [];
        if ($survey->kind === 'needs' && ! $hidden) {
            $rows = $this->analyzer->engineRows($survey->id)->whereIn('id', $filtered->pluck('id'))->values();
            $needs = $this->analyzer->needsFor($questions, $rows, $locale, TrainingNeed::where('engine_survey_id', $survey->id)->get(['skill_id', 'skill_name', 'school_id']));
        }

        return ['summary' => $summary, 'questions' => $stats, 'segments' => $this->segments($survey, $all, $threshold, $open, $locale), 'needs' => $needs];
    }

    /** The answerable questions, in order, without the layout types. */
    public function answerable(array $questions): array
    {
        return array_values(array_filter($questions, fn ($q) => ! in_array($q['type'], SurveyDefinition::NO_ANSWER, true)));
    }

    /** The segment values a viewer may filter by: only values with at least the threshold of answers behind them (unless identified answers are open to the viewer). */
    private function segments(Survey $survey, Collection $all, int $threshold, bool $open, string $locale): array
    {
        $fields = $survey->setting('segment_fields') ?: ($survey->isIdentified() ? SurveyPrivacy::SEGMENT_FIELDS : []);
        $name = $locale === 'en' ? 'name_en' : 'name_ar';
        $out = [];
        foreach (array_intersect(SurveyPrivacy::SEGMENT_FIELDS, $fields) as $field) {
            $counts = $all->map(fn ($s) => $s->segment_snapshot[$field] ?? null)->filter(fn ($v) => $v !== null && $v !== '')->countBy()->filter(fn ($n) => $open || $n >= $threshold);
            if ($counts->isEmpty()) {
                continue;
            }
            $labels = match ($field) {
                'school_id' => School::whereIn('id', $counts->keys())->pluck($name, 'id'),
                'job_title_id' => JobTitle::whereIn('id', $counts->keys())->pluck($name, 'id'),
                default => collect(),
            };
            $out[$field] = $counts->map(fn ($n, $v) => ['value' => (string) $v, 'label' => (string) ($labels[$v] ?? $v), 'count' => $n])->sortByDesc('count')->values()->all();
        }

        return $out;
    }

    private function withFiles(Survey $survey, array $stats, Collection $submissions): array
    {
        $ids = $submissions->pluck('id');
        foreach ($stats as &$s) {
            if ($s['type'] === 'file_upload') {
                $s['files'] = $ids->isEmpty() ? 0 : SurveyFile::where('survey_id', $survey->id)->whereIn('submission_id', $ids)->where('question_id', $s['id'])->count();
                unset($s['samples']);
            }
        }

        return $stats;
    }

    /** Whether a segment (or source) filter is applied; other query keys (format, language, version, page) are not filters. */
    private function isFiltered(array $filters): bool
    {
        $keys = array_merge(SurveyPrivacy::SEGMENT_FIELDS, ['source']);

        return (bool) array_filter(array_intersect_key($filters, array_flip($keys)), fn ($v) => $v !== null && $v !== '');
    }

    // Individual answers ---------------------------------------------------------------------------------------------

    /** One row per submission. The person appears only for identified surveys and viewers holding the identified permission; segments only above the threshold. */
    public function answers(Survey $survey, array $filters, int $page, int $perPage, bool $canIdentify): array
    {
        $survey->loadMissing('currentVersion');
        $rows = $this->submissions($survey, $filters);
        $threshold = $this->threshold($survey);
        $open = $canIdentify && $survey->isIdentified();
        if ($this->isFiltered($filters) && $rows->count() > 0 && $rows->count() < $threshold && ! $open) {
            throw new BusinessRuleException(__('messages.surveys.segment_hidden', ['n' => $threshold]), 'survey_segment_hidden', ['threshold' => $threshold]);
        }
        $total = $rows->count();
        $slice = $rows->sortByDesc('submitted_at')->slice(($page - 1) * $perPage, $perPage)->values();
        $users = $open ? User::whereIn('id', $slice->pluck('respondent_user_id')->filter())->with('employee.school:id,name_ar,name_en')->get()->keyBy('id') : collect();
        $files = SurveyFile::whereIn('submission_id', $slice->pluck('id'))->get()->groupBy('submission_id');
        $versions = $survey->versions()->pluck('number', 'id');
        $showSegments = $open || $total >= $threshold;

        return [
            'total' => $total, 'page' => $page, 'per_page' => $perPage, 'identified' => $open, 'segments_shown' => $showSegments,
            'rows' => $slice->map(function (SurveySubmission $s) use ($users, $files, $versions, $open, $showSegments) {
                $u = $open ? $users->get($s->respondent_user_id) : null;

                return [
                    'id' => $s->id, 'submitted_at' => $s->submitted_at, 'edited_at' => $s->edited_at, 'edit_count' => $s->edit_count, 'source' => $s->source, 'version' => $versions[$s->version_id] ?? null,
                    'duration_seconds' => $s->duration_seconds,
                    'respondent' => $u ? ['id' => $u->id, 'name' => $u->displayName(), 'school' => $u->employee?->school?->name_ar] : null,
                    'segment' => $showSegments ? $s->segment_snapshot : null,
                    'answers' => $s->answers,
                    'files' => ($files->get($s->id) ?? collect())->map(fn ($f) => ['id' => $f->id, 'question_id' => $f->question_id, 'name' => $f->original_name, 'size' => $f->size, 'mime' => $f->mime])->values()->all(),
                ];
            })->all(),
        ];
    }

    // Comparison -----------------------------------------------------------------------------------------------------

    /** Surveys side by side: their summaries and every comparable question they share by id. */
    public function compare(Collection $surveys, string $locale = 'ar'): array
    {
        $per = [];
        foreach ($surveys as $s) {
            $s->loadMissing('currentVersion');
            $subs = $this->submissions($s);
            $participants = $s->participants()->count();
            $per[$s->id] = [
                'survey' => ['id' => $s->id, 'title' => $s->title($locale), 'kind' => $s->kind, 'status' => $s->status, 'published_at' => $s->published_at, 'closed_at' => $s->closed_at],
                'submissions' => $subs->count(), 'participants' => $participants, 'response_rate' => $participants ? round($s->participants()->whereNotNull('responded_at')->count() / $participants * 100, 1) : null,
                'questions' => collect($this->analyzer->questionStats($this->answerable($s->currentVersion?->questions ?? $s->questions ?? []), $subs))->keyBy('id'),
                'definitions' => collect($s->currentVersion?->questions ?? $s->questions ?? [])->keyBy('id'),
            ];
        }
        $ids = collect($per)->flatMap(fn ($p) => $p['questions']->keys())->countBy()->filter(fn ($n) => $n >= 2)->keys();
        $questions = [];
        foreach ($ids as $qid) {
            $defs = collect($per)->map(fn ($p) => $p['definitions'][$qid] ?? null)->filter();
            $type = $defs->first()['type'] ?? null;
            if (! in_array($type, self::COMPARABLE, true) || $defs->pluck('type')->unique()->count() !== 1) {
                continue;
            }
            $questions[] = [
                'id' => $qid, 'type' => $type, 'title' => $defs->first()['title'] ?? $qid,
                'by_survey' => collect($per)->map(function ($p) use ($qid, $type) {
                    $st = $p['questions'][$qid] ?? null;
                    if (! $st) {
                        return null;
                    }

                    return match ($type) {
                        'rating', 'scale' => ['answered' => $st['answered'], 'average' => $st['average'] ?? null, 'scale' => $st['scale'] ?? null],
                        'nps' => ['answered' => $st['answered'], 'average' => $st['average'] ?? null, 'nps' => $st['nps'] ?? null],
                        'matrix' => ['answered' => $st['answered'], 'rows' => $st['rows'] ?? []],
                        default => ['answered' => $st['answered'], 'options' => $st['options'] ?? []],
                    };
                })->all(),
            ];
        }

        return [
            'surveys' => collect($per)->map(fn ($p) => $p['survey'] + ['submissions' => $p['submissions'], 'participants' => $p['participants'], 'response_rate' => $p['response_rate']])->values()->all(),
            'questions' => $questions,
        ];
    }

    // Export ---------------------------------------------------------------------------------------------------------

    /** The results document in one of the shared formats; the respondent column exists only when identified answers are open to the viewer. */
    public function export(Survey $survey, string $format, string $locale, array $filters, bool $canIdentify, ?User $actor): string
    {
        $r = $this->results($survey, $filters, null, $canIdentify, $locale);
        if ($r['summary']['hidden']) {
            throw new BusinessRuleException(__('messages.surveys.segment_hidden', ['n' => $r['summary']['threshold']]), 'survey_segment_hidden');
        }
        $survey->loadMissing('currentVersion');
        $questions = $this->answerable($survey->currentVersion?->questions ?? $survey->questions ?? []);
        $labels = [];
        foreach ($questions as $q) {
            if (! empty($q['options'])) {
                $labels[$q['id']] = array_column($q['options'], 'label', 'id');
            }
        }
        $open = $canIdentify && $survey->isIdentified();
        $subs = $this->submissions($survey, $filters);
        $users = $open ? User::whereIn('id', $subs->pluck('respondent_user_id')->filter())->get()->keyBy('id') : collect();
        $raw = $subs->map(fn (SurveySubmission $s) => [
            'who' => $open ? $users->get($s->respondent_user_id)?->displayName() : null, 'at' => $s->submitted_at->format('Y-m-d H:i'),
            'answers' => collect($s->answers)->map(fn ($v, $k) => isset($labels[$k]) ? (is_array($v) ? array_map(fn ($x) => $labels[$k][$x] ?? $x, $v) : ($labels[$k][$v] ?? $v)) : ($k === 'yes_no' ? $v : $v))->all(),
        ])->all();
        $subtitle = ($locale === 'en' ? 'Survey results · ' : 'نتائج الاستبانة · ').now()->format('Y-m-d');
        $doc = SurveyDocument::build($survey->title($locale), $subtitle, $subs->count(), $r['questions'], $questions, $raw, $locale);
        SurveyEvents::record($survey, 'exported', ['format' => $format, 'rows' => $subs->count(), 'identified' => $open, 'filters' => array_filter($filters)], $actor);

        return $this->exporter->render($doc, $format, $locale);
    }

    // Needs ----------------------------------------------------------------------------------------------------------

    /** Turns selected report needs of a needs survey into training-need records (overall or one per school). */
    public function generateNeeds(Survey $survey, array $data, User $by, string $locale = 'ar'): int
    {
        if ($survey->kind !== 'needs') {
            throw new BusinessRuleException(__('messages.surveys.not_needs'), 'survey_not_needs');
        }
        $needs = collect($this->results($survey, [], null, true, 'ar')['needs'])->keyBy('key');
        $split = $data['split'] ?? 'overall';
        $minIndex = (int) ($data['min_index'] ?? 0);
        $target = app(SurveyAudience::class)->describe($survey->audience ?? []);
        $created = 0;

        DB::transaction(function () use ($data, $needs, $split, $minIndex, $survey, $target, $by, &$created) {
            foreach ($data['items'] as $item) {
                $need = $needs->get($item['key']);
                if (! $need) {
                    continue;
                }
                $segments = $split === 'school'
                    ? collect($need['by_school'])->filter(fn ($s) => $s['key'] !== '—' && $s['in_need'] > 0 && $s['index'] >= $minIndex)->map(fn ($s) => ['school_id' => $s['key'], 'index' => $s['index'], 'in_need' => $s['in_need'], 'respondents' => $s['respondents']])
                    : collect([['school_id' => null, 'index' => $need['index'], 'in_need' => $need['in_need'], 'respondents' => $need['respondents']]]);
                foreach ($segments as $seg) {
                    $skillName = $need['skill_id'] ? (Skill::find($need['skill_id'])?->name_ar ?? $need['skill_name']) : $need['skill_name'];
                    $reason = sprintf('نتائج استبانة «%s»: مؤشر الاحتياج %d%%، و%d من أصل %d مستجيباً بحاجة إلى التدريب في «%s».', $survey->title_ar, $seg['index'], $seg['in_need'], $seg['respondents'], $skillName).(! empty($data['notes']) ? "\n".$data['notes'] : '');
                    TrainingNeed::updateOrCreate(
                        ['engine_survey_id' => $survey->id, 'skill_name' => $skillName, 'school_id' => $seg['school_id']],
                        ['skill_id' => $need['skill_id'], 'employees_count' => max(1, $seg['in_need']), 'priority' => $item['priority'] ?? SurveyAnalyzer::priority($seg['index']), 'need_index' => $seg['index'], 'reason' => $reason,
                            'target_group' => Str::limit($target, 250, ''), 'status' => ! empty($item['program_id']) ? 'planned' : 'approved', 'program_id' => $item['program_id'] ?? null, 'submitted_by' => $by->id, 'reviewed_by' => $by->id],
                    );
                    $created++;
                }
            }
        });
        SurveyEvents::record($survey, 'needs_generated', ['created' => $created, 'split' => $split], $by);

        return $created;
    }

    // Overview -------------------------------------------------------------------------------------------------------

    /** The studio at a glance: surveys by status and kind, answers over the last thirty days, the open surveys and their rates. */
    public function overview(string $locale = 'ar'): array
    {
        $since = now()->subDays(30)->startOfDay();
        $recent = SurveySubmission::whereNull('context_id')->where('submitted_at', '>=', $since)->get(['submitted_at', 'source']);
        $open = Survey::where('status', 'published')->withCount(['participants', 'submissions' => fn ($q) => $q->whereNull('context_id')])->orderByDesc('published_at')->limit(6)->get();
        $answeredBy = $open->isEmpty() ? collect() : DB::table('survey_participants')->selectRaw('survey_id, count(*) as n')->whereIn('survey_id', $open->pluck('id'))->whereNotNull('responded_at')->groupBy('survey_id')->pluck('n', 'survey_id');

        return [
            'by_status' => Survey::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'by_kind' => Survey::query()->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->all(),
            'submissions_30d' => $recent->count(),
            'timeline' => $recent->groupBy(fn ($s) => $s->submitted_at->toDateString())->map(fn ($g, $day) => ['date' => $day, 'value' => $g->count()])->sortKeys()->values()->all(),
            'by_source_30d' => $recent->countBy('source')->all(),
            'open' => $open->map(fn (Survey $s) => ['id' => $s->id, 'title' => $s->title($locale), 'kind' => $s->kind, 'closes_at' => $s->closes_at, 'participants' => $s->participants_count, 'submissions' => $s->submissions_count,
                'response_rate' => $s->participants_count ? round(($answeredBy[$s->id] ?? 0) / $s->participants_count * 100, 1) : null])->all(),
        ];
    }
}
