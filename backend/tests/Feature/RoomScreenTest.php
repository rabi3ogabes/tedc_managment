<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use Tests\TestCase;

class RoomScreenTest extends TestCase
{
    public function test_the_room_screen_shows_the_days_sessions_with_live_attendance_by_token(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = TrainingRoom::create(['code' => 'R-1', 'name_ar' => 'قاعة 1', 'name_en' => 'Hall 1', 'capacity' => 30, 'status' => 'active']);
        $program = $this->makeProgram(['title_ar' => 'برنامج القيادة', 'title_en' => 'Leadership']);
        $session = $this->makeSession($program, now()->subMinutes(30), 2);
        $trainer = Trainer::create(['name_ar' => 'سامر', 'name_en' => 'Samer', 'status' => 'active', 'source' => 'center']);
        $session->update(['training_room_id' => $room->id, 'trainer_id' => $trainer->id]);

        $present = $this->makeEmployee();
        $absent = $this->makeEmployee();
        $r1 = Registration::create(['program_id' => $program->id, 'employee_id' => $present->id, 'source' => 'center_nomination', 'status' => 'approved']);
        Registration::create(['program_id' => $program->id, 'employee_id' => $absent->id, 'source' => 'center_nomination', 'status' => 'approved']);
        Attendance::create(['program_session_id' => $session->id, 'registration_id' => $r1->id, 'employee_id' => $present->id, 'check_in_at' => now()->subMinutes(25), 'method' => 'qr', 'status' => 'present']);

        $day = $this->asUser($admin)->getJson("/api/v1/admin/rooms/{$room->id}/screen")->assertOk()
            ->assertJsonPath('data.sessions.0.state', 'live')->assertJsonPath('data.sessions.0.counts.present', 1)->assertJsonPath('data.sessions.0.counts.expected', 2)
            ->assertJsonPath('data.sessions.0.trainees.0.status', 'present')->assertJsonPath('data.sessions.0.trainer.name', 'سامر')->json('data');
        $this->assertSame('قاعة 1', $day['room']['name']);

        $link = $this->asUser($admin)->postJson("/api/v1/admin/rooms/{$room->id}/screen-link")->assertOk()->json('data');
        $this->getJson('/api/v1/public/room-screen/'.$link['token'])->assertOk()->assertJsonPath('data.sessions.0.program.title', 'برنامج القيادة');
        $this->getJson('/api/v1/public/room-screen/'.$link['token'].'?date='.now()->addDays(3)->toDateString())->assertOk()->assertJsonCount(0, 'data.sessions');

        $new = $this->asUser($admin)->postJson("/api/v1/admin/rooms/{$room->id}/screen-link?regenerate=1")->assertOk()->json('data.token');
        $this->assertNotSame($link['token'], $new);
        $this->getJson('/api/v1/public/room-screen/'.$link['token'])->assertNotFound();
    }
}
