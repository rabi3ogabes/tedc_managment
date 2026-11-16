<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRsvp;
use App\Services\Communication\AnnouncementLifecycle;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Events and announcements for the signed-in person: the live ones (pinned first), events with their own registration status, and RSVP. */
class MyEventsController extends Controller
{
    public function __construct(private readonly AnnouncementLifecycle $lifecycle) {}

    public function events(Request $request): JsonResponse
    {
        $user = $this->user();
        $mine = AnnouncementRsvp::where('user_id', $user->id)->pluck('status', 'announcement_id');
        $rows = $this->lifecycle->live(Announcement::query())->whereIn('type', ['event', 'activity'])->orderByDesc('is_pinned')->latest('published_at')->limit(60)->get()
            ->map(fn (Announcement $a) => $this->card($a) + ['my_rsvp' => $mine[$a->id] ?? null, 'going' => AnnouncementRsvp::where('announcement_id', $a->id)->where('status', 'going')->count()]);

        return response()->json(['data' => $rows->values()]);
    }

    /** Live announcements and news, pinned ones first (the home screens of the portal and the app). */
    public function announcements(): JsonResponse
    {
        $rows = $this->lifecycle->live(Announcement::query())->whereIn('type', ['news', 'announcement', 'circular'])->orderByDesc('is_pinned')->orderBy('pin_order')->latest('published_at')->limit(30)->get()
            ->map(fn (Announcement $a) => $this->card($a) + ['media' => collect(['images', 'video', 'audio', 'links'])->mapWithKeys(fn ($k) => [$k => collect($a->media[$k] ?? [])->map(fn ($m) => ['title' => $m['title'] ?? null, 'url' => $m['url'] ?? FileStorage::publicUrl($m['path'] ?? null)])->filter(fn ($m) => $m['url'])->values()->all()]), 'body' => $a->translate('body')]);

        return response()->json(['data' => $rows->values()]);
    }

    public function rsvp(Request $request, Announcement $announcement): JsonResponse
    {
        $going = $request->validate(['going' => ['required', 'boolean']])['going'];

        return response()->json(['data' => $this->lifecycle->rsvp($announcement, $this->user(), $going)]);
    }

    private function card(Announcement $a): array
    {
        $e = $a->event ?? [];

        return ['id' => $a->id, 'type' => $a->type, 'title' => $a->translate('title'), 'excerpt' => mb_substr(strip_tags((string) $a->translate('body')), 0, 200), 'is_pinned' => (bool) $a->is_pinned,
            'cover_url' => FileStorage::publicUrl($a->cover_path), 'published_at' => $a->published_at?->toIso8601String(),
            'event' => $a->event ? ['starts_at' => $e['starts_at'] ?? null, 'ends_at' => $e['ends_at'] ?? null, 'venue' => $e[app()->getLocale() === 'ar' ? 'venue_ar' : 'venue_en'] ?? null, 'online_url' => $e['online_url'] ?? null,
                'registration_url' => $e['registration_url'] ?? null, 'rsvp' => (bool) ($e['rsvp'] ?? false), 'capacity' => $e['capacity'] ?? null] : null];
    }
}
