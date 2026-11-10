<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MinistryExport;
use App\Services\Communication\FeedBuilder;
use App\Services\Communication\MinistryExporter;
use App\Services\Communication\MinistrySiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Settings → Ministry website: where news and events are pushed, the feed addresses, the export log and manual export files. */
class MinistrySiteController extends Controller
{
    public function __construct(private readonly MinistrySiteSettings $settings, private readonly FeedBuilder $feeds) {}

    public function show(): JsonResponse
    {
        $base = url('/api/v1/public/feeds');

        return response()->json(['data' => $this->settings->masked() + [
            'feeds' => ['news_json' => "$base/news.json", 'events_json' => "$base/events.json", 'news_rss' => "$base/news-rss.xml", 'events_rss' => "$base/events-rss.xml", 'news_atom' => "$base/news-atom.xml", 'events_atom' => "$base/events-atom.xml"],
            'log' => MinistryExport::with('announcementRow:id,title_ar,title_en')->latest('updated_at')->limit(20)->get(),
            'counts' => ['queued' => MinistryExport::where('status', 'queued')->count(), 'failed' => MinistryExport::where('status', 'failed')->count(), 'sent' => MinistryExport::where('status', 'sent')->count()],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'auto_export' => ['sometimes', 'boolean'], 'endpoint' => ['nullable', 'url:https', 'max:500'],
            'auth_header' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{1,60}$/'], 'site_name' => ['nullable', 'string', 'max:100'], 'api_key' => ['nullable', 'string', 'max:300'], 'clear_api_key' => ['sometimes', 'boolean'],
        ]);
        $this->settings->update($d, $this->user());

        return $this->show();
    }

    /** Sends everything waiting right now, and retries failed items. */
    public function run(MinistryExporter $exporter): JsonResponse
    {
        MinistryExport::where('status', 'failed')->update(['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now()]);

        return response()->json(['data' => $exporter->run()]);
    }

    /** A file to upload by hand when the site has no endpoint. */
    public function file(Request $request): Response|JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(['news', 'events'])], 'format' => ['required', Rule::in(['json', 'csv', 'rss', 'atom'])], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $lang = $d['lang'] ?? 'ar';

        return match ($d['format']) {
            'json' => response($json = json_encode($this->feeds->json($d['kind']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => "attachment; filename=\"{$d['kind']}.json\""]),
            'csv' => response($this->feeds->csv($d['kind']), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$d['kind']}.csv\""]),
            'rss' => response($this->feeds->rss($d['kind'], $lang), 200, ['Content-Type' => 'application/rss+xml', 'Content-Disposition' => "attachment; filename=\"{$d['kind']}-rss.xml\""]),
            'atom' => response($this->feeds->atom($d['kind'], $lang), 200, ['Content-Type' => 'application/atom+xml', 'Content-Disposition' => "attachment; filename=\"{$d['kind']}-atom.xml\""]),
        };
    }
}
