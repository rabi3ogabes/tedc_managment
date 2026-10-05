<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanItem;
use App\Services\AnnualPlanExport;
use App\Services\AnnualPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The annual training plan studio: plans, rules, generation, items, workflow, execution, changes and export. */
class AnnualPlanController extends Controller
{
    public function __construct(private readonly AnnualPlanService $plans) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => TrainingPlan::withCount('items')->orderByDesc('year')->orderByDesc('version')->get()->map(fn ($p) => $this->present($p))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2024', 'max:2100'], 'title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:2000'], 'new_version' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->present($this->plans->create($data, $this->user()), true)], 201);
    }

    public function show(TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->present($plan, true)]);
    }

    public function update(Request $request, TrainingPlan $plan): JsonResponse
    {
        $plan->update($request->validate(['title_ar' => ['sometimes', 'string', 'max:200'], 'title_en' => ['sometimes', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:2000']]));

        return response()->json(['data' => $this->present($plan, true)]);
    }

    public function rules(Request $request, TrainingPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['required', 'array'], 'rules.weights' => ['sometimes', 'array'], 'rules.weights.*' => ['numeric', 'min:0', 'max:100'],
            'rules.max_seats_per_group' => ['sometimes', 'integer', 'min:1', 'max:5000'], 'rules.default_hours_per_group' => ['sometimes', 'numeric', 'min:1', 'max:500'],
            'rules.max_seats_total' => ['sometimes', 'nullable', 'integer', 'min:1'], 'rules.max_hours_total' => ['sometimes', 'nullable', 'numeric', 'min:1'],
            'rules.min_fill_percent' => ['sometimes', 'integer', 'min:0', 'max:100'], 'rules.carry_over' => ['sometimes', 'boolean'],
            'rules.program_types' => ['sometimes', 'array'], 'rules.program_types.*' => ['string', 'max:40'], 'rules.mandatory_categories' => ['sometimes', 'array'], 'rules.mandatory_categories.*' => ['uuid'],
            'rules.windows' => ['sometimes', 'array'], 'rules.windows.*' => ['integer', 'min:1', 'max:4'],
        ]);
        $plan->update(['rules' => array_replace_recursive($this->plans->rules($plan), $data['rules'])]);

        return response()->json(['data' => $this->present($plan->fresh(), true)]);
    }

    public function generate(TrainingPlan $plan): JsonResponse
    {
        $result = $this->plans->generate($plan);

        return response()->json(['data' => $this->present($plan->fresh(), true) + $result]);
    }

    public function storeItem(Request $request, TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->plans->addItem($plan, $request->validate($this->itemRules(true)), $this->user())], 201);
    }

    public function updateItem(Request $request, TrainingPlan $plan, TrainingPlanItem $item): JsonResponse
    {
        abort_unless($item->plan_id === $plan->id, 404);

        return response()->json(['data' => $this->plans->updateItem($plan, $item, $request->validate($this->itemRules(false)), $this->user())]);
    }

    public function destroyItem(Request $request, TrainingPlan $plan, TrainingPlanItem $item): JsonResponse
    {
        abort_unless($item->plan_id === $plan->id, 404);
        $this->plans->removeItem($plan, $item, $request->input('reason'), $this->user());

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function submit(TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->present($this->plans->submit($plan, $this->user()))]);
    }

    public function return(Request $request, TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->present($this->plans->return($plan, $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null, $this->user()))]);
    }

    public function approve(Request $request, TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->present($this->plans->approve($plan, $this->user(), $request->validate(['signed_pdf_path' => ['nullable', 'string', 'max:300']])['signed_pdf_path'] ?? null))]);
    }

    public function activate(TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->present($this->plans->activate($plan))]);
    }

    public function close(TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->present($this->plans->close($plan))]);
    }

    public function execution(TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->plans->execution($plan)]);
    }

    public function changes(TrainingPlan $plan): JsonResponse
    {
        return response()->json(['data' => $plan->changes()->with('changedBy:id,name,name_ar')->latest('created_at')->get()->map(fn ($c) => [
            'id' => $c->id, 'item_id' => $c->item_id, 'change_type' => $c->change_type, 'before' => $c->before, 'after' => $c->after, 'reason' => $c->reason,
            'changed_by' => $c->changedBy?->displayName(), 'created_at' => $c->created_at?->toIso8601String(),
        ])->all()]);
    }

    public function export(Request $request, TrainingPlan $plan, AnnualPlanExport $export): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];
        $name = "training-plan-{$plan->year}-v{$plan->version}.{$format}";
        $type = $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf';

        return response($format === 'xlsx' ? $export->xlsx($plan) : $export->pdf($plan), 200, ['Content-Type' => $type, 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }

    private function itemRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'title_ar' => [$req, 'string', 'max:200'], 'title_en' => [$req, 'string', 'max:200'], 'program_id' => ['sometimes', 'nullable', 'uuid', 'exists:programs,id'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:program_categories,id'], 'audience' => ['sometimes', 'nullable', 'array'],
            'priority' => ['sometimes', Rule::in(['critical', 'high', 'medium', 'low'])], 'planned_groups' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'planned_seats' => ['sometimes', 'integer', 'min:0', 'max:100000'], 'planned_hours' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
            'window_start' => ['sometimes', 'nullable', 'date'], 'window_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:window_start'],
            'status' => ['sometimes', Rule::in(['planned', 'in_execution', 'done', 'postponed', 'cancelled'])], 'is_emergency' => ['sometimes', 'boolean'],
            'rationale_ar' => ['sometimes', 'nullable', 'string', 'max:2000'], 'rationale_en' => ['sometimes', 'nullable', 'string', 'max:2000'], 'review_comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    private function present(TrainingPlan $plan, bool $withItems = false): array
    {
        $out = [
            'id' => $plan->id, 'year' => $plan->year, 'version' => $plan->version, 'title_ar' => $plan->title_ar, 'title_en' => $plan->title_en, 'status' => $plan->status, 'notes' => $plan->notes,
            'rules' => $this->plans->rules($plan), 'items_count' => $plan->items_count ?? $plan->items()->count(), 'submitted_at' => $plan->submitted_at?->toIso8601String(), 'approved_at' => $plan->approved_at?->toIso8601String(),
            'signed_pdf_path' => $plan->signed_pdf_path,
        ];
        if ($withItems) {
            $out['items'] = $plan->items()->orderByDesc('priority_score')->get()->all();
        }

        return $out;
    }
}
