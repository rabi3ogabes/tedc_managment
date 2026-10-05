<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CareerPath;
use App\Models\EligibilityRule;
use App\Models\Employee;
use App\Models\EmployeePathProgress;
use App\Models\ProfessionalLicence;
use App\Services\CareerPathEngine;
use App\Services\LicenceService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Career and licence paths, their levels, the compliance view and the licence register. */
class CareerAdminController extends Controller
{
    public function __construct(private readonly CareerPathEngine $engine, private readonly LicenceService $licences) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => CareerPath::with('levels')->orderBy('type')->orderBy('title_ar')->get()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => CareerPath::create($this->validated($request, true))->load('levels')], 201);
    }

    public function update(Request $request, CareerPath $path): JsonResponse
    {
        $path->update($this->validated($request, false));

        return response()->json(['data' => $path->load('levels')]);
    }

    /** Replaces the levels: conditions in the eligibility-rule syntax, required programs ("any N of"), PD hours and validity. */
    public function levels(Request $request, CareerPath $path): JsonResponse
    {
        $d = $request->validate([
            'levels' => ['required', 'array', 'min:1', 'max:10'], 'levels.*.level_no' => ['required', 'integer', 'min:1', 'max:20', 'distinct'], 'levels.*.title_ar' => ['required', 'string', 'max:200'], 'levels.*.title_en' => ['required', 'string', 'max:200'],
            'levels.*.conditions' => ['nullable', 'array', 'max:30'], 'levels.*.conditions.*.field' => ['required', 'string', Rule::in(EligibilityRule::FIELDS)], 'levels.*.conditions.*.operator' => ['required', 'string', 'max:20'], 'levels.*.conditions.*.value' => ['nullable'],
            'levels.*.required_programs' => ['nullable', 'array', 'max:20'], 'levels.*.required_programs.*.program_ids' => ['required', 'array', 'min:1'], 'levels.*.required_programs.*.program_ids.*' => ['uuid'], 'levels.*.required_programs.*.min' => ['nullable', 'integer', 'min:1'],
            'levels.*.min_pd_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'], 'levels.*.validity_months' => ['nullable', 'integer', 'min:1', 'max:240'], 'levels.*.renewal_conditions' => ['nullable', 'array'],
        ]);
        $path->levels()->delete();
        foreach ($d['levels'] as $l) {
            $path->levels()->create($l + ['min_pd_hours' => $l['min_pd_hours'] ?? 0]);
        }

        return response()->json(['data' => $path->load('levels')->fresh('levels')]);
    }

    /** The funnel per level and the matrix of employees against the path. */
    public function compliance(Request $request, CareerPath $path): JsonResponse|Response
    {
        $f = $request->validate(['school' => ['nullable', 'uuid'], 'job' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['not_started', 'in_progress', 'eligible', 'achieved', 'expired', 'lost'])], 'format' => ['nullable', Rule::in(['xlsx', 'pdf', 'docx', 'csv'])], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $path->load('levels');
        $rows = EmployeePathProgress::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en', 'employee.jobTitle:id,name_ar,name_en'])->where('path_id', $path->id)
            ->whereHas('employee', fn ($q) => $q->when($f['school'] ?? null, fn ($w, $s) => $w->where('school_id', $s))->when($f['job'] ?? null, fn ($w, $j) => $w->where('job_title_id', $j)))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->get();
        $licences = ProfessionalLicence::where('path_id', $path->id)->where('status', 'active')->get()->groupBy('employee_id');
        $matrix = $rows->map(fn ($p) => ['employee_id' => $p->employee_id, 'name' => $p->employee->user?->displayName(), 'employee_no' => $p->employee->employee_no, 'school' => $p->employee->school?->translate('name'), 'job' => $p->employee->jobTitle?->translate('name'), 'current_level' => $p->current_level_no, 'target_level' => $p->target_level_no, 'status' => $p->status,
            'unmet' => collect($p->explanation ?? [])->where('met', false)->pluck('label')->values()->all(), 'expires_at' => $licences->get($p->employee_id)?->max('expires_at')?->toDateString()])->values();
        $funnel = $path->levels->map(fn ($l) => ['level_no' => $l->level_no, 'title_ar' => $l->title_ar, 'title_en' => $l->title_en, 'at_level' => $rows->where('current_level_no', $l->level_no)->count(), 'eligible_for' => $rows->where('target_level_no', $l->level_no)->where('status', 'eligible')->count()])->prepend(['level_no' => 0, 'title_ar' => 'بدون مستوى', 'title_en' => 'No level', 'at_level' => $rows->where('current_level_no', 0)->count(), 'eligible_for' => 0])->values();

        if (isset($f['format'])) {
            $ar = ($f['lang'] ?? 'ar') === 'ar';
            $doc = ['title' => ($ar ? 'الامتثال: ' : 'Compliance: ').($ar ? $path->title_ar : $path->title_en), 'subtitle' => null, 'sections' => [
                ['heading' => $ar ? 'القمع حسب المستوى' : 'Funnel by level', 'table' => ['head' => [$ar ? 'المستوى' : 'Level', $ar ? 'عدد الموظفين' : 'Employees', $ar ? 'مؤهلون للمستوى' : 'Eligible for'], 'rows' => $funnel->map(fn ($x) => [$ar ? $x['title_ar'] : $x['title_en'], $x['at_level'], $x['eligible_for']])->all()]],
                ['heading' => $ar ? 'المصفوفة' : 'Matrix', 'table' => ['head' => [$ar ? 'الموظف' : 'Employee', $ar ? 'الرقم' : 'No.', $ar ? 'المدرسة' : 'School', $ar ? 'الوظيفة' : 'Job', $ar ? 'المستوى الحالي' : 'Current', $ar ? 'الحالة' : 'Status', $ar ? 'ينقصه' : 'Missing', $ar ? 'انتهاء الرخصة' : 'Licence expires'], 'rows' => $matrix->map(fn ($m) => [$m['name'], $m['employee_no'], $m['school'], $m['job'], $m['current_level'], $m['status'], implode(' | ', $m['unmet']), $m['expires_at']])->all()]]]];

            return response(app(ReportExporter::class)->render($doc, $f['format'], $f['lang'] ?? 'ar'), 200, ['Content-Type' => ReportExporter::MIME[$f['format']], 'Content-Disposition' => "attachment; filename=\"compliance.{$f['format']}\""]);
        }

        return response()->json(['data' => ['funnel' => $funnel, 'matrix' => $matrix, 'totals' => $rows->countBy('status')]]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $id = $request->validate(['employee_id' => ['nullable', 'uuid', 'exists:employees,id']])['employee_id'] ?? null;
        $n = $id ? count($this->engine->evaluate(Employee::findOrFail($id))) : $this->engine->evaluateAll();

        return response()->json(['data' => ['evaluated' => $n]]);
    }

    public function achieve(Request $request, CareerPath $path): JsonResponse
    {
        $employee = Employee::findOrFail($request->validate(['employee_id' => ['required', 'uuid', 'exists:employees,id']])['employee_id']);

        return response()->json(['data' => $this->engine->achieve($employee, $path, $this->user())]);
    }

    // ── licences

    public function licences(Request $request): JsonResponse
    {
        $rows = ProfessionalLicence::with('employee.user:id,name,name_ar', 'path:id,title_ar,title_en')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->when($request->query('path'), fn ($q, $p) => $q->where('path_id', $p))
            ->when($request->query('expiring'), fn ($q) => $q->where('status', 'active')->whereBetween('expires_at', [today(), today()->addDays(90)]))->orderBy('expires_at')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($l) => $l->toArray() + ['employee_name' => $l->employee->user?->displayName(), 'employee_no' => $l->employee->employee_no])->all()]);
    }

    public function saveLicence(Request $request, ?ProfessionalLicence $licence = null): JsonResponse
    {
        $d = $request->validate(['employee_id' => [$licence ? 'sometimes' : 'required', 'uuid', 'exists:employees,id'], 'path_id' => ['nullable', 'uuid', 'exists:career_paths,id'], 'level_no' => ['required', 'integer', 'min:1', 'max:20'], 'licence_no' => ['required', 'string', 'max:40'], 'issued_at' => ['required', 'date'], 'expires_at' => ['nullable', 'date', 'after:issued_at'], 'status' => ['sometimes', Rule::in(['active', 'expired', 'suspended'])]]);
        $row = $licence ? tap($licence)->update($d) : ProfessionalLicence::create($d + ['source' => 'manual', 'status' => 'active']);
        $this->engine->evaluate(Employee::find($row->employee_id));

        return response()->json(['data' => $row->fresh()], $licence ? 200 : 201);
    }

    /** CSV (employee_no, level_no, licence_no, issued_at, expires_at[, path_id]) or JSON rows; running it twice changes nothing. */
    public function importLicences(Request $request): JsonResponse
    {
        $request->validate(['file' => ['nullable', 'file', 'max:5120', 'mimes:csv,txt'], 'rows' => ['nullable', 'array', 'max:2000']]);
        $rows = $request->input('rows', []);
        if ($file = $request->file('file')) {
            $h = null;
            foreach (file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $cells = str_getcsv(ltrim($line, "\xEF\xBB\xBF"));
                if (! $h) {
                    $h = array_map(fn ($c) => strtolower(trim($c)), $cells);

                    continue;
                }
                $rows[] = array_combine($h, array_pad($cells, count($h), null)) ?: [];
            }
        }

        return response()->json(['data' => $this->licences->import($rows)]);
    }

    private function validated(Request $request, bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return $request->validate(['type' => [$r, Rule::in(['promotion', 'licence', 'specialisation'])], 'title_ar' => [$r, 'string', 'max:200'], 'title_en' => [$r, 'string', 'max:200'], 'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'], 'job_title_ids' => ['nullable', 'array'], 'job_title_ids.*' => ['uuid'], 'is_active' => ['sometimes', 'boolean']]);
    }
}
