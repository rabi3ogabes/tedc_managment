<?php

namespace Tests\Feature;

use App\Migration\Cleanser;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\MigrationBatch;
use App\Models\MigrationRow;
use App\Models\PdActivity;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);   // the import steps are rate limited
    }

    private function admin(): User
    {
        return $this->makeUser(Role::CENTER_ADMIN);
    }

    private function csv(array $rows, string $name = 'data.csv'): UploadedFile
    {
        $out = "\xEF\xBB\xBF".implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r)), $rows));

        return UploadedFile::fake()->createWithContent($name, $out);
    }

    private function upload(User $admin, string $kind, array $rows)
    {
        return $this->asUser($admin)->post('/api/v1/admin/migration/batches', ['kind' => $kind, 'file' => $this->csv($rows)], ['Accept' => 'application/json']);
    }

    private function step(User $admin, string $id, string $action)
    {
        return $this->asUser($admin)->postJson("/api/v1/admin/migration/batches/{$id}/{$action}");
    }

    /** Uploads, validates and imports in one go; returns the batch id. */
    private function load(User $admin, string $kind, array $rows): string
    {
        $id = $this->upload($admin, $kind, $rows)->assertCreated()->json('data.id');
        $this->step($admin, $id, 'validate')->assertOk();
        $this->step($admin, $id, 'import')->assertOk();

        return $id;
    }

    public function test_cleansing_handles_arabic_text_digits_dates_and_gender(): void
    {
        $this->assertSame('احمد علي', Cleanser::arabic('  أَحْمَد   عَلِيّ '));                       // diacritics, spaces and alef/yeh variants unified
        $this->assertSame('مدرسه', Cleanser::arabic('مدرسـه'));                                      // tatweel removed
        $this->assertSame('E-1234', Cleanser::code(' e-١٢٣٤ '));                                       // Arabic-Indic digits, upper case
        $this->assertSame('+97455512345', Cleanser::phone('+٩٧٤ 5551-2345'));
        $this->assertNull(Cleanser::phone('123'));
        $this->assertSame('a@b.qa', Cleanser::email('  A@B.QA '));
        $this->assertNull(Cleanser::email('not an email'));
        $this->assertSame('female', Cleanser::gender('أنثى'));
        $this->assertSame('male', Cleanser::gender('M'));
        $this->assertNull(Cleanser::gender('?'));
        $this->assertSame('2024-03-01', Cleanser::date('2024-03-01'));
        $this->assertSame('2024-03-01', Cleanser::date('01/03/2024'));                                // day first
        $this->assertSame('2024-03-01', Cleanser::date('١-٣-٢٠٢٤'));
        $this->assertSame('2024-03-01', Cleanser::date(45352));                                       // an Excel day number
        $this->assertNull(Cleanser::date('31/02/2024'));                                              // not a real date
        $this->assertNull(Cleanser::date('soon'));
        $this->assertSame(12.5, Cleanser::number('١٢٫٥'));
    }

    public function test_templates_and_the_column_mapping_recognise_arabic_and_english_headers(): void
    {
        $admin = $this->admin();
        $csv = $this->asUser($admin)->get('/api/v1/admin/migration/templates/employees?lang=ar')->assertOk();
        $this->assertStringContainsString('الرقم الوظيفي *', $csv->getContent());
        $this->assertStringStartsWith('PK', $this->asUser($admin)->get('/api/v1/admin/migration/templates/employees?format=xlsx&lang=en')->assertOk()->getContent());
        $this->asUser($admin)->get('/api/v1/admin/migration/templates/nonsense')->assertNotFound();

        $b = $this->upload($admin, 'employees', [['Personal No', 'الاسم', 'Mail', 'المدرسة', 'Colour'], ['E-1', 'حمد', 'h@x.qa', 'S-1', 'blue']])->assertCreated();
        $this->assertSame(['Personal No' => 'employee_no', 'الاسم' => 'name', 'Mail' => 'email', 'المدرسة' => 'school_code'], $b->json('data.mapping'));
        $this->assertSame(1, $b->json('data.total_rows'));
        $this->assertContains('Colour', $b->json('data.columns'));                                    // columns that map to nothing are shown, not lost
        $this->assertNotContains('"Colour"', [$b->json('data.mapping')]);
    }

    public function test_validation_reports_each_problem_and_marks_repeats(): void
    {
        $admin = $this->admin();
        $school = $this->makeSchool(['code' => 'SCH-1']);
        $title = JobTitle::first();
        $this->makeEmployee(['employee_no' => 'E-2']);
        $rows = [
            ['employee_no', 'name', 'email', 'school_code', 'job_title_code', 'gender', 'hire_date'],
            ['e-١', 'Hamad', 'HAMAD@MOE.QA', 'sch-1', $title->code, 'ذكر', '01/09/2018'],          // fine, once cleansed
            ['E-1', 'Duplicate', 'dup@moe.qa', 'SCH-1', $title->code, 'male', '2018-09-01'],        // the same person again
            ['E-3', 'No mail', '', 'SCH-1', $title->code, '', ''],                                  // a new person needs an e-mail
            ['E-4', 'Bad mail', 'nope', 'SCH-1', $title->code, '', ''],
            ['E-5', 'Unknown school', 'e5@moe.qa', 'SCH-X', $title->code, '', ''],
            ['E-6', 'Bad date', 'e6@moe.qa', 'SCH-1', 'NO-TITLE', '', '31/02/2024'],
            ['', 'No number', 'n@moe.qa', 'SCH-1', $title->code, '', ''],
            ['E-2', 'Existing', 'ex@moe.qa', 'SCH-1', $title->code, 'female', ''],                 // exists: will be updated, so no e-mail needed
        ];
        $id = $this->upload($admin, 'employees', $rows)->assertCreated()->json('data.id');
        $r = $this->step($admin, $id, 'validate')->assertOk();
        $this->assertSame(8, $r->json('data.total'));
        $this->assertSame(2, $r->json('data.valid'));
        $this->assertSame(1, $r->json('data.duplicate'));
        $this->assertSame(5, $r->json('data.invalid'));
        $this->assertSame(1, $r->json('data.will_create'));
        $this->assertSame(1, $r->json('data.will_update'));
        $problems = $r->json('data.problems');
        foreach (['missing:email', 'invalid:email', 'unknown:school_code', 'invalid:hire_date', 'unknown:job_title_code', 'missing:employee_no'] as $p) {
            $this->assertArrayHasKey($p, $problems, $p);
        }
        $rows = $this->asUser($admin)->getJson("/api/v1/admin/migration/batches/{$id}/rows?status=invalid")->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame(['E-3'], array_column(array_filter($rows->json('data'), fn ($x) => in_array('missing:email', $x['errors'], true)), 'key'));
        $first = MigrationRow::where('batch_id', $id)->where('row_no', 2)->first();
        $this->assertSame('E-1', $first->mapped['employee_no']);                                    // cleansed
        $this->assertSame('hamad@moe.qa', $first->mapped['email']);
        $this->assertSame('male', $first->mapped['gender']);
        $this->assertSame('2018-09-01', $first->mapped['hire_date']);
        $this->assertStringNotContainsString('HAMAD', (string) $first->getRawOriginal('data'));     // the source rows are stored encrypted

        $csv = $this->asUser($admin)->get("/api/v1/admin/migration/batches/{$id}/errors")->assertOk()->getContent();
        $this->assertStringContainsString('missing:email', $csv);                                    // a file to correct and upload again
        $this->assertStringContainsString('duplicate_of_row:2', $csv);
    }

    public function test_mapping_value_maps_and_defaults_transform_the_rows(): void
    {
        $admin = $this->admin();
        $this->makeSchool(['code' => 'SCH-1']);
        $teacher = JobTitle::where('code', 'TEACHER')->first();
        $id = $this->upload($admin, 'employees', [['رقم', 'الاسم', 'بريد', 'مدرسة', 'الوظيفة'], ['E-9', 'سارة', 's@moe.qa', 'مدرسة الشمال', 'معلمة']])->assertCreated()->json('data.id');
        $this->asUser($admin)->putJson("/api/v1/admin/migration/batches/{$id}/mapping", [
            'mapping' => ['رقم' => 'employee_no', 'الاسم' => 'name', 'بريد' => 'email', 'مدرسة' => 'school_code', 'الوظيفة' => 'job_title_code'],
            'value_maps' => ['school_code' => ['مدرسة الشمال' => 'SCH-1'], 'job_title_code' => ['معلمه' => $teacher->code]],        // the map ignores alef/yeh/teh-marbuta spelling variants
            'defaults' => ['nationality' => 'QA'],
        ])->assertOk();
        $r = $this->step($admin, $id, 'validate')->assertOk();
        $this->assertSame(1, $r->json('data.valid'), json_encode($r->json('data.problems')));
        $this->step($admin, $id, 'import')->assertOk();
        $e = Employee::where('employee_no', 'E-9')->first();
        $this->assertSame('QA', $e->nationality);
        $this->assertSame($teacher->id, $e->job_title_id);
        $this->asUser($admin)->putJson("/api/v1/admin/migration/batches/{$id}/mapping", ['mapping' => ['رقم' => 'employee_no']])->assertStatus(422)->assertJsonPath('code', 'batch_closed');
    }

    public function test_a_dry_run_changes_nothing_and_an_import_reconciles(): void
    {
        $admin = $this->admin();
        $this->makeSchool(['code' => 'SCH-1']);
        $this->makeEmployee(['employee_no' => 'E-2', 'qualification' => 'diploma'], $this->makeUser(Role::EMPLOYEE, ['email' => 'e2@moe.qa', 'name' => 'Before']));
        $before = ['employees' => Employee::count(), 'users' => User::count()];
        $id = $this->upload($admin, 'employees', [['employee_no', 'name', 'email', 'school_code', 'qualification'], ['E-1', 'New One', 'e1@moe.qa', 'SCH-1', 'master'], ['E-2', 'After', 'e2@moe.qa', 'SCH-1', 'phd'], ['E-3', 'Bad', 'x', 'SCH-1', '']])->json('data.id');
        $this->step($admin, $id, 'dry-run')->assertStatus(422)->assertJsonPath('code', 'not_validated');
        $this->step($admin, $id, 'validate')->assertOk();

        $dry = $this->step($admin, $id, 'dry-run')->assertOk();
        $this->assertSame(1, $dry->json('data.created'));
        $this->assertSame(1, $dry->json('data.updated'));
        $this->assertSame($before, ['employees' => Employee::count(), 'users' => User::count()]);        // nothing was kept
        $this->assertSame('diploma', Employee::where('employee_no', 'E-2')->first()->qualification);
        $this->assertNotNull(MigrationBatch::find($id)->report['dry_run']);

        $r = $this->step($admin, $id, 'import')->assertOk();
        $this->assertSame(['source_rows' => 3, 'valid' => 2, 'invalid' => 1, 'duplicate' => 0, 'pending' => 0, 'imported' => 2, 'imported_created' => 1, 'imported_updated' => 1], collect($r->json('data'))->only(['source_rows', 'valid', 'invalid', 'duplicate', 'pending', 'imported', 'imported_created', 'imported_updated'])->all());
        $this->assertTrue($r->json('data.accounted_for'));
        $this->assertTrue($r->json('data.matches'));
        $this->assertSame($r->json('data.checksum_expected'), $r->json('data.checksum_imported'));
        $this->assertSame($before['employees'] + 1, Employee::count());
        $this->assertSame('phd', Employee::where('employee_no', 'E-2')->first()->qualification);
        $this->assertSame('New One', User::where('email', 'e1@moe.qa')->first()->name);
        $this->assertContains(Role::EMPLOYEE, User::where('email', 'e1@moe.qa')->first()->roles()->pluck('slug')->all());
        $this->step($admin, $id, 'import')->assertStatus(422)->assertJsonPath('code', 'already_imported');
        $this->assertSame(2, AuditLog::whereIn('action', ['migration_uploaded', 'migration_imported'])->count());

        // Uploading the same file again updates, it does not duplicate.
        $again = $this->load($admin, 'employees', [['employee_no', 'name', 'email', 'school_code'], ['E-1', 'New One Renamed', 'e1@moe.qa', 'SCH-1']]);
        $this->assertSame($before['employees'] + 1, Employee::count());
        $this->assertSame('New One Renamed', User::where('email', 'e1@moe.qa')->first()->name);
        $this->assertSame(1, MigrationBatch::find($again)->updated_rows);
    }

    public function test_a_batch_can_be_rolled_back_to_the_way_things_were(): void
    {
        $admin = $this->admin();
        $this->makeSchool(['code' => 'SCH-1']);
        $existing = $this->makeEmployee(['employee_no' => 'E-2', 'qualification' => 'diploma', 'nationality' => 'QA'], $this->makeUser(Role::EMPLOYEE, ['email' => 'e2@moe.qa', 'name' => 'Original Name']));
        $counts = ['employees' => Employee::count(), 'users' => User::count(), 'roles' => DB::table('role_user')->count()];
        $id = $this->load($admin, 'employees', [['employee_no', 'name', 'email', 'school_code', 'qualification', 'nationality'], ['E-1', 'Newcomer', 'e1@moe.qa', 'SCH-1', 'master', 'EG'], ['E-2', 'Changed Name', 'e2@moe.qa', 'SCH-1', 'phd', 'EG']]);
        $this->assertSame($counts['employees'] + 1, Employee::count());

        $r = $this->step($admin, $id, 'rollback')->assertOk();
        $this->assertSame(['removed' => 2, 'restored' => 1], $r->json('data'));                       // the new employee and user (the role grant goes with the user), and one record restored
        $this->assertSame($counts, ['employees' => Employee::count(), 'users' => User::count(), 'roles' => DB::table('role_user')->count()]);
        $e = $existing->fresh();
        $this->assertSame('diploma', $e->qualification);
        $this->assertSame('QA', $e->nationality);
        $this->assertSame('Original Name', $e->user->name);
        $this->assertSame('rolled_back', MigrationBatch::find($id)->status);
        $this->step($admin, $id, 'rollback')->assertStatus(422)->assertJsonPath('code', 'not_imported');
        $this->assertSame(1, AuditLog::where('action', 'migration_rolled_back')->count());
    }

    public function test_the_other_kinds_bring_in_programs_registrations_attendance_certificates_and_pd(): void
    {
        $admin = $this->admin();
        $this->makeSchool(['code' => 'SCH-1']);
        $this->load($admin, 'employees', [['employee_no', 'name', 'email', 'school_code'], ['E-1', 'One', 'one@moe.qa', 'SCH-1'], ['E-2', 'Two', 'two@moe.qa', 'SCH-1']]);
        $this->load($admin, 'trainers', [['email', 'name_ar', 'name_en', 'specializations', 'is_external'], ['tr@x.com', 'سعد', 'Saad', 'A; B', 'نعم']]);
        $this->assertTrue(Trainer::where('email', 'tr@x.com')->first()->is_external);

        $p = $this->load($admin, 'programs', [['code', 'title_ar', 'title_en', 'total_hours', 'start_date', 'end_date'], ['old-1', 'برنامج قديم', 'Old program', '20', '01/02/2022', '05/02/2022']]);
        $program = Program::where('code', 'OLD-1')->first();
        $this->assertSame(Program::STATUS_COMPLETED, $program->status);                              // history, not offered for registration
        $this->assertEquals(20, $program->total_hours);

        $this->load($admin, 'registrations', [['employee_no', 'program_code', 'status', 'completed_at', 'attendance_percent'], ['E-1', 'OLD-1', 'ناجح', '05/02/2022', '95'], ['E-2', 'OLD-1', 'cancelled', '', '']]);
        $this->assertSame('completed', Registration::where('program_id', $program->id)->whereHas('employee', fn ($q) => $q->where('employee_no', 'E-1'))->first()->status);
        $this->assertSame('bulk_import', Registration::where('program_id', $program->id)->first()->source);

        $a = $this->load($admin, 'attendance', [['employee_no', 'program_code', 'attendance_percent'], ['E-1', 'OLD-1', '88%'], ['E-2', 'NOPE', '50']]);
        $this->assertEquals(88.0, (float) Registration::where('program_id', $program->id)->whereHas('employee', fn ($q) => $q->where('employee_no', 'E-1'))->first()->attendance_percent);

        $c = $this->load($admin, 'certificates', [['certificate_no', 'employee_no', 'program_code', 'issued_at', 'hours'], ['LEG-0001', 'E-1', 'OLD-1', '06/02/2022', '20'], ['LEG-0002', 'E-2', 'OLD-1', '06/02/2022', '']]);
        $cert = Certificate::where('certificate_no', 'LEG-0001')->first();
        $this->assertSame('valid', $cert->status);
        $this->assertSame('2022-02-06', $cert->issued_at->toDateString());
        $this->assertNotEmpty($cert->verification_code);                                              // it can be verified on the public page
        $this->assertSame(2, Certificate::count());

        \DB::table('pd_activity_types')->insert(['id' => (string) Str::uuid(), 'code' => 'CONF', 'name_ar' => 'مؤتمر', 'name_en' => 'Conference', 'hour_rules' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $pd = $this->load($admin, 'pd', [['employee_no', 'type_code', 'title', 'date', 'hours', 'provider'], ['E-1', 'conf', 'Regional conference', '10/03/2023', '6', 'MOE'], ['E-1', 'BAD', 'x', '10/03/2023', '6', ''], ['E-1', 'conf', 'Too long', '10/03/2023', '999', '']]);
        $this->assertSame(1, PdActivity::count());
        $this->assertEquals(6, PdActivity::first()->approved_hours);
        $this->assertSame('approved', PdActivity::first()->status);

        // Rolling certificates back removes the certificates and the registration made for them.
        $this->step($admin, $c, 'rollback')->assertOk();
        $this->assertSame(0, Certificate::count());
        $this->assertNotNull($p);
        $this->assertNotNull($a);
        $this->assertNotNull($pd);
    }

    public function test_access_retention_and_limits(): void
    {
        $admin = $this->admin();
        $employee = $this->makeUser(Role::EMPLOYEE);
        $this->asUser($employee)->getJson('/api/v1/admin/migration/batches')->assertForbidden();
        $this->asUser($admin)->post('/api/v1/admin/migration/batches', ['kind' => 'employees', 'file' => $this->csv([['employee_no']])], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'empty_file');
        $this->asUser($admin)->post('/api/v1/admin/migration/batches', ['kind' => 'unknown', 'file' => $this->csv([['a'], ['b']])], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asUser($admin)->post('/api/v1/admin/migration/batches', ['kind' => 'employees', 'file' => UploadedFile::fake()->create('x.exe', 5)], ['Accept' => 'application/json'])->assertStatus(422);

        // A Windows-1256 export (older Excel) is read correctly.
        $legacy = UploadedFile::fake()->createWithContent('old.csv', iconv('UTF-8', 'CP1256', "employee_no,name\nE-9,حمد\n"));
        $id = $this->asUser($admin)->post('/api/v1/admin/migration/batches', ['kind' => 'employees', 'file' => $legacy], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertSame('حمد', MigrationRow::where('batch_id', $id)->first()->source()['name']);

        // The uploaded data is deleted after its retention; the counts and audit trail stay.
        $this->step($admin, $id, 'validate')->assertOk();
        MigrationBatch::whereKey($id)->update(['expires_at' => now()->subDay()]);
        $this->artisan('tedc:migration-purge')->assertSuccessful();
        $this->assertSame(0, MigrationRow::where('batch_id', $id)->count());
        $this->assertSame(1, MigrationBatch::find($id)->total_rows);
        $this->step($admin, $id, 'validate')->assertStatus(422)->assertJsonPath('code', 'data_purged');
    }
}
