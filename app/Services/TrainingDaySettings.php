<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The shape of a training day (Settings → Training day): the program day runs from 08:00 to 13:00 and a room hosts
 * one session per day. Administrators can change the hours or switch either rule off.
 */
class TrainingDaySettings
{
    public const KEY = 'training_day';

    private const CACHE = 'site.training_day';

    public static function defaults(): array
    {
        return ['day_start' => '08:00', 'day_end' => '13:00', 'enforce_window' => true, 'one_session_per_room_per_day' => true];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        $next['enforce_window'] = (bool) $next['enforce_window'];
        $next['one_session_per_room_per_day'] = (bool) $next['one_session_per_room_per_day'];

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }

    /** Length of the program day in hours (08:00–13:00 = 5). */
    public function hours(): float
    {
        $s = $this->all();

        return max(0.5, round((strtotime($s['day_end']) - strtotime($s['day_start'])) / 3600, 2));
    }

    public function oneSessionPerRoomPerDay(): bool
    {
        return (bool) $this->all()['one_session_per_room_per_day'];
    }

    /** A session must fit inside the program day. @throws BusinessRuleException */
    public function assertWithinDay(\DateTimeInterface $start, \DateTimeInterface $end): void
    {
        $s = $this->all();
        if (! $s['enforce_window']) {
            return;
        }
        $tz = config('app.timezone');
        $from = Carbon::instance($start)->timezone($tz);
        $to = Carbon::instance($end)->timezone($tz);
        $dayStart = $from->copy()->setTimeFromTimeString($s['day_start']);
        $dayEnd = $from->copy()->setTimeFromTimeString($s['day_end']);

        if ($from->lt($dayStart) || $to->gt($dayEnd) || ! $from->isSameDay($to)) {
            throw new BusinessRuleException(__('messages.training_day.outside', ['from' => $s['day_start'], 'to' => $s['day_end']]), 'outside_training_day');
        }
    }
}
