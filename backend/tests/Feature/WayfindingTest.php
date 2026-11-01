<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TrainingRoom;
use App\Services\Wayfinding;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The centre's wayfinding map (docs/wayfinding.md): what visitors receive, how rooms find their place, what administrators manage. */
class WayfindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TrainingRoom::query()->delete();
        Cache::flush();
    }

    private function room(string $ar, ?string $en = null, string $status = 'active'): TrainingRoom
    {
        return TrainingRoom::create(['name_ar' => $ar, 'name_en' => $en ?? $ar, 'code' => 'R-'.mt_rand(1000, 9999), 'capacity' => 30, 'status' => $status, 'room_type' => 'training', 'latitude' => 25.3, 'longitude' => 51.5]);
    }

    public function test_visitors_get_the_plans_and_rooms_find_their_place_by_name(): void
    {
        $r14 = $this->room('القاعة ١٤');
        $lab = $this->room('معمل الحاسب 2', 'Computer Lab 2');
        $lib = $this->room('المكتبة');
        $meeting = $this->room('قاعة اجتماعات 3');
        $this->room('قاعة 9', status: 'inactive');

        $data = $this->getJson('/api/v1/public/wayfinding')->assertOk()->json('data');
        $this->assertCount(2, $data['map']['floors']);
        $this->assertNotEmpty($data['map']['floors'][0]['grid']);
        $this->assertCount(2, $data['entrances']);
        $rooms = collect($data['rooms'])->keyBy('id');
        $this->assertSame('f-room-14', $rooms[$r14->id]['place']);
        $this->assertSame('first', $rooms[$r14->id]['floor']);
        $this->assertTrue($rooms[$r14->id]['auto']);
        $this->assertSame('f-computer-lab-2', $rooms[$lab->id]['place']);
        $this->assertSame('g-library', $rooms[$lib->id]['place']);
        $this->assertNull($rooms[$meeting->id]['place']);      // "meeting room 3" is not training room 3
        $this->assertCount(4, $rooms);                          // the inactive room is not offered to visitors
        $this->assertSame(['lat' => 25.3, 'lng' => 51.5], $data['centre']);
    }

    public function test_two_rooms_claiming_one_place_leave_it_to_the_administrator(): void
    {
        $this->room('قاعة 5');
        $this->room('Training Room 5');
        $this->assertSame([], app(Wayfinding::class)->autoMatches());
    }

    public function test_administrators_place_rooms_move_doors_hide_rooms_and_manage_entrances_and_signs(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $theatre = $this->room('المسرح');
        $r5 = $this->room('قاعة 5');

        $this->asUser($admin)->putJson("/api/v1/admin/rooms/{$theatre->id}/map", ['place' => 'g-room-5', 'door' => [700.4, 90]])->assertOk();
        $rooms = collect($this->asUser($admin)->getJson('/api/v1/admin/rooms/map')->assertOk()->json('data.rooms'))->keyBy('id');
        $this->assertSame('g-room-5', $rooms[$theatre->id]['place']);
        $this->assertSame([700, 90], $rooms[$theatre->id]['door']);
        $this->assertFalse($rooms[$theatre->id]['auto']);
        $this->assertNull($rooms[$r5->id]['place']);            // the place is taken: the name match steps aside

        $this->asUser($admin)->putJson("/api/v1/admin/rooms/{$r5->id}/map", ['hidden' => true])->assertOk();
        Cache::flush();
        $this->assertNotContains($r5->id, collect($this->getJson('/api/v1/public/wayfinding')->json('data.rooms'))->pluck('id'));

        $this->asUser($admin)->putJson("/api/v1/admin/rooms/{$theatre->id}/map", ['place' => 'x-nowhere'])->assertStatus(422);

        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map', [
            'entrances' => [['floor' => 'ground', 'ar' => 'المدخل الرئيسي', 'en' => 'Main entrance', 'at' => [462, 788]]],
            'signs' => [['id' => 'sign-lifts', 'floor' => 'first', 'ar' => 'أمام المصاعد', 'at' => [630, 470]]],
            'centre' => ['lat' => 25.2854, 'lng' => 51.531],
        ])->assertOk()->assertJsonPath('data.entrances.0.ar', 'المدخل الرئيسي')->assertJsonPath('data.signs.0.id', 'sign-lifts');
        Cache::flush();
        $this->getJson('/api/v1/public/wayfinding')->assertJsonPath('data.centre.lat', 25.2854)->assertJsonCount(1, 'data.entrances');

        $this->asUser($admin)->putJson("/api/v1/admin/rooms/{$theatre->id}/map", ['reset' => true])->assertOk();
        $this->assertNull(collect($this->asUser($admin)->getJson('/api/v1/admin/rooms/map')->json('data.rooms'))->firstWhere('id', $theatre->id)['place']);
    }

    public function test_only_room_managers_edit_the_map(): void
    {
        $room = $this->room('قاعة 1');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson("/api/v1/admin/rooms/{$room->id}/map", ['place' => 'g-room-1'])->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/rooms/map')->assertForbidden();
    }
}
