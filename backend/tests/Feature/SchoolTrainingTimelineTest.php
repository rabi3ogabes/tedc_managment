<?php

namespace Tests\Feature;

use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\Role;
use App\Services\PdService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A school's page: its staff, and the history of their training as a timeline. */
class SchoolTrainingTimelineTest extends TestCase
{
    public function test_the_school_page_shows_its_staff_and_their_training_as_a_timeline(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $school = $this->makeSchool();
        $other = $this->makeSchool();
        $a = $this->makeEmployee(['school_id' => $school->id, 'employee_no' => '100']);
        $b = $this->makeEmployee(['school_id' => $school->id, 'employee_no' => '200']);
        $x = $this->makeEmployee(['school_id' => $other->id, 'employee_no' => '300']);
        app(PdService::class)->ensureTypes();
        $type = PdActivityType::where('code', 'conference')->first();
        $course = fn ($e, $title, $on, $hours) => PdActivity::create(['employee_id' => $e->id, 'type_id' => $type->id, 'title' => $title, 'starts_on' => Carbon::parse($on), 'ends_on' => Carbon::parse($on), 'duration_hours' => $hours, 'computed_hours' => $hours, 'approved_hours' => $hours, 'status' => 'approved']);
        $course($a, 'الأمن السيبراني', '2025-03-02', 1);
        $course($b, 'الأمن السيبراني', '2025-03-02', 1);
        $course($a, 'التعلم النشط', '2024-10-05', 12);
        $course($x, 'خارج المدرسة', '2025-03-02', 9);
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $staff = $this->asUser($admin)->getJson("/api/v1/admin/schools/{$school->id}/staff?sort=hours")->assertOk()->json();
        $this->assertSame(2, $staff['total']);
        $this->assertSame(['100', 13.0, 2], [$staff['data'][0]['employee_no'], (float) $staff['data'][0]['hours'], $staff['data'][0]['courses']]);
        $this->assertSame('2025-03-02', $staff['data'][0]['last_course_on']);
        $this->assertCount(1, $this->asUser($admin)->getJson("/api/v1/admin/schools/{$school->id}/staff?q=200")->json('data'));

        $t = $this->asUser($admin)->getJson("/api/v1/admin/schools/{$school->id}/timeline")->assertOk()->json('data');
        $this->assertSame('school', $t['scope']);
        $this->assertSame(2, $t['summary']['staff']);
        $this->assertSame(2, $t['summary']['trained']);
        $this->assertSame(3, $t['summary']['records']);
        $this->assertSame(14.0, (float) $t['summary']['hours']);
        $this->assertSame([2025, 2024], array_column($t['by_year'], 'year'));
        $this->assertSame(2025, $t['year']);
        $this->assertCount(1, $t['courses']);
        $this->assertSame(2, $t['courses'][0]['attendees']);
        $this->assertSame('التعلم النشط', $this->asUser($admin)->getJson("/api/v1/admin/schools/{$school->id}/timeline?year=2024")->json('data.courses.0.title'));

        $p = $this->asUser($admin)->getJson("/api/v1/admin/schools/{$school->id}/timeline?employee_id={$a->id}")->assertOk()->json('data');
        $this->assertSame('employee', $p['scope']);
        $this->assertSame(2, $p['summary']['courses']);
        $this->assertSame(['الأمن السيبراني', 'التعلم النشط'], array_column($p['courses'], 'title'));

        // A person of another school is not found through this school's page; an employee may not open it at all.
        $this->asUser($admin)->getJson("/api/v1/admin/schools/{$school->id}/timeline?employee_id={$x->id}")->assertNotFound();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/admin/schools/{$school->id}/staff")->assertForbidden();
    }
}
