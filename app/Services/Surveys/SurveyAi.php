<?php

namespace App\Services\Surveys;

use App\Ai\AiGuard;
use App\Exceptions\BusinessRuleException;
use App\Models\AiLog;
use App\Models\Survey;
use App\Models\SurveyAiInsight;
use App\Models\SurveySubmission;
use App\Models\User;
use App\Services\Ai\AiModelSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Survey Studio v2, Gate 8: AI assistance under the platform's AI policy.
 *
 *  Summaries   the open-text answers of a survey that opted in (per survey, off by default) are sent to the assigned model
 *              through {@see AiGuard}: the feature must be on, the model allowed to see such text (residency), personal
 *              identifiers redacted (and, for identified surveys, the respondents' names), nothing but the answer texts
 *              leaves, the prompt is never stored, and the call is logged. The model returns themes, a sentiment split,
 *              verbatim quotes and recommendations; the result is kept on the survey and recorded as an event.
 *  Suggestions the studio asks for questions from a goal; nothing personal is sent.
 */
class SurveyAi
{
    public const FEATURE = 'surveys';

    public const TEXT_TYPES = ['short_text', 'long_text'];

    public const MAX_ANSWERS_PER_QUESTION = 300;

    public const MAX_ANSWER_CHARS = 400;

    public const MIN_ANSWERS = 3;

    public function __construct(private readonly AiGuard $guard, private readonly AiModelSettings $models) {}

    /** Whether the survey may be summarised now and which questions carry open text. */
    public function status(Survey $survey): array
    {
        $survey->loadMissing('currentVersion');
        $optIn = (bool) $survey->setting('ai_opt_in', false);
        $available = $this->guard->available(self::FEATURE, true);
        $counts = $this->textCounts($survey);

        return [
            'opt_in' => $optIn,
            'available' => $available,
            'can_summarise' => $optIn && $available && array_sum(array_column($counts, 'count')) >= self::MIN_ANSWERS,
            'can_suggest' => $this->guard->available(self::FEATURE, false),
            'text_questions' => $counts,
            'min_answers' => self::MIN_ANSWERS,
            'insights' => SurveyAiInsight::with('creator:id,name,name_ar')->where('survey_id', $survey->id)->orderByDesc('created_at')->limit(20)->get()->map(fn ($i) => $this->present($i))->values()->all(),
        ];
    }

    /** Themes, sentiment, quotes and recommendations for one text question or all of them. */
    public function summarise(Survey $survey, ?string $questionId, User $by, string $locale = 'ar'): SurveyAiInsight
    {
        if (! $survey->setting('ai_opt_in', false)) {
            throw new BusinessRuleException(__('messages.surveys.ai_opt_out'), 'survey_ai_opt_out');
        }
        if ($survey->answers_purged_at) {
            throw new BusinessRuleException(__('messages.surveys.ai_purged'), 'survey_ai_purged');
        }
        $survey->loadMissing('currentVersion');
        $questions = collect($this->textQuestions($survey))->when($questionId, fn ($c) => $c->where('id', $questionId))->values();
        if ($questions->isEmpty()) {
            throw new BusinessRuleException(__('messages.surveys.ai_no_text'), 'survey_ai_no_text');
        }
        [$blocks, $count] = $this->collect($survey, $questions);
        if ($count < self::MIN_ANSWERS) {
            throw new BusinessRuleException(__('messages.surveys.ai_too_few', ['n' => self::MIN_ANSWERS]), 'survey_ai_too_few');
        }
        $names = $survey->isIdentified() ? $this->respondentNames($survey) : [];
        $system = $locale === 'en'
            ? 'You analyse survey answers for a government training centre. Work only from the answers given; never invent facts, never guess who wrote an answer, never output names, e-mails or phone numbers. Quotes must be verbatim fragments of at most 20 words. Answer in English.'
            : 'أنت محلل إجابات استبانات في مركز تدريب حكومي. اعمل فقط من الإجابات المعطاة؛ لا تخترع حقائق، ولا تخمّن هوية كاتب أي إجابة، ولا تُخرج أسماء أو بريداً أو أرقام هواتف. الاقتباسات مقاطع حرفية لا تتجاوز 20 كلمة. أجب بالعربية الفصحى المناسبة للمخاطبات الرسمية.';
        $prompt = ($locale === 'en' ? 'Survey: ' : 'الاستبانة: ').$survey->title($locale)."\n\n".$blocks;
        $schema = [
            'type' => 'object', 'required' => ['summary', 'themes', 'sentiment', 'recommendations'],
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'one paragraph'],
                'sentiment' => ['type' => 'object', 'properties' => ['positive' => ['type' => 'integer'], 'neutral' => ['type' => 'integer'], 'negative' => ['type' => 'integer']], 'description' => 'how many answers read as positive, neutral, negative'],
                'themes' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'required' => ['title', 'count'], 'properties' => ['title' => ['type' => 'string'], 'count' => ['type' => 'integer', 'description' => 'answers touching the theme'], 'quotes' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']]]]],
                'recommendations' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'string']],
            ],
        ];
        $out = $this->guard->json(self::FEATURE, $system, $prompt, $schema, $by, true, $names);
        if (! $out || ! isset($out['themes'])) {
            throw new BusinessRuleException(__('messages.surveys.ai_unavailable'), 'survey_ai_unavailable');
        }
        $payload = $this->cleanSummary($out, $count);
        $insight = SurveyAiInsight::create([
            'survey_id' => $survey->id, 'version_id' => $survey->current_version_id, 'question_id' => $questionId, 'kind' => 'summary', 'payload' => $payload,
            'answers_count' => $count, 'model' => $this->modelName(), 'redactions' => (int) ($this->lastRedactions() ?? 0), 'created_by' => $by->id, 'created_at' => now(),
        ]);
        SurveyEvents::record($survey, 'ai_summarised', ['question_id' => $questionId, 'answers' => $count, 'model' => $insight->model, 'redactions' => $insight->redactions], $by);

        return $insight;
    }

    /** Question suggestions for the studio from a goal, in the engine's own format and already normalised. */
    public function suggest(Survey $survey, string $goal, User $by, string $locale = 'ar'): SurveyAiInsight
    {
        $existing = collect($survey->questions ?? [])->pluck('title')->filter()->take(40)->implode("\n- ");
        $system = $locale === 'en'
            ? 'You design survey questions for a government training centre (teachers, school leaders, trainers). Suggest clear, neutral, single-purpose questions; no leading wording, nothing that asks for names or identifiers. Allowed types: short_text, long_text, single, multiple, dropdown, yes_no, rating, scale, likert, nps, matrix, number, date. Answer in English.'
            : 'أنت تصمم أسئلة استبانات لمركز تدريب حكومي (معلمون وقادة مدارس ومدربون). اقترح أسئلة واضحة محايدة ذات غرض واحد، بلا صياغة موجِّهة، ولا تطلب أسماء أو معرّفات. الأنواع المسموحة: short_text, long_text, single, multiple, dropdown, yes_no, rating, scale, likert, nps, matrix, number, date. أجب بالعربية الفصحى.';
        $prompt = ($locale === 'en' ? 'Survey: ' : 'الاستبانة: ').$survey->title($locale)."\n".($locale === 'en' ? 'Kind: ' : 'النوع: ').$survey->kind."\n".($locale === 'en' ? 'Goal: ' : 'الهدف: ').trim($goal)."\n".($existing ? ($locale === 'en' ? "Existing questions (do not repeat):\n- " : "الأسئلة الموجودة (لا تكررها):\n- ").$existing : '');
        $schema = ['type' => 'object', 'required' => ['questions'], 'properties' => ['questions' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 8, 'items' => ['type' => 'object', 'required' => ['type', 'title'],
            'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'title_en' => ['type' => 'string'], 'required' => ['type' => 'boolean'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']], 'rows' => ['type' => 'array', 'items' => ['type' => 'string']], 'scale' => ['type' => 'object', 'properties' => ['min' => ['type' => 'integer'], 'max' => ['type' => 'integer']]]]]]]];
        $out = $this->guard->json(self::FEATURE, $system, $prompt, $schema, $by, false);
        if (! $out || empty($out['questions']) || ! is_array($out['questions'])) {
            throw new BusinessRuleException(__('messages.surveys.ai_unavailable'), 'survey_ai_unavailable');
        }
        $questions = [];
        foreach (array_slice($out['questions'], 0, 8) as $q) {
            if (! is_array($q) || ! in_array($q['type'] ?? null, ['short_text', 'long_text', 'single', 'multiple', 'dropdown', 'yes_no', 'rating', 'scale', 'likert', 'nps', 'matrix', 'number', 'date'], true)) {
                continue;
            }
            try {
                $questions[] = SurveyDefinition::normalize([['id' => 'ai'.Str::lower(Str::random(6))] + array_intersect_key($q, array_flip(['type', 'title', 'title_en', 'required', 'options', 'rows', 'scale']))])[0];
            } catch (\Throwable) {
                // a suggestion the engine cannot hold is simply dropped
            }
        }
        if (! $questions) {
            throw new BusinessRuleException(__('messages.surveys.ai_unavailable'), 'survey_ai_unavailable');
        }
        $insight = SurveyAiInsight::create(['survey_id' => $survey->id, 'version_id' => $survey->current_version_id, 'kind' => 'suggestions', 'payload' => ['goal' => Str::limit(trim($goal), 500, ''), 'questions' => $questions], 'answers_count' => 0, 'model' => $this->modelName(), 'created_by' => $by->id, 'created_at' => now()]);
        SurveyEvents::record($survey, 'ai_suggested', ['questions' => count($questions)], $by);

        return $insight;
    }

    public function present(SurveyAiInsight $i): array
    {
        return ['id' => $i->id, 'kind' => $i->kind, 'question_id' => $i->question_id, 'payload' => $i->payload, 'answers_count' => $i->answers_count, 'model' => $i->model, 'redactions' => $i->redactions, 'by' => $i->creator?->displayName(), 'created_at' => $i->created_at];
    }

    // Helpers --------------------------------------------------------------------------------------------------------

    /** The open-text questions of the current version (never the evidence link companions, e-mails or phones). */
    private function textQuestions(Survey $survey): array
    {
        return array_values(array_filter($survey->currentVersion?->questions ?? $survey->questions ?? [], fn ($q) => in_array($q['type'], self::TEXT_TYPES, true) && ! LegacyQuestions::isCompanion($q)));
    }

    private function textCounts(Survey $survey): array
    {
        $subs = $this->submissions($survey);

        return array_map(fn ($q) => ['id' => $q['id'], 'title' => $q['title'], 'count' => $subs->filter(fn ($s) => trim((string) ($s->answers[$q['id']] ?? '')) !== '')->count()], $this->textQuestions($survey));
    }

    private function submissions(Survey $survey): Collection
    {
        return SurveySubmission::where('survey_id', $survey->id)->whereNull('context_id')->orderByDesc('submitted_at')->get(['id', 'answers', 'respondent_user_id']);
    }

    /** @return array{0: string, 1: int} the prompt blocks and how many answers they hold */
    private function collect(Survey $survey, Collection $questions): array
    {
        $subs = $this->submissions($survey);
        $blocks = [];
        $count = 0;
        foreach ($questions as $q) {
            $answers = $subs->map(fn ($s) => trim((string) ($s->answers[$q['id']] ?? '')))->filter()->take(self::MAX_ANSWERS_PER_QUESTION)->map(fn ($t) => Str::limit($t, self::MAX_ANSWER_CHARS, '…'))->values();
            if ($answers->isEmpty()) {
                continue;
            }
            $count += $answers->count();
            $blocks[] = '### '.$q['title']."\n".$answers->map(fn ($t, $i) => ($i + 1).'. '.str_replace(["\r", "\n"], ' ', $t))->implode("\n");
        }

        return [implode("\n\n", $blocks), $count];
    }

    /** The names of the people who answered an identified survey, so the redactor hides them even inside free text. */
    private function respondentNames(Survey $survey): array
    {
        $ids = $this->submissions($survey)->pluck('respondent_user_id')->filter()->unique();

        return $ids->isEmpty() ? [] : User::whereIn('id', $ids)->get(['name', 'name_ar'])->flatMap(fn ($u) => [$u->name, $u->name_ar])->filter()->unique()->values()->all();
    }

    private function cleanSummary(array $out, int $count): array
    {
        $themes = [];
        foreach (array_slice((array) ($out['themes'] ?? []), 0, 8) as $t) {
            if (! is_array($t) || trim((string) ($t['title'] ?? '')) === '') {
                continue;
            }
            $n = max(0, min($count, (int) ($t['count'] ?? 0)));
            $themes[] = ['title' => Str::limit(trim((string) $t['title']), 120, ''), 'count' => $n, 'share' => $count ? round($n / $count * 100) : 0,
                'quotes' => array_values(array_slice(array_map(fn ($q) => Str::limit(trim((string) $q), 200, '…'), array_filter((array) ($t['quotes'] ?? []), 'is_string')), 0, 3))];
        }
        $s = (array) ($out['sentiment'] ?? []);

        return [
            'summary' => Str::limit(trim((string) ($out['summary'] ?? '')), 2000, '…'),
            'sentiment' => ['positive' => max(0, (int) ($s['positive'] ?? 0)), 'neutral' => max(0, (int) ($s['neutral'] ?? 0)), 'negative' => max(0, (int) ($s['negative'] ?? 0))],
            'themes' => $themes,
            'recommendations' => array_values(array_slice(array_map(fn ($r) => Str::limit(trim((string) $r), 300, '…'), array_filter((array) ($out['recommendations'] ?? []), 'is_string')), 0, 6)),
        ];
    }

    private function modelName(): ?string
    {
        $r = $this->models->resolve('text');

        return $r['model']['model'] ?? null;
    }

    private function lastRedactions(): ?int
    {
        return AiLog::where('feature', self::FEATURE)->orderByDesc('created_at')->value('redactions');
    }
}
