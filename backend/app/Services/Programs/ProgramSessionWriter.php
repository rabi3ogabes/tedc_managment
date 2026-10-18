<?php

namespace App\Services\Programs;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\RoomService;
use App\Services\TrainerService;
use App\Services\TrainingDaySettings;
use Carbon\CarbonImmutable;

/**
 * Writes a programme's session plan onto its first group, with every rule the session screens enforce: the training
 * calendar (closed days need an approval), the program day window, the room and the trainer being free. The programme
 * builder and the new-programme form share it; the first refused session stops the whole save and names its number.
 */
class ProgramSessionWriter
{
    public function __construct(private readonly CalendarService $calendar, private readonly RoomService $rooms, private readonly TrainerService $trainers, private readonly TrainingDaySettings $day) {}

    /**
     * @param  list<array<string, mixed>>  $sessions  starts_at, ends_at, and optionally training_room_id, trainer_id, title_ar, title_en, mode, sequence …
     * @param  array{delivery_mode?: string, trainers?: list<array{id: string, role?: string}>, remote?: array<string, mixed>}  $ctx
     * @return int sessions written
     */
    public function write(Program $program, array $sessions, array $ctx, User $actor, ?string $approvalReason = null): int
    {
        $trainers = $ctx['trainers'] ?? [];
        $remote = $ctx['remote'] ?? [];
        $programOnline = ($ctx['delivery_mode'] ?? 'in_person') === 'online';
        $written = 0;

        foreach (array_values($sessions) as $i => $session) {
            try {
                $start = CarbonImmutable::parse($session['starts_at']);
                $end = CarbonImmutable::parse($session['ends_at']);
                if ($approvalReason) {
                    $this->calendar->approveSpan($start, $end, $approvalReason, $actor->id);
                }
                $this->calendar->assertTrainingAllowed($start, $end);
                $this->day->assertWithinDay($start, $end);
                $online = $programOnline || ($session['mode'] ?? null) === 'online';
                if (! empty($session['training_room_id']) && ! $online) {
                    $this->rooms->assertBookable($session['training_room_id'], $start, $end);
                }
                $trainerId = $session['trainer_id'] ?? collect($trainers)->firstWhere('role', 'lead')['id'] ?? ($trainers[0]['id'] ?? null);
                if ($trainerId) {
                    $this->trainers->assertAssignable($trainerId, $start, $end);
                }
            } catch (BusinessRuleException $e) {
                // The person must know which session of the plan was refused.
                throw new BusinessRuleException(__('messages.program_schedule.session', ['n' => $i + 1]).' '.$e->getMessage(), $e->errorCode, $e->details + ['session' => $i + 1]);
            }

            $program->sessions()->create([
                'mode' => $online ? 'online' : 'in_person',
                'online_url' => $online ? ($session['online_url'] ?? $remote['join_url'] ?? null) : null,
                'online_platform' => $online ? ($session['online_platform'] ?? $remote['platform'] ?? null) : null,
                'online_passcode' => $online ? ($session['online_passcode'] ?? $remote['passcode'] ?? null) : null,
                'sequence' => $session['sequence'] ?? $i + 1,
                'title_ar' => $session['title_ar'] ?? 'الجلسة '.($i + 1),
                'title_en' => $session['title_en'] ?? 'Session '.($i + 1),
                'starts_at' => $start, 'ends_at' => $end,
                'training_room_id' => $online ? null : ($session['training_room_id'] ?? null),
                'trainer_id' => $trainerId,
                'location_text' => $session['location_text'] ?? null,
            ]);
            $written++;
        }

        return $written;
    }
}
