<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\AppNotification;
use App\Models\Building;
use App\Models\LogisticsRequest;
use App\Models\Registration;
use App\Models\Role;
use App\Models\RoomBooking;
use App\Models\TrainingPlace;
use App\Models\TrainingRoom;
use App\Services\RegistrationService;
use App\Services\RoomService;
use Carbon\Carbon;
use Tests\TestCase;

class RoomsLogisticsTest extends TestCase
{
    private function room(int $capacity = 30, array $extra = []): TrainingRoom
    {
        return TrainingRoom::create($extra + ['code' => 'R-'.uniqid(), 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => $capacity, 'status' => 'active']);
    }

    public function test_places_and_buildings_form_a_hierarchy_that_limits_room_capacity(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $place = $this->asUser($head)->postJson('/api/v1/admin/places', ['name_ar' => 'المبنى الرئيسي', 'name_en' => 'Main campus', 'capacity_limit' => 100])->assertCreated()->json('data.id');
        $building = $this->asUser($head)->postJson('/api/v1/admin/buildings', ['place_id' => $place, 'name_ar' => 'أ', 'name_en' => 'A', 'capacity_limit' => 40])->assertCreated()->json('data.id');
        $room = $this->room(80, ['place_id' => $place, 'building_id' => $building]);

        $this->assertSame(40, app(RoomService::class)->effectiveCapacity($room->fresh()));
        $this->asUser($head)->getJson('/api/v1/admin/places')->assertOk()->assertJsonPath('data.0.buildings.0.name_en', 'A');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/places', ['name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();
        $this->assertNotNull(Building::find($building));
        $this->assertNotNull(TrainingPlace::find($place));
    }

    public function test_a_non_training_booking_conflicts_with_sessions_and_other_bookings_and_shows_who_holds_the_room(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $room = $this->room();
        $program = $this->makeProgram(['coordinator_id' => $coordinator->id]);
        $session = $this->makeSession($program, now()->addDays(2)->setTime(9, 0), 2);
        $session->update(['training_room_id' => $room->id]);
        $booker = $this->makeUser(Role::LOGISTICS_OFFICER);

        $res = $this->asUser($booker)->postJson('/api/v1/admin/room-bookings', ['room_id' => $room->id, 'purpose' => 'meeting', 'title' => 'Team meeting', 'starts_at' => $session->starts_at->copy()->addHour()->toIso8601String(), 'ends_at' => $session->starts_at->copy()->addHours(3)->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'room_conflict');
        $this->assertSame('session', $res->json('details.occupants.0.type'));

        $ok = $this->asUser($booker)->postJson('/api/v1/admin/room-bookings', ['room_id' => $room->id, 'purpose' => 'meeting', 'title' => 'Team meeting', 'starts_at' => $session->ends_at->copy()->addDay()->addHour()->toIso8601String(), 'ends_at' => $session->ends_at->copy()->addDay()->addHours(2)->toIso8601String(), 'attendees' => 10])->assertCreated();
        $clash = $this->asUser($booker)->postJson('/api/v1/admin/room-bookings', ['room_id' => $room->id, 'purpose' => 'event', 'title' => 'Open day', 'starts_at' => $session->ends_at->copy()->addDay()->addHours(1)->addMinutes(30)->toIso8601String(), 'ends_at' => $session->ends_at->copy()->addDay()->addHours(3)->toIso8601String()])->assertStatus(422);
        $this->assertSame('booking', $clash->json('details.occupants.0.type'));
        $this->assertSame('Team meeting', $clash->json('details.occupants.0.title'));

        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/room-bookings', ['room_id' => $room->id, 'purpose' => 'meeting', 'title' => 'x', 'starts_at' => now()->addDays(9)->toIso8601String(), 'ends_at' => now()->addDays(9)->addHour()->toIso8601String()])->assertForbidden();
        $this->asUser($booker)->deleteJson('/api/v1/admin/room-bookings/'.$ok->json('data.id'))->assertOk();
        $this->assertSame('cancelled', RoomBooking::find($ok->json('data.id'))->status);
    }

    public function test_a_session_cannot_take_a_room_that_a_booking_holds_and_capacity_is_checked(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $room = $this->room(5);
        $this->asUser($this->makeUser(Role::LOGISTICS_OFFICER))->postJson('/api/v1/admin/room-bookings', ['room_id' => $room->id, 'purpose' => 'exam', 'title' => 'Exam', 'starts_at' => now()->next(Carbon::MONDAY)->setTime(9, 0)->toIso8601String(), 'ends_at' => now()->next(Carbon::MONDAY)->setTime(12, 0)->toIso8601String()])->assertCreated();
        $program = $this->makeProgram(['capacity' => 20]);

        $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/sessions", ['title_ar' => 'ج', 'title_en' => 'S', 'starts_at' => now()->next(Carbon::MONDAY)->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->next(Carbon::MONDAY)->setTime(11, 0)->toIso8601String(), 'training_room_id' => $room->id])->assertStatus(422)->assertJsonPath('code', 'room_conflict');
    }

    public function test_approving_beyond_the_effective_room_capacity_is_refused(): void
    {
        $room = $this->room(2);
        $program = $this->makeProgram(['capacity' => 10]);
        $group = $program->groups()->first();
        $group->update(['default_room_id' => $room->id]);
        $svc = app(RegistrationService::class);
        $regs = [];
        foreach (range(1, 3) as $i) {
            $regs[] = $svc->register($program->fresh(), $this->makeEmployee(), Registration::SOURCE_SELF);
        }
        $svc->transition($regs[0], Registration::STATUS_APPROVED, null, null, 'ok');
        $svc->transition($regs[1], Registration::STATUS_APPROVED, null, null, 'ok');

        try {
            $svc->transition($regs[2], Registration::STATUS_APPROVED);
            $this->fail('expected room_capacity');
        } catch (BusinessRuleException $e) {
            $this->assertSame('room_capacity', $e->errorCode);
        }
    }

    public function test_the_seating_plan_is_designed_validated_and_auto_assigned(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $room = $this->room(20);
        $program = $this->makeProgram();
        $session = $this->makeSession($program, now()->addDays(2), 2);
        $session->update(['training_room_id' => $room->id]);
        $names = ['Zed', 'Amy', 'Bob'];
        foreach ($names as $n) {
            $user = $this->makeUser(Role::EMPLOYEE, ['name' => $n, 'name_ar' => $n]);
            Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee([], $user)->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        }

        $this->asUser($head)->putJson("/api/v1/admin/rooms/{$room->id}/seating", ['session_id' => $session->id, 'layout' => ['rows' => 2, 'cols' => 3, 'blocked' => ['0,0']]])->assertOk();
        $auto = $this->asUser($head)->postJson("/api/v1/admin/rooms/{$room->id}/seating/auto", ['session_id' => $session->id, 'mode' => 'alphabetical'])->assertOk();
        $assign = $auto->json('data.assignments');
        $this->assertArrayNotHasKey('0,0', $assign);
        $this->assertSame(3, count($assign));
        $first = $this->asUser($head)->getJson("/api/v1/admin/rooms/{$room->id}/seating?session={$session->id}")->assertOk()->json('data');
        $this->assertSame('Amy', $first['seats']['0,1']['name']);

        $this->asUser($head)->putJson("/api/v1/admin/rooms/{$room->id}/seating", ['session_id' => $session->id, 'layout' => ['rows' => 1, 'cols' => 2, 'blocked' => []]])->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/rooms/{$room->id}/seating/auto", ['session_id' => $session->id, 'mode' => 'random'])->assertStatus(422)->assertJsonPath('code', 'seats_insufficient');
    }

    public function test_logistics_requests_reach_the_team_are_tracked_and_escalate_when_overdue(): void
    {
        $officer = $this->makeUser(Role::LOGISTICS_OFFICER);
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $program = $this->makeProgram();
        $session = $this->makeSession($program, now()->addDays(2), 2);

        $res = $this->asUser($coordinator)->postJson('/api/v1/admin/logistics-requests', ['session_id' => $session->id, 'items' => [['type' => 'catering', 'qty' => 30], ['type' => 'printing', 'qty' => 30]], 'needed_by' => now()->addDay()->toDateTimeString(), 'notes' => 'Coffee break'])->assertCreated();
        $id = $res->json('data.id');
        $this->assertTrue(AppNotification::where('user_id', $officer->id)->where('type', 'logistics.request_new')->exists());
        $this->asUser($officer)->getJson('/api/v1/admin/logistics-requests?status=new')->assertOk()->assertJsonCount(1, 'data');

        $this->asUser($officer)->putJson("/api/v1/admin/logistics-requests/{$id}", ['status' => 'in_progress', 'assignee_id' => $officer->id, 'comment' => 'On it'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $coordinator->id)->where('type', 'logistics.request_updated')->exists());
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson("/api/v1/admin/logistics-requests/{$id}", ['status' => 'done'])->assertForbidden();

        LogisticsRequest::whereKey($id)->update(['needed_by' => now()->subHour()]);
        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $this->artisan('tedc:operations-hourly')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $officer->id)->where('type', 'logistics.request_overdue')->count());

        $this->asUser($officer)->putJson("/api/v1/admin/logistics-requests/{$id}", ['status' => 'done'])->assertOk();
        $this->assertNotNull(LogisticsRequest::find($id)->completed_at);
    }
}
