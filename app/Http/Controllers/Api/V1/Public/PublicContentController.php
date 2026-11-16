<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\Cms\CmsService;
use App\Services\Cms\PublicStatsService;
use App\Services\Communication\AnnouncementLifecycle;
use App\Services\Communication\FeedBuilder;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** Public events and calendar, the news/events feeds for the Ministry website, published pages and the centre's own statistics. */
class PublicContentController extends Controller
{
    public function __construct(private readonly AnnouncementLifecycle $lifecycle, private readonly FeedBuilder $feeds) {}

    public function events(Request $request): JsonResponse
    {
        $past = $request->boolean('past');
        $rows = $this->lifecycle->live(Announcement::query())->where('is_public', true)->whereIn('type', ['event', 'activity'])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('is_pinned')->orderBy('pin_order')->latest('published_at')->get()
            ->filter(function (Announcement $a) use ($past) {
                $starts = ! empty($a->event['starts_at']) ? Carbon::parse($a->event['starts_at']) : null;

                return $starts === null || ($past ? $starts->isPast() : $starts->isFuture() || $starts->isToday());
            })->sortBy(fn (Announcement $a) => $a->event['starts_at'] ?? '9999')->values();

        return response()->json(['data' => $rows->map(fn ($a) => $this->card($a))->all()]);
    }

    public function event(string $id): JsonResponse
    {
        $a = $this->lifecycle->live(Announcement::query())->where('is_public', true)->whereIn('type', ['event', 'activity'])->findOrFail($id);

        return response()->json(['data' => $this->card($a) + [
            'body' => $a->translate('body'),
            'media' => collect(['images', 'video', 'audio', 'links'])->mapWithKeys(fn ($k) => [$k => collect($a->media[$k] ?? [])->map(fn ($m) => ['title' => $m['title'] ?? null, 'url' => $m['url'] ?? FileStorage::publicUrl($m['path'] ?? null)])->filter(fn ($m) => $m['url'])->values()->all()]),
            'ics_url' => url('/api/v1/public/events/'.$a->id.'/event.ics'),
        ]]);
    }

    public function eventIcs(string $id): Response
    {
        $a = $this->lifecycle->live(Announcement::query())->where('is_public', true)->whereIn('type', ['event', 'activity'])->findOrFail($id);

        return $this->ics([$a], 'event');
    }

    /** The whole public calendar as one ICS file to subscribe to. */
    public function calendarIcs(): Response
    {
        $rows = $this->lifecycle->live(Announcement::query())->where('is_public', true)->whereIn('type', ['event', 'activity'])->get()->filter(fn ($a) => ! empty($a->event['starts_at']));

        return $this->ics($rows->all(), 'calendar');
    }

    public function feed(string $name): Response|JsonResponse
    {
        $lang = request()->query('lang') === 'en' ? 'en' : 'ar';
        $kind = str_starts_with($name, 'events') || str_contains($name, 'events') ? 'events' : 'news';

        return match (true) {
            str_ends_with($name, '.json') => response()->json($this->feeds->json($kind)),
            str_ends_with($name, '.rss') || $name === 'rss.xml' || str_ends_with($name, 'rss.xml') => response($this->feeds->rss($kind, $lang), 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']),
            str_ends_with($name, 'atom.xml') => response($this->feeds->atom($kind, $lang), 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']),
            str_ends_with($name, '.csv') => response($this->feeds->csv($kind), 200, ['Content-Type' => 'text/csv; charset=UTF-8']),
            default => abort(404),
        };
    }

    public function page(string $page, Request $request, CmsService $cms): JsonResponse
    {
        abort_unless(in_array($page, CmsService::PAGES, true), 404);
        // A signed-in visitor also sees blocks meant for signed-in people; the token is optional here.
        $viewer = $request->bearerToken() ? auth('api')->user() : null;

        return response()->json(['data' => $cms->publicPage($page, $viewer)]);
    }

    public function stats(PublicStatsService $stats): JsonResponse
    {
        return response()->json(['data' => $stats->visible()]);
    }

    private function card(Announcement $a): array
    {
        $e = $a->event ?? [];

        return [
            'id' => $a->id, 'type' => $a->type, 'title' => $a->translate('title'), 'excerpt' => mb_substr(strip_tags((string) $a->translate('body')), 0, 200),
            'cover_url' => FileStorage::publicUrl($a->cover_path), 'is_pinned' => (bool) $a->is_pinned,
            'event' => ['starts_at' => $e['starts_at'] ?? null, 'ends_at' => $e['ends_at'] ?? null, 'venue' => $e[app()->getLocale() === 'ar' ? 'venue_ar' : 'venue_en'] ?? null,
                'online_url' => $e['online_url'] ?? null, 'registration_url' => $e['registration_url'] ?? null, 'rsvp' => (bool) ($e['rsvp'] ?? false), 'capacity' => $e['capacity'] ?? null],
        ];
    }

    private function ics(array $events, string $name): Response
    {
        $esc = fn (?string $s) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $s);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TEDC//Events//EN', 'CALSCALE:GREGORIAN'];
        foreach ($events as $a) {
            $e = $a->event ?? [];
            if (empty($e['starts_at'])) {
                continue;
            }
            $start = Carbon::parse($e['starts_at'])->utc();
            $end = ! empty($e['ends_at']) ? Carbon::parse($e['ends_at'])->utc() : $start->copy()->addHour();
            $lines = array_merge($lines, [
                'BEGIN:VEVENT', 'UID:'.$a->id.'@tedc', 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'), 'DTSTART:'.$start->format('Ymd\THis\Z'), 'DTEND:'.$end->format('Ymd\THis\Z'),
                'SUMMARY:'.$esc($a->title_en ?: $a->title_ar), 'DESCRIPTION:'.$esc(mb_substr(strip_tags((string) ($a->body_en ?: $a->body_ar)), 0, 400)),
                'LOCATION:'.$esc($e['venue_en'] ?? $e['venue_ar'] ?? ($e['online_url'] ?? '')), 'END:VEVENT',
            ]);
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=UTF-8', 'Content-Disposition' => "inline; filename=\"{$name}.ics\""]);
    }
}
