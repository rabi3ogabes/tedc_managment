<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Skill;
use App\Models\TrainingNeed;
use App\Services\AnalyticsService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Schools submit training requests; the training center reviews and plans them.
 */
class TrainingNeedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $needs = TrainingNeed::with(['school:id,name_ar,name_en,region', 'skill', 'targetJobTitle', 'program:id,code,title_ar,title_en'])
            ->when($this->schoolScope(), fn ($q, $id) => $q->where('school_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->query('school_id'), fn ($q, $id) => $q->where('school_id', $id))
            ->latest()
            ->paginate($this->perPage($request, 25));

        return response()->json($needs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['nullable', 'uuid', 'exists:schools,id'],
            'skill_id' => ['nullable', 'uuid', 'exists:skills,id'],
            'skill_name' => ['required_without:skill_id', 'nullable', 'string', 'max:255'],
            'employees_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'priority' => ['required', Rule::in(TrainingNeed::PRIORITIES)],
            'reason' => ['required', 'string', 'max:3000'],
            'target_job_title_id' => ['nullable', 'uuid', 'exists:job_titles,id'],
            'target_group' => ['nullable', 'string', 'max:255'],
        ]);

        $schoolId = $this->schoolScope() ?? $data['school_id'] ?? null;
        abort_unless($schoolId, 422, 'school_id is required');

        if (! empty($data['skill_id'])) {
            $data['skill_name'] = Skill::find($data['skill_id'])->name_ar;
        }

        $need = TrainingNeed::create($data + ['school_id' => $schoolId, 'submitted_by' => $this->user()->id, 'status' => 'submitted']);

        return response()->json(['data' => $need->load(['school', 'skill'])], 201);
    }

    public function update(Request $request, TrainingNeed $need, NotificationService $notifications): JsonResponse
    {
        abort_unless($this->isCenterStaff(), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'approved', 'planned', 'fulfilled', 'rejected'])],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'program_id' => ['nullable', 'uuid', 'exists:programs,id'],
        ]);

        $need->update($data + ['reviewed_by' => $this->user()->id]);

        if ($need->submitted_by) {
            $notifications->send(
                $need->submitted_by,
                'training_need.'.$data['status'],
                ['ar' => 'تحديث على طلب الاحتياج التدريبي', 'en' => 'Training request update'],
                ['ar' => "طلب «{$need->skill_name}»: ".$data['status'], 'en' => "Request \"{$need->skill_name}\": ".$data['status']],
                ['training_need_id' => $need->id],
            );
        }

        return response()->json(['data' => $need->load(['school', 'skill', 'program'])]);
    }

    public function analytics(AnalyticsService $analytics): JsonResponse
    {
        abort_if($this->user()->hasRole(Role::SCHOOL_ADMIN) && ! $this->isCenterStaff(), 403);

        return response()->json(['data' => $analytics->trainingNeeds()]);
    }
}
