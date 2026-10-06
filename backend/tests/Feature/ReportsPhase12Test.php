<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\Registration;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\BuiltInReports;
use App\Services\Reports\ReportRunner;
use App\Services\Reports\ReportRunService;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReportsPhase12Test extends TestCase
{
    /** Two schools with one registered, attended, certified, evaluated employee each. @return array{0: array, 1: array} */
    private function world(): array
    {
        $out = [];
        foreach ([1, 2] as $n) {
            $school = $this->makeSchool(['name_ar' => "مدرسة {$n}", 'name_en' => "School {$n}"]);
            $employee = $this->makeEmployee(['school_id' => $school->id]);
            $program = $this->makeProgram(['title_ar' => "برنامج {$n}", 'title_en' => "Program {$n}", 'total_hours' => 10]);
            $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED, 'attendance_percent' => 80 + $n, 'approved_at' => now()]);
            $session = $this->makeSession($program, now()->subDay());
            Attendance::create(['program_session_id' => $session->id, 'registration_id' => $reg->id, 'employee_id' => $employee->id, 'status' => 'present', 'method' => 'manual', 'check_in_at' => now()->subDay(), 'check_out_at' => now()->subDay()->addHour(), 'minutes_attended' => 60]);
            Certificate::create(['certificate_no' => "C-{$n}", 'verification_code' => "V{$n}XYZ", 'registration_id' => $reg->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 10, 'status' => 'valid']);
            Evaluation::create(['program_id' => $program->id, 'registration_id' => $reg->id, 'employee_id' => $employee->id, 'ratings' => [], 'satisfaction_score' => 90, 'pre_test_score' => 50, 'post_test_score' => 80, 'submitted_at' => now()]);
            $out[] = compact('school', 'employee', 'program', 'reg');
        }

        return $out;
    }

    private function schoolAdmin($school): User
    {
        return $this->makeEmployee(['school_id' => $school->id], $this->makeUser(Role::SCHOOL_ADMIN))->user->load('roles');
    }

    private function def(string $dataset, array $columns, array $extra = []): array
    {
        return ['dataset' => $dataset, 'columns' => $columns] + $extra;
    }

    public function test_every_dataset_keeps_to_the_scope_of_the_person(): void
    {
        [$a, $b] = $this->world();
        $runner = app(ReportRunner::class);
        $admin = $this->schoolAdmin($a['school']);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $cases = ['registrations' => 'employee_no', 'attendance' => 'employee_no', 'certificates' => 'employee_no', 'evaluations' => 'program', 'assessments' => 'employee_no'];
        foreach ($cases as $dataset => $field) {
            $def = $this->def($dataset, [['field' => $field]]);
            $mine = $runner->run($def, [], $admin, all: true);
            $all = $runner->run($def, [], $head, all: true);
            $this->assertLessThanOrEqual($all['total'], $mine['total'], $dataset);
            if ($dataset !== 'assessments') {
                $this->assertSame(1, $mine['total'], "{$dataset}: a school sees only its own rows");
                $this->assertSame(2, $all['total'], "{$dataset}: the centre sees both");
            }
        }
        $names = array_column($runner->run($this->def('registrations', [['field' => 'school']]), [], $admin, all: true)['rows'], 'school');
        $this->assertSame(['مدرسة 1'], $names);

        // Datasets about the centre itself are closed to a school's scope.
        $this->makeProgram();
        \DB::table('training_kits')->insert(['id' => (string) \Str::uuid(), 'code' => 'K1', 'title_ar' => 'ح', 'title_en' => 'k', 'status' => 'draft', 'owner_id' => $head->id, 'created_by' => $head->id, 'created_at' => now(), 'updated_at' => now()]);
        $kits = $this->def('kits', [['field' => 'code']]);
        $this->assertSame(0, $runner->run($kits, [], $admin, all: true)['total']);
        $this->assertSame(1, $runner->run($kits, [], $head, all: true)['total']);
        foreach (['trainers' => 'trainer', 'rooms' => 'room', 'plan_items' => 'title'] as $dataset => $field) {
            $this->assertSame(0, $runner->run($this->def($dataset, [['field' => $field]]), [], $admin, all: true)['total'], "{$dataset} is for the centre");
        }
    }

    public function test_the_builder_accepts_only_listed_fields_operators_and_aggregates(): void
    {
        $this->world();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $post = fn (array $body) => $this->asUser($head)->postJson('/api/v1/admin/report-definitions/preview', $body + ['title_ar' => 'ت', 'title_en' => 't', 'dataset' => 'registrations', 'columns' => [['field' => 'program']]]);

        $post([])->assertOk()->assertJsonPath('data.total', 2);
        $post(['columns' => [['field' => 'password']]])->assertStatus(422)->assertJsonPath('code', 'field_not_allowed');
        $post(['columns' => [['field' => 'u.password']]])->assertStatus(422);
        $post(['columns' => [['field' => 'program', 'aggregate' => 'sum']]])->assertStatus(422)->assertJsonPath('code', 'aggregate_not_allowed');
        $post(['columns' => [['field' => 'registrations']]])->assertStatus(422)->assertJsonPath('code', 'aggregate_required');
        $post(['filters' => [['field' => 'program', 'operator' => 'gt', 'value' => '1']]])->assertStatus(422)->assertJsonPath('code', 'operator_not_allowed');
        $post(['filters' => [['field' => 'status; drop table users', 'operator' => 'eq', 'value' => 'x']]])->assertStatus(422)->assertJsonPath('code', 'field_not_allowed');
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $this->asUser($coordinator)->postJson('/api/v1/admin/report-definitions/preview', ['title_ar' => 'ت', 'title_en' => 't', 'dataset' => 'employees', 'columns' => [['field' => 'national_id']]])->assertStatus(422)->assertJsonPath('code', 'personal_not_allowed');
        $post = fn (array $body) => $this->asUser($head)->postJson('/api/v1/admin/report-definitions/preview', $body + ['title_ar' => 'ت', 'title_en' => 't', 'dataset' => 'registrations', 'columns' => [['field' => 'program']]]);
        $post(['dataset' => 'nope'])->assertStatus(422);

        // A value that looks like SQL is only ever a value.
        $r = $post(['filters' => [['field' => 'program', 'operator' => 'contains', 'value' => "x' or '1'='1"]]])->assertOk();
        $this->assertSame(0, $r->json('data.total'));
        $this->assertSame(2, \DB::table('registrations')->count());
    }

    public function test_aggregates_group_and_total_correctly(): void
    {
        $this->world();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $res = app(ReportRunner::class)->run($this->def('registrations', [['field' => 'status'], ['field' => 'registrations', 'aggregate' => 'count'], ['field' => 'attendance_percent', 'aggregate' => 'avg'], ['field' => 'program_hours', 'aggregate' => 'sum']],
            ['chart' => ['type' => 'bar', 'x' => 'status', 'y' => 'registrations__count'], 'sort' => [['field' => 'registrations__count', 'dir' => 'desc']]]), [], $head, all: true);

        $this->assertSame(1, $res['total']);                                    // one group: completed
        $row = $res['rows'][0];
        $this->assertSame('completed', $row['status']);
        $this->assertEquals(2, $row['registrations__count']);
        $this->assertEquals(81.5, $row['attendance_percent__avg']);             // (81 + 82) / 2
        $this->assertEquals(20, $row['program_hours__sum']);
        $this->assertEquals(2, $res['totals']['registrations__count']);
        $this->assertSame('completed', $res['chart']['points'][0]['label']);

        // Filters: operators on numbers, dates and text.
        $one = fn (array $f) => app(ReportRunner::class)->run($this->def('registrations', [['field' => 'employee_no']], ['filters' => [$f]]), [], $head, all: true)['total'];
        $this->assertSame(1, $one(['field' => 'attendance_percent', 'operator' => 'gte', 'value' => 82]));
        $this->assertSame(2, $one(['field' => 'attendance_percent', 'operator' => 'between', 'value' => [80, 90]]));
        $this->assertSame(2, $one(['field' => 'created_at', 'operator' => 'lte', 'value' => now()->toDateString()]));   // the whole day counts
        $this->assertSame(0, $one(['field' => 'created_at', 'operator' => 'gte', 'value' => now()->addDay()->toDateString()]));
        $this->assertSame(1, $one(['field' => 'program', 'operator' => 'contains', 'value' => 'برنامج 1']));
        $this->assertSame(2, $one(['field' => 'status', 'operator' => 'in', 'value' => ['completed', 'approved']]));
        $this->assertSame(2, $one(['field' => 'tasks_completed', 'operator' => 'is_false']));
    }

    public function test_every_built_in_report_runs_for_the_roles_it_is_made_for(): void
    {
        $this->world();
        app(BuiltInReports::class)->ensure();
        $runs = app(ReportRunService::class);
        $super = $this->makeUser(Role::SUPER_ADMIN);
        $keys = array_column(BuiltInReports::all(), 'key');
        $this->assertGreaterThanOrEqual(35, count($keys));

        foreach (ReportDefinition::where('is_system', true)->get() as $def) {
            $result = $runs->preview($def, [], $super, 1, 20);
            $this->assertArrayHasKey('columns', $result, $def->key);
            $this->assertNotEmpty($result['columns'], $def->key);
        }

        // Spot checks on content.
        $by = fn (string $key) => ReportDefinition::where('key', $key)->firstOrFail();
        $this->assertSame(2, $runs->preview($by('employee_courses'), [], $super, 1, 20)['total']);
        $stats = $runs->preview($by('achievement_statistics'), [], $super, 1, 20);
        $this->assertSame(5, $stats['parts']);
        $matrix = $runs->preview($by('programs_licences_matrix'), [], $super, 1, 20);
        $this->assertGreaterThanOrEqual(2, $matrix['total']);
        $this->assertGreaterThan(4, count($matrix['columns']));                 // fixed columns + programs
        $this->assertSame(['✔'], array_values(array_unique(array_filter(array_map(fn ($r) => collect($r)->slice(4)->filter()->first(), array_filter($matrix['rows'], fn ($r) => $r['school'] !== null))))));
    }

    public function test_reports_for_a_role_are_listed_only_to_that_role_and_trainee_reports_are_always_about_the_person(): void
    {
        [$a, $b] = $this->world();
        $employee = $a['employee']->user->load('roles');
        $trainer = $this->makeUser(Role::TRAINER);

        $titles = fn (User $u) => collect($this->asUser($u)->getJson('/api/v1/admin/report-definitions')->assertOk()->json('data'))->pluck('key')->all();
        $emp = $titles($employee);
        $this->assertContains('my_courses_statement', $emp);
        $this->assertNotContains('employees_data', $emp);
        $tr = $titles($trainer);
        $this->assertContains('trainer_calendar', $tr);
        $this->assertNotContains('employees_data', $tr);
        $this->assertContains('employees_data', $titles($this->makeUser(Role::CENTER_ADMIN)));

        // The trainee's statement lists only their own course, whatever filters they send.
        $own = $this->asUser($employee)->getJson('/api/v1/me/reports/my_courses_statement')->assertOk();
        $this->assertSame(1, $own->json('data.total'));
        $this->assertSame($a['program']->title_ar, $own->json('data.rows.0.program'));
        $this->asUser($employee)->getJson('/api/v1/me/reports/employees_data')->assertNotFound();
        $csv = $this->asUser($employee)->get('/api/v1/me/reports/my_courses_statement/export?format=pdf&lang=ar');
        $csv->assertOk();
        $this->assertStringStartsWith('%PDF', $csv->getContent());

        // Another person may not run someone else's private report.
        $mine = $this->asUser($this->makeUser(Role::TRAINING_HEAD))->postJson('/api/v1/admin/report-definitions', ['title_ar' => 'خاص', 'title_en' => 'Private', 'dataset' => 'registrations', 'columns' => [['field' => 'program']]])->assertCreated()->json('data.id');
        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson("/api/v1/admin/report-definitions/{$mine}/preview", [])->assertForbidden();
    }

    public function test_exports_open_in_all_three_formats_and_large_ones_wait_for_the_minute_job(): void
    {
        $this->world();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $def = $this->asUser($head)->postJson('/api/v1/admin/report-definitions', ['title_ar' => 'تقرير', 'title_en' => 'Report', 'dataset' => 'registrations', 'columns' => [['field' => 'employee_no'], ['field' => 'program'], ['field' => 'status']]])->assertCreated()->json('data.id');

        $run = $this->asUser($head)->postJson("/api/v1/admin/report-definitions/{$def}/run", ['formats' => ['xlsx', 'pdf', 'docx'], 'lang' => 'ar'])->assertCreated();
        $this->assertSame('ready', $run->json('data.status'));
        $id = $run->json('data.id');
        $this->assertStringStartsWith('PK', $this->asUser($head)->get("/api/v1/admin/report-runs/{$id}/download?format=xlsx")->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', $this->asUser($head)->get("/api/v1/admin/report-runs/{$id}/download?format=pdf")->assertOk()->getContent());
        $this->assertStringStartsWith('PK', $this->asUser($head)->get("/api/v1/admin/report-runs/{$id}/download?format=docx")->assertOk()->getContent());
        $this->asUser($this->makeUser(Role::TRAINING_HEAD))->get("/api/v1/admin/report-runs/{$id}/download?format=xlsx")->assertForbidden();   // someone else's run

        config(['tedc.reports.inline_rows' => 1]);
        $big = $this->asUser($head)->postJson("/api/v1/admin/report-definitions/{$def}/run", ['formats' => ['xlsx']])->assertCreated();
        $this->assertSame('queued', $big->json('data.status'));
        $this->asUser($head)->get('/api/v1/admin/report-runs/'.$big->json('data.id').'/download?format=xlsx')->assertStatus(422)->assertJsonPath('code', 'not_ready');
        $this->artisan('tedc:reports-run')->assertSuccessful();
        $this->assertSame('ready', ReportRun::find($big->json('data.id'))->status);
        $this->assertSame(1, AppNotification::where('user_id', $head->id)->where('type', 'report.ready')->count());
    }

    public function test_exports_with_personal_data_need_the_permission_and_are_audited(): void
    {
        $this->world();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $body = ['title_ar' => 'ش', 'title_en' => 'p', 'dataset' => 'employees', 'columns' => [['field' => 'employee_no'], ['field' => 'national_id']]];
        $this->asUser($coordinator)->postJson('/api/v1/admin/report-definitions', $body)->assertCreated()->json('data.id');   // saving is fine; running is what is checked
        $id = $this->asUser($head)->postJson('/api/v1/admin/report-definitions', $body)->assertCreated()->json('data.id');
        $this->assertFalse($coordinator->hasPermission('reports.export_personal'));

        $run = $this->asUser($head)->postJson("/api/v1/admin/report-definitions/{$id}/run", ['formats' => ['xlsx']])->assertCreated()->json('data.id');
        $this->asUser($head)->get("/api/v1/admin/report-runs/{$run}/download?format=xlsx")->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'report.export_personal')->where('auditable_id', $run)->count());
        $this->assertTrue(ReportRun::find($run)->personal);
    }

    public function test_scheduled_reports_are_sent_with_a_signed_link_and_failures_alert_administrators(): void
    {
        $this->world();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $reader = $this->makeUser(Role::EXECUTIVE);
        app(BuiltInReports::class)->ensure();
        $def = ReportDefinition::where('key', 'employee_courses')->first();

        $id = $this->asUser($head)->postJson('/api/v1/admin/report-schedules', ['definition_id' => $def->id, 'frequency' => 'weekly', 'formats' => ['pdf'], 'recipients' => ['users' => [$reader->id], 'emails' => ['ministry@example.test']]])->assertCreated()->json('data.id');
        $this->assertTrue(ReportSchedule::find($id)->next_run_at->isFuture());
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/report-schedules')->assertForbidden();

        ReportSchedule::whereKey($id)->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('tedc:reports-run')->assertSuccessful();
        $note = AppNotification::where('user_id', $reader->id)->where('type', 'report.scheduled')->first();
        $this->assertNotNull($note);
        preg_match('#https?://\S+report-runs/[0-9a-f-]+/download\?\S+#', (string) $note->body_en, $m);
        $this->assertNotEmpty($m, 'the notification carries a download link');
        $url = rtrim($m[0], ' ·');
        $this->get($url)->assertOk();
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=bad', $url))->assertStatus(403);

        $s = ReportSchedule::find($id);
        $this->assertTrue($s->next_run_at->isFuture());
        $this->assertNotNull($s->last_run_at);

        // A report that cannot be produced: the administrators hear about it, the schedule moves on.
        ReportDefinition::whereKey($def->id)->update(['dataset' => 'gone']);
        ReportSchedule::whereKey($id)->update(['next_run_at' => now()->subMinute()]);
        $this->artisan('tedc:reports-run')->assertSuccessful();
        $this->assertNotNull(ReportSchedule::find($id)->last_error);
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'report.schedule_failed')->count());
    }

    public function test_the_signed_link_expires(): void
    {
        $this->world();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $def = ReportDefinition::create(['title_ar' => 'ت', 'title_en' => 't', 'dataset' => 'registrations', 'columns' => [['field' => 'program']], 'visibility' => 'private', 'owner_id' => $head->id]);
        $run = app(ReportRunService::class)->request($def, [], $head, ['xlsx'], 'ar');
        $url = URL::temporarySignedRoute('report-runs.signed', now()->addMinutes(5), ['run' => $run->id, 'format' => 'xlsx']);
        $this->get($url)->assertOk();
        $this->travel(10)->minutes();
        $this->get($url)->assertStatus(403);
    }
}
