<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\School;
use App\Models\Skill;
use App\Services\AudienceRules;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\ProgramPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Smart program creation: from collected training needs, or by choosing the target audience
 * with filters (age, experience, specialization ...).
 */
class ProgramBuilderController extends Controller
{
    public function __construct(
        private readonly ProgramPlanner $planner,
        private readonly SurveyAudience $audience,
        private readonly AudienceRules $rules,
    ) {}

    /** Everything the wizard needs in one call: open needs and the filter options. */
    public function options(): JsonResponse
    {
        $needs = $this->planner->needsPool();

        return response()->json(['data' => [
            'needs' => $needs,
            'totals' => ['topics' => $needs->count(), 'requests' => $needs->sum('requests'), 'employees' => $needs->sum('employees'), 'critical' => $needs->where('priority', 'critical')->count()],
            'filters' => $this->audience->options() + [
                'job_titles' => JobTitle::orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en', 'category']),
                'schools' => School::orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'region', 'type', 'stage']),
                'regions' => School::REGIONS,
                'school_types' => School::TYPES,
            ],
            'categories' => ProgramCategory::orderBy('name_ar')->get(['id', 'slug', 'name_ar', 'name_en', 'color']),
            'skills' => Skill::orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en', 'category']),
            'session_hours' => ProgramPlanner::SESSION_HOURS,
        ]]);
    }

    /** A full suggestion (title, hours, capacity, audience, dates, sessions, rooms, trainers). */
    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'need_ids' => ['nullable', 'array', 'max:200'], 'need_ids.*' => ['uuid'],
            'skill_id' => ['nullable', 'uuid', 'exists:skills,id'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        return response()->json(['data' => $this->planner->draft($data['need_ids'] ?? [], $data['skill_id'] ?? null, isset($data['from']) ? CarbonImmutable::parse($data['from']) : null)]);
    }

    /** Re-plans the session schedule after hours, start date or capacity change. */
    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'total_hours' => ['required', 'numeric', 'min:1', 'max:200'],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'from' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'session_hours' => ['nullable', 'integer', 'min:1', 'max:8'],
            'skill_ids' => ['nullable', 'array'], 'skill_ids.*' => ['uuid'],
        ]);
        $codes = Skill::whereIn('id', $data['skill_ids'] ?? [])->pluck('code')->all();
        $sessions = $this->planner->plan((float) $data['total_hours'], CarbonImmutable::parse($data['from']), (int) $data['capacity'], $codes, $data['start_time'] ?? '09:00', (int) ($data['session_hours'] ?? ProgramPlanner::SESSION_HOURS));

        return response()->json(['data' => ['sessions' => $sessions, 'trainers' => $this->planner->trainerSuggestions($codes, $sessions[0] ?? null)]]);
    }

    /** Live size and composition of an audience, with a few sample employees. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(SurveyAudience::rules('audience.'));
        $audience = $data['audience'] ?? [];

        return response()->json(['data' => $this->audience->preview($audience, app()->getLocale()) + ['sample' => $this->audience->sample($audience), 'summary' => $this->audience->describe($audience)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32', Rule::unique('programs', 'code')],
            'category_id' => ['nullable', 'uuid', 'exists:program_categories,id'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'summary_ar' => ['nullable', 'string', 'max:500'], 'summary_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string'], 'description_en' => ['nullable', 'string'],
            'objectives' => ['nullable', 'array'], 'objectives.*' => ['string', 'max:500'],
            'delivery_mode' => ['sometimes', Rule::in(['in_person', 'online', 'hybrid'])],
            'level' => ['sometimes', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'total_hours' => ['required', 'numeric', 'min:1', 'max:1000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'min_attendance_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'requires_tasks' => ['sometimes', 'boolean'], 'requires_evaluation' => ['sometimes', 'boolean'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'registration_opens_at' => ['nullable', 'date'], 'registration_closes_at' => ['nullable', 'date', 'after:registration_opens_at'],
            'registration_modes' => ['nullable', 'array'], 'registration_modes.*' => [Rule::in(Program::MODES)],
            'status' => ['sometimes', Rule::in([Program::STATUS_DRAFT, Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN])],
            'skills' => ['sometimes', 'array'], 'skills.*.id' => ['required', 'uuid', 'exists:skills,id'], 'skills.*.target_level' => ['nullable', 'integer', 'between:1,5'],
            'trainers' => ['sometimes', 'array'], 'trainers.*.id' => ['required', 'uuid', 'exists:trainers,id'], 'trainers.*.role' => ['nullable', Rule::in(['lead', 'assistant'])],
            'need_ids' => ['nullable', 'array', 'max:200'], 'need_ids.*' => ['uuid', 'exists:training_needs,id'],
            'sessions' => ['nullable', 'array', 'max:60'],
            'sessions.*.title_ar' => ['nullable', 'string', 'max:255'], 'sessions.*.title_en' => ['nullable', 'string', 'max:255'],
            'sessions.*.starts_at' => ['required', 'date'], 'sessions.*.ends_at' => ['required', 'date', 'after:sessions.*.starts_at'],
            'sessions.*.training_room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'], 'sessions.*.trainer_id' => ['nullable', 'uuid', 'exists:trainers,id'],
            'sessions.*.location_text' => ['nullable', 'string', 'max:255'],
            'calendar_approval_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'invite_audience' => ['sometimes', 'boolean'],
            'nominate_audience' => ['sometimes', 'boolean'],
        ] + SurveyAudience::rules('audience.'));

        $reason = $this->user()->hasPermission('calendar.approve') ? ($data['calendar_approval_reason'] ?? null) : null;
        $program = $this->planner->create($data, $this->user(), $reason);

        $meta = [];
        $published = in_array($program->status, Program::VISIBLE, true);
        if ($request->boolean('invite_audience')) {
            $meta['invited'] = $published ? $this->planner->invite($program) : 0;
        }
        if ($request->boolean('nominate_audience')) {
            $meta['nomination'] = $published && $this->user()->hasPermission('nominations.center') ? $this->planner->nominateAudience($program, $this->user()) : null;
        }

        $program->load(['category', 'skills', 'trainers', 'sessions.trainer', 'sessions.room', 'eligibilityRules']);

        return response()->json(['data' => new ProgramResource($program), 'meta' => $meta], 201);
    }

    // Saved program audience -------------------------------------------------

    public function showAudience(Program $program): JsonResponse
    {
        $audience = $program->audience ?? [];

        return response()->json(['data' => [
            'audience' => $audience,
            'summary' => $this->audience->describe($audience),
            'preview' => $this->audience->preview($audience, app()->getLocale()),
            'sample' => $this->audience->sample($audience),
            'registered' => $program->registrations()->count(),
        ]]);
    }

    public function updateAudience(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(SurveyAudience::rules('audience.'));
        $this->rules->apply($program, $data['audience'] ?? []);

        return $this->showAudience($program->refresh());
    }

    public function nominateAudience(Program $program): JsonResponse
    {
        if (! in_array($program->status, Program::VISIBLE, true)) {
            throw new BusinessRuleException(__('messages.program_builder.publish_first'), 'program_not_published');
        }

        return response()->json(['data' => $this->planner->nominateAudience($program, $this->user())]);
    }
}
