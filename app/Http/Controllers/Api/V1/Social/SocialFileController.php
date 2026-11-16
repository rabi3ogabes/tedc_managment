<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Models\SocialUpload;
use App\Models\Space;
use App\Social\SocialFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Uploading files for posts and comments (small ones through the API, big ones straight to storage) and opening them. */
class SocialFileController extends Controller
{
    public function __construct(private readonly SocialFiles $files) {}

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['space_id' => ['required', 'uuid'], 'file' => ['required', 'file']]);
        $upload = $this->files->receive($this->user(), Space::findOrFail($d['space_id']), $request->file('file'));

        return response()->json(['data' => $this->row($upload)], 201);
    }

    public function sign(Request $request): JsonResponse
    {
        $d = $request->validate(['space_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'mime' => ['required', 'string', 'max:120'], 'size' => ['required', 'integer', 'min:1']]);
        $r = $this->files->sign($this->user(), Space::findOrFail($d['space_id']), $d['name'], $d['mime'], (int) $d['size']);

        return response()->json(['data' => ['id' => $r['upload']->id, 'upload' => $r['target']]], 201);
    }

    public function complete(SocialUpload $upload): JsonResponse
    {
        return response()->json(['data' => $this->row($this->files->complete($this->user(), $upload))]);
    }

    public function destroy(SocialUpload $upload): JsonResponse
    {
        $this->files->discard($this->user(), $upload);

        return response()->json(['message' => 'ok']);
    }

    public function show(SocialUpload $upload): JsonResponse
    {
        return response()->json(['data' => $this->files->download($this->user(), $upload)]);
    }

    /** @return array<string, mixed> */
    private function row(SocialUpload $u): array
    {
        $shown = $this->files->present([[['type' => 'file', 'upload_id' => $u->id]]])[0][0] ?? [];

        return ['id' => $u->id, 'name' => $u->name, 'mime' => $u->mime, 'size' => $u->size, 'width' => $u->width, 'height' => $u->height, 'status' => $u->status,
            'kind' => $shown['kind'] ?? null, 'thumb_url' => $shown['thumb_url'] ?? null];
    }
}
