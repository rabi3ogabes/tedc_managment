<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompetencyDomain;
use App\Models\JobCompetencyRequirement;
use App\Models\JobTitle;
use App\Models\Skill;
use App\Services\CompetencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/** The competency framework: domains, competencies with level descriptors, required levels per job, import and export. */
class CompetencyController extends Controller
{
    private const COLUMNS = ['code', 'name_ar', 'name_en', 'category', 'domain', 'licence_relevant', 'level_1_ar', 'level_1_en', 'level_2_ar', 'level_2_en', 'level_3_ar', 'level_3_en', 'level_4_ar', 'level_4_en', 'level_5_ar', 'level_5_en'];

    public function __construct(private readonly CompetencyService $competencies) {}

    public function domains(): JsonResponse
    {
        return response()->json(['data' => CompetencyDomain::orderBy('sort_order')->orderBy('code')->get()->all()]);
    }

    public function storeDomain(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:48', 'unique:competency_domains,code'], 'name_ar' => ['required', 'string', 'max:200'], 'name_en' => ['required', 'string', 'max:200'], 'sort_order' => ['sometimes', 'integer', 'min:0']]);

        return response()->json(['data' => CompetencyDomain::create($data)], 201);
    }

    public function updateDomain(Request $request, CompetencyDomain $domain): JsonResponse
    {
        $domain->update($request->validate(['name_ar' => ['sometimes', 'string', 'max:200'], 'name_en' => ['sometimes', 'string', 'max:200'], 'sort_order' => ['sometimes', 'integer', 'min:0']]));

        return response()->json(['data' => $domain]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Skill::with('domain:id,code,name_ar,name_en')->when($request->query('domain_id'), fn ($q, $v) => $q->where('domain_id', $v))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name_ar', "%{$t}%")->orWhereLike('name_en', "%{$t}%")->orWhereLike('code', "%{$t}%")))
            ->orderBy('name_en')->get();

        return response()->json(['data' => $rows->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Skill::create($request->validate($this->rules(null)))->load('domain')], 201);
    }

    public function update(Request $request, Skill $competency): JsonResponse
    {
        $competency->update($request->validate($this->rules($competency)));

        return response()->json(['data' => $competency->fresh('domain')]);
    }

    public function requirements(JobTitle $jobTitle): JsonResponse
    {
        return response()->json(['data' => JobCompetencyRequirement::where('job_title_id', $jobTitle->id)->get()->all()]);
    }

    public function syncRequirements(Request $request, JobTitle $jobTitle): JsonResponse
    {
        $data = $request->validate([
            'requirements' => ['present', 'array', 'max:200'], 'requirements.*.skill_id' => ['required', 'uuid', 'exists:skills,id'], 'requirements.*.required_level' => ['required', 'integer', 'between:1,5'],
            'requirements.*.weight' => ['sometimes', 'integer', 'between:1,10'], 'requirements.*.education_stage' => ['nullable', 'string', 'max:32'], 'requirements.*.subject' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(['data' => $this->competencies->replaceRequirements($jobTitle->id, $data['requirements'])->all()]);
    }

    public function weights(Request $request): JsonResponse
    {
        if ($request->isMethod('put')) {
            $data = $request->validate(['verified' => ['sometimes', 'numeric', 'min:0', 'max:10'], 'manager' => ['sometimes', 'numeric', 'min:0', 'max:10'], 'observation' => ['sometimes', 'numeric', 'min:0', 'max:10'], 'self' => ['sometimes', 'numeric', 'min:0', 'max:10'], 'licence' => ['sometimes', 'numeric', 'min:1', 'max:5']]);

            return response()->json(['data' => $this->competencies->saveWeights($data)]);
        }

        return response()->json(['data' => $this->competencies->weights()]);
    }

    public function export(Request $request): Response
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['csv', 'xlsx'])]])['format'] ?? 'xlsx';
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray([self::COLUMNS], null, 'A1');
        $row = 2;
        foreach (Skill::with('domain')->orderBy('code')->get() as $s) {
            $line = [$s->code, $s->name_ar, $s->name_en, $s->category, $s->domain?->code, $s->licence_relevant ? 1 : 0];
            foreach (range(1, 5) as $lvl) {
                array_push($line, $s->descriptors[$lvl]['ar'] ?? '', $s->descriptors[$lvl]['en'] ?? '');
            }
            $sheet->fromArray([$line], null, 'A'.$row++);
        }
        $path = tempnam(sys_get_temp_dir(), 'comp');
        ($format === 'csv' ? new Csv($sheet->getParent()) : new Xlsx($sheet->getParent()))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return response($bytes, 200, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"competency-framework.{$format}\""]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']]);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($h) => trim((string) $h), array_shift($sheet) ?? []);
        $rows = array_map(fn ($r) => array_combine($header, array_pad(array_slice($r, 0, count($header)), count($header), null)), $sheet);

        return response()->json(['data' => $this->competencies->import($rows)]);
    }

    private function rules(?Skill $skill): array
    {
        $r = $skill ? 'sometimes' : 'required';

        return [
            'code' => [$r, 'alpha_dash', 'max:48', Rule::unique('skills', 'code')->ignore($skill?->id)], 'name_ar' => [$r, 'string', 'max:200'], 'name_en' => [$r, 'string', 'max:200'], 'category' => [$r, 'string', 'max:48'],
            'domain_id' => ['sometimes', 'nullable', 'uuid', 'exists:competency_domains,id'], 'framework_version' => ['sometimes', 'nullable', 'string', 'max:16'], 'licence_relevant' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
            'descriptors' => ['sometimes', 'nullable', 'array'], 'descriptors.*.ar' => ['nullable', 'string', 'max:500'], 'descriptors.*.en' => ['nullable', 'string', 'max:500'],
        ];
    }
}
