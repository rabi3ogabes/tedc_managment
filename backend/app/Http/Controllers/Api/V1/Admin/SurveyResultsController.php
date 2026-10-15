<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\TrainingNeed;
use App\Services\Reports\ReportExporter;
use App\Services\Surveys\SurveyAnalytics;
use App\Services\Surveys\SurveyPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Survey Studio v2, Gate 6: results, individual answers, comparison, exports, needs generation and the overview. */
class SurveyResultsController extends Controller
{
    public function __construct(private readonly SurveyAnalytics $analytics) {}

    public function results(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate($this->filterRules() + ['version' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $this->analytics->results($studioSurvey, $d, isset($d['version']) ? (int) $d['version'] : null, $this->canIdentify(), app()->getLocale())]);
    }

    public function answers(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate($this->filterRules() + ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);

        return response()->json(['data' => $this->analytics->answers($studioSurvey, $d, (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 25), $this->canIdentify())]);
    }

    public function export(Request $request, Survey $studioSurvey): Response
    {
        $d = $request->validate($this->filterRules() + ['format' => ['sometimes', Rule::in(['xlsx', 'pdf', 'docx', 'csv'])], 'lang' => ['sometimes', Rule::in(['ar', 'en'])]]);
        $format = $d['format'] ?? 'xlsx';
        $lang = $d['lang'] ?? 'ar';
        $bytes = $this->analytics->export($studioSurvey, $format, $lang, $d, $this->canIdentify(), $this->user());

        return response($bytes, 200, ['Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => 'attachment; filename="survey-results.'.$format.'"']);
    }

    public function compare(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:2', 'max:6'], 'ids.*' => ['uuid', 'exists:surveys,id']]);
        $surveys = Survey::whereIn('id', $d['ids'])->get()->sortBy(fn ($s) => array_search($s->id, $d['ids'], true))->values();

        return response()->json(['data' => $this->analytics->compare($surveys, app()->getLocale())]);
    }

    public function generateNeeds(Request $request, Survey $studioSurvey): JsonResponse
    {
        $d = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:60'], 'items.*.key' => ['required', 'string', 'max:255'], 'items.*.priority' => ['nullable', Rule::in(TrainingNeed::PRIORITIES)],
            'items.*.program_id' => ['nullable', 'uuid', 'exists:programs,id'], 'split' => ['nullable', Rule::in(['overall', 'school'])], 'min_index' => ['nullable', 'integer', 'min:0', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['created' => $this->analytics->generateNeeds($studioSurvey, $d, $this->user(), app()->getLocale())]);
    }

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->analytics->overview(app()->getLocale())]);
    }

    private function canIdentify(): bool
    {
        return $this->user()->hasPermission('surveys.results.identified');
    }

    private function filterRules(): array
    {
        $rules = ['source' => ['nullable', 'string', 'max:16']];
        foreach (SurveyPrivacy::SEGMENT_FIELDS as $f) {
            $rules[$f] = ['nullable', 'string', 'max:120'];
        }

        return $rules;
    }
}
