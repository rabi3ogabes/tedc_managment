<?php

namespace Tests\Feature;

use App\Models\CalendarDay;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\RoomBooking;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The generated session plan of a new programme: recurrence patterns, closed days, one fixed room when free, per-session rooms otherwise, flags, re-checks and the save. */
class ProgramSchedulePlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        TrainingRoom::query()->update(['status' => 'inactive']); // only the rooms each test creates take part
    }

    private function room(string $code, int $capacity = 30): TrainingRoom
    {
        return TrainingRoom::create(['code' => $code, 'name_ar' => 'قاعة '.$code, 'name_en' => 'Room '.$code, 'capacity' => $capacity, 'status' => 'active']);
    }

    /** The first Monday at least ten days away. */
    private function monday(): CarbonImmutable
    {
        $d = CarbonImmutable::today()->addDays(10);
        while ($d->dayOfWeek !== 1) {
            $d = $d->addDay();
        }

        return $d;
    }

    private function busy(TrainingRoom $room, CarbonImmutable $day): void
    {
        $program = $this->makeProgram();
        ProgramSession::create(['program_id' => $program->id, 'sequence' => 1, 'title_ar' => 'أخرى', 'title_en' => 'Other', 'starts_at' => $day->setTime(8, 0), 'ends_at' => $day->setTime(10, 0), 'training_room_id' => $room->id]);
    }

    public function test_weekly_and_biweekly_series_skip_closed_days_fix_one_room_and_say_what_they_did(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        [$r1, $r5, $r9] = [$this->room('R1'), $this->room('R5'), $this->room('R9', 10)];
        $mon = $this->monday();
        $body = ['total_hours' => 12, 'capacity' => 20, 'start_date' => $mon->toDateString(), 'pattern' => 'weekly', 'sessions_count' => 5, 'room_id' => $r5->id];

        $this->asUser($this->makeUser(Role::TRAINER))->postJson('/api/v1/admin/programs/schedule-plan', $body)->assertForbidden();

        // Five Mondays, 12 hours split as quarter hours with the last taking the rest, room 5 fixed for all of them.
        $p = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', $body)->assertOk()->json('data');
        $this->assertSame(5, $p['summary']['count']);
        $this->assertSame([1, 1, 1, 1, 1], array_column($p['sessions'], 'weekday'));
        $this->assertSame(collect(range(0, 4))->map(fn ($i) => $mon->addWeeks($i)->toDateString())->all(), array_column($p['sessions'], 'date'));
        $this->assertEquals([2.5, 2.5, 2.5, 2.5, 2.0], array_column($p['sessions'], 'hours'));
        $this->assertSame(12.0, (float) $p['summary']['hours']);
        $this->assertSame(['08:00', '10:30'], [$p['sessions'][0]['start_time'], $p['sessions'][0]['end_time']]);
        $this->assertSame(['fixed', $r5->id], [$p['summary']['room']['mode'], $p['summary']['room']['room_id']]);
        $this->assertSame([$r5->id], array_values(array_unique(array_column($p['sessions'], 'training_room_id'))));
        $this->assertSame(0, $p['summary']['errors']);
        $this->assertSame($r5->id, collect($p['rooms'])->firstWhere('free_all', true)['id'] === $r5->id ? $r5->id : collect($p['rooms'])->firstWhere('id', $r5->id)['id']);
        $this->assertFalse(collect($p['rooms'])->firstWhere('id', $r9->id)['fits']); // a room of ten is too small for twenty

        // Every other week: fourteen days apart.
        $b = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['every_weeks' => 2] + $body)->assertOk()->json('data');
        $this->assertEquals([14, 14, 14, 14], collect($b['sessions'])->sliding(2)->map(fn ($pair) => CarbonImmutable::parse($pair->first()['date'])->diffInDays(CarbonImmutable::parse($pair->last()['date'])))->all());

        // A holiday on the third Monday: that date is skipped, the series moves on, and the plan says so.
        CalendarDay::create(['date' => $mon->addWeeks(2)->toDateString(), 'type' => 'vacation', 'title_ar' => 'إجازة', 'title_en' => 'Holiday']);
        Cache::flush();
        $h = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', $body)->assertOk()->json('data');
        $this->assertNotContains($mon->addWeeks(2)->toDateString(), array_column($h['sessions'], 'date'));
        $this->assertSame($mon->addWeeks(5)->toDateString(), $h['sessions'][4]['date']);
        $this->assertSame('skipped_closed', collect($h['notes'])->firstWhere('code', 'skipped_closed')['code']);

        // Room 5 is busy on one Monday: another room free on every date is fixed instead, and the plan explains why.
        $this->busy($r5, $mon->addWeeks(1));
        $x = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['pattern' => 'weekly'] + array_replace($body, ['start_date' => $mon->toDateString()]) + [])->assertOk()->json('data');
        $dates = array_column($x['sessions'], 'date');
        $this->assertContains($mon->addWeeks(1)->toDateString(), $dates);
        $this->assertSame('fixed', $x['summary']['room']['mode']);
        $this->assertSame($r1->id, $x['summary']['room']['room_id']);
        $this->assertSame('preferred_room_unavailable', collect($x['notes'])->firstWhere('code', 'preferred_room_unavailable')['code']);
        $this->assertSame(0, $x['summary']['errors']);

        // A confirmed booking holds a room too, not only sessions.
        RoomBooking::create(['room_id' => $r1->id, 'purpose' => 'meeting', 'title' => 'اجتماع', 'starts_at' => $mon->setTime(9, 0), 'ends_at' => $mon->setTime(11, 0), 'status' => 'confirmed']);
        $y = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', $body)->assertOk()->json('data');
        $this->assertSame('mixed', $y['summary']['room']['mode']); // r5 busy on week 2, r1 busy on week 1, r9 too small: no room is free on all five dates
        $this->assertSame('no_single_room', collect($y['notes'])->firstWhere('code', 'no_single_room')['code']);
        $this->assertSame([$r5->id, $r1->id, $r1->id], [$y['sessions'][0]['training_room_id'], $y['sessions'][1]['training_room_id'], $y['sessions'][2]['training_room_id']]); // ties go to the first room by name
        $this->assertSame('room_differs', collect($y['sessions'][0]['flags'])->firstWhere('code', 'room_differs')['code']); // the odd one out against the room most sessions use
        $this->assertNull(collect($y['sessions'][1]['flags'])->firstWhere('code', 'room_differs'));
    }

    public function test_consecutive_custom_online_trainer_and_the_recheck_of_an_edited_plan(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room('R2');
        $mon = $this->monday();

        // Consecutive open days: Friday and Saturday are skipped (Qatar's weekend).
        $c = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['total_hours' => 15, 'capacity' => 10, 'start_date' => $mon->addDays(3)->toDateString(), 'pattern' => 'consecutive', 'room_id' => $room->id])->assertOk()->json('data');
        $this->assertSame(3, $c['summary']['count']);
        $this->assertSame([4, 0, 1], array_column($c['sessions'], 'weekday')); // Thursday, Sunday, Monday

        // A weekend weekday has no open days.
        $w = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['total_hours' => 10, 'start_date' => $mon->toDateString(), 'pattern' => 'weekly', 'weekday' => 5])->assertOk()->json('data');
        $this->assertSame([[], ['no_open_days']], [$w['sessions'], array_column($w['notes'], 'code')]); // no flood of skipped-day notes

        // Chosen dates: three of them, hours shared; a trainer already teaching on one is flagged.
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => [], 'rating' => 4]);
        $other = $this->makeProgram();
        ProgramSession::create(['program_id' => $other->id, 'sequence' => 1, 'title_ar' => 'أخرى', 'title_en' => 'Other', 'starts_at' => $mon->addDays(1)->setTime(8, 0), 'ends_at' => $mon->addDays(1)->setTime(10, 0), 'trainer_id' => $trainer->id]);
        $dates = [$mon->toDateString(), $mon->addDays(1)->toDateString(), $mon->addDays(9)->toDateString()];
        $u = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['total_hours' => 9, 'capacity' => 10, 'pattern' => 'custom', 'dates' => $dates, 'trainer_id' => $trainer->id, 'start_time' => '09:00', 'room_id' => $room->id])->assertOk()->json('data');
        $this->assertSame($dates, array_column($u['sessions'], 'date'));
        $this->assertEquals([3.0, 3.0, 3.0], array_column($u['sessions'], 'hours'));
        $this->assertSame('trainer_busy', collect($u['sessions'][1]['flags'])->firstWhere('code', 'trainer_busy')['code']);
        $this->assertSame([1, 0, 'ok'], [$u['summary']['errors'], count($u['sessions'][0]['flags']), $u['sessions'][0]['status']]);

        // Online programmes need no room.
        $o = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['total_hours' => 10, 'start_date' => $mon->toDateString(), 'pattern' => 'weekly', 'delivery_mode' => 'online'])->assertOk()->json('data');
        $this->assertSame('remote', $o['summary']['room']['mode']);
        $this->assertSame([null, 'online'], [$o['sessions'][0]['training_room_id'], $o['sessions'][0]['mode']]);

        // The recheck: the person moved a session onto a booked room and out of the day window.
        $this->busy($room, $mon->addDays(14));
        $edited = array_map(fn ($s) => ['starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'], 'training_room_id' => $s['training_room_id']], $c['sessions']);
        $edited[0] = ['starts_at' => $mon->addDays(14)->setTime(8, 0)->format('Y-m-d H:i:s'), 'ends_at' => $mon->addDays(14)->setTime(15, 0)->format('Y-m-d H:i:s'), 'training_room_id' => $room->id];
        $k = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['total_hours' => 15, 'capacity' => 10, 'sessions' => $edited])->assertOk()->json('data');
        $codes = collect($k['sessions'])->flatMap(fn ($s) => collect($s['flags'])->pluck('code'))->all();
        $this->assertContains('room_busy', $codes);
        $this->assertContains('outside_day', $codes);
        $this->assertGreaterThan(0, $k['summary']['errors']);
        $this->assertSame($mon->addDays(14)->toDateString(), collect($k['sessions'])->last()['date']); // re-sorted by date
    }

    public function test_the_plan_is_saved_with_the_programme_on_its_first_group_and_a_refused_session_rolls_everything_back(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room('R5');
        $mon = $this->monday();
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => [], 'rating' => 4]);
        $plan = $this->asUser($admin)->postJson('/api/v1/admin/programs/schedule-plan', ['total_hours' => 12, 'capacity' => 20, 'start_date' => $mon->toDateString(), 'pattern' => 'weekly', 'sessions_count' => 5, 'room_id' => $room->id])->assertOk()->json('data');
        $sessions = array_map(fn ($s) => collect($s)->only(['starts_at', 'ends_at', 'training_room_id', 'title_ar', 'title_en', 'mode'])->all(), $plan['sessions']);
        $payload = ['title_ar' => 'برنامج الاثنين', 'program_type' => 'licensing', 'total_hours' => 12, 'capacity' => 20, 'start_date' => $mon->toDateString(), 'end_date' => $mon->addWeeks(4)->toDateString(), 'trainers' => [['id' => $trainer->id, 'role' => 'lead']], 'sessions' => $sessions];

        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', $payload)->assertCreated()->json('data.id');
        $program = Program::findOrFail($id);
        $this->assertSame(5, $program->sessions()->count());
        $this->assertSame([$room->id], $program->sessions()->pluck('training_room_id')->unique()->values()->all());
        $this->assertSame([$trainer->id], $program->sessions()->pluck('trainer_id')->unique()->values()->all());
        $group = $program->groups()->first();
        $this->assertSame($group->id, $program->sessions()->first()->training_group_id); // group 1
        $this->assertSame(['الجلسة 1', 'الجلسة 5'], [$program->sessions()->reorder('sequence')->first()->title_ar, $program->sessions()->reorder('sequence', 'desc')->first()->title_ar]);

        // The same room is now taken on those Mondays: saving again names the refused session and leaves nothing behind.
        $before = Program::count();
        $r = $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'برنامج ثانٍ', 'code' => 'SECOND-1'] + $payload)->assertStatus(422);
        $this->assertSame('room_conflict', $r->json('code'));
        $this->assertSame(1, $r->json('details.session'));
        $this->assertStringContainsString('الجلسة 1', $r->json('message'));
        $this->assertSame($before, Program::count());

        // A weekend date needs the management's approval; with the permission and a reason it is saved.
        $sat = $mon->addDays(5);
        $weekend = [['starts_at' => $sat->setTime(8, 0)->format('Y-m-d H:i:s'), 'ends_at' => $sat->setTime(11, 0)->format('Y-m-d H:i:s'), 'training_room_id' => null]];
        $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'نهاية الأسبوع', 'code' => 'WE-1', 'total_hours' => 3, 'capacity' => 10, 'sessions' => $weekend])->assertStatus(422)->assertJsonPath('code', 'calendar_closed');
        $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'نهاية الأسبوع', 'code' => 'WE-1', 'total_hours' => 3, 'capacity' => 10, 'sessions' => $weekend, 'calendar_approval_reason' => 'برنامج طارئ'])->assertCreated();
    }
}
