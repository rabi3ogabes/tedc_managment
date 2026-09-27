<?php

namespace App\Services;

use Anthropic\Client;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Evaluation;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Skill;
use App\Models\TrainingNeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI Training Assistant.
 *
 * Grounds Claude in an aggregated, anonymised snapshot of platform data
 * (school training requests, workforce skill levels, previous program
 * outcomes, coverage gaps) and asks for a structured answer: narrative +
 * concrete program suggestions (target group, seats, rationale).
 *
 * When no API key is configured, or the call fails, a deterministic
 * rule-based analyst produces the same response shape so the feature is
 * always available.
 */
class AiAssistant
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the AI Training Advisor of a government training and educational development center that serves schools in the State of Qatar.
You help administrators and executives make data-driven decisions about which professional development programs to create, for whom, and at what scale.

Ground every statement in the DATA SNAPSHOT supplied with the question. When the data does not support a claim, say so rather than inventing numbers.
Prioritise needs by: number of employees affected, request priority (critical > high > medium > low), number of requesting schools, and weak skill coverage.
When suggesting a program, size the seats from the requested employee counts and state the target group (job roles, school stage or type).
Prefer extending or re-running programs that already show high satisfaction and impact over creating near-duplicates.

Answer in the language of the question (Arabic by default; Modern Standard Arabic suitable for official government communication).
The `answer` field is concise Markdown for display in a dashboard. Program titles are always given in both Arabic and English.
PROMPT;

    public function __construct(private readonly AnalyticsService $analytics) {}

    public function enabled(): bool
    {
        return filled(config('tedc.ai.api_key'));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{answer: string, suggestions: array, insights: array, source: string, report_id: string}
     */
    public function ask(User $user, string $question, array $history = []): array
    {
        $snapshot = $this->snapshot();
        $result = null;

        if ($this->enabled()) {
            try {
                $result = $this->askClaude($question, $snapshot, $history);
            } catch (Throwable $e) {
                Log::warning('AI assistant call failed, using rule-based analyst', ['error' => $e->getMessage()]);
            }
        }

        $result ??= $this->ruleBased($question, $snapshot);

        $report = Report::create([
            'type' => 'ai_insight',
            'title' => mb_substr($question, 0, 250),
            'parameters' => ['question' => $question],
            'payload' => $result,
            'status' => 'ready',
            'generated_by' => $user->id,
        ]);

        return $result + ['report_id' => $report->id];
    }

    /**
     * Aggregated, non-personal data given to the model.
     */
    public function snapshot(): array
    {
        $needs = $this->analytics->trainingNeeds();

        $skills = Skill::all()->keyBy('id');
        $employees = max(1, Employee::where('status', 'active')->count());
        $skillCoverage = EmployeeSkill::select('skill_id', DB::raw('count(*) as holders'), DB::raw('avg(level) as avg_level'))
            ->groupBy('skill_id')->get()
            ->map(fn ($row) => [
                'skill' => $skills[$row->skill_id]?->name_en,
                'skill_ar' => $skills[$row->skill_id]?->name_ar,
                'holders_percent' => round($row->holders / $employees * 100, 1),
                'avg_level_1_to_5' => round((float) $row->avg_level, 2),
            ])->sortBy('holders_percent')->values()->take(15);

        $satisfaction = Evaluation::select('program_id', DB::raw('avg(satisfaction_score) as score'))->groupBy('program_id')->pluck('score', 'program_id');

        $programs = Program::with(['skills', 'category'])
            ->withCount(['registrations as completed' => fn ($q) => $q->where('status', Registration::STATUS_COMPLETED)])
            ->withAvg(['registrations as impact' => fn ($q) => $q->whereNotNull('impact_score')], 'impact_score')
            ->latest()->limit(25)->get()
            ->map(fn (Program $p) => [
                'code' => $p->code,
                'title_en' => $p->title_en,
                'title_ar' => $p->title_ar,
                'category' => $p->category?->name_en,
                'status' => $p->status,
                'capacity' => $p->capacity,
                'completed' => $p->completed,
                'satisfaction' => isset($satisfaction[$p->id]) ? round($satisfaction[$p->id], 1) : null,
                'impact_score' => $p->impact ? round($p->impact, 1) : null,
                'skills' => $p->skills->pluck('name_en'),
            ]);

        $byJob = TrainingNeed::with('targetJobTitle')->whereIn('status', ['submitted', 'under_review', 'approved', 'planned'])->get()
            ->groupBy(fn ($n) => $n->targetJobTitle?->name_en ?? ($n->target_group ?: 'Unspecified'))
            ->map->sum('employees_count');

        $geo = $this->analytics->geographic();

        return [
            'generated_at' => now()->toDateString(),
            'workforce' => ['active_employees' => $employees, 'schools' => count($geo['schools'])],
            'training_needs' => [
                'totals' => $needs['totals'],
                'most_requested_skills' => $needs['most_requested_skills']->map(fn ($s) => collect($s)->except('skill_id')),
                'skills_without_program' => $needs['uncovered_skills']->pluck('skill'),
                'requested_employees_by_target_group' => $byJob,
            ],
            'weakest_skill_coverage' => $skillCoverage,
            'previous_programs' => $programs,
            'regions_by_coverage' => collect($geo['regions'])->map(fn ($r) => collect($r)->only(['region', 'coverage', 'gap', 'open_needs'])),
        ];
    }

    private function askClaude(string $question, array $snapshot, array $history): ?array
    {
        $client = new Client(apiKey: config('tedc.ai.api_key'), requestOptions: ['timeout' => (float) config('tedc.ai.timeout')]);

        $messages = [];
        foreach (array_slice($history, -6) as $turn) {
            if (in_array($turn['role'] ?? null, ['user', 'assistant'], true) && filled($turn['content'] ?? null)) {
                $messages[] = ['role' => $turn['role'], 'content' => (string) $turn['content']];
            }
        }
        $messages[] = [
            'role' => 'user',
            'content' => "DATA SNAPSHOT (JSON):\n".json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n\nQUESTION:\n".$question,
        ];

        $message = $client->messages->create(
            model: config('tedc.ai.model'),
            maxTokens: config('tedc.ai.max_tokens'),
            system: [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cacheControl' => ['type' => 'ephemeral']]],
            thinking: ['type' => 'adaptive'],
            messages: $messages,
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        );

        if ($message->stopReason === 'refusal') {
            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data + ['source' => 'ai'];
                }
            }
        }

        return null;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'insights' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title_ar' => ['type' => 'string'],
                            'title_en' => ['type' => 'string'],
                            'target_group' => ['type' => 'string'],
                            'seats' => ['type' => 'integer'],
                            'priority' => ['type' => 'string', 'enum' => ['critical', 'high', 'medium', 'low']],
                            'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'rationale' => ['type' => 'string'],
                        ],
                        'required' => ['title_ar', 'title_en', 'target_group', 'seats', 'priority', 'skills', 'rationale'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['answer', 'insights', 'suggestions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Deterministic analyst used when the LLM is unavailable.
     */
    private function ruleBased(string $question, array $snapshot): array
    {
        $arabic = (bool) preg_match('/\p{Arabic}/u', $question) || app()->getLocale() === 'ar';
        $skills = collect($snapshot['training_needs']['most_requested_skills'])->take(5);
        $uncovered = collect($snapshot['training_needs']['skills_without_program']);
        $topGroup = collect($snapshot['training_needs']['requested_employees_by_target_group'])->sortDesc()->keys()->first() ?? ($arabic ? 'المعلمون' : 'Teachers');

        $suggestions = $skills->map(function ($row) use ($uncovered, $topGroup) {
            $priority = match (true) {
                $row['priority_score'] >= 200 => 'critical',
                $row['priority_score'] >= 80 => 'high',
                $row['priority_score'] >= 30 => 'medium',
                default => 'low',
            };

            return [
                'title_ar' => 'برنامج '.$row['skill'],
                'title_en' => $row['skill'].' Program',
                'target_group' => $topGroup,
                'seats' => (int) $row['employees'],
                'priority' => $priority,
                'skills' => [$row['skill']],
                'rationale' => $uncovered->contains($row['skill'])
                    ? "{$row['requests']} requests from {$row['schools']} schools ({$row['employees']} employees) and no active program covers this skill."
                    : "{$row['requests']} requests from {$row['schools']} schools ({$row['employees']} employees); existing capacity should be extended.",
            ];
        })->values()->all();

        $weak = collect($snapshot['weakest_skill_coverage'])->take(3)->pluck($arabic ? 'skill_ar' : 'skill')->filter()->implode($arabic ? '، ' : ', ');
        $lowRegion = collect($snapshot['regions_by_coverage'])->first();

        $insights = array_values(array_filter([
            $weak ? ($arabic ? "أضعف المهارات انتشاراً بين الموظفين: {$weak}." : "Least widespread skills across the workforce: {$weak}.") : null,
            $lowRegion ? ($arabic ? "أقل تغطية تدريبية في منطقة {$lowRegion['region']} ({$lowRegion['coverage']}%)." : "Lowest training coverage is in {$lowRegion['region']} ({$lowRegion['coverage']}%).") : null,
            $uncovered->isNotEmpty() ? ($arabic ? 'مهارات مطلوبة دون برامج حالية: '.$uncovered->take(5)->implode('، ') : 'Requested skills with no current program: '.$uncovered->take(5)->implode(', ')) : null,
        ]));

        $lines = collect($suggestions)->map(fn ($s, $i) => ($i + 1).'. **'.($arabic ? $s['title_ar'] : $s['title_en'])."** — {$s['seats']} ".($arabic ? 'مقعد' : 'seats')." ({$s['priority']})");

        $answer = ($arabic
            ? "بناءً على طلبات المدارس وفجوات المهارات الحالية، نقترح البرامج التالية:\n\n"
            : "Based on school requests and current skill gaps, we suggest the following programs:\n\n").$lines->implode("\n");

        if ($lines->isEmpty()) {
            $answer = $arabic ? 'لا توجد طلبات احتياجات تدريبية مفتوحة حالياً لتحليلها.' : 'There are no open training-need requests to analyse yet.';
        }

        return ['answer' => $answer, 'insights' => $insights, 'suggestions' => $suggestions, 'source' => 'rules'];
    }
}
