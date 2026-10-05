<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RegistrationResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TrainingGroupResource;
use App\Models\Program;
use App\Models\TrainingGroup;
use App\Services\TrainingGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Training groups: per-program list, creation with sessions, copy, publish, status changes and the status board. */
class TrainingGroupController extends Controller
{
    public function __construct(private readonly TrainingGroupService $groups) {}

    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => TrainingGroupResource::collection($program->groups()->with(['program', 'supervisor:id,name,name_ar', 'room', 'trainers.trainer'])->withCount('sessions')->get())]);
    }

    public function show(TrainingGroup $group): TrainingGroupResource
    {
        return new TrainingGroupResource($group->load(['program', 'supervisor:id,name,name_ar', 'room', 'trainers.trainer'])->loadCount('sessions'));
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $result = $this->groups->create($program, $this->validated($request), $this->user());

        return response()->json(['data' => (new TrainingGroupResource($result['group']->load(['program', 'supervisor', 'room'])->loadCount('sessions')))->resolve() + ['skipped' => $result['skipped']]], 201);
    }

    public function preview(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['sessions' => ['required', 'array'], 'sessions.start_date' => ['required', 'date']] + $this->patternRules() + ['default_room_id' => ['nullable', 'uuid', 'exists:training_rooms,id']]);

        return response()->json(['data' => $this->groups->preview($data['sessions'], $data['default_room_id'] ?? null)]);
    }

    public function update(Request $request, TrainingGroup $group): TrainingGroupResource
    {
        $data = $request->validate($this->fields(true));

        return new TrainingGroupResource($this->groups->update($group, $data)->load(['program', 'supervisor', 'room']));
    }

    public function destroy(TrainingGroup $group): JsonResponse
    {
        $this->groups->delete($group);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function clone(Request $request, TrainingGroup $group): JsonResponse
    {
        $data = $request->validate(['start_date' => ['required', 'date'], 'capacity' => ['sometimes', 'integer', 'min:1', 'max:5000']]);
        $result = $this->groups->clone($group, $data, $this->user());

        return response()->json(['data' => (new TrainingGroupResource($result['group']->load(['program', 'supervisor', 'room'])->loadCount('sessions')))->resolve() + ['skipped' => $result['skipped']]], 201);
    }

    public function status(Request $request, TrainingGroup $group): TrainingGroupResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(TrainingGroup::STATUSES)], 'reason' => ['nullable', 'string', 'max:1000'], 'postponed_to' => ['nullable', 'date', 'after:today']]);

        return new TrainingGroupResource($this->groups->transition($group, $data['status'], $data['reason'] ?? null, $data['postponed_to'] ?? null, $this->user())->load(['program', 'supervisor', 'room']));
    }

    public function publish(TrainingGroup $group): TrainingGroupResource
    {
        return new TrainingGroupResource($this->groups->publish($group)->load('program'));
    }

    public function unpublish(TrainingGroup $group): TrainingGroupResource
    {
        return new TrainingGroupResource($this->groups->unpublish($group)->load('program'));
    }

    public function sessions(TrainingGroup $group): JsonResponse
    {
        return response()->json(['data' => SessionResource::collection($group->sessions()->with(['program', 'trainer', 'room'])->orderBy('starts_at')->get())]);
    }

    public function participants(TrainingGroup $group): JsonResponse
    {
        return response()->json(['data' => RegistrationResource::collection($group->registrations()->with(['employee.user', 'employee.school', 'employee.jobTitle', 'program'])->latest()->get())]);
    }

    /** The status board: one column per status with the groups in it, filtered by year, category, supervisor and emergency. */
    public function board(Request $request): JsonResponse
    {
        $groups = TrainingGroup::with(['program:id,code,title_ar,title_en,category_id,kind', 'supervisor:id,name,name_ar', 'room'])->withCount('sessions')
            ->when($request->query('year'), fn ($q, $y) => $q->whereYear('start_date', (int) $y))
            ->when($request->query('category_id'), fn ($q, $c) => $q->whereHas('program', fn ($p) => $p->where('category_id', $c)))
            ->when($request->query('supervisor_id'), fn ($q, $s) => $q->where('supervisor_id', $s))
            ->when($request->boolean('emergency'), fn ($q) => $q->where('is_emergency', true))
            ->when($request->query('month'), fn ($q, $m) => $q->whereMonth('start_date', (int) $m))
            ->orderBy('start_date')->limit(500)->get();

        $columns = [];
        foreach (TrainingGroup::STATUSES as $status) {
            $items = $groups->where('status', $status);
            $columns[$status] = ['count' => $items->count(), 'groups' => TrainingGroupResource::collection($items->values())->resolve()];
        }

        return response()->json(['data' => ['columns' => $columns, 'total' => $groups->count()]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate($this->fields(false) + $this->patternRules());
    }

    /** @return array<string, mixed> */
    private function fields(bool $partial): array
    {
        $p = $partial ? 'sometimes' : 'nullable';

        return [
            'title_ar' => ['nullable', 'string', 'max:255'], 'title_en' => ['nullable', 'string', 'max:255'], 'delivery_mode' => ['sometimes', Rule::in(TrainingGroup::MODES)],
            'start_date' => [$p, 'date'], 'end_date' => [$p, 'date', 'after_or_equal:start_date'], 'registration_opens_at' => ['nullable', 'date'], 'registration_closes_at' => ['nullable', 'date', 'after:registration_opens_at'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:5000'], 'min_attendance_percent' => ['nullable', 'integer', 'between:0,100'],
            'supervisor_id' => ['nullable', 'uuid', 'exists:users,id'], 'default_room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'],
            'is_emergency' => ['sometimes', 'boolean'], 'emergency_reason' => ['nullable', 'string', 'max:1000'], 'status_reason' => ['nullable', 'string', 'max:1000'], 'plan_item_id' => ['nullable', 'uuid', 'exists:training_plan_items,id'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function patternRules(): array
    {
        return [
            'sessions' => ['nullable', 'array'], 'sessions.start_date' => ['nullable', 'date'], 'sessions.weekdays' => ['required_with:sessions', 'array', 'min:1'], 'sessions.weekdays.*' => ['integer', 'between:0,6'],
            'sessions.starts' => ['nullable', 'date_format:H:i'], 'sessions.ends' => ['nullable', 'date_format:H:i', 'after:sessions.starts'],
            'sessions.count' => ['nullable', 'integer', 'min:1', 'max:120'], 'sessions.end_date' => ['nullable', 'date'],
        ];
    }
}
