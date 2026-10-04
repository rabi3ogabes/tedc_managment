<?php

namespace Tests\Feature;

use App\Models\Registration;
use Tests\TestCase;

class HomeCheckInTest extends TestCase
{
    public function test_check_in_is_offered_only_for_the_session_happening_now_and_the_rest_are_listed_below(): void
    {
        $employee = $this->makeEmployee();
        $program = $this->makeProgram();
        Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);

        // Nothing is happening now: no check-in, the upcoming sessions are listed.
        $later = $this->makeSession($program, now()->addDays(2));
        $this->makeSession($program, now()->addDays(3));
        $res = $this->asUser($employee->user)->getJson('/api/v1/me/home')->assertOk();
        $this->assertNull($res->json('data.current_session'));
        $this->assertCount(2, $res->json('data.upcoming_sessions'));
        $this->assertSame($later->id, $res->json('data.next_session.id'));

        // A session that started half an hour ago: check-in appears and it leaves the upcoming list.
        $live = $this->makeSession($program, now()->subMinutes(30));
        $res = $this->asUser($employee->user)->getJson('/api/v1/me/home')->assertOk();
        $this->assertSame($live->id, $res->json('data.current_session.id'));
        $this->assertTrue($res->json('data.current_session.live'));
        $this->assertNotContains($live->id, array_column($res->json('data.upcoming_sessions'), 'id'));
        $this->assertCount(2, $res->json('data.upcoming_sessions'));
    }
}
