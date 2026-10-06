<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\KpiSample;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboards\DashboardService;
use App\Services\Kpi\DataIntegrityChecker;
use App\Services\Kpi\KpiService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardsKpiPhase12Test extends TestCase
{
    private function enrolled($school = null, string $status = Registration::STATUS_APPROVED, array $extra = [])
    {
        $employee = $this->makeEmployee($school ? ['school_id' => $school->id] : []);
        $program = $this->makeProgram();
        $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => $status, 'attendance_percent' => 90, 'approved_at' => now()] + $extra);

        return [$employee, $program, $reg];
    }

    public function test_every_widget_in_every_preset_returns_data_for_its_role(): void
    {
        $this->enrolled();
        $svc = app(DashboardService::class);
        $this->assertGreaterThanOrEqual(15, count(DashboardService::PRESETS));

        foreach (DashboardService::PRESETS as $role => $keys) {
            $user = $this->makeUser($role);
            $this->makeEmployee([], $user);
            $user = $user->fresh()->load('roles');
            $layout = $this->asUser($user)->getJson('/api/v1/dashboard')->assertOk();
            $this->assertSame($role, $layout->json('data.role'));
            $this->assertSame($keys, array_column($layout->json('data.widgets'), 'key'), $role);
            foreach ($keys as $key) {
                $w = $this->asUser($user)->getJson("/api/v1/dashboard/widgets/{$key}")->assertOk();
                $this->assertSame($key, $w->json('data.key'), "{$role}/{$key}");
                $this->assertContains($w->json('data.type'), ['kpis', 'bar', 'donut', 'gauge', 'table', 'list', 'heatmap'], "{$role}/{$key}");
            }
        }
        $this->assertNotNull($svc);
    }

    public function test_a_role_only_gets_the_widgets_of_its_preset_and_people_can_personalise_within_it(): void
    {
        $trainee = $this->makeUser(Role::EMPLOYEE);
        $this->makeEmployee([], $trainee);
        $this->asUser($trainee)->getJson('/api/v1/dashboard/widgets/plan_execution')->assertNotFound();       // a leadership widget

        $order = ['my_hours', 'my_upcoming'];
        $saved = $this->asUser($trainee)->putJson('/api/v1/dashboard/layout', ['order' => $order, 'hidden' => ['my_tasks', 'plan_execution']])->assertOk();
        $keys = array_column($saved->json('data.widgets'), 'key');
        $this->assertSame(['my_hours', 'my_upcoming'], array_slice($keys, 0, 2));                              // their order first
        $this->assertContains('my_progress', $keys);                                                           // the rest of the preset follows
        $this->assertNotContains('plan_execution', $keys);                                                     // never beyond the preset
        $hidden = collect($saved->json('data.widgets'))->where('hidden', true)->pluck('key')->all();
        $this->assertSame(['my_tasks'], $hidden);
        $again = $this->asUser($trainee)->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame($keys, array_column($again->json('data.widgets'), 'key'));                           // it persists

        // Administrators edit a role's preset; unknown widgets are refused.
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($trainee)->putJson('/api/v1/admin/dashboard-presets/employee', ['widgets' => ['my_hours']])->assertForbidden();
        $this->asUser($head)->putJson('/api/v1/admin/dashboard-presets/employee', ['widgets' => ['my_hours', 'my_certificates']])->assertOk();
        $this->asUser($head)->putJson('/api/v1/admin/dashboard-presets/employee', ['widgets' => ['rm_rf']])->assertStatus(422);
        $this->assertSame(['my_hours', 'my_certificates'], array_column($this->asUser($trainee)->getJson('/api/v1/dashboard')->json('data.widgets'), 'key'));
    }

    public function test_dashboard_numbers_follow_the_scope_and_the_leadership_view_has_plan_and_satisfaction(): void
    {
        $s1 = $this->makeSchool();
        $s2 = $this->makeSchool();
        $this->enrolled($s1, Registration::STATUS_COMPLETED, ['pass_status' => 'passed']);
        $this->enrolled($s2, Registration::STATUS_COMPLETED, ['pass_status' => 'failed']);
        $this->enrolled($s2);
        $admin = $this->makeEmployee(['school_id' => $s1->id], $this->makeUser(Role::SCHOOL_ADMIN))->user->load('roles');
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $count = fn (User $u, string $item) => collect($this->asUser($u)->getJson('/api/v1/dashboard/widgets/participation')->assertOk()->json('data.items'))->firstWhere('key', $item)['value'];
        $this->assertSame(1, $count($admin, 'approved'));          // only the school's own
        $this->assertSame(3, $count($head, 'approved'));
        $this->assertSame(1, $count($head, 'passed'));
        $this->assertSame(1, $count($head, 'failed'));

        // Satisfaction: highest and lowest programs.
        foreach ([[95, 'a'], [40, 'b'], [70, 'c'], [60, 'd']] as [$score]) {
            [$e, $p, $r] = $this->enrolled($s1, Registration::STATUS_COMPLETED);
            Evaluation::create(['program_id' => $p->id, 'registration_id' => $r->id, 'employee_id' => $e->id, 'ratings' => [], 'satisfaction_score' => $score, 'submitted_at' => now()]);
        }
        $rank = $this->asUser($head)->getJson('/api/v1/dashboard/widgets/satisfaction_ranking')->assertOk()->json('data.rows');
        $this->assertSame('highest', $rank[0]['kind']);
        $this->assertSame(4.75, $rank[0]['score']);
        $lowest = array_values(array_filter($rank, fn ($r) => $r['kind'] === 'lowest'));
        $this->assertEquals(2.0, $lowest[0]['score']);                // the lowest of all comes first among the lowest
        $leader = $this->makeUser(Role::CENTER_LEADERSHIP);
        $alerts = $this->asUser($leader)->getJson('/api/v1/dashboard/widgets/low_satisfaction_alerts')->assertOk()->json('data.items');
        $this->assertCount(2, $alerts);                             // 40 and 60 are under 70

        // The leadership dashboard names the annual plan.
        $plan = $this->asUser($leader)->getJson('/api/v1/dashboard/widgets/plan_execution')->assertOk();
        $this->assertSame('no_plan', $plan->json('data.note'));
        $keys = array_column($this->asUser($leader)->getJson('/api/v1/dashboard')->json('data.widgets'), 'key');
        foreach (['plan_execution', 'satisfaction_ranking', 'low_satisfaction_alerts', 'achievement_by_school', 'achievement_by_job'] as $k) {
            $this->assertContains($k, $keys);
        }
    }

    public function test_the_kpis_are_measured_from_real_data_and_compared_with_targets(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $kpi = app(KpiService::class);

        // Response time: percentiles of the last five minutes; error rate from the statuses.
        foreach ([100, 200, 300, 400, 5000] as $ms) {
            DB::table('request_metrics')->insert(['route' => 'x', 'duration_ms' => $ms, 'status' => 200, 'created_at' => now()]);
        }
        DB::table('request_metrics')->insert(['route' => 'x', 'duration_ms' => 50, 'status' => 500, 'created_at' => now()]);
        DB::table('request_metrics')->insert(['route' => 'old', 'duration_ms' => 99999, 'status' => 200, 'created_at' => now()->subHour()]);   // outside the window
        foreach ([1, 1, 1, 0] as $up) {
            KpiSample::create(['metric' => 'uptime_probe', 'value' => $up, 'window' => '5m', 'measured_at' => now()]);
        }
        [$e1, $p1, $r1] = $this->enrolled(null, Registration::STATUS_COMPLETED);
        [$e2, $p2, $r2] = $this->enrolled();
        Evaluation::create(['program_id' => $p1->id, 'registration_id' => $r1->id, 'employee_id' => $e1->id, 'ratings' => [], 'satisfaction_score' => 90, 'pre_test_score' => 50, 'post_test_score' => 80, 'submitted_at' => now()]);
        User::query()->update(['last_active_at' => now()->subDays(60)]);
        $e1->user->forceFill(['last_active_at' => now()])->save();

        $m = $kpi->measure();
        $this->assertSame(200.0, $m['response_time_p50']['value']);                 // 50, 100, 200, 300, 400, 5000: the middle one
        $this->assertSame(5000.0, $m['response_time_p95']['value']);
        $this->assertSame(16.67, $m['error_rate']['value']);                        // 1 of 6 requests failed
        $this->assertSame(75.0, $m['uptime']['value']);                             // 3 of 4 probes answered
        $this->assertSame(50.0, $m['course_completion']['value']);                  // 1 completed of 2 enrolled
        $this->assertSame(4.5, $m['satisfaction']['value']);                        // 90 of 100 is 4.5 of 5
        $this->assertSame(60.0, $m['knowledge_gain']['value']);                     // 50 → 80 is +60 %
        $this->assertGreaterThan(0, $m['active_users_monthly']['value']);
        $this->assertSame(100.0, $m['data_integrity']['value']);                    // nothing wrong yet
        $this->assertGreaterThan(80, $m['data_completion']['value']);                // the test employees have no gender recorded

        // An impossible state lowers data integrity and is named.
        $session = $this->makeSession($p1, now()->subDay());
        Attendance::create(['program_session_id' => $session->id, 'registration_id' => $r1->id, 'employee_id' => $e1->id, 'status' => 'present', 'method' => 'manual', 'check_in_at' => now(), 'check_out_at' => now()->subHours(2), 'minutes_attended' => 0]);
        $integrity = app(DataIntegrityChecker::class)->rate();
        $this->assertLessThan(100, $integrity['value']);
        $this->assertSame(1, $integrity['meta']['checks']['attendance_checkout_before_checkin']['violations']);

        // Collecting stores samples, and a breach warns the administrators once a day.
        $kpi->collect();
        $this->assertGreaterThan(0, KpiSample::where('metric', 'data_integrity')->count());
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'kpi.breach')->where('title_en', 'like', '%Data integrity%')->count());
        $kpi->collect();
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'kpi.breach')->where('title_en', 'like', '%Data integrity%')->count());

        // The dashboard shows value, target, status and a trend for every metric; targets are editable.
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $res = $this->asUser($head)->getJson('/api/v1/admin/kpis')->assertOk();
        $this->assertCount(count(KpiService::METRICS), $res->json('data'));
        $integrityRow = collect($res->json('data'))->firstWhere('metric', 'data_integrity');
        $this->assertSame('breach', $integrityRow['status']);
        $this->assertSame(100.0, (float) $integrityRow['target']);
        $this->assertNotEmpty($integrityRow['trend']);
        $this->asUser($head)->putJson('/api/v1/admin/kpi-targets', ['targets' => ['data_integrity' => 90, 'bogus' => 1]])->assertOk();
        $this->assertSame('ok', collect($this->asUser($head)->getJson('/api/v1/admin/kpis')->json('data'))->firstWhere('metric', 'data_integrity')['status']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/kpis')->assertForbidden();

        // The monthly report opens in PDF and Word.
        $this->assertStringStartsWith('%PDF', $this->asUser($head)->get('/api/v1/admin/kpis/report?format=pdf&month='.now()->format('Y-m'))->assertOk()->getContent());
        $this->assertStringStartsWith('PK', $this->asUser($head)->get('/api/v1/admin/kpis/report?format=docx&lang=en')->assertOk()->getContent());
    }

    public function test_api_requests_are_sampled_into_the_response_time_metrics(): void
    {
        config(['tedc.kpi.sample_rate' => 1]);
        $this->getJson('/api/v1/public/stats')->assertOk();
        $this->getJson('/api/v1/public/stats')->assertOk();
        $this->assertGreaterThanOrEqual(2, DB::table('request_metrics')->count());
        $row = DB::table('request_metrics')->latest('id')->first();
        $this->assertSame(200, (int) $row->status);
        $this->assertGreaterThanOrEqual(0, (int) $row->duration_ms);

        DB::table('request_metrics')->delete();
        config(['tedc.kpi.sample_rate' => 0]);
        $this->getJson('/api/v1/public/stats')->assertOk();
        $this->assertSame(0, DB::table('request_metrics')->count());                 // switched off
    }
}
