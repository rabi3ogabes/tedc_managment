<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Registration;
use App\Models\Role;
use App\Services\Notifications\NotificationTemplates;
use Database\Seeders\DemoScenarioSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Tests\TestCase;

class UpcomingNotificationsTest extends TestCase
{
    private function timeline(string $query = ''): array
    {
        return $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/notifications/upcoming'.$query)->assertOk()->json('data');
    }

    public function test_it_projects_reminders_and_check_in_calls_for_a_coming_session(): void
    {
        $program = $this->makeProgram(['status' => Program::STATUS_IN_PROGRESS]);
        $session = $this->makeSession($program, now()->addDays(3)->setTime(8, 0), 5);
        foreach ([1, 2, 3] as $i) {
            Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'center_nomination', 'status' => 'approved']);
        }

        $data = $this->timeline();
        $byType = collect($data['items'])->keyBy('type');
        $this->assertEqualsCanonicalizing(['session.reminder', 'session.attendance_open', 'session.attendance_missed'], array_keys($byType->all()));
        $this->assertSame(3, $byType['session.reminder']['recipients']);
        $this->assertSame($session->starts_at->copy()->subDay()->toIso8601String(), $byType['session.reminder']['at']);
        $this->assertSame($program->code, $byType['session.reminder']['program']['code']);
        $this->assertStringNotContainsString('{{', json_encode($byType['session.reminder'], JSON_UNESCAPED_UNICODE), 'placeholders are filled in');
        $this->assertStringContainsString($program->title_ar, $byType['session.reminder']['body_ar']);
        $this->assertSame(3, $data['summary']['total']);
        $this->assertSame(['push', 'email', 'sms'], array_keys($byType['session.reminder']['channels']));

        // Sorted in time order, and filterable.
        $times = array_column($data['items'], 'at');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertCount(1, $this->timeline('?type=session.reminder')['items']);
        $this->assertSame([], $this->timeline('?days=1')['items'], 'nothing falls inside the next day');
    }

    public function test_a_switched_off_event_is_shown_as_stopped_and_cancelled_sessions_are_left_out(): void
    {
        $program = $this->makeProgram(['status' => Program::STATUS_IN_PROGRESS]);
        $session = $this->makeSession($program, now()->addDays(2)->setTime(8, 0), 5);
        Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'center_nomination', 'status' => 'approved']);
        app(NotificationTemplates::class)->ensure();
        NotificationTemplate::where('event', 'session.reminder')->update(['enabled' => false, 'sms' => false]);
        app(NotificationTemplates::class)->flush();

        $reminder = collect($this->timeline()['items'])->firstWhere('type', 'session.reminder');
        $this->assertFalse($reminder['enabled']);
        $this->assertSame('off', $reminder['channels']['sms']);
        $this->assertSame('setup', $reminder['channels']['email'], 'chosen, but no provider is set up yet');

        $session->update(['status' => 'cancelled']);
        $this->assertSame([], $this->timeline()['items']);
    }

    public function test_the_demo_scenario_fills_the_timeline_and_only_staff_can_see_it(): void
    {
        ProgramCategory::create(['name_ar' => 'عام', 'name_en' => 'General', 'slug' => 'general']);
        $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => 'teacher@tedc.qa']));
        $this->makeUser(Role::CENTER_ADMIN, ['email' => 'center@tedc.qa']);
        $this->seed(DemoTestAccountsSeeder::class);
        $this->seed(DemoScenarioSeeder::class);

        $data = $this->timeline('?days=30');
        $this->assertGreaterThan(5, $data['summary']['total']);
        $types = collect($data['types'])->pluck('type')->all();
        $this->assertContains('session.reminder', $types);
        $this->assertContains('session.attendance_open', $types);
        $this->assertContains('course.nudge', $types, 'learners of the online course will be nudged');

        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/notifications/upcoming')->assertForbidden();
    }
}
