<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRsvp;
use App\Models\MinistryExport;
use App\Services\Communication\AnnouncementLifecycle;
use App\Services\Communication\MinistryExporter;
use App\Services\FileStorage;
use App\Services\Notifications\AudienceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Communication Center: announcements, news, circulars, activities and events — with a display window, pinning, an archive, republishing,
 * media (images, video, audio, files, links), audience filters and export to the Ministry website.
 */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementLifecycle $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['type' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = $this->lifecycle->search($f)->with('author:id,name,name_ar')->withCount(['rsvps as going_count' => fn ($r) => $r->where('status', 'going')]);
        // The board hides the archive unless asked for.
        if (empty($f['status'])) {
            $q->where('status', '!=', 'archived');
        }

        return response()->json($q->orderByDesc('is_pinned')->orderBy('pin_order')->orderByDesc('created_at')->paginate($this->perPage($request)));
    }

    /** The archive: everything archived or expired, searchable by words, type and date. */
    public function archive(Request $request): JsonResponse
    {
        $f = $request->validate(['type' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json($this->lifecycle->search($f)->whereIn('status', ['archived', 'expired'])->with('author:id,name,name_ar')->orderByDesc('updated_at')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $announcement = Announcement::create($this->validated($request) + ['created_by' => $this->user()->id, 'status' => 'draft']);
        $recipients = 0;
        if ($request->boolean('publish')) {
            $this->requirePublishRight();
            $recipients = $this->lifecycle->publish($announcement);
        }

        return response()->json(['data' => $announcement->refresh(), 'meta' => ['recipients' => $recipients]], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($this->validated($request, true));

        return response()->json(['data' => $announcement]);
    }

    public function publish(Announcement $announcement): JsonResponse
    {
        $this->requirePublishRight();

        return response()->json(['data' => $announcement->refresh(), 'meta' => ['recipients' => $this->lifecycle->publish($announcement)]]);
    }

    public function pin(Request $request, Announcement $announcement): JsonResponse
    {
        $d = $request->validate(['pinned' => ['required', 'boolean'], 'order' => ['nullable', 'integer', 'min:0']]);

        return response()->json(['data' => $this->lifecycle->pin($announcement, $d['pinned'], $d['order'] ?? null)]);
    }

    public function reorderPins(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['uuid']]);
        $this->lifecycle->reorderPins($d['ids']);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function archiveOne(Announcement $announcement): JsonResponse
    {
        return response()->json(['data' => $this->lifecycle->archive($announcement)]);
    }

    public function unarchive(Announcement $announcement): JsonResponse
    {
        return response()->json(['data' => $this->lifecycle->unarchive($announcement)]);
    }

    public function republish(Request $request, Announcement $announcement): JsonResponse
    {
        $d = $request->validate(['starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'publish' => ['sometimes', 'boolean']]);
        if (($d['publish'] ?? true)) {
            $this->requirePublishRight();
        }
        $copy = $this->lifecycle->republish($announcement, isset($d['starts_at']) ? Carbon::parse($d['starts_at']) : null, isset($d['ends_at']) ? Carbon::parse($d['ends_at']) : null, $this->user(), $d['publish'] ?? true);

        return response()->json(['data' => $copy], 201);
    }

    /** Adds an image, video, audio clip or file (uploaded) or a link (video/audio/link by address) to the announcement. */
    public function media(Request $request, Announcement $announcement, FileStorage $storage): JsonResponse
    {
        $d = $request->validate([
            'kind' => ['required', Rule::in(['images', 'video', 'audio', 'files', 'links'])],
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'file' => ['nullable', 'file', 'max:51200', 'mimes:png,jpg,jpeg,webp,gif,mp3,m4a,wav,ogg,mp4,webm,pdf,doc,docx,ppt,pptx,xls,xlsx'],
        ]);
        if (! $request->hasFile('file') && empty($d['url'])) {
            throw new BusinessRuleException('Send a file or an address.', 'media_required');
        }
        $media = $announcement->media ?? [];
        $item = ['title' => $d['title'] ?? $request->file('file')?->getClientOriginalName() ?? $d['url']];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $mime = (string) $file->getMimeType();
            $expect = ['images' => 'image/', 'video' => 'video/', 'audio' => 'audio/'][$d['kind']] ?? null;
            if ($expect && ! str_starts_with($mime, $expect)) {
                throw new BusinessRuleException('The file does not match the chosen kind.', 'media_mismatch');
            }
            // Pictures, audio and video are shown on public pages, so they go to the public bucket; other files stay private.
            $item['path'] = in_array($d['kind'], ['images', 'video', 'audio'], true) ? $storage->uploadPublic($file, 'announcements/'.$announcement->id) : $storage->upload($file, 'documents', 'announcements/'.$announcement->id);
            $item['mime'] = $mime;
        } else {
            $item['url'] = $d['url'];
        }
        $media[$d['kind']] = array_values(array_merge($media[$d['kind']] ?? [], [$item]));
        $announcement->update(['media' => $media]);

        return response()->json(['data' => $announcement]);
    }

    public function removeMedia(Request $request, Announcement $announcement): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(['images', 'video', 'audio', 'files', 'links'])], 'index' => ['required', 'integer', 'min:0']]);
        $media = $announcement->media ?? [];
        unset($media[$d['kind']][$d['index']]);
        $media[$d['kind']] = array_values($media[$d['kind']] ?? []);
        $announcement->update(['media' => $media]);

        return response()->json(['data' => $announcement]);
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

    public function rsvps(Announcement $announcement): JsonResponse
    {
        $rows = AnnouncementRsvp::with('user.employee.school')->where('announcement_id', $announcement->id)->orderBy('created_at')->get()->map(fn ($r) => [
            'id' => $r->id, 'status' => $r->status, 'at' => $r->created_at?->toIso8601String(), 'name' => $r->user?->displayName(), 'email' => $r->user?->email, 'school' => $r->user?->employee?->school?->translate('name'),
        ]);

        return response()->json(['data' => $rows, 'meta' => ['going' => $rows->where('status', 'going')->count(), 'waitlisted' => $rows->where('status', 'waitlisted')->count(), 'capacity' => $announcement->event['capacity'] ?? null]]);
    }

    /** Queues the item for the Ministry website now (the same push the automatic export uses). */
    public function exportMinistry(Announcement $announcement, MinistryExporter $exporter): JsonResponse
    {
        if ($announcement->status !== 'published') {
            throw new BusinessRuleException('Only published items can be exported.', 'not_published');
        }
        $exporter->enqueue($announcement);
        $exporter->run();

        return response()->json(['data' => $announcement->refresh(), 'meta' => ['export' => MinistryExport::where('announcement_id', $announcement->id)->first()]]);
    }

    private function requirePublishRight(): void
    {
        if (! $this->user()->hasPermission('announcements.publish') && ! $this->user()->hasPermission('announcements.manage')) {
            abort(403);
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(Announcement::TYPES)],
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'body_ar' => ['nullable', 'string'],
            'body_en' => ['nullable', 'string'],
            'audience' => ['sometimes', Rule::in(Announcement::AUDIENCES)],
            'target_ids' => ['nullable', 'array'],
            'target_ids.*' => ['string', 'max:64'],
            'audience_filter' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.type' => ['required', Rule::in(['link', 'video', 'file'])],
            'attachments.*.title' => ['required', 'string', 'max:255'],
            'attachments.*.url' => ['nullable', 'url', 'max:500'],
            'is_public' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'notify_push' => ['sometimes', 'boolean'], 'notify_email' => ['sometimes', 'boolean'],
            'export_to_ministry' => ['sometimes', 'boolean'],
            'event' => ['nullable', 'array'],
            'event.starts_at' => ['nullable', 'date'], 'event.ends_at' => ['nullable', 'date'],
            'event.venue_ar' => ['nullable', 'string', 'max:255'], 'event.venue_en' => ['nullable', 'string', 'max:255'],
            'event.online_url' => ['nullable', 'url:http,https', 'max:500'], 'event.registration_url' => ['nullable', 'url:http,https', 'max:500'],
            'event.program_id' => ['nullable', 'uuid', 'exists:programs,id'],
            'event.capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'event.rsvp' => ['nullable', 'boolean'], 'event.reminder_hours' => ['nullable', 'integer', 'between:0,336'],
        ]);
        // A sender only reaches people inside their own scope; the audience filter is checked again when sending.
        if (($data['audience'] ?? null) === 'filter' && app(AudienceResolver::class)->isEmpty($data['audience_filter'] ?? [])) {
            throw new BusinessRuleException('Choose who should receive it.', 'audience_required');
        }

        return $data;
    }
}
