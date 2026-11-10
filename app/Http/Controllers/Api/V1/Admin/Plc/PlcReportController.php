<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Services\Plc\PlcReports;
use App\Services\Plc\WorkshopWorkflow;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** PLC Phase 7: the figures (filtered, inside the person's scope), the filter options, and the exports. */
class PlcReportController extends Controller
{
    public function __construct(private readonly PlcReports $reports) {}

    public function show(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->reports->build($this->user(), $f)]);
    }

    /** The decision log across the scope, filtered by kind, decision and date, on top of the report filters. */
    public function decisions(Request $request): JsonResponse
    {
        $f = $this->filters($request, ['kind' => ['nullable', 'string', 'max:32'], 'decision' => ['nullable', 'string', 'max:48'], 'decided_from' => ['nullable', 'date'], 'decided_to' => ['nullable', 'date'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500']]);

        return response()->json(['data' => $this->reports->decisions($this->user(), $f)]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->reports->options($this->user())]);
    }

    public function export(Request $request): Response
    {
        $f = $this->filters($request, ['format' => ['sometimes', Rule::in(['xlsx', 'pdf', 'docx', 'csv'])], 'lang' => ['sometimes', Rule::in(['ar', 'en'])]]);
        $format = $f['format'] ?? 'xlsx';
        $lang = $f['lang'] ?? 'ar';
        $labels = $this->labels($lang);
        $bytes = $this->reports->export($this->user(), $f, $format, $lang, $labels);

        return response($bytes, 200, ['Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => 'attachment; filename="plc-report.'.$format.'"']);
    }

    private function filters(Request $request, array $extra = []): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'source' => ['nullable', Rule::in(PlcWorkshop::SOURCES)], 'scenario' => ['nullable', Rule::in(PlcWorkshop::SCENARIOS)],
            'state' => ['nullable', Rule::in(WorkshopWorkflow::STATES)], 'mode' => ['nullable', Rule::in(['in_person', 'online', 'hybrid'])], 'school_id' => ['nullable', 'uuid'], 'community_id' => ['nullable', 'uuid'], 'region' => ['nullable', 'string', 'max:120'], 'need_id' => ['nullable', 'uuid'],
        ] + $extra);
    }

    /** Human names for the export's codes, in the requested language. */
    private function labels(string $lang): array
    {
        $ar = $lang === 'ar';

        return [
            'state' => ['draft' => $ar ? 'مسودة' : 'Draft', 'submitted' => $ar ? 'مقدمة' : 'Submitted', 'needs_changes' => $ar ? 'تحتاج تعديلاً' : 'Needs changes', 'approved' => $ar ? 'معتمدة' : 'Approved', 'scheduled' => $ar ? 'مجدولة' : 'Scheduled', 'ready' => $ar ? 'جاهزة' : 'Ready', 'in_progress' => $ar ? 'جارية' : 'In progress',
                'completed' => $ar ? 'مكتملة' : 'Completed', 'awaiting_evaluation' => $ar ? 'بانتظار التقييم' : 'Awaiting evaluation', 'closed' => $ar ? 'مغلقة' : 'Closed', 'archived' => $ar ? 'مؤرشفة' : 'Archived', 'rejected' => $ar ? 'مرفوضة' : 'Rejected', 'cancelled' => $ar ? 'ملغاة' : 'Cancelled'],
            'source' => ['rased' => $ar ? 'احتياج من راصد' : 'Need from راصد', 'centre' => $ar ? 'مركز التدريب والتطوير' : 'Training & Development Centre', 'entity' => $ar ? 'جهة أخرى' : 'Another entity', 'school' => $ar ? 'مدرسة' : 'School'],
            'instrument' => ['workshop' => $ar ? 'تقييم الورشة' : 'Workshop evaluation', 'trainer' => $ar ? 'تقييم المدرب' : 'Trainer evaluation', 'session' => $ar ? 'تقييم الجلسة' : 'Session evaluation', 'community' => $ar ? 'تقييم المجتمع' : 'Community evaluation'],
        ];
    }
}
