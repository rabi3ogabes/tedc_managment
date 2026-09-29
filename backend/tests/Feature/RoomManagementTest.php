<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RoomManagementTest extends TestCase
{
    private function room(array $extra = []): TrainingRoom
    {
        return TrainingRoom::create($extra + [
            'name_ar' => 'قاعة', 'name_en' => 'Hall', 'capacity' => 40, 'layouts' => ['classroom' => 40, 'u_shape' => 24],
            'facilities' => [['key' => 'projector', 'qty' => 1], ['key' => 'markers', 'qty' => 6]],
        ]);
    }

    public function test_admin_manages_a_room_with_location_layouts_and_equipment(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $id = $this->asUser($admin)->postJson('/api/v1/admin/rooms', [
            'name_ar' => 'قاعة الاختبار', 'name_en' => 'Test Hall', 'code' => 'R-9', 'office' => 'Main office', 'building' => 'Tower A', 'floor' => '3',
            'location' => 'Next to the lift', 'layouts' => ['theatre' => 80, 'u_shape' => 30], 'layout' => 'theatre',
            'facilities' => [['key' => 'projector', 'qty' => 1], ['key' => 'markers', 'qty' => 10], ['key' => 'markers', 'qty' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.capacity', 80)
            ->assertJsonPath('data.floor', '3')
            ->assertJsonPath('data.equipment.1.qty', 12)
            ->assertJsonPath('data.equipment.0.label', 'جهاز عرض (داتا شو)')
            ->json('data.id');

        $this->asUser($admin)->postJson('/api/v1/admin/rooms', ['name_ar' => 'x', 'name_en' => 'x', 'facilities' => [['key' => 'jetpack']]])->assertUnprocessable();
        $this->asUser($admin)->postJson('/api/v1/admin/rooms', ['name_ar' => 'x', 'name_en' => 'x', 'layouts' => ['bogus' => 4]])->assertUnprocessable();
        $this->asUser($admin)->postJson('/api/v1/admin/rooms', ['name_ar' => 'y', 'name_en' => 'y', 'code' => 'R-9'])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->asUser($admin)->putJson("/api/v1/admin/rooms/$id", ['floor' => '4', 'status' => 'maintenance'])->assertOk()->assertJsonPath('data.status', 'maintenance');
        $this->asUser($admin)->getJson('/api/v1/admin/rooms?office=Main office&layout=theatre&min_capacity=50')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($admin)->getJson('/api/v1/admin/rooms?min_capacity=500')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($admin)->getJson('/api/v1/admin/rooms/options')->assertOk()->assertJsonPath('data.offices.0', 'Main office');

        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson('/api/v1/admin/rooms', ['name_ar' => 'z', 'name_en' => 'z'])->assertCreated();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/rooms')->assertForbidden();
    }

    public function test_sessions_cannot_double_book_or_use_an_unavailable_room(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room();
        $program = $this->makeProgram();
        $url = "/api/v1/admin/programs/{$program->id}/sessions";
        $payload = fn (string $start, string $end, array $x = []) => $x + ['title_ar' => 'ج', 'title_en' => 'S', 'starts_at' => $start, 'ends_at' => $end, 'training_room_id' => $room->id];

        $first = $this->asUser($admin)->postJson($url, $payload('2027-03-07 09:00', '2027-03-07 11:00'))->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson($url, $payload('2027-03-07 10:00', '2027-03-07 12:00'))->assertUnprocessable()->assertJsonPath('code', 'room_conflict')->assertJsonPath('details.sessions.0.id', $first);
        // Back-to-back is fine.
        $second = $this->asUser($admin)->postJson($url, $payload('2027-03-07 11:00', '2027-03-07 13:00'))->assertCreated()->json('data.id');
        // Moving a session onto a taken slot is rejected; keeping its own slot is not.
        $this->asUser($admin)->putJson("/api/v1/admin/sessions/$second", ['starts_at' => '2027-03-07 10:30', 'ends_at' => '2027-03-07 12:30'])->assertUnprocessable()->assertJsonPath('code', 'room_conflict');
        $this->asUser($admin)->putJson("/api/v1/admin/sessions/$second", ['starts_at' => '2027-03-07 11:00', 'ends_at' => '2027-03-07 13:30'])->assertOk();
        // Cancelled sessions free the room.
        $this->asUser($admin)->putJson("/api/v1/admin/sessions/$first", ['status' => 'cancelled'])->assertOk();
        $this->asUser($admin)->postJson($url, $payload('2027-03-07 09:00', '2027-03-07 10:30'))->assertCreated();

        $room->update(['status' => 'maintenance']);
        $this->asUser($admin)->postJson($url, $payload('2027-03-08 09:00', '2027-03-08 10:00'))->assertUnprocessable()->assertJsonPath('code', 'room_unavailable');
    }

    public function test_availability_ranks_rooms_by_fit_and_flags_conflicts(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        TrainingRoom::query()->delete(); // drop the seeded reference rooms
        $small = $this->room(['name_ar' => 'صغيرة', 'name_en' => 'Small', 'capacity' => 20, 'layouts' => ['classroom' => 20]]);
        $fit = $this->room(['name_ar' => 'مناسبة', 'name_en' => 'Fit', 'capacity' => 40]);
        $huge = $this->room(['name_ar' => 'ضخمة', 'name_en' => 'Huge', 'capacity' => 400, 'layouts' => ['classroom' => 400], 'facilities' => [['key' => 'projector', 'qty' => 1]]]);
        $busy = $this->room(['name_ar' => 'مشغولة', 'name_en' => 'Busy', 'capacity' => 40]);
        $this->room(['name_ar' => 'صيانة', 'name_en' => 'Down', 'capacity' => 40, 'status' => 'maintenance']);
        $program = $this->makeProgram();
        $this->makeSession($program, CarbonImmutable::parse('2027-03-07 09:00'))->update(['training_room_id' => $busy->id]);

        $res = $this->asUser($admin)->getJson('/api/v1/admin/rooms/availability?'.http_build_query([
            'starts_at' => '2027-03-07 10:00', 'ends_at' => '2027-03-07 12:00', 'capacity' => 30, 'layout' => 'classroom', 'equipment' => ['projector', 'markers'],
        ]))->assertOk();

        $rows = collect($res->json('data'));
        $this->assertCount(4, $rows, 'maintenance rooms are not offered');
        $this->assertSame($fit->id, $rows[0]['room']['id'], 'best fit first');
        $this->assertSame($huge->id, $rows[1]['room']['id']);
        $this->assertSame($small->id, $rows[2]['room']['id'], 'too small ranks after rooms that fit');
        $this->assertFalse($rows[2]['fits_capacity']);
        $this->assertSame($busy->id, $rows[3]['room']['id'], 'booked rooms come last');
        $this->assertFalse($rows[3]['available']);
        $this->assertCount(1, $rows[3]['conflicts']);
        $this->assertSame(['markers'], $rows[1]['missing_equipment']);

        $this->asUser($admin)->getJson("/api/v1/admin/rooms/{$busy->id}/schedule?from=2027-03-01&to=2027-03-31")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_room_with_history_is_deactivated_instead_of_deleted(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $used = $this->room();
        $unused = $this->room(['name_en' => 'Spare']);
        $this->makeSession($this->makeProgram(), CarbonImmutable::parse('2027-03-07 09:00'))->update(['training_room_id' => $used->id]);

        $this->asUser($admin)->deleteJson("/api/v1/admin/rooms/{$used->id}")->assertOk()->assertJsonPath('meta.deactivated', true);
        $this->assertSame('inactive', $used->fresh()->status);
        $this->asUser($admin)->deleteJson("/api/v1/admin/rooms/{$unused->id}")->assertNoContent();
        $this->assertNull(TrainingRoom::find($unused->id));
    }
}
