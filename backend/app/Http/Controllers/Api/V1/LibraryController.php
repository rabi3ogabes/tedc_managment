<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\EligibilityRule;
use App\Models\KitFile;
use App\Models\LibraryCollection;
use App\Models\LibraryItem;
use App\Models\LibraryShelf;
use App\Models\Material;
use App\Models\ResourceShare;
use App\Services\FileStorage;
use App\Services\Library\ExternalLibraryService;
use App\Services\Library\LibraryService;
use App\Services\Library\SharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The digital library (browse, read, shelf, review for everyone; catalogue management and rights for library managers) and resource sharing. */
class LibraryController extends Controller
{
    public function __construct(private readonly LibraryService $library, private readonly ExternalLibraryService $external, private readonly SharingService $sharing, private readonly FileStorage $storage) {}

    // ── readers

    public function index(Request $request): JsonResponse
    {
        $items = $this->library->search($this->user(), $request->query());
        $collections = LibraryCollection::where('is_featured', true)->orderBy('sort_order')->get()->map(fn ($c) => ['id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en]);

        return response()->json(['data' => $items->map(fn ($i) => $this->library->present($i, $this->user()))->values(), 'collections' => $collections]);
    }

    public function show(LibraryItem $item): JsonResponse
    {
        abort_unless($this->library->canView($item, $this->user()), 404);
        $reviews = DB::table('library_reviews')->where('item_id', $item->id)->whereNotNull('review')->latest()->limit(20)->get(['stars', 'review', 'created_at']);

        return response()->json(['data' => $this->library->present($item, $this->user()) + ['review_list' => $reviews]]);
    }

    public function read(LibraryItem $item): JsonResponse
    {
        return response()->json(['data' => $this->library->open($item, $this->user())]);
    }

    public function download(LibraryItem $item): JsonResponse
    {
        return response()->json(['data' => $this->library->download($item, $this->user())]);
    }

    public function review(Request $request, LibraryItem $item): JsonResponse
    {
        $d = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'review' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->library->review($item, $this->user(), $d['stars'], $d['review'] ?? null)]);
    }

    public function shelf(Request $request, LibraryItem $item): JsonResponse
    {
        $d = $request->validate(['on' => ['required', 'boolean'], 'progress' => ['nullable', 'numeric', 'between:0,100'], 'position' => ['nullable', 'string', 'max:60']]);
        $this->library->shelf($item, $this->user(), (bool) $d['on'], isset($d['progress']) ? (float) $d['progress'] : null, $d['position'] ?? null);

        return response()->json(['data' => ['on_shelf' => (bool) $d['on']]]);
    }

    public function myShelf(): JsonResponse
    {
        $rows = LibraryShelf::where('user_id', $this->user()->id)->with('item')->latest()->get()->filter(fn ($s) => $s->item && $this->library->canView($s->item, $this->user()));

        return response()->json(['data' => $rows->map(fn ($s) => $this->library->present($s->item, $this->user()) + ['progress' => (float) $s->progress, 'position' => $s->position])->values()]);
    }

    // ── managers

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->library->save($this->rules($request), $this->user())], 201);
    }

    public function update(Request $request, LibraryItem $item): JsonResponse
    {
        return response()->json(['data' => $this->library->save($this->rules($request, true), $this->user(), $item)]);
    }

    public function destroy(LibraryItem $item): JsonResponse
    {
        $item->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** The file (stored privately) and the cover (public). */
    public function upload(Request $request, LibraryItem $item): JsonResponse
    {
        $d = $request->validate(['file' => ['nullable', 'file', 'max:102400', 'mimes:pdf,epub,mp3,mp4,m4a,zip,doc,docx,ppt,pptx'], 'cover' => ['nullable', 'image', 'max:5120']]);
        if ($f = $request->file('file')) {
            $item->update(['file_path' => $this->storage->upload($f, 'materials', 'library/'.$item->id), 'file_mime' => $f->getMimeType()]);
        }
        if ($c = $request->file('cover')) {
            $item->update(['cover_path' => $this->storage->uploadPublic($c, 'library')]);
        }

        return response()->json(['data' => $item->fresh()]);
    }

    public function collections(): JsonResponse
    {
        return response()->json(['data' => LibraryCollection::with('items:id,title_ar,title_en')->orderBy('sort_order')->get()->all()]);
    }

    public function saveCollection(Request $request, ?LibraryCollection $collection = null): JsonResponse
    {
        $d = $request->validate(['name_ar' => [$collection ? 'sometimes' : 'required', 'string', 'max:200'], 'name_en' => [$collection ? 'sometimes' : 'required', 'string', 'max:200'], 'is_featured' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer'], 'item_ids' => ['sometimes', 'array', 'max:500'], 'item_ids.*' => ['uuid', 'exists:library_items,id']]);
        $row = $collection ? tap($collection)->update(collect($d)->except('item_ids')->all()) : LibraryCollection::create(collect($d)->except('item_ids')->all());
        if (isset($d['item_ids'])) {
            $row->items()->sync(collect($d['item_ids'])->values()->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());
        }

        return response()->json(['data' => $row->load('items:id,title_ar,title_en')], $collection ? 200 : 201);
    }

    public function deleteCollection(LibraryCollection $collection): JsonResponse
    {
        $collection->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ── external libraries

    public function externalSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']])['q']);

        return response()->json(['data' => $this->external->search($q)]);
    }

    public function externalImport(Request $request): JsonResponse
    {
        $d = $request->validate(['library' => ['required', Rule::in(ExternalLibraryService::LIBRARIES)], 'external_id' => ['nullable', 'string', 'max:200'], 'title' => ['required', 'string', 'max:300'], 'authors' => ['nullable', 'array'], 'year' => ['nullable', 'integer'], 'type' => ['nullable', 'string', 'max:12'], 'url' => ['required', 'url', 'max:500']]);

        return response()->json(['data' => $this->external->import($d, $this->user())], 201);
    }

    public function externalSettings(Request $request): JsonResponse
    {
        if ($request->isMethod('PUT')) {
            $d = $request->validate(['maktabati' => ['array'], 'qnl' => ['array'], 'maktabati.enabled' => ['boolean'], 'qnl.enabled' => ['boolean'], 'maktabati.driver' => [Rule::in(['link', 'fake'])], 'qnl.driver' => [Rule::in(['link', 'fake'])], 'maktabati.search_url' => ['nullable', 'string', 'max:500'], 'qnl.search_url' => ['nullable', 'string', 'max:500'],
                'maktabati.api_url' => ['nullable', 'url', 'max:500'], 'qnl.api_url' => ['nullable', 'url', 'max:500'], 'maktabati.api_key' => ['nullable', 'string', 'max:300'], 'qnl.api_key' => ['nullable', 'string', 'max:300']]);

            return response()->json(['data' => $this->external->update($d, $this->user())]);
        }

        return response()->json(['data' => $this->external->masked()]);
    }

    // ── sharing

    public function share(Request $request): JsonResponse
    {
        $d = $request->validate(['resource_type' => ['required', Rule::in(SharingService::TYPES)], 'resource_id' => ['required', 'uuid'], 'target_type' => ['required', Rule::in(SharingService::TARGETS)], 'target_id' => ['required', 'string', 'max:64'], 'permission' => ['required', Rule::in(['view', 'download', 'reshare'])], 'expires_at' => ['nullable', 'date', 'after:now']]);

        return response()->json(['data' => $this->sharing->share($this->user(), $d['resource_type'], $d['resource_id'], $d['target_type'], $d['target_id'], $d['permission'], isset($d['expires_at']) ? new \DateTimeImmutable($d['expires_at']) : null)], 201);
    }

    public function shares(Request $request): JsonResponse
    {
        $d = $request->validate(['resource_type' => ['required', Rule::in(SharingService::TYPES)], 'resource_id' => ['required', 'uuid']]);

        return response()->json(['data' => ResourceShare::where('resource_type', $d['resource_type'])->where('resource_id', $d['resource_id'])->latest()->get()->all(), 'policy' => $this->sharing->policyFor($this->user())]);
    }

    public function unshare(ResourceShare $share): JsonResponse
    {
        abort_unless($share->shared_by === $this->user()->id || $this->user()->hasPermission('sharing.manage'), 403);
        $share->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** "Shared with me": the resources others shared with the person, with what they may do. */
    public function sharedWithMe(): JsonResponse
    {
        $policy = $this->sharing->policyFor($this->user());
        $rows = $this->sharing->visibleShares($this->user())->map(function ($s) use ($policy) {
            $meta = match ($s->resource_type) {
                'material' => Material::find($s->resource_id)?->only(['title_ar', 'title_en']),
                'kit_file' => ($f = KitFile::find($s->resource_id)) ? ['title_ar' => $f->name, 'title_en' => $f->name] : null,
                'library_item' => LibraryItem::find($s->resource_id)?->only(['title_ar', 'title_en']),
                'lesson' => CourseLessonMeta::of($s->resource_id),
                default => null,
            };
            $viewOnly = $this->sharing->isViewOnly($s->resource_type, $s->resource_id, $policy) || $s->permission === 'view';

            return $meta ? ['id' => $s->id, 'resource_type' => $s->resource_type, 'resource_id' => $s->resource_id, 'permission' => $viewOnly ? 'view' : $s->permission, 'view_only' => $viewOnly, 'watermark' => true, 'expires_at' => $s->expires_at?->toIso8601String()] + $meta : null;
        })->filter()->values();

        return response()->json(['data' => $rows]);
    }

    private function rules(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate(['type' => [$r, Rule::in(['book', 'journal', 'periodical', 'audio', 'video', 'elearning', 'kit', 'link'])], 'title_ar' => [$r, 'string', 'max:300'], 'title_en' => ['nullable', 'string', 'max:300'], 'description_ar' => ['nullable', 'string', 'max:5000'], 'description_en' => ['nullable', 'string', 'max:5000'],
            'authors' => ['nullable', 'array', 'max:20'], 'authors.*' => ['string', 'max:200'], 'publisher' => ['nullable', 'string', 'max:200'], 'isbn' => ['nullable', 'string', 'max:30'], 'year' => ['nullable', 'integer', 'between:1800,2100'], 'language' => ['sometimes', Rule::in(['ar', 'en'])],
            'subjects' => ['nullable', 'array', 'max:30'], 'subjects.*' => ['string', 'max:100'], 'skill_ids' => ['nullable', 'array'], 'url' => ['nullable', 'url', 'max:500'], 'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'rights' => ['nullable', 'array'], 'rights.owner' => ['nullable', 'string', 'max:200'], 'rights.licence' => ['nullable', 'string', 'max:200'], 'rights.download' => ['boolean'], 'rights.print' => ['boolean'], 'rights.watermark' => ['boolean'], 'rights.embargo_from' => ['nullable', 'date'], 'rights.embargo_until' => ['nullable', 'date'],
            'audience' => ['nullable', 'array', 'max:20'], 'audience.*.field' => ['required', 'string', Rule::in(EligibilityRule::FIELDS)], 'audience.*.operator' => ['required', 'string', 'max:20'], 'audience.*.value' => ['nullable'], 'audience.*.is_mandatory' => ['boolean']]);
    }
}

/** Titles of lessons for the "shared with me" list. */
final class CourseLessonMeta
{
    /** @return array{title_ar: string, title_en: string}|null */
    public static function of(string $id): ?array
    {
        $l = CourseLesson::find($id);

        return $l ? ['title_ar' => $l->title_ar, 'title_en' => $l->title_en] : null;
    }
}
