<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Program;
use App\Models\Role;
use App\Models\SaberSurveyLink;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** سابر+ phase 4 (docs/saber/04-data-tools.md): imports, survey links workbook, trainers manager, supervisors. All workbooks are made here. */
class SaberDataToolsTest extends TestCase
{
    /** @param callable(Worksheet): void $fill */
    private function workbook(callable $fill, string $title = 'Sheet1'): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($title);
        $fill($sheet);
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'book.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function excelDate(string $d): float
    {
        return ExcelDate::PHPToExcel(CarbonImmutable::parse($d));
    }

    public function test_the_rooms_calendar_is_matched_to_programmes_and_applied_to_their_groups(): void
    {
        $program = $this->makeProgram(['title_ar' => 'برنامج القيادة المدرسية', 'total_hours' => 10]);
        TrainingRoom::create(['code' => 'R1', 'name_ar' => 'قاعة الريادة', 'name_en' => 'Riyada', 'capacity' => 25, 'status' => 'active']);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $file = $this->workbook(function ($s) {
            $s->setCellValue('H3', 'قاعة الريادة')->setCellValue('I3', 'قاعة المستقبل');
            $s->setCellValue('H4', '25 متدرب')->setCellValue('I4', '30 متدرب');
            $s->setCellValue('D5', $this->excelDate('2026-11-01'))->setCellValue('H5', 'برنامج القيادة المدرسيه - 1')->setCellValue('I5', 'برنامج غير موجود اطلاقا - 2');
            $s->setCellValue('D6', $this->excelDate('2026-11-02'))->setCellValue('I6', 'برنامج القيادة المدرسيه - 1');
        }, 'تقويم القاعات التدريبية');

        $plan = $this->asUser($head)->post('/api/v1/admin/saber/data/calendar/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame(3, $plan['cells']);
        $this->assertCount(1, $plan['tracks']);
        $this->assertSame($program->id, $plan['tracks'][0]['program_id']);
        $this->assertSame($program->groups()->first()->id, $plan['tracks'][0]['group_id'], 'group number 1 of the programme');
        $this->assertSame('diff', $plan['tracks'][0]['verdict']);
        $this->assertSame(['2026-11-01', '2026-11-02'], array_column($plan['tracks'][0]['days'], 'date'));
        $this->assertSame('برنامج غير موجود اطلاقا - 2', $plan['unmatched'][0]['label']);
        $this->assertSame([true, false], array_column($plan['rooms'], 'exists'));

        $res = $this->asUser($head)->postJson('/api/v1/admin/saber/data/calendar/apply', ['tracks' => $plan['tracks'], 'approve' => true])->assertOk()->json('data');
        $this->assertSame(['updated' => 1, 'created' => 0, 'rooms_created' => 1, 'approved' => 1], array_intersect_key($res, array_flip(['updated', 'created', 'rooms_created', 'approved'])));
        $group = $program->groups()->first();
        $this->assertNotNull($group->validated_at);
        $sessions = $group->sessions()->orderBy('starts_at')->with('room')->get();
        $this->assertSame(['قاعة الريادة', 'قاعة المستقبل'], $sessions->map(fn ($s) => TrainingRoom::find($s->training_room_id)->name_ar)->all());

        $again = $this->asUser($head)->post('/api/v1/admin/saber/data/calendar/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame('match', $again['tracks'][0]['verdict'], 're-checking the same file finds the group up to date');
        $this->asUser($this->makeUser(Role::COORDINATOR))->post('/api/v1/admin/saber/data/calendar/preview', ['file' => $file], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_the_programme_list_is_checked_then_updates_or_creates_programmes_with_their_groups(): void
    {
        $existing = $this->makeProgram(['title_ar' => 'مهارات القيادة']);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $file = $this->workbook(function ($s) {
            $s->setCellValue('A1', 'قائمة برامج المركز');
            $s->fromArray(['اسم البرنامج', 'نوع البرنامج', 'آلية التدريب', 'الفئة المستهدفة', 'عدد الساعات', 'عدد الأيام', 'الهدف العام', 'الكتل التدريبية', 'توفر الحقيبة', 'موعد تنفيذ', 'ملاحظات'], null, 'A2');
            $s->fromArray(['مهارات القيادة', 'تطويري', 'حضوري', 'القادة', 15, 3, '', 1, 'متوفر', '', ''], null, 'A3');
            $s->fromArray(['التعلم الرقمي', 'تدريبي', 'عن بعد', 'المعلمون', 10, 2, 'تمكين المعلمين رقمياً', 2, 'غير متوفر', '15/11/2026', 'أولوية'], null, 'A4');
            $s->fromArray(['التعلم الرقمي', 'تدريبي', 'عن بعد', '', 10, 2, '', 1, '', '', ''], null, 'A5');
        }, 'البرامج');

        $plan = $this->asUser($head)->post('/api/v1/admin/saber/data/programs/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(['update', 'create'], array_column($plan['rows'], 'action'));
        $this->assertSame('2026-11-15', $plan['rows'][1]['date']);
        $this->assertStringContainsString('التعلم الرقمي', collect($plan['issues'])->pluck('message')->implode(' '), 'the duplicate row is reported');

        $res = $this->asUser($head)->postJson('/api/v1/admin/saber/data/programs/apply', ['rows' => $plan['rows']])->assertOk()->json('data');
        $this->assertSame(1, $res['created']);
        $this->assertSame(1, $res['updated']);
        $this->assertSame(3, $existing->fresh()->training_days);
        $new = Program::where('title_ar', 'التعلم الرقمي')->first();
        $this->assertSame(Program::STATUS_DRAFT, $new->status);
        $this->assertSame('online', $new->delivery_mode);
        $this->assertFalse($new->kit_ready);
        $this->assertSame(2, $new->training_days);
        $this->assertSame(2, $new->groups()->count());
        $this->assertSame('2026-11-15', $new->start_date->toDateString());

        $this->asUser($head)->postJson('/api/v1/admin/saber/data/programs/preview', ['url' => 'https://evil.example.com/list.xlsx'])->assertUnprocessable();
    }

    public function test_survey_links_are_uploaded_and_given_to_programmes(): void
    {
        $a = $this->makeProgram(['title_ar' => 'برنامج أ']);
        $b = $this->makeProgram(['title_ar' => 'برنامج ب']);
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $file = $this->workbook(fn ($s) => $s->fromArray([['م', 'رابط الإستمارة', 'رابط تعديل الإستمارة'], [1, 'https://forms.example.com/s/1', 'https://forms.example.com/e/1'], [2, 'https://forms.example.com/s/2', '']]));

        $this->asUser($coordinator)->post('/api/v1/admin/saber/data/survey-links', ['file' => $file, 'replace' => 1], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.uploaded', 2);
        $link = SaberSurveyLink::where('serial', '1')->first();
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/data/survey-links/{$link->id}/assign", ['program_id' => $a->id])->assertOk();
        $this->assertSame('https://forms.example.com/s/1', $a->fresh()->survey_link);
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/data/survey-links/{$link->id}/assign", ['program_id' => $b->id])->assertOk();
        $this->assertNull($a->fresh()->survey_link, 'moving the link takes it from the first programme');
        $this->assertSame('https://forms.example.com/e/1', $b->fresh()->survey_edit_link);

        $this->asUser($coordinator)->getJson('/api/v1/admin/saber/data/survey-links')->assertOk()->assertJsonPath('data.0.program.id', $b->id);
        $this->asUser($coordinator)->get('/api/v1/admin/saber/data/survey-links/template')->assertOk();
        $this->asUser($coordinator)->deleteJson('/api/v1/admin/saber/data/survey-links')->assertOk()->assertJsonPath('data.deleted', 2);
    }

    public function test_trainers_wait_for_approval_import_by_national_id_fill_from_staff_and_export(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);   // manages trainers, does not approve them
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $id = $this->asUser($coordinator)->postJson('/api/v1/admin/saber/data/trainers', ['name' => 'مدرب جديد', 'national_id' => '28763400001', 'type' => 'ministry'])->assertCreated()
            ->assertJsonPath('data.validated', false)->assertJsonPath('data.national_id', '•••••••0001')->json('data.id');
        $this->assertNotContains($id, collect($this->asUser($coordinator)->getJson('/api/v1/admin/saber/overview')->json('data.trainers'))->pluck('id')->all(), 'a supervisor cannot pick a trainer waiting for approval');
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/data/trainers/{$id}/validate")->assertForbidden();
        $this->asUser($head)->postJson("/api/v1/admin/saber/data/trainers/{$id}/validate")->assertOk()->assertJsonPath('data.validated', true);
        $this->assertContains($id, collect($this->asUser($coordinator)->getJson('/api/v1/admin/saber/overview')->json('data.trainers'))->pluck('id')->all());
        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/data/trainers', ['name' => 'آخر', 'national_id' => '28763400001'])->assertUnprocessable()->assertJsonPath('code', 'duplicate_national_id');

        $file = $this->workbook(fn ($s) => $s->fromArray([
            ['الاسم', 'رقم الهوية', 'البريد الإلكتروني', 'رقم الجوال', 'نوع المدرب'],
            ['مدرب جديد (محدث)', '28763400001', 'new@example.com', '55123456', 'مدرب من الوزارة'],
            ['مدربة ثانية', '29163400002', 'bad-email', '123', 'مدرب من منتسبي المدارس'],
        ]));
        $plan = $this->asUser($coordinator)->post('/api/v1/admin/saber/data/trainers/import/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame(['update', 'create'], array_column($plan['rows'], 'action'));
        $this->assertSame('school', $plan['rows'][1]['type']);
        $this->assertCount(2, $plan['errors'], 'bad e-mail and bad mobile are reported and left empty');
        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/data/trainers/import/apply', ['rows' => $plan['rows']])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);
        $this->assertSame('new@example.com', Trainer::find($id)->email);
        $second = Trainer::where('name_ar', 'مدربة ثانية')->first();
        $this->assertNull($second->validated_at);

        // Fill from the staff records by national ID.
        $staff = $this->makeUser(Role::EMPLOYEE, ['email' => 'staff.member@test.qa', 'phone' => '66123456']);
        Employee::create(['user_id' => $staff->id, 'employee_no' => 'E-1', 'national_id' => '29163400002', 'national_id_hash' => Employee::nationalIdHash('29163400002'), 'status' => 'active']);
        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/data/trainers/fill-from-staff')->assertOk()->assertJsonPath('data.matched', 1);
        $this->assertSame('staff.member@test.qa', $second->fresh()->email);

        $this->asUser($coordinator)->get('/api/v1/admin/saber/data/trainers/export')->assertOk()->assertDownload();
        $this->asUser($coordinator)->getJson('/api/v1/admin/saber/data/trainers?pending=1')->assertOk()->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.name', 'مدربة ثانية');
    }

    public function test_the_supervisors_page_lists_programmes_with_approved_and_pending_groups(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR, ['name_ar' => 'المشرفة هند']);
        $program = $this->makeProgram(['title_ar' => 'برنامج المشرفة', 'coordinator_id' => $coordinator->id]);
        $program->groups()->first()->update(['validated_at' => now()]);
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$program->id}/groups")->assertCreated();

        $row = collect($this->asUser($coordinator)->getJson('/api/v1/admin/saber/data/supervisors')->assertOk()->json('data'))->firstWhere('id', $coordinator->id);
        $this->assertSame('برنامج المشرفة', $row['programs'][0]['title']);
        $this->assertSame([1, 1], [$row['validated'], $row['pending']]);
    }
}
