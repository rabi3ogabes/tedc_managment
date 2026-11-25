<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\Forecast\ForecastReport;
use App\Http\Controllers\Controller;
use App\Models\Forecast;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Forecasts and the open risk list as Excel, PDF or Word (RPT-11), in the person's language. */
class ForecastExportController extends Controller
{
    public function __construct(private readonly ForecastReport $report, private readonly ReportExporter $exporter) {}

    public function forecasts(Request $request, string $format): Response
    {
        $d = $request->validate(['dimension' => ['nullable', Rule::in(['competency', 'job', 'school'])], 'year' => ['nullable', 'integer', 'between:2020,2100'], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $year = (int) ($d['year'] ?? Forecast::max('year') ?? now()->year + 1);
        $lang = $d['lang'] ?? app()->getLocale();

        return $this->file($this->report->forecasts($year, $d['dimension'] ?? null, $lang), $format, $lang, "forecasts-{$year}");
    }

    public function risks(Request $request, string $format): Response
    {
        $d = $request->validate(['type' => ['nullable', 'string', 'max:40'], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $lang = $d['lang'] ?? app()->getLocale();

        return $this->file($this->report->risks($d['type'] ?? null, $lang), $format, $lang, 'risks-'.now()->format('Y-m-d'));
    }

    private function file(array $doc, string $format, string $lang, string $name): Response
    {
        return response($this->exporter->render($doc, $format, $lang), 200, [
            'Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => "attachment; filename=\"{$name}.{$format}\"",
        ]);
    }
}
