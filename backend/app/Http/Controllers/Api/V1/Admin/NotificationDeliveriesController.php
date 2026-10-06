<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\DeliveryReport;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Delivery tracking: who got what, by which channel, and what happened — with Excel and PDF exports. */
class NotificationDeliveriesController extends Controller
{
    public function __construct(private readonly DeliveryReport $report, private readonly ReportExporter $exporter) {}

    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->report->paginate($f, $this->user(), $this->perPage($request)), 'summary' => $this->report->summary($f, $this->user())]);
    }

    public function export(Request $request): Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['xlsx', 'pdf'])]])['format'];
        $lang = $request->query('lang') === 'en' ? 'en' : 'ar';
        $doc = $this->report->document($this->filters($request), $this->user(), $lang);

        return response($this->exporter->render($doc, $format, $lang), 200, ['Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => "attachment; filename=\"notification-deliveries.{$format}\""]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'campaign_id' => ['nullable', 'uuid'], 'type' => ['nullable', 'string', 'max:64'], 'channel' => ['nullable', Rule::in(DeliveryReport::CHANNELS)],
            'status' => ['nullable', Rule::in(DeliveryReport::STATUSES)], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
