<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\TrainingGroup;
use App\Models\TrainingKit;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\RoomService;
use App\Services\TrainerService;
use App\Services\TrainingDaySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * سابر+ on شاهين: a group's training days are its sessions, numbered 1…n in `sequence`; a programme has `training_days` days per group
 * (else its hours ÷ the training day). A day without a date is an empty slot. Every change goes through the rules the session screens use:
 * the training calendar, the training-day window, the room and the trainer being free (docs/saber/00-gap-analysis.md, A4–A6).
 */
class Workbench
{
    /** Group colours offered and given automatically (one not yet used in the programme). */
    public const PALETTE = ['#8A1538', '#0E7490', '#7C3AED', '#B45309', '#15803D', '#BE185D', '#1D4ED8', '#A16207', '#0F766E', '#9333EA'];

    public function __construct(
        private readonly CalendarService $calendar,
        private readonly RoomService $rooms,
        private readonly TrainerService $trainers,
        private readonly TrainingDaySettings $day,
    ) {}

    public function daysPerGroup(Program $program): int
    {
        if ($program->training_days) {
            return (int) $program->training_days;
        }

        return max(1, (int) ceil(((float) $program->total_hours ?: $this->day->hours()) / $this->day->hours()));
    }

    /** first (August–December), second (January–July) or year; from the group's own choice, else its first day. */
    public static function termOf(?string $chosen, ?string $firstDate): ?string
    {
        if (in_array($chosen, ['first', 'second', 'year'], true)) {
            return $chosen;
        }
        if (! $firstDate) {
            return null;
        }

        return (int) substr($firstDate, 5, 2) >= 8 ? 'first' : 'second';
    }

    /** Set by hand, else ready when a linked kit is approved or published. */
    public function kitReady(Program $program): bool
    {
        if ($program->kit_ready !== null) {
            return (bool) $program->kit_ready;
        }

        return $program->relationLoaded('kits')
            ? $program->kits->contains(fn ($k) => in_array($k->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true))
            : $program->kits()->whereIn('status', [TrainingKit::APPROVED, TrainingKit::PUBLISHED])->exists();
    }

    /**
     * The group's days 1…n: each with its session (date, times, room, trainer) or empty.
     *
     * @return list<array<string, mixed>>
     */
    public function days(TrainingGroup $group, int $count): array
    {
        $sessions = $this->slots($group);
        $tz = config('app.timezone');
        $out = [];
        $last = max($count, (int) ($sessions->keys()->max() ?? 0));
        for ($i = 1; $i <= $last; $i++) {
            $s = $sessions->get($i);
            $out[] = [
                'idx' => $i,
                'extra' => $i > $count,
                'session_id' => $s?->id,
                'date' => $s?->starts_at?->timezone($tz)->toDateString(),
                'start_time' => $s?->starts_at?->timezone($tz)->format('H:i'),
                'end_time' => $s?->ends_at?->timezone($tz)->format('H:i'),
                'room_id' => $s?->training_room_id,
                'trainer_id' => $s?->trainer_id,
                'status' => $s?->status,
                'locked' => $s ? $s->attendance()->exists() : false,
            ];
        }

        return $out;
    }

    /**
     * Sets the date, times, room or trainer of day `idx`. Picking the trainer of day 1 gives it to every day where they are free; the first
     * room chosen for a group goes to every day where it is free. An explicit clash is refused; a clash that only comes from moving the date
     * clears the inherited room or trainer and says so.
     *
     * @param  array{date?: ?string, start_time?: ?string, end_time?: ?string, room_id?: ?string, trainer_id?: ?string}  $patch
     * @return list<array{type: string, text: string}> notices
     */
    public function setDay(TrainingGroup $group, int $idx, array $patch, User $actor): array
    {
        if (array_key_exists('date', $patch) && blank($patch['date'])) {
            $this->resetDay($group, $idx);

            return [['type' => 'info', 'text' => __('messages.saber.day_reset', ['n' => $idx])]];
        }

        return DB::transaction(function () use ($group, $idx, $patch) {
            $this->normalise($group);
            $program = $group->program;
            $session = $this->slots($group)->get($idx);
            $notices = [];
            $online = $program->delivery_mode === 'online';
            $tz = config('app.timezone');
            $settings = $this->day->all();

            $date = $patch['date'] ?? $session?->starts_at?->timezone($tz)->toDateString();
            if (! $date) {
                throw new BusinessRuleException(__('messages.saber.date_first'), 'date_first');
            }
            $startTime = $patch['start_time'] ?? $session?->starts_at?->timezone($tz)->format('H:i') ?? $settings['day_start'];
            $endTime = $patch['end_time'] ?? $session?->ends_at?->timezone($tz)->format('H:i') ?? $settings['day_end'];
            $start = CarbonImmutable::parse("{$date} {$startTime}", $tz);
            $end = CarbonImmutable::parse("{$date} {$endTime}", $tz);
            if ($end->lessThanOrEqualTo($start)) {
                throw new BusinessRuleException(__('messages.saber.end_after_start'), 'end_before_start');
            }
            $moved = ! $session || ! $session->starts_at->equalTo($start) || ! $session->ends_at->equalTo($end);

            $roomId = array_key_exists('room_id', $patch) ? $patch['room_id'] : ($session?->training_room_id ?? $group->default_room_id);
            $trainerId = array_key_exists('trainer_id', $patch) ? $patch['trainer_id'] : ($session?->trainer_id ?? $this->groupTrainer($group));

            if ($moved) {
                $this->calendar->assertTrainingAllowed($start, $end);
                $this->day->assertWithinDay($start, $end);
            }
            if ($roomId && ! $online && ($moved || $roomId !== $session?->training_room_id)) {
                if (! $this->rooms->isFree($roomId, $start, $end, $session?->id)) {
                    if (array_key_exists('room_id', $patch)) {
                        $this->rooms->assertBookable($roomId, $start, $end, $session?->id);   // explains who holds it
                    }
                    $notices[] = ['type' => 'warning', 'text' => __('messages.saber.room_cleared', ['room' => $this->roomName($roomId), 'date' => $date])];
                    $roomId = null;
                }
            }
            if ($trainerId && ($moved || $trainerId !== $session?->trainer_id)) {
                if ($this->trainers->conflicts($trainerId, $start, $end, $session?->id)->isNotEmpty()) {
                    if (array_key_exists('trainer_id', $patch)) {
                        $this->trainers->assertAssignable($trainerId, $start, $end, $session?->id);
                    }
                    $notices[] = ['type' => 'warning', 'text' => __('messages.saber.trainer_cleared', ['date' => $date])];
                    $trainerId = null;
                }
            }

            $hadRoom = $group->sessions()->whereNotNull('training_room_id')->exists() || $group->default_room_id;
            $values = ['starts_at' => $start, 'ends_at' => $end, 'training_room_id' => $online ? null : $roomId, 'trainer_id' => $trainerId];
            if ($session) {
                $session->update($values);
            } else {
                $program->sessions()->create($values + [
                    'training_group_id' => $group->id, 'sequence' => $idx, 'mode' => $online ? 'online' : 'in_person',
                    'title_ar' => "اليوم {$idx}", 'title_en' => "Day {$idx}",
                ]);
            }

            if ($idx === 1 && array_key_exists('trainer_id', $patch) && $patch['trainer_id']) {
                $notices = [...$notices, ...$this->spread($group, $idx, 'trainer_id', $patch['trainer_id'])];
            }
            if (array_key_exists('room_id', $patch) && $patch['room_id'] && ! $hadRoom && ! $online) {
                $group->update(['default_room_id' => $patch['room_id']]);
                $notices = [...$notices, ...$this->spread($group, $idx, 'training_room_id', $patch['room_id'])];
            }

            return $notices;
        });
    }

    /** Empties day `idx` (its session goes; a day that already has attendance is kept). */
    public function resetDay(TrainingGroup $group, int $idx): void
    {
        $this->normalise($group);
        $session = $this->slots($group)->get($idx);
        if (! $session) {
            return;
        }
        if ($session->attendance()->exists()) {
            throw new BusinessRuleException(__('messages.saber.day_has_attendance', ['n' => $idx]), 'day_has_attendance');
        }
        $session->delete();
    }

    /** Empties every day of the group that has no attendance yet. @return int days emptied */
    public function resetGroup(TrainingGroup $group): int
    {
        $n = 0;
        foreach ($group->sessions()->whereDoesntHave('attendance')->get() as $s) {
            $s->delete();
            $n++;
        }
        $group->update(['default_room_id' => null]);

        return $n;
    }

    public function nextColour(Program $program): string
    {
        $used = $program->groups()->pluck('color')->filter()->map(fn ($c) => strtoupper($c))->all();

        return collect(self::PALETTE)->first(fn ($c) => ! in_array(strtoupper($c), $used, true)) ?? self::PALETTE[$program->groups()->count() % count(self::PALETTE)];
    }

    /** Gives a room or a trainer to every other day of the group where it is free. @return list<array{type: string, text: string}> */
    private function spread(TrainingGroup $group, int $from, string $field, string $value): array
    {
        $applied = 0;
        $skipped = [];
        foreach ($this->slots($group) as $i => $s) {
            if ($i === $from || $s->{$field} === $value) {
                continue;
            }
            $free = $field === 'training_room_id'
                ? $this->rooms->isFree($value, $s->starts_at, $s->ends_at, $s->id)
                : $this->trainers->conflicts($value, $s->starts_at, $s->ends_at, $s->id)->isEmpty();
            if (! $free) {
                $skipped[] = $i;

                continue;
            }
            $s->update([$field => $value]);
            $applied++;
        }
        if ($applied === 0 && $skipped === []) {
            return [];
        }
        $key = $field === 'training_room_id' ? 'room' : 'trainer';

        return [['type' => $skipped ? 'warning' : 'success', 'text' => $skipped
            ? __("messages.saber.{$key}_spread_skipped", ['n' => $applied, 'days' => implode('، ', $skipped)])
            : __("messages.saber.{$key}_spread", ['n' => $applied])]];
    }

    /** The group's sessions by day number. @return Collection<int, ProgramSession> */
    private function slots(TrainingGroup $group): Collection
    {
        $sessions = $group->sessions()->orderBy('sequence')->orderBy('starts_at')->get();
        $unique = $sessions->pluck('sequence')->filter()->unique()->count() === $sessions->count();

        return $unique ? $sessions->keyBy('sequence') : $sessions->values()->keyBy(fn ($s, $i) => $i + 1);
    }

    /** Numbers the group's sessions 1…n (in their order) when they are not already numbered that way. */
    private function normalise(TrainingGroup $group): void
    {
        $sessions = $group->sessions()->orderBy('sequence')->orderBy('starts_at')->get();
        if ($sessions->pluck('sequence')->filter()->unique()->count() === $sessions->count()) {
            return;
        }
        foreach ($sessions->values() as $i => $s) {
            $s->update(['sequence' => $i + 1]);
        }
    }

    private function groupTrainer(TrainingGroup $group): ?string
    {
        return $group->sessions()->whereNotNull('trainer_id')->orderBy('sequence')->value('trainer_id');
    }

    private function roomName(string $id): string
    {
        $r = TrainingRoom::find($id);

        return $r ? ($r->name_ar ?: $r->name_en) : '';
    }
}
