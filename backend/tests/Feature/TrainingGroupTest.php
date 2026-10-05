<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use Tests\TestCase;

class TrainingGroupTest extends TestCase
{
    private function trainingHead()
    {
        return $this->makeUser(Role::TRAINING_HEAD);
    }

    private function makeGroup(Program $program, array $attributes = []): TrainingGroup
    {
        $sequence = $program->groups()->max('sequence') + 1;

        return TrainingGroup::create($attributes + ['program_id' => $program->id, 'code' => $program->code.'-G'.$sequence, 'sequence' => $sequence, 'capacity' => 5, 'status' => TrainingGroup::REGISTRATION_OPEN, 'published_at' => now()]);
    }

    public function test_a_group_is_created_with_its_own_dates_and_calendar_aware_sessions(): void
    {
        $program = $this->makeProgram(['code' => 'LEAD', 'capacity' => 20]);
        $room = TrainingRoom::first() ?? TrainingRoom::create(['code' => 'R1', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 30, 'status' => 'active']);

        // Sunday 1 Nov 2026 + Friday (closed, Qatar's weekend) → 4 candidate days, two of them Fridays.
        $res = $this->asUser($this->trainingHead())->postJson("/api/v1/admin/programs/{$program->id}/groups", [
            'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'capacity' => 12, 'delivery_mode' => 'in_person', 'default_room_id' => $room->id,
            'sessions' => ['weekdays' => [0, 5], 'starts' => '08:00', 'ends' => '13:00', 'count' => 4],
        ])->assertCreated();

        $group = TrainingGroup::find($res->json('data.id'));
        $this->assertSame('LEAD-G2', $group->code);
        $this->assertSame(2, $group->sequence);
        $this->assertSame(12, $group->capacity);
        $this->assertSame(TrainingGroup::PLANNED, $group->status);
        $this->assertNull($group->published_at);

        $sessions = $group->sessions()->orderBy('starts_at')->get();
        $this->assertCount(2, $sessions, 'the two Fridays are skipped');
        $this->assertSame(['2026-11-01', '2026-11-08'], $sessions->map(fn ($s) => $s->starts_at->toDateString())->all());
        $this->assertSame($room->id, $sessions[0]->training_room_id);
        $this->assertCount(2, $res->json('data.skipped'));
        $this->assertSame('closed_day', $res->json('data.skipped.0.reason'));
        $this->assertSame($group->id, $sessions[0]->training_group_id);
    }

    public function test_the_session_preview_shows_what_would_be_created_without_saving(): void
    {
        $program = $this->makeProgram();
        $res = $this->asUser($this->trainingHead())->postJson("/api/v1/admin/programs/{$program->id}/groups/preview", ['sessions' => ['start_date' => '2026-11-01', 'weekdays' => [0, 5], 'starts' => '08:00', 'ends' => '13:00', 'count' => 3]])->assertOk();

        $this->assertCount(3, $res->json('data'));
        $this->assertSame([true, false, true], collect($res->json('data'))->map(fn ($d) => $d['allowed'])->all());
        $this->assertSame(0, ProgramSession::count());
    }

    public function test_a_group_can_be_copied_to_a_new_date_with_its_sessions_shifted(): void
    {
        $program = $this->makeProgram();
        $head = $this->trainingHead();
        $source = $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/groups", ['start_date' => '2026-11-01', 'end_date' => '2026-11-03', 'sessions' => ['weekdays' => [0, 1, 2], 'starts' => '08:00', 'ends' => '13:00', 'count' => 3]])->assertCreated()->json('data.id');

        $copy = $this->asUser($head)->postJson("/api/v1/admin/groups/{$source}/clone", ['start_date' => '2026-12-06'])->assertCreated();

        $group = TrainingGroup::find($copy->json('data.id'));
        $this->assertSame('2026-12-06', $group->start_date->toDateString());
        $this->assertSame('2026-12-08', $group->end_date->toDateString());
        $this->assertSame(['2026-12-06', '2026-12-07', '2026-12-08'], $group->sessions()->orderBy('starts_at')->get()->map(fn ($s) => $s->starts_at->toDateString())->all());
        $this->assertSame(3, TrainingGroup::find($source)->sessions()->count(), 'the original keeps its own sessions');
    }

    public function test_status_changes_follow_the_allowed_transitions_and_need_a_reason_when_the_rfp_says_so(): void
    {
        $program = $this->makeProgram();
        $group = $this->makeGroup($program, ['status' => TrainingGroup::PLANNED]);
        $head = $this->trainingHead();
        $change = fn (array $body) => $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/status", $body);

        $change(['status' => TrainingGroup::COMPLETED])->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
        $change(['status' => TrainingGroup::POSTPONED])->assertUnprocessable();                     // a reason is mandatory
        $change(['status' => TrainingGroup::POSTPONED, 'reason' => 'المدرب غير متاح', 'postponed_to' => '2026-12-12'])->assertOk()->assertJsonPath('data.status', 'postponed');

        $fresh = $group->fresh();
        $this->assertSame('المدرب غير متاح', $fresh->status_reason);
        $this->assertSame('2026-12-12', $fresh->postponed_to->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'group_status_changed', 'user_id' => $head->id]);

        $change(['status' => TrainingGroup::PLANNED])->assertOk();
        $change(['status' => TrainingGroup::ONGOING])->assertOk();
        $change(['status' => TrainingGroup::COMPLETED])->assertOk();
        $change(['status' => TrainingGroup::PLANNED])->assertUnprocessable();

        $this->asUser($this->makeUser(Role::TRAINER))->postJson("/api/v1/admin/groups/{$group->id}/status", ['status' => 'planned'])->assertForbidden();
    }

    public function test_postponing_or_cancelling_tells_registrants_supervisor_and_managers_and_frees_the_rooms(): void
    {
        $program = $this->makeProgram();
        $room = TrainingRoom::first() ?? TrainingRoom::create(['code' => 'R1', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 30, 'status' => 'active']);
        $supervisor = $this->makeUser(Role::COORDINATOR);
        $group = $this->makeGroup($program, ['supervisor_id' => $supervisor->id]);
        $session = $this->makeSession($program, now()->addDays(10));
        $session->update(['training_group_id' => $group->id, 'training_room_id' => $room->id]);

        $managerEmployee = $this->makeEmployee();
        $trainee = $this->makeEmployee(['supervisor_id' => $managerEmployee->id]);
        Registration::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'employee_id' => $trainee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);

        $this->asUser($this->trainingHead())->postJson("/api/v1/admin/groups/{$group->id}/status", ['status' => 'cancelled', 'reason' => 'قلة المسجلين'])->assertOk();

        foreach ([$trainee->user_id, $supervisor->id, $managerEmployee->user_id] as $userId) {
            $this->assertDatabaseHas('notifications', ['user_id' => $userId, 'type' => 'group.cancelled']);
        }
        $session->refresh();
        $this->assertNull($session->training_room_id);
        $this->assertSame('cancelled', $session->status);
    }

    public function test_publishing_opens_a_group_to_registration_and_unpublishing_is_blocked_once_people_registered(): void
    {
        $program = $this->makeProgram();
        $group = $this->makeGroup($program, ['status' => TrainingGroup::PLANNED, 'published_at' => null]);
        $head = $this->trainingHead();

        $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/publish")->assertOk();
        $this->assertNotNull($group->fresh()->published_at);
        $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/unpublish")->assertOk();
        $this->assertNull($group->fresh()->published_at);

        $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/publish")->assertOk();
        Registration::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        $this->asUser($head)->postJson("/api/v1/admin/groups/{$group->id}/unpublish")->assertUnprocessable();
    }

    public function test_a_group_with_registrations_cannot_be_deleted_and_an_empty_one_can(): void
    {
        $program = $this->makeProgram();
        $extra = $this->makeGroup($program);
        $head = $this->trainingHead();
        Registration::create(['program_id' => $program->id, 'training_group_id' => $extra->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_PENDING]);

        $this->asUser($head)->deleteJson("/api/v1/admin/groups/{$extra->id}")->assertUnprocessable();
        $empty = $this->makeGroup($program);
        $this->asUser($head)->deleteJson("/api/v1/admin/groups/{$empty->id}")->assertOk();
        $this->assertNull(TrainingGroup::find($empty->id));
    }

    public function test_the_status_board_lists_groups_by_status_with_filters(): void
    {
        $a = $this->makeProgram(['is_emergency' => true]);
        $b = $this->makeProgram();
        $this->makeGroup($a, ['status' => TrainingGroup::POSTPONED, 'is_emergency' => true, 'status_reason' => 'x']);
        $this->makeGroup($b, ['status' => TrainingGroup::ONGOING]);

        $board = $this->asUser($this->trainingHead())->getJson('/api/v1/admin/groups/board')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $board['columns']['postponed']['count']);
        $this->assertGreaterThanOrEqual(1, $board['columns']['ongoing']['count']);

        $emergency = $this->asUser($this->trainingHead())->getJson('/api/v1/admin/groups/board?emergency=1')->json('data');
        $this->assertSame(1, $emergency['columns']['postponed']['count']);
        $this->assertSame(0, $emergency['columns']['ongoing']['count']);
    }

    public function test_the_lifecycle_moves_groups_with_their_dates_and_flags_a_group_that_was_never_held(): void
    {
        $program = $this->makeProgram(['status' => Program::STATUS_REGISTRATION_OPEN]);
        $first = $program->groups()->first();
        $first->update(['status' => TrainingGroup::PLANNED, 'published_at' => now(), 'start_date' => today(), 'end_date' => today()->addDays(5)]);
        $held = $this->makeGroup($program, ['status' => TrainingGroup::ONGOING, 'start_date' => today()->subDays(5), 'end_date' => today()->subDay()]);
        $never = $this->makeGroup($program, ['status' => TrainingGroup::ONGOING, 'start_date' => today()->subDays(5), 'end_date' => today()->subDay()]);

        $heldSession = $this->makeSession($program, now()->subDays(3));
        $heldSession->update(['training_group_id' => $held->id]);
        $registration = Registration::create(['program_id' => $program->id, 'training_group_id' => $held->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        Attendance::create(['program_session_id' => $heldSession->id, 'registration_id' => $registration->id, 'employee_id' => $registration->employee_id, 'status' => 'present', 'method' => 'manual']);
        $neverSession = $this->makeSession($program, now()->subDays(3));
        $neverSession->update(['training_group_id' => $never->id]);

        $this->artisan('tedc:group-lifecycle')->assertSuccessful();

        $this->assertSame(TrainingGroup::ONGOING, $first->fresh()->status);
        $this->assertSame(TrainingGroup::COMPLETED, $held->fresh()->status);
        $this->assertSame(TrainingGroup::INCOMPLETE, $never->fresh()->status);
        $this->assertNotEmpty($never->fresh()->status_reason);
        $this->assertSame(Program::STATUS_IN_PROGRESS, $program->fresh()->status, 'the program follows its most advanced group');
    }
}
