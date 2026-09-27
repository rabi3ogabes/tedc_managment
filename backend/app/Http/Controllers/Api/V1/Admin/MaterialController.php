<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Program;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function __construct(private readonly FileStorage $storage) {}

    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => $program->materials()->latest()->get()]);
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['file', 'video', 'link', 'document', 'presentation'])],
            'program_session_id' => ['nullable', 'uuid', Rule::exists('program_sessions', 'id')->where('program_id', $program->id)],
            'visibility' => ['sometimes', Rule::in(['participants', 'trainers', 'public'])],
            'url' => ['required_if:type,link,video', 'nullable', 'url', 'max:500'],
            'file' => ['required_unless:type,link,video', 'nullable', 'file', 'max:102400', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,png,jpg,jpeg,mp4,zip'],
        ]);

        $attributes = collect($data)->except('file')->all() + ['uploaded_by' => $this->user()->id];

        if ($file = $request->file('file')) {
            $attributes += [
                'storage_path' => $this->storage->upload($file, 'materials', $program->id),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        }

        return response()->json(['data' => $program->materials()->create($attributes)], 201);
    }

    public function destroy(Material $material): JsonResponse
    {
        if ($material->storage_path) {
            $this->storage->delete('materials', $material->storage_path);
        }
        $material->delete();

        return response()->json(null, 204);
    }
}
