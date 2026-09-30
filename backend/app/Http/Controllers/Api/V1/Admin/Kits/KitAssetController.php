<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Models\KitAsset;
use App\Models\TrainingKit;
use App\Services\FileStorage;
use App\Services\Kits\DeckModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pictures used inside a kit: uploaded, generated or extracted from imported decks. */
class KitAssetController extends KitBaseController
{
    public function __construct(private readonly FileStorage $storage) {}

    public function index(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $assets = $kit->assets()->when($request->query('source'), fn ($q, $s) => $q->where('source', $s))->latest()->limit(60)->get();
        $ttl = (int) config('tedc.kits.asset_url_ttl');

        return response()->json(['data' => $assets->map(fn (KitAsset $a) => [
            'id' => $a->id, 'name' => $a->name, 'mime' => $a->mime, 'width' => $a->width, 'height' => $a->height, 'prompt' => $a->prompt, 'source' => $a->source,
            'provider' => $a->meta['provider'] ?? null, 'url' => $this->storage->temporaryUrl('documents', $a->storage_path, $ttl),
        ])]);
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

        return response()->json(['data' => [
            'id' => $asset->id, 'name' => $asset->name, 'mime' => $asset->mime, 'width' => $asset->width, 'height' => $asset->height, 'source' => 'upload',
            'url' => $this->storage->temporaryUrl('documents', $asset->storage_path, (int) config('tedc.kits.asset_url_ttl')),
        ]], 201);
    }
}
