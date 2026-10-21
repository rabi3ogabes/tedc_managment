<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeNumber;
use App\Models\PdActivity;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\AnnualHoursService;
use App\Services\Import\TraineeHistoryImport;
use App\Support\XlsxStream;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** The Ministry's trainee extract (employees × courses attended) — checked with a synthetic workbook; the real files never enter the repository. */
class TraineeHistoryImportTest extends TestCase
{
    private const HEAD = ['IDNO', 'EMPNO_SEQ', 'EMPTYPE', 'EMPNAME', 'NATNAME', 'EMPNO', 'SECAPPMDT', 'ORGNNAME', 'SUBORGNAME', 'EQLEVEL_DESC', 'CERT_NAME', 'SP_CODE_DESC', 'JOBNAME', 'EMPJOBCLASS_DESC', 'EMPCLASS_DESC',
        'EMP_TYPE_DESC', 'SEX_DESC', 'SEC_EMAIL', 'MOBILE_NO', 'COURSE_TYPE_NAME_A', 'TRACK_DESCE', 'COURSE_NAME_A', 'COUNTRY_NAME_A', 'PROVIDER_NAME_A', 'COURSE_DT_FROM', 'COURSE_DT_END', 'DURATION', 'PROG_JOBNO', 'PROG_JOBNO_DESC'];

    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** A row of the extract: defaults are a permanent female teacher at school A; $o overrides by column name. */
    private function row(array $o = []): array
    {
        $d = ['IDNO' => 20000000001, 'EMPNO_SEQ' => 0, 'EMPTYPE' => null, 'EMPNAME' => 'منى الاختبار', 'NATNAME' => 'قطرية', 'EMPNO' => 7001, 'SECAPPMDT' => '2015-09-01', 'ORGNNAME' => 'مدرسة الاختبار الابتدائية للبنات',
            'SUBORGNAME' => 'مدرسة الاختبار الابتدائية للبنات', 'EQLEVEL_DESC' => 'جامعي', 'CERT_NAME' => 'بكالوريوس تربية', 'SP_CODE_DESC' => 'رياضيات', 'JOBNAME' => 'معلم رياضيات', 'EMPJOBCLASS_DESC' => 'أكاديمية بالمدارس',
            'EMPCLASS_DESC' => 'دائم', 'EMP_TYPE_DESC' => 'موظف', 'SEX_DESC' => 'أنثى', 'SEC_EMAIL' => 'mona.test@example.test', 'MOBILE_NO' => '97455500001', 'COURSE_TYPE_NAME_A' => 'دورة', 'TRACK_DESCE' => 'برامج تخصصية',
            'COURSE_NAME_A' => 'دورة الاختبار الأولى', 'COUNTRY_NAME_A' => 'قطر', 'PROVIDER_NAME_A' => 'المجلس الأعلى للتعليم', 'COURSE_DT_FROM' => '2024-02-04', 'COURSE_DT_END' => '2024-02-05', 'DURATION' => 6, 'PROG_JOBNO' => 130, 'PROG_JOBNO_DESC' => 'معلم رياضيات'];

        return array_map(fn ($h) => array_key_exists($h, $o) ? $o[$h] : $d[$h], self::HEAD);
    }

    /** @param list<array<int, mixed>> $rows */
    private function workbook(array $rows, string $name = 'trainees'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Sheet 1');
        $sheet->fromArray(self::HEAD, null, 'A1');
        foreach ($rows as $i => $r) {
            foreach ($r as $c => $v) {
                $cell = Coordinate::stringFromColumnIndex($c + 1).($i + 2);
                if (in_array(self::HEAD[$c], ['SECAPPMDT', 'COURSE_DT_FROM', 'COURSE_DT_END'], true) && is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $sheet->setCellValue($cell, Date::PHPToExcel(Carbon::parse($v)));
                } elseif ($v !== null) {
                    $sheet->setCellValueExplicit($cell, $v, is_int($v) || is_float($v) ? 'n' : 's');
                }
            }
        }
        $book->createSheet()->setTitle('SQL Statement')->setCellValue('A1', 'SQL Statement');
        $path = tempnam(sys_get_temp_dir(), $name).'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->made[] = $path;
    }

    public function test_the_stream_reader_returns_every_row_and_cell_exactly(): void
    {
        $f = $this->workbook([$this->row(), $this->row(['EMPNO' => 7002, 'IDNO' => 20000000002, 'SEC_EMAIL' => null, 'DURATION' => 2.5])]);
        $rows = iterator_to_array(XlsxStream::rows($f, 'Sheet 1'));
        $this->assertCount(3, $rows);
        $this->assertSame('IDNO', $rows[0][0]);
        $this->assertSame(20000000001, $rows[1][0]);
        $this->assertSame('منى الاختبار', $rows[1][3]);
        $this->assertSame(2.5, $rows[2][26]);
        $this->assertArrayNotHasKey(17, $rows[2]);   // an empty cell is absent
        $this->assertSame(['SQL Statement'], array_values(iterator_to_array(XlsxStream::rows($f, 'SQL Statement'))[0]));
    }

    public function test_people_schools_job_titles_and_every_course_are_imported_and_a_second_run_changes_nothing(): void
    {
        $existing = School::create(['code' => 'EX-1', 'name_en' => 'x', 'name_ar' => 'مدرسة الاختبار الابتدائية للبنات', 'type' => 'government', 'stage' => 'primary', 'region' => 'doha']);
        $rows = [
            // A teacher with two courses.
            $this->row(),
            $this->row(['COURSE_NAME_A' => 'دورة الاختبار الثانية', 'COURSE_DT_FROM' => '2023-10-01', 'COURSE_DT_END' => '2023-10-01', 'DURATION' => 4, 'TRACK_DESCE' => 'ترخيص']),
            // The same person re-hired under a new number, in another school and unit; no e-mail on the new record, a different phone.
            $this->row(['EMPNO' => 7005, 'EMPNO_SEQ' => 1, 'SECAPPMDT' => '2020-01-10', 'ORGNNAME' => 'مدرسة ثانية الإعدادية للبنين', 'SUBORGNAME' => 'روضة مدرسة ثانية الإعدادية للبنين', 'SEC_EMAIL' => null,
                'MOBILE_NO' => null, 'COURSE_NAME_A' => 'دورة بعد إعادة التعيين', 'DURATION' => 12]),
            // A principal who resigned; a male teacher with no e-mail; a person prepared for deletion.
            $this->row(['IDNO' => 20000000002, 'EMPNO' => 7002, 'EMPNAME' => '  خالد   الاختبار ', 'NATNAME' => 'جزائري', 'SEX_DESC' => 'ذكر', 'JOBNAME' => 'مدير مدرسة', 'PROG_JOBNO' => 77, 'PROG_JOBNO_DESC' => 'مدير مدرسة',
                'EMPJOBCLASS_DESC' => 'قيادية', 'EMPCLASS_DESC' => 'استقالة', 'SEC_EMAIL' => 'khaled.test@example.test', 'EQLEVEL_DESC' => 'ماجستير']),
            $this->row(['IDNO' => 20000000003, 'EMPNO' => 7003, 'EMPNAME' => 'يوسف الاختبار', 'SEX_DESC' => 'ذكر', 'SEC_EMAIL' => null, 'NATNAME' => 'غير محددة الجنسية']),
            $this->row(['IDNO' => 20000000004, 'EMPNO' => 7004, 'EMPNAME' => 'سارة الاختبار', 'ORGNNAME' => 'مجهزين للحذف', 'SUBORGNAME' => 'مجهزين للحذف', 'SEC_EMAIL' => 'mona.test@example.test']),   // the same address as the first person
        ];
        $files = [$this->workbook($rows)];
        $r = app(TraineeHistoryImport::class)->run($files);

        $this->assertTrue($r['reconciled']);
        $this->assertSame([6, 0, 4, 5, 6], [$r['source_rows'], $r['rejected_rows'], $r['people'], $r['employee_numbers'], $r['activities_imported']]);
        $this->assertSame(1, $r['schools_matched']);        // the school that was already there
        $this->assertSame(1, $r['schools_created']);        // the second school; «مجهزين للحذف» is not a school
        $this->assertSame(1, $r['departments_created']);    // the kindergarten wing of the second school
        $this->assertSame([1, 1], [$r['job_titles_created'], $r['job_titles_matched']]);   // «معلم رياضيات» is new; «مدير مدرسة» is the title that already exists
        $this->assertEquals($r['source_hours'], $r['imported_hours']);
        $this->assertSame(6 + 4 + 12 + 6 + 6 + 6.0, (float) $r['imported_hours']);

        // The re-hired teacher: one person, two numbers, the best employment current (the latest, in the new school); the first e-mail and phone are kept.
        $mona = Employee::where('national_id_hash', Employee::nationalIdHash('20000000001'))->firstOrFail();
        $this->assertSame('20000000001', $mona->national_id);   // encrypted at rest, readable through the model
        $this->assertSame('7005', $mona->employee_no);
        $this->assertSame(['7001', '7005'], EmployeeNumber::where('employee_id', $mona->id)->orderBy('employee_no')->pluck('employee_no')->all());
        $this->assertSame('مدرسة ثانية الإعدادية للبنين', $mona->school->name_ar);
        $this->assertSame('female', $mona->gender);
        $this->assertSame('Qatar', $mona->nationality);
        $this->assertSame('bachelor', $mona->qualification);
        $this->assertSame('أكاديمية بالمدارس', $mona->job_class);
        $this->assertSame('mona.test@example.test', $mona->user->email);
        $this->assertSame('97455500001', $mona->user->phone);
        $this->assertSame('2020-01-10', $mona->hire_date->toDateString());
        $this->assertSame(3, PdActivity::where('employee_id', $mona->id)->count());
        $this->assertSame('teaching', $mona->jobTitle->category);
        $this->assertSame('J130', $mona->jobTitle->code);
        $this->assertSame('boys', $mona->school->gender);
        $this->assertSame('preparatory', $mona->school->stage);

        // The principal who resigned: inactive, leadership, nationality mapped through the normalised dictionary, name tidied.
        $khaled = Employee::where('national_id_hash', Employee::nationalIdHash('20000000002'))->firstOrFail();
        $this->assertSame(['inactive', 'inactive', 'leadership', 'Algeria', 'master'], [$khaled->status, $khaled->user->status, $khaled->jobTitle->category, $khaled->nationality, $khaled->qualification]);
        $this->assertSame('خالد الاختبار', $khaled->user->name_ar);
        // No e-mail: a placeholder on a reserved domain; an address another person already has: a placeholder too.
        $this->assertSame('7003@no-email.moe.invalid', Employee::where('employee_no', '7003')->first()->user->email);
        $this->assertNull(Employee::where('employee_no', '7003')->first()->nationality);
        $sara = Employee::where('employee_no', '7004')->first();
        $this->assertSame('7004@no-email.moe.invalid', $sara->user->email);
        $this->assertNull($sara->school_id);                  // «مجهزين للحذف» is no school
        $this->assertSame('inactive', $sara->status);
        $this->assertSame('مجهزين للحذف', $sara->sub_unit);
        $this->assertSame(2, $r['placeholder_emails']);
        $this->assertSame(2, $r['inactive_people']);

        // Every course is an approved record with its source columns, counted in the hours the person trained.
        $a = PdActivity::where('employee_id', $mona->id)->where('title', 'دورة الاختبار الثانية')->first();
        $this->assertSame(['approved', 4.0, 4.0, 'ترخيص', 'دورة', '130', 'moe-training-records', '7001'], [$a->status, (float) $a->approved_hours, (float) $a->duration_hours, $a->track, $a->course_type, $a->external_ref, $a->source_system, $a->source_empno]);
        $this->assertSame('قطر', $a->location);
        $lastYear = app(AnnualHoursService::class)->yearOf() - 1;
        PdActivity::where('title', 'دورة الاختبار الأولى')->update(['starts_on' => "{$lastYear}-06-01", 'ends_on' => "{$lastYear}-06-02"]);
        $this->assertGreaterThanOrEqual(6.0, app(AnnualHoursService::class)->lastYearHours([$mona->id])['hours'][$mona->id]);

        // A second run (the same file, or one that overlaps it) adds nothing and says so.
        $again = app(TraineeHistoryImport::class)->run($files);
        $this->assertSame([0, 6, 4, 0], [$again['activities_imported'], $again['activities_existing'], $again['people_updated'], $again['people_created']]);
        $this->assertSame(4, Employee::whereNotNull('national_id_hash')->count());
        $this->assertSame(4, User::where('email', 'like', '%example.test')->orWhere('email', 'like', '%no-email.moe.invalid')->count());
        $this->assertSame(1, School::where('name_ar', 'مدرسة الاختبار الابتدائية للبنات')->count());
    }

    public function test_a_dry_run_writes_nothing_and_bad_rows_are_reported_not_hidden(): void
    {
        $rows = [
            $this->row(),
            $this->row(['COURSE_NAME_A' => 'دورة بتاريخ معكوس', 'COURSE_DT_FROM' => '2024-05-10', 'COURSE_DT_END' => '2024-05-01']),
            $this->row(['COURSE_NAME_A' => 'دورة بلا ساعات', 'DURATION' => 0]),
            $this->row(['COURSE_NAME_A' => null]),
            $this->row(['IDNO' => null, 'EMPNO' => 7099, 'COURSE_NAME_A' => 'بلا هوية']),
            $this->row(['COURSE_NAME_A' => 'دورة الاختبار الأولى']),   // exact duplicate of the first row
        ];
        $files = [$this->workbook($rows)];
        $dry = app(TraineeHistoryImport::class)->run($files, true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, Employee::whereNotNull('national_id_hash')->count());
        $this->assertSame(0, PdActivity::whereNotNull('row_hash')->count());
        $this->assertSame(0, School::where('source', 'moe_training_extract')->count());

        $r = app(TraineeHistoryImport::class)->run($files);
        $this->assertTrue($r['reconciled']);
        $this->assertSame(6, $r['source_rows']);
        $this->assertSame(1, $r['rejected_rows']);                  // no national ID
        $this->assertSame(3, $r['activities_rejected']);            // reversed dates, zero hours, no course name
        $this->assertSame([1, 1], [$r['activities_imported'], $r['activities_existing']]);   // one imported, the exact duplicate recognised
        $this->assertSame(1, $r['people']);
        $codes = array_count_values(array_column($r['issue_samples'], 'code'));
        $this->assertSame(['identity_missing' => 1, 'end_before_start' => 1, 'hours_invalid' => 1, 'course_name_missing' => 1], $codes);
        $this->assertStringNotContainsString('20000000001', json_encode($r), 'the report never carries a national ID');
    }

    public function test_an_employee_number_filed_under_two_national_ids_keeps_both_people_and_all_their_courses(): void
    {
        $rows = [
            $this->row(['COURSE_NAME_A' => 'ا']),
            $this->row(['IDNO' => 20000000009, 'EMPNO' => 7001, 'EMPNAME' => 'شخص آخر', 'SEC_EMAIL' => 'other.test@example.test', 'COURSE_NAME_A' => 'ب']),
        ];
        $r = app(TraineeHistoryImport::class)->run([$this->workbook($rows)]);
        $this->assertTrue($r['reconciled']);
        $this->assertSame([2, 2, 0], [$r['people'], $r['activities_imported'], $r['rejected_rows']]);
        $first = Employee::where('national_id_hash', Employee::nationalIdHash('20000000001'))->first();
        $other = Employee::where('national_id_hash', Employee::nationalIdHash('20000000009'))->first();
        $this->assertSame('7001', $first->employee_no);
        $this->assertSame('7001-0009', $other->employee_no);          // the second person's number is made unique, nothing is lost
        $this->assertSame(1, PdActivity::where('employee_id', $other->id)->count());
        $this->assertSame(['7001-0009'], EmployeeNumber::where('employee_id', $other->id)->where('is_current', true)->pluck('employee_no')->all());   // every employee has a current number on record
        $this->assertContains('empno_filed_under_two_ids', array_column($r['issue_samples'], 'code'));
    }

    public function test_imported_people_are_found_by_national_id_or_any_old_number_and_their_profile_shows_the_history(): void
    {
        $f = $this->workbook([$this->row(), $this->row(['EMPNO' => 7005, 'EMPNO_SEQ' => 1, 'SECAPPMDT' => '2020-01-10', 'COURSE_NAME_A' => 'دورة ثانية', 'COURSE_DT_FROM' => '2023-03-01', 'COURSE_DT_END' => '2023-03-01', 'DURATION' => 3])]);
        app(TraineeHistoryImport::class)->run([$f]);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = Employee::where('national_id_hash', Employee::nationalIdHash('20000000001'))->value('id');

        // The national ID (full 11 digits) is found through its blind index; the old number works as well as the current one.
        $this->assertSame([$id], collect($this->asUser($admin)->getJson('/api/v1/admin/employees?q=20000000001')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$id], collect($this->asUser($admin)->getJson('/api/v1/admin/employees?q=7001')->json('data'))->pluck('id')->all());
        $this->assertSame([$id], collect($this->asUser($admin)->getJson('/api/v1/admin/employees?q=7005')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->asUser($admin)->getJson('/api/v1/admin/employees?q=20000000999')->json('data'));
        // The ID itself never leaves the server.
        $this->assertStringNotContainsString('20000000001', $this->asUser($admin)->getJson("/api/v1/admin/employees/{$id}")->getContent());

        $d = $this->asUser($admin)->getJson("/api/v1/admin/employees/{$id}")->assertOk()->json('data');
        $this->assertSame(['أكاديمية بالمدارس', 'دائم', 'جامعي', 'بكالوريوس تربية'], [$d['employee']['job_class'], $d['employee']['employment_class'], $d['employee']['education_level'], $d['employee']['certificate_name']]);
        $this->assertSame([2, 9.0, 2], [$d['training_history']['totals']['courses'], (float) $d['training_history']['totals']['hours'], count($d['training_history']['totals']['by_year'])]);
        $this->assertSame(['دورة الاختبار الأولى', 'دورة ثانية'], array_column($d['training_history']['rows'], 'title'));   // latest first (2024 before 2023)
        $this->assertSame(['7005', '7001'], array_column($d['employee']['employee_numbers'], 'employee_no'));
    }

    public function test_a_file_without_the_expected_columns_is_refused_with_a_clear_message(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Sheet 1')->fromArray([['IDNO', 'NAME']], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->made[] = $path;
        $this->expectExceptionMessage('The column EMPNO_SEQ is missing');
        app(TraineeHistoryImport::class)->run([$path]);
    }

    public function test_the_command_runs_end_to_end_and_fails_loudly_when_the_numbers_do_not_add_up(): void
    {
        $f = $this->workbook([$this->row(), $this->row(['IDNO' => 20000000005, 'EMPNO' => 7010, 'EMPNAME' => 'ليلى الاختبار', 'SEC_EMAIL' => 'layla.test@example.test'])]);
        $this->artisan('tedc:import-trainee-history', ['files' => [$f], '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Employee::whereNotNull('national_id_hash')->count());
        $this->artisan('tedc:import-trainee-history', ['files' => [$f]])->assertSuccessful();
        $this->assertSame(2, Employee::whereNotNull('national_id_hash')->count());
    }

    public function test_school_names_written_differently_find_the_official_school_but_never_the_other_gender_or_the_kindergarten(): void
    {
        $key = fn (string $n) => TraineeHistoryImport::schoolKey($n);
        $this->assertSame($key('معيذر الابتدائية للبنين'), $key('مدرسة معيذر الابتدائية بنين'));
        $this->assertSame($key('أحمد بن حنبل الثانوية للبنين'), $key('أحمد بن حنبل الثانوية للبنبن'));
        $this->assertSame($key('الجميلية الابتدائية الإعدادية الثانوية للبنين'), $key('الجميلية ابتدائي اعدادي ثانوي بنين'));
        $this->assertNotSame($key('الوكير الثانوية للبنين'), $key('الوكير الثانوية للبنات'));
        $this->assertNotSame($key('روضة مدرسة الكرعانة الابتدائية للبنات'), $key('الكرعانة الابتدائية للبنات'));

        $byKey = [
            $key('حطين النموذجية للبنين') => ['a' => 'حطين'],
            $key('الجميلية الثانوية للبنين') => ['b1' => 'الجميلية', 'b2' => 'الجميلية'],          // listed twice under one name
            $key('الوكرة الثانوية للبنين') => ['c' => 'الوكرة بنين'], $key('الوكرة الثانوية للبنات') => ['d' => 'الوكرة بنات'],
            $key('السد الابتدائية للبنين') => ['e' => 'السد', 'f' => 'السد الجديدة'],                // two different schools: no guess
        ];
        $this->assertSame('a', TraineeHistoryImport::pickSchool($byKey, $key('حطين النموذجية')));
        $this->assertSame('b1', TraineeHistoryImport::pickSchool($byKey, $key('الجميلية الثانوية بنين')));
        $this->assertNull(TraineeHistoryImport::pickSchool($byKey, $key('الوكرة الثانوية')));
        $this->assertNull(TraineeHistoryImport::pickSchool($byKey, $key('السد الابتدائية للبنين')));
        $this->assertNull(TraineeHistoryImport::pickSchool($byKey, $key('مدرسة لا توجد')));

        $this->assertTrue(TraineeHistoryImport::isAdministrative('إدارة الموارد البشرية'));
        $this->assertTrue(TraineeHistoryImport::isAdministrative('مكتب وكيل الوزارة المساعد للشؤون التعليمية'));
        $this->assertTrue(TraineeHistoryImport::isAdministrative('المعارين'));
        $this->assertFalse(TraineeHistoryImport::isAdministrative('معهد النور للمكفوفين'));
        $this->assertFalse(TraineeHistoryImport::isAdministrative('مدرسة الإدارة النموذجية للبنين'));
    }
}
