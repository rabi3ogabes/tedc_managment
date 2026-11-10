<?php

namespace App\Http\Controllers\Api\V1\Help;

use App\Help\HelpService;
use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\Role;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The article editor for the people who write the manuals: roles, pages, screenshots, versions and feedback analytics. */
class HelpAdminController extends Controller
{
    public function __construct(private readonly HelpService $help) {}

    public function index(Request $request): JsonResponse
    {
        $q = HelpArticle::query()->orderBy('module')->orderBy('sort_order');
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('q')) {
            $like = '%'.mb_strtolower((string) $request->query('q')).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(title_ar) like ?', [$like])->orWhereRaw('lower(title_en) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like]));
        }

        return response()->json(['data' => $q->get()->map(fn ($a) => $this->help->present($a, false))->all(), 'roles' => Role::whereIn('slug', HelpService::MANUAL_ROLES)->get(['slug', 'name_ar', 'name_en'])]);
    }

    public function show(HelpArticle $article): JsonResponse
    {
        return response()->json(['data' => $this->help->present($article) + ['versions' => $article->versions()->orderByDesc('version')->get(['version', 'created_at'])->all()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $a = $this->help->save($this->rules($request), null, $this->user());

        return response()->json(['data' => $this->help->present($a)], 201);
    }

    public function update(Request $request, HelpArticle $article): JsonResponse
    {
        return response()->json(['data' => $this->help->present($this->help->save($this->rules($request, $article), $article, $this->user()))]);
    }

    public function destroy(HelpArticle $article): JsonResponse
    {
        $article->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function rollback(HelpArticle $article, int $version): JsonResponse
    {
        return response()->json(['data' => $this->help->present($this->help->rollback($article, $version, $this->user()))]);
    }

    /** Adds a screenshot to an article. */
    public function screenshot(Request $request, HelpArticle $article, FileStorage $storage): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:jpg,jpeg,png,webp', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'caption_ar' => ['nullable', 'string', 'max:300'], 'caption_en' => ['nullable', 'string', 'max:300']]);
        $path = $storage->uploadPublic($request->file('file'), 'help/screenshots');
        $shots = $article->screenshots ?? [];
        $shots[] = ['path' => $path, 'caption_ar' => $request->input('caption_ar'), 'caption_en' => $request->input('caption_en')];
        $article->update(['screenshots' => $shots, 'updated_by' => $this->user()->id]);

        return response()->json(['data' => $this->help->present($article->refresh())], 201);
    }

    public function removeScreenshot(HelpArticle $article, int $index): JsonResponse
    {
        $shots = $article->screenshots ?? [];
        unset($shots[$index]);
        $article->update(['screenshots' => array_values($shots)]);

        return response()->json(['data' => $this->help->present($article->refresh())]);
    }

    public function video(Request $request, HelpArticle $article, FileStorage $storage): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:mp4,webm', 'mimes:mp4,webm', 'max:51200']]);
        $path = $storage->uploadPublic($request->file('file'), 'help/videos');
        $article->update(['video_asset' => $path, 'updated_by' => $this->user()->id, 'version' => $article->version + 1]);

        return response()->json(['data' => $this->help->present($article->refresh())], 201);
    }

    public function analytics(): JsonResponse
    {
        return response()->json(['data' => $this->help->analytics()]);
    }

    private function rules(Request $request, ?HelpArticle $article = null): array
    {
        return $request->validate([
            'slug' => [$article ? 'sometimes' : 'required', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('help_articles', 'slug')->ignore($article?->id)],
            'title_ar' => [$article ? 'sometimes' : 'required', 'string', 'max:255'], 'title_en' => [$article ? 'sometimes' : 'required', 'string', 'max:255'],
            'body_ar' => ['nullable', 'string', 'max:60000'], 'body_en' => ['nullable', 'string', 'max:60000'],
            'roles' => ['nullable', 'array'], 'roles.*' => ['string', Rule::exists('roles', 'slug')],
            'module' => ['nullable', 'string', 'max:40'], 'related_routes' => ['nullable', 'array', 'max:20'], 'related_routes.*' => ['string', 'max:200'],
            'video_url' => ['nullable', 'url', 'max:500'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'], 'status' => ['nullable', Rule::in(['draft', 'published'])],
        ]);
    }
}
