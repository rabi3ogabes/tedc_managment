<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingRoom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What the screen at a classroom shows for one day: each session in the room with its program, trainer, time
 * and the trainees with their live attendance.
 */
class RoomScreenService
{
    public function token(TrainingRoom $room, bool $regenerate = false): string
    {
        if ($regenerate || ! $room->display_token) {
            $room->forceFill(['display_token' => Str::random(40)])->save();
        }

        return $room->display_token;
    }

    /** @return array<string, mixed> */
    public function day(TrainingRoom $room, ?string $date = null): array
    {
        $tz = config('app.timezone');
        $day = $date ? Carbon::parse($date, $tz) : Carbon::now($tz);
        $now = Carbon::now($tz);

        $sessions = ProgramSession::with(['program:id,code,title_ar,title_en', 'trainer:id,name_ar,name_en,title_ar,title_en,photo_path'])
            ->where('training_room_id', $room->id)->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->orderBy('starts_at')->get();

        $registrations = Registration::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en'])
            ->whereIn('program_id', $sessions->pluck('program_id')->unique())->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->get()->groupBy('program_id');
        $records = Attendance::whereIn('program_session_id', $sessions->pluck('id'))->get()->groupBy('program_session_id');

        $next = $sessions->first(fn (ProgramSession $s) => $s->starts_at->gt($now));
        $rows = $sessions->map(function (ProgramSession $s) use ($registrations, $records, $now, $next) {
            $mine = $records->get($s->id, collect())->keyBy('registration_id');
            $trainees = $registrations->get($s->program_id, collect())->map(function (Registration $r) use ($mine, $s, $now) {
                $a = $mine->get($r->id);
                $status = $a?->check_in_at ? ($a->status === 'late' ? 'late' : 'present') : ($s->ends_at->lt($now) ? 'absent' : 'expected');

                return ['name' => $r->employee->user->displayName(), 'school' => $r->employee->school?->translate('name'), 'status' => $status, 'check_in_at' => $a?->check_in_at?->toIso8601String()];
            })->sortBy([fn ($a, $b) => ['present' => 0, 'late' => 1, 'expected' => 2, 'absent' => 3][$a['status']] <=> ['present' => 0, 'late' => 1, 'expected' => 2, 'absent' => 3][$b['status']], fn ($a, $b) => strcmp($a['name'], $b['name'])])->values();

            return [
                'id' => $s->id, 'title' => $s->translate('title'), 'sequence' => $s->sequence, 'mode' => $s->mode,
                'program' => ['code' => $s->program->code, 'title' => $s->program->translate('title')],
                'trainer' => $s->trainer ? ['name' => trim(($s->trainer->translate('title') ? $s->trainer->translate('title').' ' : '').$s->trainer->translate('name')), 'photo' => FileStorage::publicUrl($s->trainer->photo_path)] : null,
                'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(), 'minutes' => $s->durationMinutes(),
                'state' => $s->ends_at->lt($now) ? 'ended' : ($s->starts_at->lte($now) ? 'live' : ($next?->id === $s->id ? 'next' : 'upcoming')),
                'counts' => ['expected' => $trainees->count(), 'present' => $trainees->whereIn('status', ['present', 'late'])->count(), 'late' => $trainees->where('status', 'late')->count(), 'absent' => $trainees->where('status', 'absent')->count()],
                'trainees' => $trainees,
            ];
        })->values();

        return [
            'room' => ['name' => $room->translate('name'), 'code' => $room->code, 'building' => $room->building, 'floor' => $room->floor, 'capacity' => $room->capacity],
            'template' => app(RoomScreenSettings::class)->all(),
            'date' => $day->toDateString(), 'is_today' => $day->isSameDay($now), 'now' => $now->toIso8601String(), 'sessions' => $rows,
        ];
    }

    /** Every active room's screen for one day, for the wall that shows them all at once. @return list<array<string, mixed>> */
    public function wall(?string $date = null): array
    {
        return TrainingRoom::where('status', 'active')->orderBy('office')->orderBy('floor')->orderBy('name_ar')->get()
            ->map(fn (TrainingRoom $room) => ['id' => $room->id] + $this->day($room, $date))->all();
    }
}
