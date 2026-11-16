<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\ReportDefinition;
use App\Services\Reports\BuiltInReports;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Trainee self-service reports (calendar, hours, completed courses, attendance, statement) — always about the person asking. */
class MyReportsController extends Controller
{
    public function __construct(private readonly ReportRunService $runs, private readonly BuiltInReports $builtIn) {}

    public function index(): JsonResponse
    {
        $this->builtIn->ensure();

        return response()->json(['data' => ReportDefinition::where('is_system', true)->where('category', 'trainee')->orderBy('title_ar')->get(['key', 'title_ar', 'title_en'])->map(fn ($d) => ['key' => $d->key, 'title' => ['ar' => $d->title_ar, 'en' => $d->title_en]])]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $def = $this->definition($key);
        $p = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $params = ['date_from' => $p['from'] ?? null, 'date_to' => $p['to'] ?? null];

        return response()->json(['data' => $this->runs->preview($def, $params, $this->user(), $p['page'] ?? 1, 50)]);
    }

    /** The statement as a file (a PDF to print or Excel). */
    public function export(Request $request, string $key)
    {
        $def = $this->definition($key);
        $p = $request->validate(['format' => ['required', Rule::in(ReportRunService::FORMATS)], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $lang = $p['lang'] ?? 'ar';
        $doc = $this->runs->document($def, ['date_from' => $p['from'] ?? null, 'date_to' => $p['to'] ?? null], $this->user(), $lang);
        $bytes = app(ReportExporter::class)->render($doc, $p['format'], $lang);

        return response($bytes, 200, ['Content-Type' => ReportExporter::MIME[$p['format']], 'Content-Disposition' => "attachment; filename=\"{$key}.{$p['format']}\""]);
    }

    private function definition(string $key): ReportDefinition
    {
        $this->builtIn->ensure();

        return ReportDefinition::where(['is_system' => true, 'category' => 'trainee', 'key' => $key])->firstOrFail();
    }
}
