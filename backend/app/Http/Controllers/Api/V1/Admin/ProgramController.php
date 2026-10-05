<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Services\FileStorage;
use App\Services\ImpactService;
use App\Support\RemoteProgramRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user();

        // Everything except the delivery type, so the three type tabs can show how many programs each one holds.
        $scope = fn () => Program::query()
            // Trainers only see programs they deliver.
            ->when($user->hasRole(Role::TRAINER) && ! $this->isCenterStaff($user), fn ($q) => $q->whereHas('trainers', fn ($t) => $t->where('trainers.user_id', $user->id)))
            ->when($request->query('status'), fn ($q, $status) => $q->whereIn('status', explode(',', $status)))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->whereLike('title_ar', "%{$term}%")->orWhereLike('title_en', "%{$term}%")->orWhereLike('code', "%{$term}%")));

        $programs = $scope()->with(['category', 'trainers'])
            ->withCount(['registrations as seats_taken' => fn ($q) => $q->whereIn('status', Registration::SEAT_HOLDING)])
            ->when(in_array($request->query('delivery_mode'), ['in_person', 'online', 'hybrid'], true), fn ($q) => $q->where('delivery_mode', $request->query('delivery_mode')))
            ->latest()
            ->paginate($this->perPage($request));

        $counts = $scope()->selectRaw('delivery_mode, count(*) as total')->groupBy('delivery_mode')->pluck('total', 'delivery_mode');

        return ProgramResource::collection($programs)->additional(['types' => [
            'in_person' => (int) ($counts['in_person'] ?? 0), 'online' => (int) ($counts['online'] ?? 0), 'hybrid' => (int) ($counts['hybrid'] ?? 0),
        ]]);
    }

    public function show(Program $program): ProgramResource
    {
        $program->load(['category', 'coordinator:id,name,name_ar', 'skills', 'trainers', 'sessions.trainer', 'sessions.room', 'targetGroups.jobTitle', 'eligibilityRules', 'units.skills'])
            ->loadCount(['registrations as seats_taken' => fn ($q) => $q->whereIn('status', Registration::SEAT_HOLDING)]);

        return new ProgramResource($program);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $program = DB::transaction(function () use ($data, $request) {
            $program = Program::create($data + ['created_by' => $this->user()->id]);
            $this->syncRelations($program, $request);

            return $program;
        });

        return (new ProgramResource($program->load(['category', 'skills', 'trainers', 'targetGroups'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Program $program): ProgramResource
    {
        DB::transaction(function () use ($request, $program) {
            $program->update($this->validated($request, $program));
            $this->syncRelations($program, $request);
        });

        return $this->show($program);
    }

    public function updateStatus(Request $request, Program $program): ProgramResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(Program::STATUSES)]]);
        $program->update($data);

        return $this->show($program);
    }

    public function uploadCover(Request $request, Program $program, FileStorage $storage): ProgramResource
    {
        $request->validate(['cover' => ['required', 'image', 'max:5120']]);
        $program->update(['cover_path' => $storage->uploadPublic($request->file('cover'), 'programs')]);

        return $this->show($program);
    }

    public function destroy(Program $program): JsonResponse
    {
        $program->delete();

        return response()->json(null, 204);
    }

    public function impact(Program $program, ImpactService $impact): JsonResponse
    {
        return response()->json(['data' => $impact->programImpact($program)]);
    }

    private function validated(Request $request, ?Program $program = null): array
    {
        $required = $program ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:32', Rule::unique('programs', 'code')->ignore($program?->id)],
            'category_id' => ['nullable', 'uuid', 'exists:program_categories,id'],
            'coordinator_id' => ['nullable', 'uuid', 'exists:users,id'],
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'summary_ar' => ['nullable', 'string', 'max:500'],
            'summary_en' => ['nullable', 'string', 'max:500'],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'objectives' => ['nullable', 'array'],
            'objectives.*' => ['string', 'max:500'],
            'delivery_mode' => ['sometimes', Rule::in(['in_person', 'online', 'hybrid'])],
            'level' => ['sometimes', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'total_hours' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'min_attendance_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'require_biometric' => ['sometimes', 'boolean'],
            'requires_tasks' => ['sometimes', 'boolean'],
            'requires_evaluation' => ['sometimes', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'after:registration_opens_at'],
            'registration_modes' => ['nullable', 'array'],
            'registration_modes.*' => [Rule::in(Program::MODES)],
            'status' => ['sometimes', Rule::in(Program::STATUSES)],
            'is_featured' => ['sometimes', 'boolean'],
            'skills' => ['sometimes', 'array'],
            'skills.*.id' => ['required', 'uuid', 'exists:skills,id'],
            'skills.*.target_level' => ['required', 'integer', 'between:1,5'],
            'trainers' => ['sometimes', 'array'],
            'trainers.*.id' => ['required', 'uuid', 'exists:trainers,id'],
            'trainers.*.role' => ['nullable', Rule::in(['lead', 'assistant'])],
            'target_groups' => ['sometimes', 'array'],
            'target_groups.*.job_title_id' => ['nullable', 'uuid', 'exists:job_titles,id'],
            'target_groups.*.department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'target_groups.*.school_type' => ['nullable', 'string', 'max:32'],
            'target_groups.*.education_stage' => ['nullable', 'string', 'max:32'],
            'target_groups.*.description' => ['nullable', 'string', 'max:255'],
        ] + RemoteProgramRules::rules());
    }

    private function syncRelations(Program $program, Request $request): void
    {
        if ($request->has('skills')) {
            $program->skills()->sync(collect($request->input('skills'))->mapWithKeys(fn ($s) => [$s['id'] => ['target_level' => $s['target_level']]]));
        }
        if ($request->has('trainers')) {
            $program->trainers()->sync(collect($request->input('trainers'))->mapWithKeys(fn ($t) => [$t['id'] => ['role' => $t['role'] ?? 'lead']]));
        }
        if ($request->has('target_groups')) {
            $program->targetGroups()->delete();
            foreach ($request->input('target_groups') as $group) {
                $program->targetGroups()->create(collect($group)->only(['job_title_id', 'department_id', 'school_type', 'education_stage', 'description'])->all());
            }
        }
    }
}
