<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramEvaluationReport;
use App\Models\TrainingGroup;
use App\Services\EvaluationSettings;
use App\Services\ProgramEvaluationService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The program evaluation report: draft from the data, edit, review, approve, export. */
class EvaluationReportController extends Controller
{
    public function __construct(private readonly ProgramEvaluationService $service, private readonly ReportExporter $exporter) {}

    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => ProgramEvaluationReport::where('program_id', $program->id)->latest()->get()->all()]);
    }

    public function preview(Program $program, Request $request): JsonResponse
    {
        $group = $this->group($program, $request->query('group'));
        $m = $this->service->metrics($program, $group);
        $c = $this->service->classify($m);

        return response()->json(['data' => ['metrics' => $m, 'classification' => $c['classification'], 'reasons' => $c['reasons'], 'thresholds' => app(EvaluationSettings::class)->get('classification')]]);
    }

    public function store(Program $program, Request $request): JsonResponse
    {
        $group = $this->group($program, $request->input('group_id'));

        return response()->json(['data' => $this->service->generate($program, $group, $this->user())], 201);
    }

    public function update(Request $request, ProgramEvaluationReport $report): JsonResponse
    {
        if ($report->status === 'approved') {
            throw new BusinessRuleException(__('messages.evaluation.report_locked'), 'report_locked');
        }
        $d = $request->validate([
            'qualitative' => ['sometimes', 'array'], 'qualitative.strengths' => ['array', 'max:30'], 'qualitative.strengths.*.ar' => ['nullable', 'string', 'max:500'], 'qualitative.strengths.*.en' => ['nullable', 'string', 'max:500'],
            'qualitative.improvements' => ['array', 'max:30'], 'qualitative.improvements.*.ar' => ['nullable', 'string', 'max:500'], 'qualitative.improvements.*.en' => ['nullable', 'string', 'max:500'],
            'recommendations_ar' => ['nullable', 'string', 'max:5000'], 'recommendations_en' => ['nullable', 'string', 'max:5000'],
            'classification' => ['sometimes', Rule::in(['successful_continue', 'needs_review', 'weak_stop'])], 'status' => ['sometimes', Rule::in(['draft', 'reviewed'])],
        ]);
        $reviewed = ($d['status'] ?? null) === 'reviewed' && $report->status !== 'reviewed';
        $report->update($d);
        $reviewed && $this->service->markReviewed($report->fresh());

        return response()->json(['data' => $report->fresh()]);
    }

    public function approve(ProgramEvaluationReport $report): JsonResponse
    {
        if ($report->status !== 'reviewed') {
            throw new BusinessRuleException(__('messages.evaluation.report_not_reviewed'), 'report_not_reviewed');
        }

        return response()->json(['data' => $this->service->approve($report, $this->user())]);
    }

    public function export(ProgramEvaluationReport $report, Request $request): Response
    {
        $d = $request->validate(['format' => ['sometimes', Rule::in(['pdf', 'docx', 'xlsx'])], 'lang' => ['sometimes', Rule::in(['ar', 'en'])]]);
        $format = $d['format'] ?? 'pdf';

        return response($this->exporter->render($this->service->document($report, $d['lang'] ?? 'ar'), $format, $d['lang'] ?? 'ar'), 200, ['Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => "attachment; filename=\"evaluation-report.{$format}\""]);
    }

    private function group(Program $program, ?string $id): ?TrainingGroup
    {
        return $id ? TrainingGroup::where('program_id', $program->id)->findOrFail($id) : null;
    }
}
