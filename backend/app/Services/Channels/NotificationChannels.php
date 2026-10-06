<?php

namespace App\Services\Channels;

use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Push\PushDispatcher;
use App\Services\Push\PushSettings;
use App\Services\ThemeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The delivery side of notifications: which channels (push, e-mail, SMS) a notification uses, and the e-mail / SMS
 * queue. Push keeps its own dispatcher; e-mail and SMS are queued here and sent within moments (a handful at once while
 * the administrator waits, large groups by the minute scheduler) and every outcome — sent, failed or skipped with the
 * reason — is recorded so the administrator can see exactly what happened.
 */
class NotificationChannels
{
    public const ALL = ['push', 'email', 'sms'];

    /** Up to this many messages are sent right away; more wait for the scheduler. */
    private const INLINE = 5;

    /** The channels chosen for the task being performed now (null = no choice made: defaults apply). @var list<string>|null */
    private ?array $chosen = null;

    public function __construct(private readonly ChannelSettings $settings, private readonly PushSettings $push, private readonly EmailSender $email, private readonly SmsGateway $sms) {}

    /** Called by the request middleware when the administrator picked channels for this task. @param  list<string>|null  $channels */
    public function choose(?array $channels): void
    {
        $this->chosen = $channels === null ? null : array_values(array_intersect(self::ALL, $channels));
    }

    /**
     * Channels for one notification. A choice made for the task wins over the event's own settings; either way a channel
     * whose master switch is off is never used.
     *
     * @param  array{push?: bool, email?: bool, sms?: bool}  $event  the event's own flags
     * @return list<string>
     */
    public function resolve(array $event = []): array
    {
        $wanted = $this->chosen ?? array_values(array_filter(self::ALL, fn (string $c) => ($event[$c] ?? true) && $this->settings->defaultOn($c)));

        return array_values(array_filter($wanted, fn (string $c) => $this->masterOn($c)));
    }

    private function masterOn(string $channel): bool
    {
        return $channel === 'push' ? true : $this->settings->enabled($channel);
    }

    /** What each channel can do right now, for the screens that offer the choice. @return array<string, array{on: bool, ready: bool}> */
    public function status(): array
    {
        return [
            'push' => ['on' => $this->settings->defaultOn('push'), 'ready' => $this->push->isReady()],
            'email' => ['on' => $this->settings->enabled('email') && $this->settings->defaultOn('email'), 'ready' => $this->settings->ready('email')],
            'sms' => ['on' => $this->settings->enabled('sms') && $this->settings->defaultOn('sms'), 'ready' => $this->settings->ready('sms')],
        ];
    }

    /**
     * Queues the e-mail / SMS copies of notifications that were just created, and the push messages the rules postponed.
     *
     * @param  array<string, string>  $notificationIds  user id => notification id
     * @param  list<string>  $channels  from resolve()
     * @param  array<string, array{channels: list<string>, defer: array<string, Carbon>}>  $plan  per person, from DeliveryPolicy (empty = everyone gets $channels at once)
     */
    public function enqueue(array $notificationIds, string $type, array $channels, array $plan = [], ?string $campaignId = null): int
    {
        if ($notificationIds === []) {
            return 0;
        }
        $now = now();
        $rows = [];
        $immediate = 0;
        foreach ($notificationIds as $userId => $notificationId) {
            $mine = isset($plan[$userId]) ? array_values(array_intersect($channels, $plan[$userId]['channels'])) : $channels;
            foreach ($mine as $channel) {
                $when = $plan[$userId]['defer'][$channel] ?? null;
                if ($channel === 'push' && ! $when) {
                    continue;      // push leaves at once through its own dispatcher
                }
                // A channel whose provider is not set up yet stays silent (the settings page says so); nothing is written for it.
                if ($channel !== 'push' && ! $this->settings->ready($channel)) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid(), 'notification_id' => $notificationId, 'user_id' => $userId, 'channel' => $channel, 'type' => $type,
                    'status' => 'queued', 'reason' => null, 'to' => null, 'attempts' => 0, 'sent_at' => null, 'campaign_id' => $campaignId,
                    'not_before' => $when, 'created_at' => $now, 'updated_at' => $now,
                ];
                $immediate += $when ? 0 : 1;
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            NotificationDelivery::insert($chunk);
        }
        if ($immediate > 0 && $immediate <= self::INLINE) {
            $this->process(self::INLINE);
        }

        return count($rows);
    }

    /** Sends queued messages that are due. @return array{sent: int, failed: int, skipped: int} */
    public function process(int $limit = 200): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $rows = NotificationDelivery::where('status', 'queued')->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()))->orderBy('created_at')->limit($limit)->get();
        if ($rows->isEmpty()) {
            return $out;
        }
        $notifications = AppNotification::whereIn('id', $rows->pluck('notification_id')->filter())->get()->keyBy('id');
        $users = User::whereIn('id', $rows->pluck('user_id')->filter())->get()->keyBy('id');

        foreach ($rows as $row) {
            $notification = $notifications->get($row->notification_id);
            $user = $users->get($row->user_id);
            $locale = $user?->locale === 'en' ? 'en' : 'ar';
            if (! $notification || ! $user) {
                $this->finish($row, 'skipped', 'missing', null, $out);

                continue;
            }
            $title = (string) ($locale === 'en' ? ($notification->title_en ?: $notification->title_ar) : ($notification->title_ar ?: $notification->title_en));
            $body = $locale === 'en' ? ($notification->body_en ?: $notification->body_ar) : ($notification->body_ar ?: $notification->body_en);

            if ($row->channel === 'push') {
                app(PushDispatcher::class)->dispatch([$user->id], (string) $row->type, ['ar' => $notification->title_ar, 'en' => $notification->title_en], ['ar' => $notification->body_ar, 'en' => $notification->body_en], (array) $notification->data + ['notification_id' => $notification->id]);
                $this->finish($row, 'sent', null, null, $out);
            } elseif ($row->channel === 'email') {
                if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    $this->finish($row, 'skipped', 'no_email', null, $out);

                    continue;
                }
                $result = $this->email->send($user->email, $title, $body, $locale);
                $this->finish($row, $result['ok'] ? 'sent' : 'failed', $result['error'], $this->maskEmail($user->email), $out);
            } else {
                $number = $this->sms->normalise($user->phone);
                if (! $number) {
                    $this->finish($row, 'skipped', 'no_phone', null, $out);

                    continue;
                }
                $center = app(ThemeService::class)->centerName()[$locale];
                $result = $this->sms->send($number, Str::limit($center.': '.$title.($body ? ' — '.$body : ''), 300, '…'), (string) $row->id);
                $this->finish($row, $result['ok'] ? 'sent' : 'failed', $result['error'], '+'.substr($number, 0, 3).'•••'.substr($number, -3), $out, $result['id'] ?? null);
            }
        }

        return $out;
    }

    /** One failed message is tried again twice before it is given up. */
    private function finish(NotificationDelivery $row, string $status, ?string $reason, ?string $to, array &$out, ?string $providerId = null): void
    {
        $attempts = $row->attempts + 1;
        if ($status === 'failed' && $attempts < 3) {
            $row->update(['attempts' => $attempts, 'reason' => $reason, 'failed_reason' => $reason, 'to' => $to]);   // stays queued

            return;
        }
        $row->update([
            'status' => $status, 'reason' => $reason, 'failed_reason' => $status === 'failed' ? $reason : null, 'to' => $to, 'attempts' => $attempts,
            'sent_at' => $status === 'sent' ? now() : null, 'provider_message_id' => $providerId ?? $row->provider_message_id,
        ]);
        $out[$status]++;
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];

        return Str::substr($name, 0, 2).'•••@'.$domain;
    }

    /** Last week's numbers per channel, and the latest messages. @return array<string, mixed> */
    public function report(): array
    {
        $since = now()->subDays(7);
        $rows = NotificationDelivery::where('created_at', '>=', $since)->selectRaw('channel, status, count(*) as n')->groupBy('channel', 'status')->get();
        $stats = [];
        foreach (['email', 'sms'] as $channel) {
            $stats[$channel] = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0];
            foreach ($rows->where('channel', $channel) as $r) {
                $stats[$channel][$r->status] = (int) $r->n;
            }
        }

        return [
            'stats' => $stats,
            'recent' => NotificationDelivery::latest('created_at')->limit(15)->get()->map(fn (NotificationDelivery $d) => $d->only(['id', 'channel', 'type', 'status', 'reason', 'to']) + ['at' => ($d->sent_at ?? $d->created_at)->toIso8601String()])->values(),
        ];
    }
}
