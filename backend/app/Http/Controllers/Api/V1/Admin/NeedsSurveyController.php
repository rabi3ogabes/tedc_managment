<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NeedsSurvey;
use App\Models\NeedsSurveyRecipient;
use App\Models\Skill;
use App\Models\TrainingNeed;
use App\Services\NeedsSurveys\SurveyAnalyzer;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\NeedsSurveys\SurveySchema;
use App\Services\NeedsSurveys\SurveyTemplates;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Training-needs assessment surveys: design (template, Word / Excel import or builder),
 * targeting, distribution, live report and conversion of the results into training needs.
 */
class NeedsSurveyController extends Controller
{
    public function __construct(private readonly SurveyAudience $audience, private readonly SurveyAnalyzer $analyzer) {}

    public function index(Request $request): JsonResponse
    {
        $surveys = NeedsSurvey::query()
            ->withCount(['recipients', 'responses', 'trainingNeeds'])
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('q'), fn ($q, $t) => $q->whereLike('title', "%{$t}%"))
            ->latest()
            ->paginate($this->perPage($request, 30));

        $surveys->getCollection()->transform(fn (NeedsSurvey $s) => $this->summary($s));

        return response()->json($surveys);
    }

    public function templates(SurveyTemplates $templates): JsonResponse
    {
        return response()->json(['data' => $templates->all()]);
    }

    public function audienceOptions(): JsonResponse
    {
        return response()->json(['data' => $this->audience->options()]);
    }

    public function audiencePreview(Request $request): JsonResponse
    {
        $data = $request->validate(SurveyAudience::rules('audience.'));

        return response()->json(['data' => $this->audience->preview($data['audience'] ?? [], app()->getLocale())]);
    }

    public function store(Request $request, SurveyTemplates $templates): JsonResponse
    {
        $data = $this->validated($request, true);
        if (($data['source'] ?? null) === 'template' && empty($data['questions'])) {
            $template = $templates->find((string) ($data['template_key'] ?? '')) ?? abort(422, 'Unknown template');
            $data['questions'] = $template['questions'];
            $data['title'] ??= $template['title'];
            $data['description'] ??= $template['description'];
            $data['settings'] = ($data['settings'] ?? []) + ['accent' => $template['accent']];
        }

        $needsSurvey = NeedsSurvey::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? 'builder',
            'template_key' => $data['template_key'] ?? null,
            'questions' => SurveySchema::normalize($data['questions'] ?? []),
            'audience' => SurveyAudience::clean($data['audience'] ?? []),
            'settings' => $this->settings($data['settings'] ?? []),
            'closes_at' => $data['closes_at'] ?? null,
            'created_by' => $this->user()->id,
            'status' => 'draft',
        ]);

        return response()->json(['data' => $this->detail($needsSurvey)], 201);
    }

    public function show(NeedsSurvey $needsSurvey): JsonResponse
    {
        return response()->json(['data' => $this->detail($needsSurvey)]);
    }

    public function update(Request $request, NeedsSurvey $needsSurvey): JsonResponse
    {
        $data = $this->validated($request, false);
        $changes = array_intersect_key($data, array_flip(['title', 'description', 'closes_at']));
        if (array_key_exists('questions', $data)) {
            $changes['questions'] = SurveySchema::normalize($data['questions'] ?? []);
        }
        if (array_key_exists('audience', $data)) {
            $changes['audience'] = SurveyAudience::clean($data['audience'] ?? []);
        }
        if (array_key_exists('settings', $data)) {
            $changes['settings'] = $this->settings($data['settings'] ?? []);
        }
        $needsSurvey->update($changes);

        return response()->json(['data' => $this->detail($needsSurvey->fresh())]);
    }

    public function duplicate(NeedsSurvey $needsSurvey): JsonResponse
    {
        $copy = $needsSurvey->replicate(['status', 'published_at', 'closed_at']);
        $copy->fill(['title' => $needsSurvey->title.' (نسخة)', 'status' => 'draft', 'created_by' => $this->user()->id])->save();

        return response()->json(['data' => $this->detail($copy)], 201);
    }

    public function destroy(NeedsSurvey $needsSurvey): JsonResponse
    {
        $needsSurvey->delete();

        return response()->json(['message' => 'deleted']);
    }

    /** Sends the survey to its audience. Re-publishing adds (and notifies) only new people. */
    public function publish(NeedsSurvey $needsSurvey, NotificationService $notifications): JsonResponse
    {
        abort_if(! array_filter($needsSurvey->questions ?? [], fn ($q) => $q['type'] !== 'section'), 422, __('Add at least one question.'));

        $existing = $needsSurvey->recipients()->pluck('user_id')->flip();
        $now = now();
        $new = [];
        $this->audience->query($needsSurvey->audience ?? [])->select('employees.id', 'employees.user_id')->orderBy('employees.id')
            ->chunk(1000, function ($chunk) use (&$new, $existing, $needsSurvey, $now) {
                $rows = $chunk->reject(fn ($e) => $existing->has($e->user_id))->map(fn ($e) => [
                    'id' => (string) Str::uuid(), 'survey_id' => $needsSurvey->id, 'user_id' => $e->user_id, 'employee_id' => $e->id,
                    'notified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ])->values()->all();
                if ($rows) {
                    NeedsSurveyRecipient::insertOrIgnore($rows);
                    array_push($new, ...array_column($rows, 'user_id'));
                }
            });

        abort_if(! $new && ! $existing->count(), 422, __('No employees match the target audience.'));

        $needsSurvey->update(['status' => 'published', 'published_at' => $needsSurvey->published_at ?? $now, 'closed_at' => null]);

        if ($new) {
            $notifications->broadcast($new, 'needs_survey.invite',
                ['ar' => 'استبانة جديدة: '.$needsSurvey->title, 'en' => 'New survey: '.$needsSurvey->title],
                ['ar' => 'شاركنا احتياجاتك التدريبية — تستغرق دقائق قليلة.', 'en' => 'Share your training needs — it only takes a few minutes.'],
                ['needs_survey_id' => $needsSurvey->id, 'link' => '/portal/surveys?needs='.$needsSurvey->id],
            );
        }

        return response()->json(['data' => $this->detail($needsSurvey->fresh()), 'added' => count($new)]);
    }

    public function remind(NeedsSurvey $needsSurvey, NotificationService $notifications): JsonResponse
    {
        abort_unless($needsSurvey->isOpen(), 422, __('The survey is not open.'));
        $pending = $needsSurvey->recipients()->whereNull('responded_at')->pluck('user_id');
        if ($pending->isNotEmpty()) {
            $notifications->broadcast($pending, 'needs_survey.reminder',
                ['ar' => 'تذكير: '.$needsSurvey->title, 'en' => 'Reminder: '.$needsSurvey->title],
                ['ar' => 'لم نستلم إجاباتك بعد، رأيك يصنع برامج التدريب القادمة.', 'en' => 'We have not received your answers yet — your input shapes the next programs.'],
                ['needs_survey_id' => $needsSurvey->id, 'link' => '/portal/surveys?needs='.$needsSurvey->id],
            );
            $needsSurvey->recipients()->whereNull('responded_at')->update(['reminded_at' => now()]);
        }

        return response()->json(['reminded' => $pending->count()]);
    }

    public function close(NeedsSurvey $needsSurvey): JsonResponse
    {
        $needsSurvey->update(['status' => 'closed', 'closed_at' => now()]);

        return response()->json(['data' => $this->detail($needsSurvey)]);
    }

    public function reopen(NeedsSurvey $needsSurvey): JsonResponse
    {
        abort_unless($needsSurvey->published_at, 422);
        $needsSurvey->update(['status' => 'published', 'closed_at' => null, 'closes_at' => $needsSurvey->closes_at?->isPast() ? null : $needsSurvey->closes_at]);

        return response()->json(['data' => $this->detail($needsSurvey)]);
    }

    public function report(Request $request, NeedsSurvey $needsSurvey): JsonResponse
    {
        $filters = $request->only(SurveyAnalyzer::SEGMENTS);

        return response()->json(['data' => $this->analyzer->report($needsSurvey, $filters, app()->getLocale())]);
    }

    /** All responses as a UTF-8 CSV that opens correctly in Excel. */
    public function export(NeedsSurvey $needsSurvey): StreamedResponse
    {
        $questions = array_values(array_filter($needsSurvey->questions, fn ($q) => $q['type'] !== 'section'));
        $anonymous = $needsSurvey->isAnonymous();

        return response()->streamDownload(function () use ($needsSurvey, $questions, $anonymous) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $head = array_merge($anonymous ? [] : ['الاسم', 'البريد'], ['المدرسة', 'المسمى الوظيفي', 'سنوات الخبرة', 'التخصص', 'الجنسية', 'تاريخ الإجابة']);
            foreach ($questions as $q) {
                if ($q['type'] === 'matrix') {
                    foreach ($q['rows'] as $row) {
                        $head[] = $q['title'].' — '.$row['label'];
                    }
                } else {
                    $head[] = $q['title'];
                }
            }
            fputcsv($out, $head);

            $needsSurvey->responses()->with(['user:id,name,name_ar,email', 'school:id,name_ar', 'jobTitle:id,name_ar'])->orderBy('submitted_at')
                ->chunk(500, function ($responses) use ($out, $questions, $anonymous) {
                    foreach ($responses as $r) {
                        $line = array_merge($anonymous ? [] : [$r->user?->name_ar ?? $r->user?->name, $r->user?->email], [
                            $r->school?->name_ar, $r->jobTitle?->name_ar, $r->experience_years, $r->specialization, $r->nationality, $r->submitted_at->format('Y-m-d H:i'),
                        ]);
                        foreach ($questions as $q) {
                            $a = $r->answers[$q['id']] ?? null;
                            if ($q['type'] === 'matrix') {
                                foreach ($q['rows'] as $row) {
                                    $line[] = $a[$row['id']] ?? '';
                                }

                                continue;
                            }
                            $labels = collect($q['options'] ?? [])->pluck('label', 'id');
                            $line[] = match (true) {
                                $a === null => '',
                                is_array($a) => collect($a)->map(fn ($v) => $labels[$v] ?? $v)->implode(' | '),
                                $q['type'] === 'yes_no' => $a === 'yes' ? 'نعم' : 'لا',
                                default => $labels[$a] ?? $a,
                            };
                        }
                        fputcsv($out, $line);
                    }
                });
            fclose($out);
        }, Str::slug($needsSurvey->title ?: 'survey').'-responses.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Converts selected report needs into training-need records (overall or one per school). */
    public function generateNeeds(Request $request, NeedsSurvey $needsSurvey): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.key' => ['required', 'string', 'max:255'],
            'items.*.priority' => ['nullable', Rule::in(TrainingNeed::PRIORITIES)],
            'items.*.program_id' => ['nullable', 'uuid', 'exists:programs,id'],
            'split' => ['nullable', Rule::in(['overall', 'school'])],
            'min_index' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = $this->analyzer->report($needsSurvey, [], 'ar');
        $needs = collect($report['needs'])->keyBy('key');
        $split = $data['split'] ?? 'overall';
        $minIndex = $data['min_index'] ?? 0;
        $target = $this->audience->describe($needsSurvey->audience ?? []);
        $created = 0;

        DB::transaction(function () use ($data, $needs, $split, $minIndex, $needsSurvey, $target, &$created) {
            foreach ($data['items'] as $item) {
                $need = $needs->get($item['key']);
                if (! $need) {
                    continue;
                }
                $segments = $split === 'school'
                    ? collect($need['by_school'])->filter(fn ($s) => $s['key'] !== '—' && $s['in_need'] > 0 && $s['index'] >= $minIndex)
                        ->map(fn ($s) => ['school_id' => $s['key'], 'index' => $s['index'], 'in_need' => $s['in_need'], 'respondents' => $s['respondents']])
                    : collect([['school_id' => null, 'index' => $need['index'], 'in_need' => $need['in_need'], 'respondents' => $need['respondents']]]);

                foreach ($segments as $seg) {
                    $skillName = $need['skill_id'] ? (Skill::find($need['skill_id'])?->name_ar ?? $need['skill_name']) : $need['skill_name'];
                    $reason = sprintf('نتائج استبانة «%s»: مؤشر الاحتياج %d%%، و%d من أصل %d مستجيباً بحاجة إلى التدريب في «%s».',
                        $needsSurvey->title, $seg['index'], $seg['in_need'], $seg['respondents'], $skillName).(! empty($data['notes']) ? "\n".$data['notes'] : '');

                    TrainingNeed::updateOrCreate(
                        ['survey_id' => $needsSurvey->id, 'skill_name' => $skillName, 'school_id' => $seg['school_id']],
                        [
                            'skill_id' => $need['skill_id'],
                            'employees_count' => max(1, $seg['in_need']),
                            'priority' => $item['priority'] ?? SurveyAnalyzer::priority($seg['index']),
                            'need_index' => $seg['index'],
                            'reason' => $reason,
                            'target_group' => Str::limit($target, 250, ''),
                            'status' => ! empty($item['program_id']) ? 'planned' : 'approved',
                            'program_id' => $item['program_id'] ?? null,
                            'submitted_by' => $this->user()->id,
                            'reviewed_by' => $this->user()->id,
                        ],
                    );
                    $created++;
                }
            }
        });

        return response()->json(['created' => $created]);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required_unless:source,template' : 'sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'source' => ['nullable', Rule::in(NeedsSurvey::SOURCES)],
            'template_key' => ['nullable', 'string', 'max:48'],
            'questions' => [$creating ? 'required_unless:source,template' : 'sometimes', 'array'],
            'audience' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.anonymous' => ['nullable', 'boolean'],
            'settings.welcome' => ['nullable', 'string', 'max:2000'],
            'settings.thank_you' => ['nullable', 'string', 'max:2000'],
            'settings.accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.show_progress' => ['nullable', 'boolean'],
            'settings.one_per_page' => ['nullable', 'boolean'],
            'closes_at' => ['nullable', 'date'],
        ] + SurveyAudience::rules('audience.'));
    }

    private function settings(array $settings): array
    {
        return array_filter([
            'anonymous' => (bool) ($settings['anonymous'] ?? false),
            'show_progress' => (bool) ($settings['show_progress'] ?? true),
            'one_per_page' => (bool) ($settings['one_per_page'] ?? false),
            'welcome' => $settings['welcome'] ?? null,
            'thank_you' => $settings['thank_you'] ?? null,
            'accent' => $settings['accent'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function summary(NeedsSurvey $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title,
            'description' => $s->description,
            'status' => $s->isOpen() || $s->status !== 'published' ? $s->status : 'closed',
            'source' => $s->source,
            'template_key' => $s->template_key,
            'questions_count' => count(array_filter($s->questions ?? [], fn ($q) => $q['type'] !== 'section')),
            'recipients_count' => $s->recipients_count ?? $s->recipients()->count(),
            'responses_count' => $s->responses_count ?? $s->responses()->count(),
            'needs_count' => $s->training_needs_count ?? $s->trainingNeeds()->count(),
            'audience_summary' => $this->audience->describe($s->audience ?? []),
            'accent' => $s->settings['accent'] ?? null,
            'published_at' => $s->published_at,
            'closes_at' => $s->closes_at,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
    }

    private function detail(NeedsSurvey $s): array
    {
        return $this->summary($s) + [
            'questions' => $s->questions,
            'audience' => (object) ($s->audience ?? []),
            'settings' => (object) ($s->settings ?? []),
        ];
    }
}
