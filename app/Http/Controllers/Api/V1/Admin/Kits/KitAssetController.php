<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Models\KitAsset;
use App\Models\TrainingKit;
use App\Services\FileStorage;
use App\Services\Kits\DeckModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Pictures used inside a kit: uploaded, generated or extracted from imported decks. */
class KitAssetController extends KitBaseController
{
    public function __construct(private readonly FileStorage $storage) {}

    public function index(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $kind = in_array($request->query('kind'), ['image', 'video', 'audio'], true) ? $request->query('kind') : 'image';
        $assets = $kit->assets()->when($request->query('source'), fn ($q, $s) => $q->where('source', $s))
            ->where('mime', 'like', $kind.'/%')->latest()->limit(60)->get();

        return response()->json(['data' => $assets->map(fn (KitAsset $a) => $a->present($this->storage))->values()]);
    }

    /** A signed address to send a big video or sound straight to storage (the API cannot take large bodies on serverless hosting). */
    public function uploadUrl(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->editable($kit);
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'], 'mime' => ['required', 'string', 'max:120', 'regex:/^(video|audio)\//'],
            'size' => ['required', 'integer', 'min:1', 'max:'.((int) config('tedc.kits.max_upload_mb') * 1024 * 1024)],
        ]);
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION))) ?: 'bin';
        $path = "kits/{$kit->id}/assets/".Str::uuid().'.'.$extension;

        return response()->json(['data' => ['path' => $path] + $this->storage->signedUpload('materials', $path, $data['mime'])]);
    }

    /** Puts a file the kit already holds (e.g. a video made in the Video Studio) in the media library so a slide can use it. */
    public function fromFile(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->editable($kit);
        $file = $kit->files()->findOrFail($request->validate(['file_id' => ['required', 'uuid']])['file_id']);
        abort_unless(in_array($file->kind, ['image', 'video'], true) && $file->storage_path, 422);

        $asset = KitAsset::firstOrCreate(['kit_id' => $kit->id, 'storage_path' => $file->storage_path], [
            'name' => $file->name, 'mime' => $file->mime ?: ($file->kind === 'video' ? 'video/webm' : 'image/png'), 'size' => $file->size ?? 0, 'source' => 'generated', 'meta' => ['bucket' => 'documents'], 'created_by' => $this->user()->id,
        ]);

        return response()->json(['data' => $asset->present($this->storage)], 201);
    }

    /** Registers a video or sound that was sent to storage with the address above. */
    public function complete(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->editable($kit);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:300'], 'name' => ['required', 'string', 'max:255'], 'mime' => ['required', 'string', 'max:120', 'regex:/^(video|audio)\//'],
            'size' => ['required', 'integer', 'min:1'], 'duration' => ['nullable', 'integer', 'min:0', 'max:86400'], 'width' => ['nullable', 'integer', 'min:1', 'max:8000'], 'height' => ['nullable', 'integer', 'min:1', 'max:8000'],
        ]);
        // Only a file this kit was given a signed address for can be registered.
        abort_unless(str_starts_with($data['path'], "kits/{$kit->id}/assets/") && ! str_contains($data['path'], '..'), 422);

        $asset = KitAsset::create([
            'kit_id' => $kit->id, 'name' => $data['name'], 'mime' => $data['mime'], 'size' => $data['size'], 'storage_path' => $data['path'], 'source' => 'upload',
            'width' => $data['width'] ?? null, 'height' => $data['height'] ?? null, 'meta' => ['bucket' => 'materials', 'duration' => $data['duration'] ?? null], 'created_by' => $this->user()->id,
        ]);

        return response()->json(['data' => $asset->present($this->storage)], 201);
    }

    public function store(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->editable($kit);
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp,svg', 'max:8192']]);
        $upload = $request->file('image');
        $bytes = (string) file_get_contents($upload->getRealPath());
        $mime = $upload->getMimeType() ?: 'image/png';
        $mime = str_contains($mime, 'svg') || $upload->getClientOriginalExtension() === 'svg' ? 'image/svg+xml' : $mime;

        $asset = DeckModel::storeDataUri('data:'.$mime.';base64,'.base64_encode($bytes), $kit, $this->user(), $this->storage, null, 'upload');
        abort_unless($asset, 422, 'Unsupported image.');
        $asset->update(['name' => $upload->getClientOriginalName()]);

        return response()->json(['data' => $asset->refresh()->present($this->storage)], 201);
    }
}
