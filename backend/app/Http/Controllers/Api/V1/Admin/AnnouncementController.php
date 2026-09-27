<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\CommunicationService;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Communication Center.
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Announcement::with('author:id,name,name_ar')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->latest()->paginate($this->perPage($request)));
    }

    public function store(Request $request, CommunicationService $communication): JsonResponse
    {
        $announcement = Announcement::create($this->validated($request) + ['created_by' => $this->user()->id]);

        $recipients = $request->boolean('publish') ? $communication->publish($announcement) : 0;

        return response()->json(['data' => $announcement, 'meta' => ['recipients' => $recipients]], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($this->validated($request, true));

        return response()->json(['data' => $announcement]);
    }

    public function publish(Announcement $announcement, CommunicationService $communication): JsonResponse
    {
        return response()->json(['data' => $announcement->refresh(), 'meta' => ['recipients' => $communication->publish($announcement)]]);
    }

    public function attach(Request $request, Announcement $announcement, FileStorage $storage): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,png,jpg,jpeg,mp4'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $attachments = $announcement->attachments ?? [];
        $attachments[] = [
            'type' => str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'file',
            'title' => $request->input('title') ?? $file->getClientOriginalName(),
            'path' => $storage->upload($file, 'documents', 'announcements/'.$announcement->id),
            'mime' => $file->getMimeType(),
        ];
        $announcement->update(['attachments' => $attachments]);

        return response()->json(['data' => $announcement]);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => ['sometimes', Rule::in(['news', 'announcement', 'circular'])],
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'body_ar' => ['nullable', 'string'],
            'body_en' => ['nullable', 'string'],
            'audience' => ['sometimes', Rule::in(Announcement::AUDIENCES)],
            'target_ids' => ['nullable', 'array'],
            'target_ids.*' => ['string', 'max:64'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.type' => ['required', Rule::in(['link', 'video', 'file'])],
            'attachments.*.title' => ['required', 'string', 'max:255'],
            'attachments.*.url' => ['nullable', 'url', 'max:500'],
            'is_public' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);
    }
}
