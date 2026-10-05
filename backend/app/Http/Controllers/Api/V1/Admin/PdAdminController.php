<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\KnowledgeTransfer;
use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\PdAnnualTarget;
use App\Models\PdRecognitionRequest;
use App\Models\Program;
use App\Models\SiteSetting;
use App\Services\AnnualHoursService;
use App\Services\KnowledgeTransferService;
use App\Services\PdService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** PD types and hour rules, the centre's recognition queue, annual targets, reports and the knowledge-transfer review. */
class PdAdminController extends Controller
{
    public function __construct(private readonly PdService $pd, private readonly AnnualHoursService $hours, private readonly KnowledgeTransferService $kt) {}

    public function types(): JsonResponse
    {
        $this->pd->ensureTypes();

        return response()->json(['data' => PdActivityType::orderBy('name_ar')->get()->all()]);
    }

    public function saveType(Request $request, ?PdActivityType $type = null): JsonResponse
    {
        $r = $type ? 'sometimes' : 'required';
        $d = $request->validate(['code' => [$r, 'string', 'max:30', Rule::unique('pd_activity_types', 'code')->ignore($type?->id)], 'name_ar' => [$r, 'string', 'max:200'], 'name_en' => [$r, 'string', 'max:200'],
            'hour_rules' => [$r, 'array'], 'hour_rules.*.factor' => ['nullable', 'numeric', 'min:0', 'max:10'], 'hour_rules.*.cap_activity' => ['nullable', 'numeric', 'min:0'], 'hour_rules.cap_year' => ['nullable', 'numeric', 'min:0'], 'evidence_required' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']]);
        $row = $type ? tap($type)->update($d) : PdActivityType::create($d);

        return response()->json(['data' => $row->fresh()], $type ? 200 : 201);
    }

    /** The centre sees every activity; a manager sees the activities waiting for them. */
    public function activities(Request $request): JsonResponse
    {
        $user = $this->user();
        $rows = PdActivity::with('employee.user:id,name,name_ar', 'type:id,code,name_ar,name_en')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(! $user->hasPermission('pd.recognise'), fn ($q) => $q->where('manager_id', $user->id))->latest()->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($a) => $a->toArray() + ['employee_name' => $a->employee->user?->displayName()])->all()]);
    }

    public function decide(Request $request, PdActivity $activity): JsonResponse
    {
        abort_unless($activity->manager_id === $this->user()->id || $this->user()->hasPermission('pd.recognise'), 403);
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'return'])], 'note' => ['nullable', 'string', 'max:1000'], 'hours' => ['nullable', 'numeric', 'min:0', 'max:1000']]);

        return response()->json(['data' => $this->pd->decide($activity, $d['decision'], $d['note'] ?? null, $this->user(), isset($d['hours']) ? (float) $d['hours'] : null)]);
    }

    public function recognitions(Request $request): JsonResponse
    {
        $rows = PdRecognitionRequest::with('activity.employee.user:id,name,name_ar', 'activity.type:id,name_ar,name_en')->when($request->query('pending'), fn ($q) => $q->whereNull('center_decision'))->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $r->toArray() + ['employee_name' => $r->activity->employee->user?->displayName()])->all()]);
    }

    public function recognise(Request $request, PdRecognitionRequest $recognition): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'recognised_hours' => ['required_if:decision,approved', 'nullable', 'numeric', 'min:0', 'max:1000'], 'equivalent_program_ids' => ['nullable', 'array'], 'equivalent_program_ids.*' => ['uuid', 'exists:programs,id'], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->pd->recognise($recognition, $d['decision'], isset($d['recognised_hours']) ? (float) $d['recognised_hours'] : null, $d['equivalent_program_ids'] ?? [], $d['note'] ?? null, $this->user())]);
    }

    public function targets(): JsonResponse
    {
        return response()->json(['data' => ['targets' => PdAnnualTarget::orderByDesc('year')->get(), 'year_start_month' => $this->hours->startMonth(), 'current_year' => $this->hours->yearOf()]]);
    }

    public function saveTargets(Request $request): JsonResponse
    {
        $d = $request->validate(['year_start_month' => ['sometimes', 'integer', 'between:1,12'], 'targets' => ['sometimes', 'array', 'max:50'], 'targets.*.id' => ['nullable', 'uuid'], 'targets.*.year' => ['required', 'integer', 'between:2020,2100'], 'targets.*.min_hours' => ['required', 'numeric', 'min:0', 'max:2000'],
            'targets.*.audience' => ['nullable', 'array'], 'targets.*.audience.job_title_ids' => ['nullable', 'array'], 'targets.*.audience.job_categories' => ['nullable', 'array'], 'targets.*.counts' => ['nullable', 'array']]);
        if (isset($d['year_start_month'])) {
            SiteSetting::updateOrCreate(['key' => 'pd'], ['value' => ['year_start_month' => $d['year_start_month']], 'updated_by' => $this->user()->id]);
        }
        foreach ($d['targets'] ?? [] as $t) {
            isset($t['id']) ? PdAnnualTarget::whereKey($t['id'])->update(collect($t)->except('id')->all()) : PdAnnualTarget::create($t);
        }

        return $this->targets();
    }

    /** Hours per employee and year, distribution by domain, approval rates and the people below their target. */
    public function report(Request $request): JsonResponse|Response
    {
        $f = $request->validate(['year' => ['nullable', 'integer'], 'school' => ['nullable', 'uuid'], 'format' => ['nullable', Rule::in(['xlsx', 'pdf', 'docx', 'csv'])], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $year = $f['year'] ?? $this->hours->yearOf();
        $employees = Employee::with('user:id,name,name_ar', 'school:id,name_ar,name_en')->when($f['school'] ?? null, fn ($q, $s) => $q->where('school_id', $s))->limit(1000)->get();
        $rows = $employees->map(fn ($e) => ['employee' => $e->user?->displayName(), 'employee_no' => $e->employee_no, 'school' => $e->school?->translate('name')] + collect($this->hours->summary($e, $year))->only(['counted', 'total', 'target', 'shortfall'])->all())->values();
        [$from, $to] = $this->hours->bounds($year);
        $acts = PdActivity::whereBetween('starts_on', [$from, $to])->get();
        $data = ['year' => $year, 'rows' => $rows, 'by_domain' => $acts->where('status', 'approved')->groupBy(fn ($a) => $a->domain ?: '—')->map(fn ($g, $d) => ['domain' => $d, 'hours' => round($g->sum(fn ($a) => $a->approved_hours ?? $a->computed_hours), 1), 'count' => $g->count()])->values(),
            'approval' => ['submitted' => $acts->whereNotIn('status', ['draft'])->count(), 'approved' => $acts->where('status', 'approved')->count(), 'rejected' => $acts->where('status', 'rejected')->count(), 'returned' => $acts->where('status', 'returned')->count()], 'below_target' => $rows->filter(fn ($r) => ($r['shortfall'] ?? 0) > 0)->count()];

        if (isset($f['format'])) {
            $ar = ($f['lang'] ?? 'ar') === 'ar';
            $doc = ['title' => ($ar ? 'تقرير ساعات التطوير المهني ' : 'Professional-development hours ').$year, 'subtitle' => null, 'sections' => [['heading' => $ar ? 'الموظفون' : 'Employees', 'table' => ['head' => [$ar ? 'الموظف' : 'Employee', $ar ? 'الرقم' : 'No.', $ar ? 'المدرسة' : 'School', $ar ? 'مركز' : 'Centre', $ar ? 'داخلي' : 'Internal', $ar ? 'خارجي' : 'External', $ar ? 'نقل معرفة' : 'Knowledge transfer', $ar ? 'المجموع' : 'Total', $ar ? 'الحد الأدنى' : 'Target', $ar ? 'النقص' : 'Shortfall'],
                'rows' => $rows->map(fn ($r) => [$r['employee'], $r['employee_no'], $r['school'], $r['counted']['center'], $r['counted']['internal'], $r['counted']['external'], $r['counted']['knowledge_transfer'], $r['total'], $r['target'], $r['shortfall']])->all()]],
                ['heading' => $ar ? 'حسب المجال' : 'By domain', 'table' => ['head' => [$ar ? 'المجال' : 'Domain', $ar ? 'الساعات' : 'Hours', $ar ? 'الأنشطة' : 'Activities'], 'rows' => $data['by_domain']->map(fn ($d) => [$d['domain'], $d['hours'], $d['count']])->all()]]]];

            return response(app(ReportExporter::class)->render($doc, $f['format'], $f['lang'] ?? 'ar'), 200, ['Content-Type' => ReportExporter::MIME[$f['format']], 'Content-Disposition' => "attachment; filename=\"pd-report.{$f['format']}\""]);
        }

        return response()->json(['data' => $data]);
    }

    // ── knowledge transfer

    public function transfers(Request $request): JsonResponse
    {
        $rows = KnowledgeTransfer::with('employee.user:id,name,name_ar', 'registration.program:id,code,title_ar,title_en')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->when($request->query('program'), fn ($q, $p) => $q->whereHas('registration', fn ($w) => $w->where('program_id', $p)))->latest()->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($k) => $k->toArray() + ['employee_name' => $k->employee->user?->displayName(), 'program' => $k->registration->program->translate('title')])->all()]);
    }

    public function decideTransfer(Request $request, KnowledgeTransfer $transfer): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->kt->decide($transfer, $d['decision'], $d['note'] ?? null, $this->user())]);
    }

    public function reach(Program $program): JsonResponse
    {
        return response()->json(['data' => $this->kt->reach($program->id) + ['config' => $program->knowledge_transfer]]);
    }

    public function saveKnowledgeTransferSetting(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate(['required' => ['required', 'boolean'], 'min_beneficiaries' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'min_hours' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'deadline_days' => ['sometimes', 'integer', 'min:1', 'max:365'], 'evidence_required' => ['sometimes', 'boolean']]);
        $program->update(['knowledge_transfer' => $d]);

        return response()->json(['data' => $program->fresh()->knowledge_transfer]);
    }
}
