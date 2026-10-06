<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageVersion;
use App\Models\PublicStat;
use App\Services\Cms\CmsService;
use App\Services\Cms\PublicStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The page editor (working copy, publish, versions, restore) and the public statistics the homepage shows. */
class CmsController extends Controller
{
    public function __construct(private readonly CmsService $cms, private readonly PublicStatsService $stats) {}

    public function blocks(string $page): JsonResponse
    {
        $this->assertPage($page);

        return response()->json(['data' => $this->cms->draft($page), 'meta' => ['types' => CmsService::TYPES, 'published_version' => PageVersion::where('page', $page)->max('version')]]);
    }

    public function saveBlocks(Request $request, string $page): JsonResponse
    {
        $this->assertPage($page);
        $d = $request->validate([
            'blocks' => ['required', 'array', 'max:60'],
            'blocks.*.id' => ['nullable', 'uuid'], 'blocks.*.type' => ['required', Rule::in(CmsService::TYPES)], 'blocks.*.config' => ['nullable', 'array'],
            'blocks.*.is_visible' => ['sometimes', 'boolean'], 'blocks.*.audience' => ['sometimes', Rule::in(['public', 'signed_in'])],
            'blocks.*.starts_at' => ['nullable', 'date'], 'blocks.*.ends_at' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->cms->saveDraft($page, $d['blocks'], $this->user())]);
    }

    public function publish(Request $request, string $page): JsonResponse
    {
        $this->assertPage($page);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:200']])['note'] ?? null;
        $v = $this->cms->publish($page, $note, $this->user());

        return response()->json(['data' => $v->only(['id', 'page', 'version', 'note', 'created_at'])], 201);
    }

    public function versions(string $page): JsonResponse
    {
        $this->assertPage($page);

        return response()->json(['data' => PageVersion::with('publisher:id,name,name_ar')->where('page', $page)->orderByDesc('version')->limit(50)->get(['id', 'page', 'version', 'note', 'published_by', 'created_at'])]);
    }

    public function rollback(string $page, int $version): JsonResponse
    {
        $this->assertPage($page);

        return response()->json(['data' => $this->cms->rollback($page, $version, $this->user())->only(['id', 'page', 'version', 'note', 'created_at'])], 201);
    }

    public function preview(string $page): JsonResponse
    {
        $this->assertPage($page);

        return response()->json(['data' => $this->cms->previewDraft($page)]);
    }

    public function stats(): JsonResponse
    {
        $this->stats->ensure();

        return response()->json(['data' => PublicStat::orderBy('sort_order')->get()->map(fn ($s) => $s->toArray() + ['computed' => $this->stats->compute($s)]), 'meta' => ['sources' => PublicStatsService::SOURCES, 'query_keys' => PublicStatsService::QUERY_KEYS]]);
    }

    public function saveStats(Request $request): JsonResponse
    {
        $d = $request->validate([
            'stats' => ['required', 'array', 'max:12'],
            'stats.*.key' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/'], 'stats.*.label_ar' => ['required', 'string', 'max:120'], 'stats.*.label_en' => ['required', 'string', 'max:120'],
            'stats.*.source' => ['required', Rule::in(array_keys(PublicStatsService::SOURCES))],
            'stats.*.value' => ['nullable', 'string', 'max:40'], 'stats.*.icon' => ['nullable', 'string', 'max:32'], 'stats.*.is_visible' => ['sometimes', 'boolean'],
        ]);
        $keys = [];
        foreach (array_values($d['stats']) as $i => $s) {
            if ($s['source'] === 'custom_query_key' && ! in_array($s['value'] ?? '', PublicStatsService::QUERY_KEYS, true)) {
                abort(422, 'Choose one of the named indicators.');
            }
            PublicStat::updateOrCreate(['key' => $s['key']], $s + ['sort_order' => $i, 'is_visible' => $s['is_visible'] ?? true]);
            $keys[] = $s['key'];
        }
        PublicStat::whereNotIn('key', $keys)->delete();
        $this->stats->refresh();

        return $this->stats();
    }

    private function assertPage(string $page): void
    {
        abort_unless(in_array($page, CmsService::PAGES, true), 404);
    }
}
