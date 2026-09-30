<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\KitFileResource;
use App\Models\KitFile;
use App\Models\KitFileVersion;
use App\Models\TrainingKit;
use App\Services\FileStorage;
use App\Services\Kits\DeckAnalyzer;
use App\Services\Kits\DeckModel;
use App\Services\Kits\KitAccess;
use App\Services\Kits\KitFiles;
use App\Services\Kits\KitLog;
use App\Services\Kits\SvgSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Files of a kit: upload, metadata, the editable deck, versions, presence and automatic checks. */
class KitFileController extends KitBaseController
{
    public function __construct(private readonly KitFiles $files, private readonly FileStorage $storage) {}

    public function index(TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $files = $kit->files()->with(['uploader', 'updater'])->withCount(['comments as open_comments' => fn ($c) => $c->whereNull('parent_id')->where('status', '!=', 'resolved')])->get();

        return response()->json(['data' => KitFileResource::collection($files)]);
    }

    public function show(TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);

        return response()->json(['data' => new KitFileResource($file->load(['uploader', 'updater']))]);
    }

    public function store(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->manageable($kit);
        $max = (int) config('tedc.kits.max_upload_mb');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.($max * 1024)],
            'category' => ['nullable', Rule::in(KitFile::CATEGORIES)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        abort_unless(in_array($extension, ['pptx', 'ppt', 'pdf', 'docx', 'doc', 'odt', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mov', 'xlsx', 'zip'], true), 422, 'Unsupported file type.');

        $file = $this->files->upload($kit, $request->file('file'), $this->user(), $data['category'] ?? null, $data['name'] ?? null);
        if ($file->mime === 'image/svg+xml' || $extension === 'svg') {
            $clean = SvgSanitizer::clean($this->storage->get('documents', $file->storage_path));
            $this->storage->put('documents', $file->storage_path, $clean, 'image/svg+xml');
        }

        return response()->json(['data' => new KitFileResource($file->load(['uploader', 'updater']))], 201);
    }

    /** Creates a blank deck or one from a title + template. */
    public function create(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->editable($kit);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'language' => ['nullable', Rule::in(['ar', 'en'])], 'category' => ['nullable', Rule::in(KitFile::CATEGORIES)]]);
        $deck = DeckModel::blank($data['language'] ?? 'ar');
        $deck['slides'][] = DeckModel::slide('blank', [], ['color' => '#FFFFFF']);
        $file = $this->files->createDeck($kit, $this->user(), $data['name'], $deck, 'created', $data['category'] ?? null);

        return response()->json(['data' => new KitFileResource($file->load(['uploader', 'updater']))], 201);
    }

    public function update(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->manageable($kit);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'], 'category' => ['sometimes', Rule::in(KitFile::CATEGORIES)],
            'notes' => ['nullable', 'string', 'max:2000'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $file->update($data + ['updated_by' => $this->user()->id]);

        return response()->json(['data' => new KitFileResource($file->refresh()->load(['uploader', 'updater']))]);
    }

    public function destroy(TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->manageable($kit);
        KitLog::record($kit, $this->user(), 'file_deleted', 'file', $file->id, ['name' => $file->name]);
        $file->delete();

        return response()->json(null, 204);
    }

    /** Short-lived link to the stored binary (Word, PDF, image, video or the uploaded PPTX). */
    public function download(TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);
        abort_unless($file->storage_path, 404);

        return response()->json(['data' => ['url' => $this->storage->temporaryUrl('documents', $file->storage_path, 1800), 'name' => $file->original_name ?? $file->name, 'mime' => $file->mime]]);
    }

    // Deck -------------------------------------------------------------------------------------

    public function deck(TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);
        abort_unless($file->kind === 'presentation', 404);

        return response()->json(['data' => [
            'file' => new KitFileResource($file->load(['uploader', 'updater'])),
            'deck' => $file->content ? DeckModel::decorate($file->content, $this->storage) : null,
            'revision' => $file->revision,
            'presence' => $this->files->presence($file),
            'can_edit' => KitAccess::canEditContent($this->user(), $kit),
        ]]);
    }

    public function saveDeck(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->editable($kit);
        abort_unless($file->content !== null, 422, 'This file has no editable deck yet.');
        $changes = $request->validate([
            'slides' => ['sometimes', 'array', 'max:'.DeckModel::MAX_SLIDES], 'deleted' => ['sometimes', 'array', 'max:'.DeckModel::MAX_SLIDES],
            'order' => ['sometimes', 'array', 'max:'.DeckModel::MAX_SLIDES], 'theme' => ['sometimes', 'array'], 'force' => ['sometimes', 'boolean'],
        ]);
        $result = $this->files->saveDeck($file, $changes, $this->user(), (bool) ($changes['force'] ?? false));

        return response()->json(['data' => $result + ['presence' => $this->files->presence($file)]], $result['conflicts'] ? 409 : 200);
    }

    /** Attaches the deck the browser extracted from an uploaded PPTX so it becomes editable. */
    public function importDeck(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->editable($kit);
        $data = $request->validate(['deck' => ['required', 'array'], 'deck.slides' => ['required', 'array', 'min:1', 'max:'.DeckModel::MAX_SLIDES]]);
        $file = $this->files->attachDeck($file, $data['deck'], $this->user());

        return response()->json(['data' => ['file' => new KitFileResource($file), 'deck' => DeckModel::decorate($file->content, $this->storage), 'revision' => $file->revision]]);
    }

    /** Stores the PPTX the browser exported from the deck as a new downloadable version. */
    public function exportDeck(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);
        $request->validate(['file' => ['required', 'file', 'max:'.((int) config('tedc.kits.max_upload_mb') * 1024)]]);
        $file = $this->files->replaceBinary($file, $request->file('file'), $this->user(), 'export', 'Exported from the editor');

        return response()->json(['data' => new KitFileResource($file->load(['uploader', 'updater']))]);
    }

    // Versions ---------------------------------------------------------------------------------

    public function versions(TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);

        return response()->json(['data' => $file->versions()->with('author')->limit(100)->get()->map(fn (KitFileVersion $v) => [
            'id' => $v->id, 'version' => $v->version, 'source' => $v->source, 'note' => $v->note, 'size' => $v->size, 'slides' => $v->snapshot ? count($v->snapshot['slides'] ?? []) : null,
            'has_binary' => $v->storage_path !== null, 'author' => $v->author?->displayName(), 'created_at' => $v->created_at?->toIso8601String(),
        ]), 'current' => $file->version]);
    }

    public function snapshot(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->editable($kit);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $version = $this->files->snapshot($file, $this->user(), 'manual', $data['note'] ?? null);
        KitLog::record($kit, $this->user(), 'version_saved', 'file', $file->id, ['version' => $version->version, 'name' => $file->name]);

        return $this->versions($kit, $file->refresh());
    }

    public function showVersion(TrainingKit $kit, KitFile $file, KitFileVersion $version): JsonResponse
    {
        $this->viewable($kit);
        abort_unless($version->file_id === $file->id, 404);

        return response()->json(['data' => [
            'version' => $version->version, 'deck' => $version->snapshot ? DeckModel::decorate($version->snapshot, $this->storage) : null,
            'url' => $version->storage_path ? $this->storage->temporaryUrl('documents', $version->storage_path, 1800) : null,
        ]]);
    }

    public function restore(TrainingKit $kit, KitFile $file, KitFileVersion $version): JsonResponse
    {
        $this->editable($kit);
        abort_unless($version->file_id === $file->id, 404);
        $file = $this->files->restore($file, $version, $this->user());

        return response()->json(['data' => ['file' => new KitFileResource($file), 'deck' => $file->content ? DeckModel::decorate($file->content, $this->storage) : null, 'revision' => $file->revision]]);
    }

    // Presence & checks ------------------------------------------------------------------------

    public function heartbeat(Request $request, TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);
        $data = $request->validate(['slide_id' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => ['presence' => $this->files->heartbeat($file, $this->user(), $data['slide_id'] ?? null), 'revision' => $file->fresh()->revision]]);
    }

    public function leave(TrainingKit $kit, KitFile $file): JsonResponse
    {
        $this->viewable($kit);
        $this->files->leave($file, $this->user());

        return response()->json(null, 204);
    }

    public function analyze(Request $request, TrainingKit $kit, KitFile $file, DeckAnalyzer $analyzer): JsonResponse
    {
        $this->viewable($kit);
        if ($file->content === null) {
            throw new BusinessRuleException(__('messages.kit.not_a_presentation'), 'kit_invalid_file');
        }

        return response()->json(['data' => $analyzer->analyze($file, $kit, $request->boolean('ai'))]);
    }
}
