<?php

namespace App\Services\Communication;

use App\Exceptions\BusinessRuleException;
use App\Models\Announcement;
use App\Models\AnnouncementRsvp;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * draft → scheduled → published (inside starts_at..ends_at) → expired → archived. Several announcements are live at once; pinned ones come first.
 * Events and activities are announcements with a date, a place and, optionally, a seat list (RSVP).
 */
class AnnouncementLifecycle
{
    public function __construct(private readonly CommunicationService $communication, private readonly NotificationService $notifications, private readonly MinistryExporter $ministry) {}

    /** Publishes now, or schedules for starts_at when that is still ahead. @return int people notified (0 when only scheduled) */
    public function publish(Announcement $a): int
    {
        if ($a->status === 'archived') {
            throw new BusinessRuleException('Archived announcements are republished, not published.', 'announcement_archived');
        }
        if ($a->starts_at && $a->starts_at->isFuture()) {
            $a->update(['status' => 'scheduled']);

            return 0;
        }
        if ($a->ends_at && $a->ends_at->isPast()) {
            throw new BusinessRuleException('The display window has already ended.', 'window_ended');
        }
        $count = $this->communication->publish($a);
        if ($a->export_to_ministry) {
            $this->ministry->queue($a);
        }

        return $count;
    }

    /** Moves announcements along when their window opens or closes, and reminds people of events. Run every few minutes. @return array{published: int, expired: int, reminded: int} */
    public function tick(): array
    {
        $out = ['published' => 0, 'expired' => 0, 'reminded' => 0];
        Announcement::where('status', 'scheduled')->where('starts_at', '<=', now())->get()->each(function (Announcement $a) use (&$out) {
            $this->communication->publish($a);
            if ($a->export_to_ministry) {
                $this->ministry->queue($a);
            }
            $out['published']++;
        });
        $out['expired'] = Announcement::where('status', 'published')->whereNotNull('ends_at')->where('ends_at', '<=', now())->update(['status' => 'expired', 'is_pinned' => false]);
        $out['reminded'] = $this->remindEvents();

        return $out;
    }

    /** What the public and the signed-in people see right now. */
    public function live(?Builder $q = null): Builder
    {
        return ($q ?? Announcement::query())->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function pin(Announcement $a, bool $pinned, ?int $order = null): Announcement
    {
        $a->update(['is_pinned' => $pinned, 'pin_order' => $pinned ? ($order ?? ((int) Announcement::where('is_pinned', true)->max('pin_order') + 1)) : 0]);

        return $a;
    }

    /** @param  list<string>  $ids  pinned announcements in the order they should appear */
    public function reorderPins(array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            Announcement::whereKey($id)->update(['is_pinned' => true, 'pin_order' => $i + 1]);
        }
    }

    public function archive(Announcement $a): Announcement
    {
        $a->update(['status' => 'archived', 'archived_at' => now(), 'is_pinned' => false, 'pin_order' => 0]);

        return $a;
    }

    public function unarchive(Announcement $a): Announcement
    {
        $stillOpen = ! $a->ends_at || $a->ends_at->isFuture();
        $a->update(['status' => $a->published_at ? ($stillOpen ? 'published' : 'expired') : 'draft', 'archived_at' => null]);

        return $a;
    }

    /** A copy of an earlier announcement as a new draft (same wording and media, new window), published when asked. */
    public function republish(Announcement $old, ?Carbon $startsAt, ?Carbon $endsAt, ?User $by, bool $publish = true): Announcement
    {
        $new = Announcement::create($old->only([
            'type', 'title_ar', 'title_en', 'body_ar', 'body_en', 'audience', 'target_ids', 'attachments', 'cover_path', 'is_public', 'media', 'audience_filter',
            'notify_push', 'notify_email', 'export_to_ministry',
        ]) + [
            'event' => $old->event ? array_diff_key($old->event, array_flip(['reminded_at'])) : null,
            'status' => 'draft', 'published_at' => null, 'starts_at' => $startsAt ?? now(), 'ends_at' => $endsAt, 'is_pinned' => false, 'pin_order' => 0,
            'republished_from_id' => $old->id, 'created_by' => $by?->id ?? $old->created_by,
        ]);
        if ($publish) {
            $this->publish($new);
        }

        return $new->refresh();
    }

    /** Words in the title or body, a type, or a date range; the archive and the board both use it. @param  array{q?: ?string, type?: ?string, status?: ?string, from?: ?string, to?: ?string}  $f */
    public function search(array $f): Builder
    {
        return Announcement::query()
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when(filled($f['q'] ?? null), function ($q) use ($f) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $f['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(title_ar) like ?', [$like])->orWhereRaw('lower(title_en) like ?', [$like])
                    ->orWhereRaw('lower(body_ar) like ?', [$like])->orWhereRaw('lower(body_en) like ?', [$like]));
            });
    }

    // ---- events -----------------------------------------------------------------------------

    /** @return array{status: string, going: int, capacity: ?int} */
    public function rsvp(Announcement $a, User $user, bool $going): array
    {
        $event = $a->event ?? [];
        if (! in_array($a->type, ['event', 'activity'], true) || empty($event['rsvp'])) {
            throw new BusinessRuleException('This event does not take registrations here.', 'rsvp_closed');
        }
        if ($a->status !== 'published') {
            throw new BusinessRuleException('This event is not open.', 'rsvp_closed');
        }
        $capacity = isset($event['capacity']) && $event['capacity'] !== '' ? (int) $event['capacity'] : null;

        $status = DB::transaction(function () use ($a, $user, $going, $capacity) {
            Announcement::whereKey($a->id)->lockForUpdate()->first();   // seats are counted one registration at a time
            $row = AnnouncementRsvp::firstOrNew(['announcement_id' => $a->id, 'user_id' => $user->id]);
            if (! $going) {
                $row->status = 'cancelled';
                $row->save();
                $this->promoteWaiting($a, $capacity);

                return 'cancelled';
            }
            if ($row->exists && $row->status === 'going') {
                return 'going';
            }
            $taken = AnnouncementRsvp::where('announcement_id', $a->id)->where('status', 'going')->count();
            $row->status = $capacity !== null && $taken >= $capacity ? 'waitlisted' : 'going';
            $row->save();

            return $row->status;
        });

        if ($status !== 'cancelled') {
            $this->notifications->send($user, 'event.rsvp_confirmed',
                ['ar' => $status === 'going' ? 'تم تأكيد تسجيلك' : 'أنت في قائمة الانتظار', 'en' => $status === 'going' ? 'Your registration is confirmed' : 'You are on the waiting list'],
                ['ar' => $a->title_ar, 'en' => $a->title_en], ['announcement_id' => $a->id, 'route' => '/events/'.$a->id], raw: true);
        }

        return ['status' => $status, 'going' => AnnouncementRsvp::where('announcement_id', $a->id)->where('status', 'going')->count(), 'capacity' => $capacity];
    }

    private function promoteWaiting(Announcement $a, ?int $capacity): void
    {
        if ($capacity === null) {
            return;
        }
        $free = $capacity - AnnouncementRsvp::where('announcement_id', $a->id)->where('status', 'going')->count();
        if ($free <= 0) {
            return;
        }
        AnnouncementRsvp::where('announcement_id', $a->id)->where('status', 'waitlisted')->orderBy('created_at')->limit($free)->get()->each(function (AnnouncementRsvp $r) use ($a) {
            $r->update(['status' => 'going']);
            $this->notifications->send($r->user_id, 'event.rsvp_confirmed', ['ar' => 'تم تأكيد تسجيلك', 'en' => 'Your registration is confirmed'], ['ar' => $a->title_ar, 'en' => $a->title_en], ['announcement_id' => $a->id, 'route' => '/events/'.$a->id], raw: true);
        });
    }

    /** "X hours before" reminders to the people who registered (24 h unless the event says otherwise), once per event. */
    private function remindEvents(): int
    {
        $sent = 0;
        Announcement::whereIn('type', ['event', 'activity'])->where('status', 'published')->get()->each(function (Announcement $a) use (&$sent) {
            $event = $a->event ?? [];
            $starts = ! empty($event['starts_at']) ? Carbon::parse($event['starts_at']) : null;
            if (! $starts || $starts->isPast() || ! empty($event['reminded_at'])) {
                return;
            }
            $hours = (int) ($event['reminder_hours'] ?? 24);
            if ($hours <= 0 || $starts->gt(now()->addHours($hours))) {
                return;
            }
            $ids = AnnouncementRsvp::where('announcement_id', $a->id)->where('status', 'going')->pluck('user_id');
            $sent += $this->notifications->broadcast($ids, 'event.reminder',
                ['ar' => 'تذكير بالفعالية: '.$a->title_ar, 'en' => 'Event reminder: '.$a->title_en],
                ['ar' => 'تبدأ '.$starts->copy()->locale('ar')->translatedFormat('j F Y H:i'), 'en' => 'Starts '.$starts->copy()->locale('en')->translatedFormat('j F Y H:i')],
                ['announcement_id' => $a->id, 'route' => '/events/'.$a->id], raw: true);
            $a->update(['event' => $event + ['reminded_at' => now()->toIso8601String()]]);
        });

        return $sent;
    }
}
