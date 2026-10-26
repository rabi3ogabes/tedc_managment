<?php

namespace Tests\Feature;

use App\Models\CalendarDay;
use App\Models\CalendarWeeklyRule;
use App\Models\Role;
use App\Models\RoomBooking;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** سابر+ phase 3 (docs/saber/03-rooms-and-meetings.md): meeting bookings by the hour, recurrence, holidays, the rooms month. */
class SaberMeetingsTest extends TestCase
{
    private function meetingRoom(): TrainingRoom
    {
        return TrainingRoom::create(['code' => 'M1', 'name_ar' => 'قاعة الاجتماعات', 'name_en' => 'Meeting room', 'capacity' => 12, 'status' => 'active', 'room_type' => 'meeting', 'tag' => 'VIP']);
    }

    private function payload(TrainingRoom $room, array $extra = []): array
    {
        return $extra + ['room_id' => $room->id, 'title' => 'اجتماع تنسيقي', 'requested_by' => 'قسم التخطيط', 'reason' => 'متابعة الخطة', 'purpose' => 'meeting', 'date' => '2026-11-01', 'from_hour' => 9, 'to_hour' => 11];
    }

    public function test_a_meeting_is_booked_by_the_hour_and_an_overlapping_hour_is_refused(): void
    {
        $room = $this->meetingRoom();
        $officer = $this->makeUser(Role::LOGISTICS_OFFICER);

        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room))->assertCreated()->assertJsonPath('data.created', 1);
        $b = RoomBooking::first();
        $this->assertSame('قسم التخطيط', $b->requested_by);
        $this->assertSame('09:00', $b->starts_at->timezone(config('app.timezone'))->format('H:i'));

        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['from_hour' => 10, 'to_hour' => 12]))->assertUnprocessable()->assertJsonPath('code', 'hours_taken');
        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['from_hour' => 11, 'to_hour' => 13]))->assertCreated();
        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['from_hour' => 13, 'to_hour' => 15]))->assertUnprocessable();

        $slots = $this->asUser($officer)->getJson("/api/v1/admin/saber/room-slots?room_id={$room->id}&date=2026-11-01")->assertOk();
        $this->assertSame([false, false, true, true, true, true, false], collect($slots->json('data.slots'))->pluck('busy')->all());
        $this->assertTrue($slots->json('data.day.events_allowed'));
    }

    public function test_recurring_bookings_need_their_right_skip_nothing_and_are_cancelled_together(): void
    {
        $room = $this->meetingRoom();
        $officer = $this->makeUser(Role::LOGISTICS_OFFICER);   // holds saber.meeting.recurring
        $coordinator = $this->makeUser(Role::COORDINATOR);     // does not

        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['recurrence' => 'weekly', 'count' => 3]))->assertForbidden();
        $res = $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['recurrence' => 'weekly', 'count' => 3]))->assertCreated()->assertJsonPath('data.created', 3);

        $rows = RoomBooking::orderBy('starts_at')->get();
        $this->assertSame(['2026-11-01', '2026-11-08', '2026-11-15'], $rows->map(fn ($b) => $b->starts_at->timezone(config('app.timezone'))->toDateString())->all());
        $this->assertSame([$res->json('data.series_id')], $rows->pluck('series_id')->unique()->values()->all());

        $this->asUser($officer)->patchJson("/api/v1/admin/saber/meetings/{$rows[0]->id}", ['title' => 'اجتماع الفريق', 'series' => true])->assertOk();
        $this->assertSame(['اجتماع الفريق'], RoomBooking::pluck('title')->unique()->values()->all());
        $this->asUser($this->makeUser(Role::EMPLOYEE))->deleteJson("/api/v1/admin/saber/meetings/{$rows[0]->id}")->assertForbidden();
        $this->asUser($officer)->deleteJson("/api/v1/admin/saber/meetings/{$rows[1]->id}?series=1")->assertOk()->assertJsonPath('data.cancelled', 3);
        $this->assertSame(0, RoomBooking::where('status', 'confirmed')->count());
    }

    public function test_holidays_refuse_meetings_unless_they_allow_events(): void
    {
        $room = $this->meetingRoom();
        $officer = $this->makeUser(Role::LOGISTICS_OFFICER);

        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['date' => '2026-11-06']))->assertUnprocessable()->assertJsonPath('code', 'holiday');   // Friday
        CalendarDay::create(['date' => '2026-11-02', 'type' => 'vacation', 'title_ar' => 'إجازة رسمية', 'title_en' => 'Holiday']);
        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['date' => '2026-11-02']))->assertUnprocessable()->assertJsonPath('code', 'holiday');
        CalendarDay::whereDate('date', '2026-11-02')->update(['allow_events' => true]);
        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['date' => '2026-11-02']))->assertCreated();

        CalendarWeeklyRule::create(['weekday' => 3, 'title_ar' => 'الأربعاء', 'allow_events' => false]);
        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room, ['date' => '2026-10-28', 'recurrence' => 'daily', 'count' => 2]))->assertUnprocessable()->assertJsonPath('details.dates.0', '2026-10-28');
    }

    public function test_the_rooms_month_has_every_room_the_calendar_and_what_holds_each_room(): void
    {
        $room = $this->meetingRoom();
        $training = TrainingRoom::create(['code' => 'T1', 'name_ar' => 'قاعة التدريب', 'name_en' => 'Training room', 'capacity' => 30, 'status' => 'active']);
        $program = $this->makeProgram();
        $tz = config('app.timezone');
        $program->sessions()->create(['training_group_id' => $program->groups()->first()->id, 'title_ar' => 'اليوم 1', 'title_en' => 'Day 1', 'mode' => 'in_person', 'sequence' => 1, 'training_room_id' => $training->id,
            'starts_at' => CarbonImmutable::parse('2026-11-03 08:00', $tz), 'ends_at' => CarbonImmutable::parse('2026-11-03 13:00', $tz)]);
        $officer = $this->makeUser(Role::LOGISTICS_OFFICER);
        $this->asUser($officer)->postJson('/api/v1/admin/saber/meetings', $this->payload($room))->assertCreated();

        $res = $this->asUser($officer)->getJson('/api/v1/admin/saber/rooms-month?month=2026-11')->assertOk();
        $this->assertCount(30, $res->json('data.days'));
        $this->assertFalse(collect($res->json('data.days'))->firstWhere('date', '2026-11-06')['events_allowed']);
        $order = collect($res->json('data.rooms'))->pluck('id');
        $this->assertLessThan($order->search($room->id), $order->search($training->id), 'training rooms first, meeting rooms after');
        $types = collect($res->json('data.events'))->pluck('type', 'room_id');
        $this->assertSame('session', $types[$training->id]);
        $this->assertSame('booking', $types[$room->id]);
        $this->assertSame('قسم التخطيط', collect($res->json('data.events'))->firstWhere('type', 'booking')['requested_by']);
    }

    public function test_rooms_keep_a_type_and_a_tag(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/rooms', ['name_ar' => 'قاعة مجلس', 'name_en' => 'Board room', 'capacity' => 10, 'room_type' => 'meeting', 'tag' => 'VIP'])->assertCreated()->json('data.id');
        $this->asUser($admin)->getJson("/api/v1/admin/rooms/{$id}")->assertOk()->assertJsonPath('data.room_type', 'meeting')->assertJsonPath('data.tag', 'VIP');
        $this->asUser($admin)->postJson('/api/v1/admin/rooms', ['name_ar' => 'س', 'name_en' => 'x', 'capacity' => 10, 'room_type' => 'hall'])->assertUnprocessable();
    }
}
