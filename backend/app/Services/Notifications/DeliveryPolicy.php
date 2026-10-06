<?php

namespace App\Services\Notifications;

use App\Models\NotificationRule;
use App\Models\Program;
use App\Models\UserNotificationPreference;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides, for each person, whether a notification is created at all, through which channels, and not before when:
 * the rules an administrator set (per event, category, program, audience), the quiet hours of each channel, and the person's own
 * preferences for optional events. Events in MANDATORY ignore the person's opt-out.
 */
class DeliveryPolicy
{
    /** Events nobody can switch off for themselves. */
    public const MANDATORY = [
        'attendance.absence_breach', 'withdrawal.decided', 'registration.approved', 'registration.rejected', 'registration.waitlisted',
        'group.postponed', 'group.cancelled', 'certificate.issued', 'licence.expired', 'exception.granted', 'session.reminder',
    ];

    public const SOUND = 'sound';

    public function __construct(private readonly AudienceResolver $audiences) {}

    public function groupOf(string $type): string
    {
        return NotificationCatalog::events()[$type]['group'] ?? (in_array($type, ['announcement', 'event.reminder', 'event.rsvp_confirmed'], true) ? 'announcements' : 'system');
    }

    public function isMandatory(string $type): bool
    {
        return in_array($type, self::MANDATORY, true);
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<string>  $channels  what the event itself would use (push, email, sms)
     * @return array<string, array{skip: bool, channels: list<string>, defer: array<string, Carbon>}> by user id
     */
    public function plan(string $type, array $userIds, array $channels, array $data = [], bool $force = false): array
    {
        $rules = $this->rulesFor($type, $data);
        $prefs = $this->preferences($type, $userIds, $force);
        $now = now();
        $out = [];

        // Which people each rule reaches (one query per rule, not per person).
        $reach = [];
        foreach ($rules as $rule) {
            $reach[$rule->id] = array_flip($this->audiences->matching($userIds, $rule->audience_filter));
        }

        foreach ($userIds as $uid) {
            $mine = $channels;
            $defer = [];
            $skip = false;

            $rule = $rules->first(fn (NotificationRule $r) => isset($reach[$r->id][$uid]));
            if ($rule) {
                if (! $rule->enabled && ! $force) {
                    $skip = true;
                }
                if ($rule->channels !== null) {
                    $mine = array_values(array_intersect($mine, $rule->channels));
                }
                $earliest = $rule->delay_minutes > 0 ? $now->copy()->addMinutes($rule->delay_minutes) : null;
                foreach ($mine as $c) {
                    $when = $earliest;
                    $quiet = $rule->quiet_hours;
                    if ($quiet && (empty($quiet['channels']) || in_array($c, $quiet['channels'], true))) {
                        $next = $this->nextAllowed($earliest ?? $now, $quiet);
                        $when = $next && $next->gt($earliest ?? $now) ? $next : $when;
                    }
                    if ($when && $when->gt($now)) {
                        $defer[$c] = $when;
                    }
                }
            }

            foreach ($prefs[$uid] ?? [] as $c) {
                $mine = array_values(array_diff($mine, [$c]));
                unset($defer[$c]);
            }

            $out[$uid] = ['skip' => $skip, 'channels' => $mine, 'defer' => $defer];
        }

        return $out;
    }

    /** Whether the person wants a chime when something arrives. */
    public function soundOn(string $userId): bool
    {
        return ! UserNotificationPreference::where(['user_id' => $userId, 'event_group' => self::SOUND, 'channel' => self::SOUND, 'enabled' => false])->exists();
    }

    /** @return Collection<int, NotificationRule> most specific first: a program rule beats a category rule beats a general one, then priority */
    private function rulesFor(string $type, array $data): Collection
    {
        $rules = NotificationRule::whereIn('event', [$type, '*'])->get();
        if ($rules->isEmpty()) {
            return $rules;
        }
        $programId = $data['program_id'] ?? null;
        $categoryId = $programId ? Program::whereKey($programId)->value('category_id') : null;

        return $rules->filter(fn (NotificationRule $r) => ($r->program_id === null || $r->program_id === $programId) && ($r->category_id === null || $r->category_id === $categoryId))
            ->sort(function (NotificationRule $a, NotificationRule $b) use ($type) {
                $weight = fn (NotificationRule $r) => ($r->program_id ? 4 : 0) + ($r->category_id ? 2 : 0) + ($r->event === $type ? 1 : 0);

                return [$weight($b), $b->priority] <=> [$weight($a), $a->priority];
            })->values();
    }

    /** @return array<string, list<string>> the channels each person switched off for this kind of event */
    private function preferences(string $type, array $userIds, bool $force): array
    {
        if ($force || $this->isMandatory($type) || $userIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($userIds, 1000) as $chunk) {
            UserNotificationPreference::whereIn('user_id', $chunk)->where('event_group', $this->groupOf($type))->where('enabled', false)->where('channel', '!=', self::SOUND)
                ->get(['user_id', 'channel'])->each(function ($p) use (&$out) {
                    $out[$p->user_id][] = $p->channel;
                });
        }

        return $out;
    }

    /** The first moment at or after $from inside the allowed days and hours; null when the setting allows nothing. @param  array{days?: list<int>, from?: string, to?: string, timezone?: string}  $quiet */
    public function nextAllowed(CarbonInterface $from, array $quiet): ?CarbonInterface
    {
        $tz = $quiet['timezone'] ?? 'Asia/Qatar';
        $days = $quiet['days'] ?? [0, 1, 2, 3, 4, 5, 6];
        $fromTime = $quiet['from'] ?? '00:00';
        $toTime = $quiet['to'] ?? '23:59';
        if ($days === []) {
            return null;
        }
        $local = $from->copy()->timezone($tz);

        for ($i = 0; $i <= 8; $i++) {
            $day = $local->copy()->startOfDay()->addDays($i);
            if (! in_array($day->dayOfWeek, $days, true)) {
                continue;
            }
            [$fh, $fm] = array_map('intval', explode(':', $fromTime) + [1 => 0]);
            [$th, $tm] = array_map('intval', explode(':', $toTime) + [1 => 0]);
            $start = $day->copy()->setTime($fh, $fm);
            $end = $day->copy()->setTime($th, $tm);
            if ($end->lte($start)) {
                $end->addDay();      // an overnight window such as 20:00–06:00
            }
            if ($local->lt($start)) {
                return $start->timezone(config('app.timezone'));
            }
            if ($local->lt($end)) {
                return $from->copy();
            }
        }

        return null;
    }
}
