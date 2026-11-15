<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SeatingPlan;
use App\Models\Trainer;
use App\Models\TrainerAttendance;
use App\Models\TrainingGroup;
use App\Models\TrainingKit;
use App\Models\TrainingRoom;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The live training board: every classroom of a day with its trainer, supervisor, kit and the trainees who checked in. */
class LiveTrainingTest extends TestCase
{
    private const URL = '/api/v1/admin/live-training';

    private function room(string $code, int $capacity = 24, array $extra = []): TrainingRoom
    {
        return TrainingRoom::create($extra + ['code' => $code, 'name_ar' => "قاعة {$code}", 'name_en' => "Hall {$code}", 'capacity' => $capacity, 'status' => 'active', 'floor' => 'الأرضي']);
    }

    private function enrol(Program $program, ?TrainingGroup $group = null, string $name = 'متدرب'): Registration
    {
        $employee = $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['name_ar' => $name, 'name' => 'Trainee']));

        return Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'training_group_id' => $group?->id, 'source' => 'center_nomination', 'status' => 'approved']);
    }

    /** One room of the board, found by its code (the reference data has rooms of its own). @return array<string, mixed> */
    private function boardRoom(User $as, string $code, string $query = ''): array
    {
        $data = $this->asUser($as)->getJson(self::URL.$query)->assertOk()->json('data');

        return collect($data['rooms'])->firstWhere('code', $code) + ['_totals' => $data['totals'], '_is_today' => $data['is_today']];
    }

    private function checkIn(ProgramSession $session, Registration $r, string $status = 'present', int $minutesAgo = 10): Attendance
    {
        return Attendance::create(['program_session_id' => $session->id, 'registration_id' => $r->id, 'employee_id' => $r->employee_id, 'check_in_at' => now()->subMinutes($minutesAgo), 'method' => 'qr', 'status' => $status]);
    }

    public function test_the_board_lights_the_rooms_in_use_and_seats_the_trainees_who_checked_in(): void
    {
        $this->travelTo(today()->setTime(10, 0));
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $busy = $this->room('A1');
        $later = $this->room('A2');
        $dark = $this->room('A3');
        $this->room('OLD', 10, ['status' => 'inactive']);

        $supervisor = $this->makeUser(Role::COORDINATOR, ['name_ar' => 'مها المشرفة', 'name' => 'Maha']);
        $program = $this->makeProgram(['code' => 'LDR-1', 'title_ar' => 'القيادة التربوية', 'title_en' => 'Educational leadership', 'coordinator_id' => $supervisor->id]);
        $group = $program->ensureGroup();
        $trainer = Trainer::create(['name_ar' => 'سامر الكواري', 'name_en' => 'Samer', 'title_ar' => 'د.', 'status' => 'active', 'source' => 'center']);
        $kit = TrainingKit::create(['code' => 'KIT-9', 'title_ar' => 'حقيبة القيادة', 'title_en' => 'Leadership kit', 'status' => TrainingKit::PUBLISHED, 'version' => 3, 'created_by' => $admin->id, 'owner_id' => $admin->id]);
        $program->kits()->attach($kit->id, ['id' => (string) Str::uuid(), 'pinned_version' => 3]);

        $live = $this->makeSession($program, now()->subMinutes(30), 2);
        $live->update(['training_room_id' => $busy->id, 'trainer_id' => $trainer->id, 'training_group_id' => $group->id]);
        // Two earlier programmes the same trainer delivered in the centre.
        foreach (['P-OLD-1', 'P-OLD-2'] as $code) {
            $this->makeSession($this->makeProgram(['code' => $code]), now()->subDays(20), 2)->update(['trainer_id' => $trainer->id]);
        }
        TrainerAttendance::create(['session_id' => $live->id, 'trainer_id' => $trainer->id, 'check_in_at' => now()->subMinutes(35), 'method' => 'manual']);

        $a = $this->enrol($program, $group, 'أحمد علي');
        $b = $this->enrol($program, $group, 'بدر سالم');
        $this->enrol($program, $group, 'جاسم محمد');
        $this->checkIn($live, $a, 'present', 28);
        $this->checkIn($live, $b, 'late', 5);
        SeatingPlan::create(['room_id' => $busy->id, 'session_id' => $live->id, 'layout' => ['rows' => 2, 'cols' => 3, 'blocked' => ['0,1']], 'assignments' => ['1,2' => $a->id]]);

        $afternoon = $this->makeSession($this->makeProgram(['title_ar' => 'برنامج المساء']), now()->addHours(3), 2);
        $afternoon->update(['training_room_id' => $later->id]);
        $online = $this->makeSession($this->makeProgram(['title_ar' => 'برنامج عن بعد']), now()->subMinutes(10), 1);
        $online->update(['mode' => 'online']);
        $cancelled = $this->makeSession($this->makeProgram(), now()->subMinutes(10), 1);
        $cancelled->update(['training_room_id' => $dark->id, 'status' => 'cancelled']);

        $data = $this->asUser($admin)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.is_today', true)
            ->assertJsonPath('data.totals.rooms', TrainingRoom::where('status', 'active')->count())->assertJsonPath('data.totals.rooms_live', 1)->assertJsonPath('data.totals.sessions', 3)
            ->assertJsonPath('data.totals.expected', 3)->assertJsonPath('data.totals.present', 2)
            ->json('data');

        $rooms = collect($data['rooms'])->keyBy('code');
        $this->assertTrue($rooms->has('A1') && $rooms->has('A2') && $rooms->has('A3'));
        $this->assertFalse($rooms->has('OLD'), 'inactive rooms are left out');
        $this->assertSame('live', $rooms['A1']['state']);
        $this->assertSame('later', $rooms['A2']['state']);
        $this->assertSame('idle', $rooms['A3']['state'], 'a cancelled session leaves the room dark');
        $this->assertSame([], $rooms['A3']['sessions']);

        $s = $rooms['A1']['sessions'][0];
        $this->assertSame($live->id, $rooms['A1']['focus_session_id']);
        $this->assertSame('القيادة التربوية', $s['program']['title']);
        $this->assertSame('LDR-1', $s['program']['code']);
        $this->assertSame(['expected' => 3, 'present' => 2, 'late' => 1, 'absent' => 0, 'left' => 0, 'percent' => 67], $s['counts']);
        $this->assertSame('د. سامر الكواري', $s['trainers'][0]['name']);
        $this->assertSame(3, $s['trainers'][0]['programs_count'], 'the two earlier programmes and this one');
        $this->assertTrue($s['trainers'][0]['checked_in']);
        $this->assertSame('مها المشرفة', $s['supervisor']['name']);
        $this->assertSame('حقيبة القيادة', $s['kit']['title']);
        $this->assertSame(['rows' => 2, 'cols' => 3, 'blocked' => ['0,1']], $s['seating']);

        $present = collect($s['present'])->keyBy('name');
        $this->assertSame(['أحمد علي', 'بدر سالم'], $present->keys()->all(), 'in the order they arrived; the trainee who did not come is not seated');
        $this->assertSame('1,2', $present['أحمد علي']['seat']);
        $this->assertNull($present['بدر سالم']['seat']);
        $this->assertSame('late', $present['بدر سالم']['status']);

        $this->assertCount(1, $data['unplaced']);
        $this->assertSame('online', $data['unplaced'][0]['mode']);
        $this->assertSame('بدر سالم', $data['arrivals'][0]['name'], 'the latest arrival first');
        $this->assertSame('قاعة A1', $data['arrivals'][0]['room']);
        $this->assertCount(7, $data['strip']);
        $this->assertSame(3, collect($data['strip'])->firstWhere('date', today()->toDateString())['sessions']);
    }

    public function test_another_day_is_shown_from_the_calendar_and_a_finished_day_keeps_who_attended(): void
    {
        $this->travelTo(today()->setTime(9, 0));
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room('B1');
        $program = $this->makeProgram();
        $yesterday = $this->makeSession($program, now()->subDay()->setTime(8, 0), 3);
        $yesterday->update(['training_room_id' => $room->id]);
        $came = $this->enrol($program);
        $missed = $this->enrol($program);
        Attendance::create(['program_session_id' => $yesterday->id, 'registration_id' => $came->id, 'employee_id' => $came->employee_id, 'check_in_at' => $yesterday->starts_at->copy()->addMinutes(2), 'check_out_at' => $yesterday->ends_at, 'method' => 'qr', 'status' => 'present']);
        $tomorrow = $this->makeSession($program, now()->addDay()->setTime(8, 0), 3);
        $tomorrow->update(['training_room_id' => $room->id]);

        $today = $this->boardRoom($admin, 'B1');
        $this->assertSame('idle', $today['state']);
        $this->assertSame(0, $today['_totals']['sessions']);

        $before = $this->boardRoom($admin, 'B1', '?date='.today()->subDay()->toDateString());
        $this->assertFalse($before['_is_today']);
        $this->assertSame('ended', $before['state']);
        $past = $before['sessions'][0];
        $this->assertSame(['expected' => 2, 'present' => 1, 'late' => 0, 'absent' => 1, 'left' => 0, 'percent' => 50], $past['counts']);
        $this->assertCount(1, $past['present'], 'leaving at the end of the session still counts as having sat in the room');
        $this->assertSame($missed->employee->user->displayName(), collect($this->asUser($admin)->getJson(self::URL."/sessions/{$yesterday->id}")->json('data.roster'))->firstWhere('status', 'absent')['name']);

        $after = $this->boardRoom($admin, 'B1', '?date='.today()->addDay()->toDateString());
        $this->assertSame('later', $after['state']);
        $this->assertSame(2, $after['sessions'][0]['counts']['expected']);
        $this->assertSame(0, $after['sessions'][0]['counts']['present']);

        $this->asUser($admin)->getJson(self::URL.'?date=not-a-day')->assertStatus(422);
    }

    public function test_a_trainee_who_left_early_frees_the_seat_and_a_second_group_does_not_fill_the_first_groups_room(): void
    {
        $this->travelTo(today()->setTime(10, 0));
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room('C1');
        $program = $this->makeProgram();
        $g1 = $program->ensureGroup();
        $g2 = $program->makeFirstGroup(2);
        $session = $this->makeSession($program, now()->subHour(), 3);
        $session->update(['training_room_id' => $room->id, 'training_group_id' => $g1->id]);
        $stayed = $this->enrol($program, $g1);
        $left = $this->enrol($program, $g1);
        $this->enrol($program, $g2);
        $this->checkIn($session, $stayed);
        $this->checkIn($session, $left)->update(['check_out_at' => now()->subMinutes(20), 'left_early_at' => now()->subMinutes(20)]);

        $s = $this->boardRoom($admin, 'C1')['sessions'][0];
        $this->assertSame(2, $s['counts']['expected'], 'only the trainees of the group in this room');
        $this->assertSame(2, $s['counts']['present']);
        $this->assertSame(1, $s['counts']['left']);
        $this->assertCount(1, $s['present'], 'the trainee who left is no longer seated');
    }

    public function test_the_training_card_of_a_classroom_gives_the_programme_the_people_and_the_roster(): void
    {
        $this->travelTo(today()->setTime(10, 0));
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = $this->room('D1', 30, ['facilities' => [['key' => 'projector', 'qty' => 1]]]);
        $supervisor = $this->makeUser(Role::COORDINATOR, ['name_ar' => 'نورة', 'name' => 'Noura']);
        $program = $this->makeProgram(['code' => 'CARD-1', 'title_ar' => 'التقويم من أجل التعلم', 'objectives' => ['يبني اختبارات صفية'], 'total_hours' => 15]);
        $group = $program->ensureGroup();
        $group->update(['supervisor_id' => $supervisor->id]);
        $lead = Trainer::create(['name_ar' => 'ليلى', 'name_en' => 'Laila', 'status' => 'active', 'source' => 'center', 'rating' => 4.6]);
        $second = Trainer::create(['name_ar' => 'هند', 'name_en' => 'Hind', 'status' => 'active', 'source' => 'school']);
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $second->id, 'role' => 'assistant', 'status' => GroupTrainer::APPROVED]);
        $day1 = $this->makeSession($program, now()->subDay()->setTime(8, 0), 3);
        $day1->update(['training_group_id' => $group->id, 'trainer_id' => $lead->id]);
        $day2 = $this->makeSession($program, now()->subMinutes(20), 3);
        $day2->update(['training_room_id' => $room->id, 'training_group_id' => $group->id, 'trainer_id' => $lead->id, 'title_ar' => 'اليوم الثاني']);
        $r = $this->enrol($program, $group, 'خالد');
        $this->enrol($program, $group, 'سعاد');
        $this->checkIn($day2, $r);

        $card = $this->asUser($admin)->getJson(self::URL."/sessions/{$day2->id}")->assertOk()->json('data');
        $this->assertSame('التقويم من أجل التعلم', $card['program']['title']);
        $this->assertSame(['يبني اختبارات صفية'], $card['program']['objectives']);
        $this->assertSame($program->id, $card['program']['id']);
        $this->assertSame('اليوم الثاني', $card['session']['title']);
        $this->assertSame(2, $card['session']['number']);
        $this->assertSame(2, $card['session']['of']);
        $this->assertSame('live', $card['session']['state']);
        $this->assertSame('قاعة D1', $card['room']['name']);
        $this->assertSame('projector', $card['room']['equipment'][0]['key']);
        $this->assertSame(['ليلى', 'هند'], array_column($card['trainers'], 'name'));
        $this->assertSame(4.6, $card['trainers'][0]['rating']);
        $this->assertSame('نورة', $card['supervisor']['name']);
        $this->assertCount(2, $card['schedule']);
        $this->assertSame(['expected' => 2, 'present' => 1, 'late' => 0, 'absent' => 0, 'left' => 0, 'percent' => 50], $card['counts']);
        $this->assertSame(['present', 'expected'], array_column($card['roster'], 'status'), 'a running session has nobody absent yet');
        $this->assertNull($card['kit']);
    }

    public function test_the_board_is_for_the_people_who_follow_training_only(): void
    {
        $session = $this->makeSession($this->makeProgram(), now(), 1);

        $this->getJson(self::URL)->assertUnauthorized();
        foreach ([Role::EMPLOYEE, Role::TRAINER, Role::SCHOOL_ADMIN, Role::KIT_DEVELOPER] as $role) {
            $this->asUser($this->makeUser($role))->getJson(self::URL)->assertForbidden();
            $this->asUser($this->makeUser($role))->getJson(self::URL."/sessions/{$session->id}")->assertForbidden();
        }
        foreach ([Role::SUPER_ADMIN, Role::CENTER_ADMIN, Role::COORDINATOR, Role::TRAINING_HEAD, Role::CENTER_LEADERSHIP, Role::EXECUTIVE] as $role) {
            $this->asUser($this->makeUser($role))->getJson(self::URL)->assertOk();
        }
    }
}
