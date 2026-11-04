<?php

namespace Tests\Feature;

use App\Models\Registration;
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

    public function test_an_administrator_sets_the_main_door_of_a_place_and_puts_people_on_an_office(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $user = $this->makeUser(Role::EMPLOYEE, ['name' => 'Khalid Al-Marri', 'name_ar' => 'خالد المري']);
        $r14 = $this->room('قاعة 14');

        // The main door of an office, and two people on the west offices: one picked from the platform, one typed.
        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map/places/f-offices-west', [
            'door' => [260.6, 700],
            'people' => [
                ['name_ar' => 'خالد المري', 'name_en' => 'Khalid Al-Marri', 'title_ar' => 'منسق التدريب', 'desk' => 'مكتب 12', 'user_id' => $user->id],
                ['name_ar' => '  <b>سارة</b> الهاجري ', 'title_en' => 'Planning'],
                ['name_ar' => '', 'name_en' => ''],            // nobody: ignored
            ],
        ])->assertOk()->assertJsonPath('data.places.f-offices-west.door', [261, 700])->assertJsonCount(2, 'data.places.f-offices-west.people');

        Cache::flush();
        $public = $this->getJson('/api/v1/public/wayfinding')->assertOk()->json('data');
        $this->assertSame([261, 700], $public['places']['f-offices-west']['door']);
        $this->assertSame('مكتب 12', $public['places']['f-offices-west']['people'][0]['desk']);
        $this->assertSame('سارة الهاجري', $public['places']['f-offices-west']['people'][1]['name_ar']);   // tags stripped
        $this->assertArrayNotHasKey('user_id', $public['places']['f-offices-west']['people'][0]);        // visitors never see the account link
        $this->assertSame($user->id, $this->asUser($admin)->getJson('/api/v1/admin/rooms/map')->json('data.places.f-offices-west.people.0.user_id'));

        // The centre can keep the names from visitors; the administrator still sees them, and the door stays.
        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map', ['show_people' => false])->assertOk()->assertJsonPath('data.show_people', false);
        Cache::flush();
        $hidden = $this->getJson('/api/v1/public/wayfinding')->json('data');
        $this->assertSame([], $hidden['places']['f-offices-west']['people']);
        $this->assertSame([261, 700], $hidden['places']['f-offices-west']['door']);
        $this->assertCount(2, $this->asUser($admin)->getJson('/api/v1/admin/rooms/map')->json('data.places.f-offices-west.people'));

        // A room takes its place's main door, unless it has a door of its own.
        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map/places/f-room-14', ['door' => [800, 500]])->assertOk();
        $rooms = fn () => collect($this->asUser($admin)->getJson('/api/v1/admin/rooms/map')->json('data.rooms'))->keyBy('id');
        $this->assertSame([800, 500], $rooms()[$r14->id]['door']);
        $this->asUser($admin)->putJson("/api/v1/admin/rooms/{$r14->id}/map", ['door' => [810, 510]])->assertOk();
        $this->assertSame([810, 510], $rooms()[$r14->id]['door']);

        // Nobody and no door of its own leaves nothing behind; an unknown place is refused.
        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map/places/f-offices-west', ['door' => null, 'people' => []])->assertOk()->assertJsonMissingPath('data.places.f-offices-west');
        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map/places/x-nowhere', ['door' => [1, 1]])->assertNotFound();
        $this->asUser($admin)->putJson('/api/v1/admin/rooms/map/places/f-offices-west', ['door' => [1, 2, 3]])->assertStatus(422);
    }

    public function test_people_are_found_by_name_for_an_office_and_only_by_room_managers(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $user = $this->makeUser(Role::EMPLOYEE, ['name' => 'Mona Al-Naimi', 'name_ar' => 'منى النعيمي']);
        $this->makeEmployee([], $user);

        $found = $this->asUser($admin)->getJson('/api/v1/admin/rooms/map/people?q='.urlencode('منى'))->assertOk()->json('data');
        $this->assertSame($user->id, $found[0]['user_id']);
        $this->assertSame('Mona Al-Naimi', $found[0]['name_en']);
        $this->assertNotEmpty($found[0]['title_ar']);                       // the job title comes with the person
        $this->asUser($admin)->getJson('/api/v1/admin/rooms/map/people?q=m')->assertOk()->assertExactJson(['data' => []]);   // too short to search
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/rooms/map/people?q=mona')->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson('/api/v1/admin/rooms/map/places/f-offices-west', ['door' => [1, 2]])->assertForbidden();
    }

    public function test_a_session_finds_its_place_from_its_room_or_from_the_room_written_as_text(): void
    {
        $wayfinding = app(Wayfinding::class);
        $room = $this->room('القاعة التدريبية 3');
        $notHere = $this->room('قاعة 5');
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->putJson("/api/v1/admin/rooms/{$notHere->id}/map", ['place' => null])->assertOk();

        $this->assertSame('g-room-3', $wayfinding->placeOf($room->id, null));
        $this->assertNull($wayfinding->placeOf($notHere->id, 'قاعة 5'));          // marked "not at the centre": the name does not matter
        $this->assertSame('g-room-3', $wayfinding->placeOf(null, 'القاعة التدريبية 3'));
        $this->assertSame('g-room-3', $wayfinding->placeOf(null, 'Training Hall 3'));
        $this->assertSame('g-library', $wayfinding->placeOf(null, 'المكتبة'));
        $this->assertNull($wayfinding->placeOf(null, 'فندق الريتز'));
        $this->assertNull($wayfinding->placeOf(null, null));

        // The trainee's session page carries it, even when the session names the room only in words.
        $employee = $this->makeEmployee();
        $program = $this->makeProgram();
        Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $session = $this->makeSession($program, now()->addDay());
        $session->update(['location_text' => 'القاعة التدريبية 3', 'training_room_id' => null]);
        $this->asUser($employee->user)->getJson("/api/v1/me/sessions/{$session->id}")->assertOk()->assertJsonPath('data.map_place', 'g-room-3')->assertJsonPath('data.room_id', null);
    }

    public function test_only_room_managers_edit_the_map(): void
    {
        $room = $this->room('قاعة 1');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson("/api/v1/admin/rooms/{$room->id}/map", ['place' => 'g-room-1'])->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/rooms/map')->assertForbidden();
    }
}
