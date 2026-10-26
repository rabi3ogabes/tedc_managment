<?php

namespace Tests\Feature;

use App\Models\CalendarDay;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Role;
use App\Models\SaberRevision;
use App\Models\SaberSurveyLink;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\TrainingGroupService;
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

    private function upload(string $url, array $data, $user)
    {
        return $this->asUser($user)->post($url, $data, ['Accept' => 'application/json']);
    }

    public function test_a_workbook_is_inspected_sheet_by_sheet_with_its_header_row_guessed(): void
    {
        $file = $this->workbook(function ($s) {
            $s->setCellValue('A1', 'جدول التدريب');
            $s->fromArray(['البرنامج', 'المجموعة', 'التاريخ', 'من', 'إلى', 'القاعة', 'المدرب'], null, 'A3');
            $s->fromArray(['برنامج', '1', '01/11/2026', '08:00', '12:00', 'قاعة', 'مدرب'], null, 'A4');
        }, 'خطة');

        $res = $this->upload('/api/v1/admin/saber/data/import/inspect', ['file' => $file], $this->makeUser(Role::TRAINING_HEAD))->assertOk();
        $this->assertSame('خطة', $res->json('data.sheets.0.name'));
        $this->assertSame(3, $res->json('data.sheets.0.header_row'));
        $this->assertSame('التاريخ', $res->json('data.sheets.0.rows.2.2'));
        $this->upload('/api/v1/admin/saber/data/import/inspect', ['file' => $file], $this->makeUser(Role::COORDINATOR))->assertForbidden();
    }

    public function test_the_rooms_calendar_grid_is_matched_to_programmes_and_planned_in_their_drafts(): void
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
        $layout = ['type' => 'grid', 'sheet' => 'تقويم القاعات التدريبية', 'date_col' => 4, 'first_room_col' => 8];

        $plan = $this->upload('/api/v1/admin/saber/data/import/preview', ['file' => $file] + $layout, $head)->assertOk()->json('data');
        $this->assertSame(3, $plan['cells']);
        $matched = array_values(array_filter($plan['tracks'], fn ($t) => $t['program_id'] === $program->id));
        $this->assertCount(1, $matched);
        $this->assertSame($program->groups()->first()->id, $matched[0]['group_id'], 'group number 1 of the programme');
        $this->assertSame('diff', $matched[0]['verdict']);
        $this->assertSame(['2026-11-01', '2026-11-02'], array_column($matched[0]['days'], 'date'));
        $this->assertSame('برنامج غير موجود اطلاقا', $plan['proposals'][0]['name'], 'a name that matches no programme is proposed as a new draft programme');
        $this->assertSame([true, false], array_column($plan['rooms'], 'exists'));

        $res = $this->asUser($head)->postJson('/api/v1/admin/saber/data/import/apply', ['type' => 'grid', 'tracks' => $plan['tracks'], 'submit' => true])->assertOk()->json('data');
        $this->assertSame(['updated' => 1, 'created' => 0, 'rooms_created' => 1, 'drafts' => 1, 'submitted' => 1], array_intersect_key($res, array_flip(['updated', 'created', 'rooms_created', 'drafts', 'submitted'])));
        $this->assertSame(0, $program->sessions()->count(), 'an import never writes the live programme');
        $rev = SaberRevision::where('program_id', $program->id)->first();
        $this->assertSame('submitted', $rev->status);
        $this->assertSame('calendar_import', $rev->source);

        $again = $this->upload('/api/v1/admin/saber/data/import/preview', ['file' => $file] + $layout, $head)->assertOk()->json('data');
        $this->assertSame('match', collect($again['tracks'])->firstWhere('program_id', $program->id)['verdict'], 're-checking the same file finds the plan up to date');

        $this->asUser($head)->postJson("/api/v1/admin/saber/reviews/{$rev->id}/publish")->assertOk();
        $rooms = $program->groups()->first()->sessions()->orderBy('starts_at')->get()->map(fn ($x) => TrainingRoom::find($x->training_room_id)->name_ar)->all();
        $this->assertSame(['قاعة الريادة', 'قاعة المستقبل'], $rooms);
    }

    public function test_the_centre_rooms_calendar_layout_is_understood_periods_events_reservations_and_new_programmes(): void
    {
        // The shape of the centre's real workbook, with made-up names: priority row, rooms, capacities, dates in D, periods merged in A:B,
        // a holiday merged across every room, a centre-wide event in every room, a person's standing reservation, group numbers glued to names.
        TrainingRoom::create(['code' => 'X1', 'name_ar' => 'القاعة 1', 'name_en' => 'Room 1', 'capacity' => 25, 'status' => 'active']);
        $head = $this->makeUser(Role::SUPER_ADMIN);
        $file = $this->workbook(function ($s) {
            $s->setCellValue('D1', 'وقت الاستراحة')->setCellValue('H1', 'الاستراحة الأولى 9:30 - 10:00');
            $s->mergeCells('H1:J1');
            foreach (['H' => 'رجال', 'I' => 'نساء', 'J' => 'الكل'] as $c => $v) {
                $s->setCellValue("{$c}2", $v);
            }
            foreach (['H' => 'القاعة 1', 'I' => 'القاعة 2', 'J' => 'معمل الحاسب 1'] as $c => $v) {
                $s->setCellValue("{$c}3", $v)->setCellValue("{$c}4", '25 متدرب');
            }
            $dates = ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-08', '2026-11-09', '2026-11-10'];
            foreach ($dates as $i => $d) {
                $s->setCellValue('D'.($i + 5), $this->excelDate($d));
            }
            // A programme that does not exist yet, two groups written «اسم 1» / «اسم 2» (no dash), and one «- 1» style.
            $s->setCellValue('H5', 'برنامج الإرشاد المهني 1')->setCellValue('I5', 'برنامج الإرشاد المهني 2');
            $s->setCellValue('H6', 'برنامج الإرشاد المهني 1')->setCellValue('I6', 'برنامج الإرشاد المهني 2');
            $s->setCellValue('H7', 'مهارات العرض - 1');
            // A person's standing reservation in the lab.
            $s->setCellValue('J5', 'أ.فلانة الفلانية-(ابلاغها عند الحاجه للقاعه)')->setCellValue('J6', 'أ.فلانة الفلانية-(ابلاغها عند الحاجه للقاعه)');
            // A centre-wide event in every room.
            foreach (['H', 'I', 'J'] as $c) {
                $s->setCellValue("{$c}8", 'يوم المعلم');
            }
            // Exams written once beside two dates, and a holiday merged across all rooms.
            $s->setCellValue('A9', 'اختبارات منتصف الفصل')->mergeCells('A9:B10');
            $s->setCellValue('H11', 'إجازة منتصف العام')->mergeCells('H11:J11');
        }, 'تقويم القاعات التدريبية');
        $layout = ['type' => 'grid', 'sheet' => 'تقويم القاعات التدريبية', 'date_col' => 4, 'first_room_col' => 8];

        $plan = $this->upload('/api/v1/admin/saber/data/import/preview', ['file' => $file] + $layout, $head)->assertOk()->json('data');

        $this->assertSame([['يوم المعلم', 'event'], ['اختبارات منتصف الفصل', 'period'], ['إجازة منتصف العام', 'event']], array_map(fn ($p) => [$p['label'], $p['kind']], $plan['periods']));
        $this->assertSame(['normal', 'exam', 'vacation'], array_column($plan['periods'], 'type'));
        $this->assertSame(['2026-11-08', '2026-11-09'], [$plan['periods'][1]['from'], $plan['periods'][1]['to']]);
        $this->assertCount(1, $plan['reservations'], 'a person\'s reservation is not a programme');
        $this->assertSame(['برنامج الإرشاد المهني', 'مهارات العرض'], array_column($plan['proposals'], 'name'));
        $this->assertSame([2, 2], [$plan['proposals'][0]['groups'], $plan['proposals'][0]['days']]);
        $this->assertSame([], $plan['unmatched']);
        $this->assertSame(['نساء'], array_column(array_values(array_filter($plan['rooms'], fn ($r) => ! $r['exists'])), 'tag'), 'a new room keeps its trainee priority as its tag');

        $tracks = array_values(array_filter($plan['tracks'], fn ($t) => $t['proposal'] === $plan['proposals'][0]['key']));
        $this->assertSame(['المجموعة 1', 'المجموعة 2'], array_column($tracks, 'group_name'));
        $res = $this->asUser($head)->postJson('/api/v1/admin/saber/data/import/apply', [
            'type' => 'grid', 'submit' => true, 'tracks' => $plan['tracks'],
            'create' => [['key' => $plan['proposals'][0]['key'], 'name' => $plan['proposals'][0]['name'], 'days' => 2]],
            'periods' => [['label' => 'اختبارات منتصف الفصل', 'from' => '2026-11-08', 'to' => '2026-11-09', 'type' => 'exam']],
            'rooms' => collect($plan['rooms'])->where('exists', false)->map(fn ($r) => ['name' => $r['name'], 'capacity' => $r['capacity'], 'tag' => $r['tag']])->values()->all(),
        ])->assertOk()->json('data');

        $this->assertSame(1, $res['programs_created']);
        $this->assertSame(2, $res['calendar_days']);
        $program = Program::where('title_ar', 'برنامج الإرشاد المهني')->first();
        $this->assertSame(Program::STATUS_DRAFT, $program->status);
        $this->assertSame(1, $program->groups()->count(), 'the second group waits in the draft');
        $groups = SaberRevision::where('program_id', $program->id)->first()->state['groups'];
        $this->assertSame(['المجموعة 1', 'المجموعة 2'], array_column($groups, 'title'));
        $this->assertSame(['2026-11-01', '2026-11-02'], array_column($groups[1]['days'], 'date'));
        $this->assertSame('نساء', TrainingRoom::where('name_ar', 'القاعة 2')->value('tag'));
        $this->assertNull(Program::where('title_ar', 'مهارات العرض')->first(), 'a proposal left unticked is not created');
        $this->assertSame('exam', CalendarDay::whereDate('date', '2026-11-08')->value('type'));
    }

    public function test_a_day_per_row_workbook_with_any_column_order_is_mapped_and_planned(): void
    {
        $program = $this->makeProgram(['title_ar' => 'التعلم الرقمي للمعلمين', 'total_hours' => 10]);
        $room = TrainingRoom::create(['code' => 'R2', 'name_ar' => 'مختبر الحاسب', 'name_en' => 'Lab', 'capacity' => 25, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'سلمى الكواري', 'name_en' => 'Salma', 'status' => 'active', 'source' => 'center', 'validated_at' => now()]);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $file = $this->workbook(function ($s) {
            $s->fromArray(['القاعة', 'اليوم', 'اسم البرنامج التدريبي', 'رقم المجموعة', 'يبدأ', 'ينتهي', 'المدرب'], null, 'A1');
            $s->fromArray(['مختبر الحاسب', '01/11/2026', 'التعلم الرقمي للمعلمين', '2', '08:30', '12:30', 'سلمى الكوارى'], null, 'A2');
            $s->fromArray(['مختبر الحاسب', '02/11/2026', 'التعلم الرقمي للمعلمين', '2', '08:30', '12:30', 'سلمى الكوارى'], null, 'A3');
        });
        $layout = ['type' => 'rows', 'header_row' => 1, 'columns' => json_encode(['room' => 1, 'date' => 2, 'program' => 3, 'group' => 4, 'start' => 5, 'end' => 6, 'trainer' => 7])];

        $this->upload('/api/v1/admin/saber/data/import/preview', ['file' => $file, 'type' => 'rows', 'header_row' => 1, 'columns' => json_encode(['room' => 1])], $head)->assertUnprocessable();
        $plan = $this->upload('/api/v1/admin/saber/data/import/preview', ['file' => $file] + $layout, $head)->assertOk()->json('data');
        $this->assertCount(1, $plan['tracks']);
        $this->assertSame('المجموعة 2', $plan['tracks'][0]['group_name']);
        $this->assertSame('new', $plan['tracks'][0]['verdict']);
        $this->assertSame('سلمى الكواري', $plan['trainers'][0]['match']);
        $this->assertSame('08:30', $plan['tracks'][0]['days'][0]['start_time']);

        $this->asUser($head)->postJson('/api/v1/admin/saber/data/import/apply', ['type' => 'rows', 'tracks' => $plan['tracks']])->assertOk()->assertJsonPath('data.created', 1);
        $group = collect(SaberRevision::where('program_id', $program->id)->first()->state['groups'])->firstWhere('title', 'المجموعة 2');
        $this->assertSame([$room->id, $room->id], array_column($group['days'], 'room_id'));
        $this->assertSame([$trainer->id, $trainer->id], array_column($group['days'], 'trainer_id'));
        $this->assertSame('12:30', $group['days'][0]['end_time']);
    }

    public function test_the_programme_list_with_mapped_columns_goes_to_drafts(): void
    {
        $existing = $this->makeProgram(['title_ar' => 'مهارات القيادة', 'training_days' => 2]);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $file = $this->workbook(function ($s) {
            $s->setCellValue('A1', 'قائمة برامج المركز');
            $s->fromArray(['م', 'عدد الأيام', 'البرنامج', 'الآلية', 'الساعات', 'المجموعات', 'الحقيبة', 'التنفيذ'], null, 'A2');
            $s->fromArray([1, 3, 'مهارات القيادة', 'حضوري', 15, 1, 'متوفر', ''], null, 'A3');
            $s->fromArray([2, 2, 'التعلم الرقمي', 'عن بعد', 10, 2, 'غير متوفر', '15/11/2026'], null, 'A4');
            $s->fromArray([3, 2, 'التعلم الرقمي', 'عن بعد', 10, 1, '', ''], null, 'A5');
        }, 'البرامج');
        $layout = ['type' => 'programs', 'sheet' => 'البرامج', 'header_row' => 2, 'columns' => json_encode(['days' => 2, 'name' => 3, 'method' => 4, 'hours' => 5, 'groups' => 6, 'kit' => 7, 'date' => 8])];

        $plan = $this->upload('/api/v1/admin/saber/data/import/preview', ['file' => $file] + $layout, $head)->assertOk()->json('data');
        $this->assertSame(['update', 'create'], array_column($plan['rows'], 'action'));
        $this->assertSame('2026-11-15', $plan['rows'][1]['date']);
        $this->assertStringContainsString('التعلم الرقمي', collect($plan['issues'])->pluck('message')->implode(' '), 'the duplicate row is reported');

        $res = $this->asUser($head)->postJson('/api/v1/admin/saber/data/import/apply', ['type' => 'programs', 'rows' => $plan['rows'], 'submit' => true])->assertOk()->json('data');
        $this->assertSame([1, 1, 2], [$res['created'], $res['updated'], $res['submitted']]);
        $this->assertSame(2, $existing->fresh()->training_days, 'the existing programme changes only when its draft is published');
        $new = Program::where('title_ar', 'التعلم الرقمي')->first();
        $this->assertSame(Program::STATUS_DRAFT, $new->status);
        $this->assertSame('online', $new->delivery_mode);
        $this->assertFalse($new->kit_ready);
        $this->assertSame('2026-11-15', $new->start_date->toDateString());
        $this->assertCount(2, SaberRevision::where('program_id', $new->id)->first()->state['groups']);

        $this->asUser($head)->postJson('/api/v1/admin/saber/reviews/'.SaberRevision::where('program_id', $existing->id)->first()->id.'/publish')->assertOk();
        $this->assertSame(3, $existing->fresh()->training_days);
        $this->upload('/api/v1/admin/saber/data/import/preview', ['url' => 'https://evil.example.com/list.xlsx', 'type' => 'programs'], $head)->assertUnprocessable();
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
        $program = $this->makeProgram(['title_ar' => 'برنامج المشرفة', 'coordinator_id' => $coordinator->id, 'delivery_mode' => 'in_person']);
        $program->groups()->first()->update(['validated_at' => now()]);
        app(TrainingGroupService::class)->create($program, [], $coordinator);   // a live group; SABER+ additions are drafts until published

        $row = collect($this->asUser($coordinator)->getJson('/api/v1/admin/saber/data/supervisors')->assertOk()->json('data'))->firstWhere('id', $coordinator->id);
        $this->assertSame('برنامج المشرفة', $row['programs'][0]['title']);
        $this->assertSame([1, 1], [$row['validated'], $row['pending']]);
    }
}
