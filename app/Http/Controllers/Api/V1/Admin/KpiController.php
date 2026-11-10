<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Kpi\DataIntegrityChecker;
use App\Services\Kpi\KpiService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The live KPI dashboard: values against targets with a 30-day trend, editable targets, the integrity details and the monthly report. */
class KpiController extends Controller
{
    public function __construct(private readonly KpiService $kpi) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->kpi->dashboard(), 'meta' => ['refreshed_at' => now()->toIso8601String()]]);
    }

    public function refresh(): JsonResponse
    {
        $this->kpi->collect();

        return $this->index();
    }

    public function targets(Request $request): JsonResponse
    {
        $d = $request->validate(['targets' => ['required', 'array'], 'targets.*' => ['numeric', 'min:0', 'max:1000000']]);
        $this->kpi->updateTargets($d['targets']);

        return $this->index();
    }

    public function integrity(DataIntegrityChecker $checker): JsonResponse
    {
        return response()->json(['data' => $checker->rate()]);
    }

    public function report(Request $request, ReportExporter $exporter): Response
    {
        $d = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'format' => ['required', Rule::in(['pdf', 'docx', 'xlsx'])], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $lang = $d['lang'] ?? 'ar';
        $month = Carbon::parse(($d['month'] ?? now()->format('Y-m')).'-01');
        $doc = $this->kpi->monthlyDocument($month, $lang);

        return response($exporter->render($doc, $d['format'], $lang), 200, ['Content-Type' => ReportExporter::MIME[$d['format']], 'Content-Disposition' => 'attachment; filename="kpi-'.$month->format('Y-m').'.'.$d['format'].'"']);
    }
}
