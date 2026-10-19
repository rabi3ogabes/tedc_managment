<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\Role;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** The numbers behind the program page's widgets. */
class ProgramOverviewTest extends TestCase
{
    public function test_the_overview_gives_the_numbers_for_every_widget_and_only_to_those_who_may_see_the_program(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'برنامج', 'total_hours' => 6, 'capacity' => 10, 'start_date' => today()->addDays(10)->toDateString(), 'end_date' => today()->addDays(11)->toDateString(),
            'groups' => [['title_ar' => 'أ'], ['title_ar' => 'ب', 'capacity' => 5]]])->assertCreated()->json('data.id');
        foreach (['approved', 'approved', 'pending', 'waitlisted'] as $status) {
            Registration::create(['program_id' => $id, 'employee_id' => $this->makeEmployee()->id, 'status' => $status, 'source' => 'self']);
        }

        $d = $this->asUser($admin)->getJson("/api/v1/admin/programs/{$id}/overview")->assertOk()->json('data');
        $this->assertSame(['total' => 4, 'approved' => 2, 'pending' => 1, 'waitlisted' => 1], $d['participants']);
        $this->assertSame(['count' => 2, 'capacity' => 15, 'seats_taken' => 3], $d['groups']);
        foreach (['structure', 'admission', 'assessments', 'passing', 'evaluation', 'sessions', 'course', 'rules', 'tasks', 'materials', 'survey', 'certificates', 'impact', 'staff'] as $widget) {
            $this->assertArrayHasKey($widget, $d, $widget);
        }
        $this->assertSame(0, $d['sessions']['count']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/admin/programs/{$id}/overview")->assertForbidden();
    }
}
