<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Models\User;
use Tests\TestCase;

/** «برامج اليوم» in the app: the day's sessions with who trains, who supervises, where, and who came. */
class TodayBoardTest extends TestCase
{
    private function trainer(string $ar = 'مدرب', string $en = 'Trainer'): array
    {
        $user = $this->makeUser(Role::TRAINER);

        return [$user, Trainer::create(['user_id' => $user->id, 'name_ar' => $ar, 'name_en' => $en, 'email' => $user->email, 'status' => 'active'])];
    }

    private function running(array $attributes = []): Program
    {
        return $this->makeProgram($attributes + ['status' => Program::STATUS_IN_PROGRESS, 'start_date' => today()->subDay(), 'end_date' => today()->addDay()]);
    }

    private function board(User $as): array
    {
        return $this->asUser($as)->getJson('/api/v1/me/today-board')->assertOk()->json('data');
    }

    public function test_the_administrator_reads_each_session_of_today_with_its_trainer_supervisor_room_and_attendance(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [, $trainer] = $this->trainer('خالد المدرب', 'Khalid Trainer');
        $supervisor = $this->makeUser(Role::SUPERVISOR, ['name' => 'Sara Supervisor', 'name_ar' => 'سارة المشرفة']);
        $room = TrainingRoom::create(['code' => 'R-7', 'name_ar' => 'قاعة 7', 'name_en' => 'Room 7', 'capacity' => 30]);

        $program = $this->running(['title_ar' => 'القيادة', 'title_en' => 'Leadership']);
        $program->ensureGroup()->update(['supervisor_id' => $supervisor->id]);
        $now = $this->makeSession($program, now()->subMinutes(30), 2);
        $now->update(['trainer_id' => $trainer->id, 'training_room_id' => $room->id]);
        foreach ([true, false] as $came) {
            $employee = $this->makeEmployee();
            $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
            if ($came) {
                Attendance::create(['program_session_id' => $now->id, 'registration_id' => $registration->id, 'employee_id' => $employee->id, 'status' => 'present', 'check_in_at' => now(), 'method' => 'qr']);
            }
        }
        // Tomorrow's session and a cancelled programme's session are not part of today.
        $this->makeSession($program, now()->addDay());
        $this->makeSession($this->running(['status' => Program::STATUS_CANCELLED]), now()->subMinutes(10));

        $data = $this->board($admin);
        $this->assertTrue($data['whole_centre']);
        $this->assertCount(1, $data['sessions']);
        $row = $data['sessions'][0];
        $this->assertSame('القيادة', $row['program']);
        $this->assertSame($program->code, $row['program_code']);
        $this->assertSame('قاعة 7', $row['room']);
        $this->assertSame(['خالد المدرب'], $row['trainers']);
        $this->assertSame(['سارة المشرفة'], $row['supervisors']);
        $this->assertSame('now', $row['when']);
        $this->assertSame([1, 2], [$row['present'], $row['expected']]);
        $this->assertFalse($row['attention']);
        $this->assertSame(['sessions' => 1, 'now' => 1, 'later' => 0, 'past' => 0, 'attention' => 0, 'programs' => 1, 'rooms' => 1, 'trainers' => 1, 'supervisors' => 1, 'present' => 1, 'expected' => 2], $data['summary']);
    }

    public function test_trainers_come_from_the_group_or_the_programme_when_the_session_names_none_and_a_gap_is_flagged(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [, $byGroup] = $this->trainer('نور', 'Noor');
        [, $byProgramme] = $this->trainer('علي', 'Ali');

        $withGroupTrainer = $this->running(['title_ar' => 'ألف']);
        GroupTrainer::create(['group_id' => $withGroupTrainer->ensureGroup()->id, 'trainer_id' => $byGroup->id, 'role' => 'lead', 'status' => GroupTrainer::APPROVED]);
        $this->makeSession($withGroupTrainer, now()->addMinutes(10))->update(['location_text' => 'القاعة الكبرى']);

        $withProgrammeTrainer = $this->running(['title_ar' => 'باء']);
        $withProgrammeTrainer->trainers()->attach($byProgramme->id, ['role' => 'lead']);
        $this->makeSession($withProgrammeTrainer, now()->addMinutes(20))->update(['location_text' => 'قاعة 2']);

        // Nobody to train it and nowhere to hold it yet.
        $this->makeSession($this->running(['title_ar' => 'جيم']), now()->addMinutes(30));

        $rows = collect($this->board($admin)['sessions'])->keyBy('program');
        $this->assertSame(['نور'], $rows['ألف']['trainers']);
        $this->assertSame('القاعة الكبرى', $rows['ألف']['room']);
        $this->assertSame(['علي'], $rows['باء']['trainers']);
        $this->assertSame([], $rows['جيم']['trainers']);
        $this->assertTrue($rows['جيم']['attention']);
        $this->assertFalse($rows['ألف']['attention']);
        $this->assertSame(1, $this->board($admin)['summary']['attention']);
        $this->assertSame(['ألف', 'باء', 'جيم'], array_column($this->board($admin)['sessions'], 'program'), 'in the order they start');
    }

    public function test_a_trainer_sees_only_their_own_sessions_and_somebody_who_sees_no_programmes_sees_none(): void
    {
        [$user, $trainer] = $this->trainer();
        [, $other] = $this->trainer('آخر', 'Other');
        $program = $this->running();
        $mine = $this->makeSession($program, now()->addMinutes(5));
        $mine->update(['trainer_id' => $trainer->id]);
        $this->makeSession($program, now()->addMinutes(15))->update(['trainer_id' => $other->id]);

        $data = $this->board($user);
        $this->assertFalse($data['whole_centre']);
        $this->assertSame([$mine->id], array_column($data['sessions'], 'id'));

        $nobody = $this->board($this->makeEmployee()->user);
        $this->assertSame([], $nobody['sessions']);
        $this->assertSame(0, $nobody['summary']['sessions']);
    }

    public function test_the_home_card_of_the_app_carries_the_same_sessions_with_the_keys_it_always_had(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [, $trainer] = $this->trainer('خالد', 'Khalid');
        $program = $this->running(['title_ar' => 'القيادة']);
        $this->makeSession($program, now()->addMinutes(5))->update(['trainer_id' => $trainer->id, 'location_text' => 'قاعة 4']);

        $today = $this->asUser($admin)->getJson('/api/v1/me/app-home')->assertOk()->json('data.today');
        $this->assertCount(1, $today);
        foreach (['id', 'title', 'program', 'program_id', 'has_course', 'starts_at', 'ends_at', 'location', 'room_id', 'map_place', 'mode'] as $key) {
            $this->assertArrayHasKey($key, $today[0], "the home card lost {$key}");
        }
        $this->assertSame('قاعة 4', $today[0]['location']);
        $this->assertSame(['خالد'], $today[0]['trainers']);
    }
}
