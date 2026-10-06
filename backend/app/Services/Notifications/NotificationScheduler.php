<?php

namespace App\Services\Notifications;

use App\Models\NotificationCampaign;
use App\Models\ScheduledNotification;
use App\Services\Channels\NotificationChannels;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Throwable;

/** Sends the notifications an administrator scheduled — once or repeating — when they fall due. */
class NotificationScheduler
{
    public function __construct(private readonly NotificationService $notifications, private readonly NotificationChannels $channels, private readonly AudienceResolver $audiences) {}

    /** @return int how many scheduled notifications were sent now */
    public function runDue(): int
    {
        $done = 0;
        ScheduledNotification::where('status', 'scheduled')->where('send_at', '<=', now())->orderBy('send_at')->limit(50)->get()->each(function (ScheduledNotification $s) use (&$done) {
            // Claim it first so two overlapping runs never send twice.
            if (ScheduledNotification::where('id', $s->id)->where('status', 'scheduled')->update(['status' => 'sending']) !== 1) {
                return;
            }
            try {
                $this->send($s);
                $done++;
            } catch (Throwable $e) {
                $s->update(['status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
        });

        return $done;
    }

    public function send(ScheduledNotification $s): int
    {
        $sender = $s->creator;
        $ids = $this->audiences->resolve($s->audience, $sender);
        $campaign = NotificationCampaign::create([
            'event' => 'announcement', 'kind' => 'scheduled', 'audience' => 'custom', 'title_ar' => $s->title_ar, 'title_en' => $s->title_en,
            'body_ar' => $s->body_ar, 'body_en' => $s->body_en, 'created_by' => $s->created_by,
        ]);
        $this->channels->choose($s->channels ?: ['push']);
        try {
            $count = $this->notifications->broadcast(
                $ids, 'announcement', ['ar' => $s->title_ar, 'en' => $s->title_en], ['ar' => $s->body_ar, 'en' => $s->body_en],
                ['route' => '/notifications', 'campaign_id' => $campaign->id], $campaign->id, force: true, raw: true,
            );
        } finally {
            $this->channels->choose(null);
        }
        $campaign->update(['recipients' => $count]);

        $next = $this->nextRun($s);
        $s->update([
            'status' => $next ? 'scheduled' : 'sent', 'send_at' => $next ?? $s->send_at, 'runs' => $s->runs + 1, 'campaign_id' => $campaign->id, 'last_error' => null,
        ]);

        return $count;
    }

    private function nextRun(ScheduledNotification $s): ?Carbon
    {
        $next = match ($s->repeat) {
            'daily' => $s->send_at->copy()->addDay(),
            'weekly' => $s->send_at->copy()->addWeek(),
            'monthly' => $s->send_at->copy()->addMonthNoOverflow(),
            default => null,
        };
        // A run that was late never fires a backlog: it jumps to the next future slot.
        while ($next && $next->lte(now())) {
            $next = match ($s->repeat) {
                'daily' => $next->addDay(), 'weekly' => $next->addWeek(), default => $next->addMonthNoOverflow(),
            };
        }

        return $next && (! $s->repeat_until || $next->toDateString() <= $s->repeat_until->toDateString()) ? $next : null;
    }
}
