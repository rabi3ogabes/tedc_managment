<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\InternalWorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Internal workshops: the school submits and runs them, the centre approves. */
class InternalWorkshopController extends Controller
{
    public function __construct(private readonly InternalWorkshopService $workshops) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->workshops->query($this->user())->with('ownerSchool:id,name_ar,name_en')->withCount('registrations')
            ->when($request->query('approval'), fn ($q, $v) => $q->where('approval_status', $v))->latest()->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->present($p))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'summary_ar' => ['nullable', 'string', 'max:1000'], 'summary_en' => ['nullable', 'string', 'max:1000'],
            'objectives' => ['nullable', 'array', 'max:20'], 'objectives.*' => ['string', 'max:500'], 'delivery_mode' => ['sometimes', Rule::in(['in_person', 'online', 'hybrid'])],
            'total_hours' => ['required', 'numeric', 'min:0.5', 'max:200'], 'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['required', 'date', 'after_or_equal:today'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'school_id' => ['sometimes', 'uuid', 'exists:schools,id'],
        ]);

        return response()->json(['data' => $this->present($this->workshops->submit($this->user(), $data)->load('ownerSchool:id,name_ar,name_en'))], 201);
    }

    public function decision(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->workshops->decide($program, $data['decision'], $data['note'] ?? null, $this->user())->load('ownerSchool:id,name_ar,name_en'))]);
    }

    public function register(Request $request, Program $program): JsonResponse
    {
        abort_unless($this->workshops->query($this->user())->whereKey($program->id)->exists(), 404);
        $data = $request->validate(['employee_ids' => ['required_without:employee_nos', 'array', 'max:500'], 'employee_ids.*' => ['uuid'], 'employee_nos' => ['required_without:employee_ids', 'array', 'max:500'], 'employee_nos.*' => ['string', 'max:40']]);

        return response()->json(['data' => $this->workshops->registerStaff($program, $data['employee_ids'] ?? [], $this->user(), $data['employee_nos'] ?? [])]);
    }

    private function present(Program $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'title_ar' => $p->title_ar, 'title_en' => $p->title_en, 'total_hours' => $p->total_hours, 'capacity' => $p->capacity,
            'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'approval_status' => $p->approval_status, 'status' => $p->status,
            'school' => $p->ownerSchool ? ['id' => $p->ownerSchool->id, 'name_ar' => $p->ownerSchool->name_ar, 'name_en' => $p->ownerSchool->name_en] : null,
            'registrations_count' => $p->registrations_count ?? null,
        ];
    }
}
