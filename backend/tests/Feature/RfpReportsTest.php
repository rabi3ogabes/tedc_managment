<?php

namespace Tests\Feature;

use App\Models\CareerPath;
use App\Models\CareerPathLevel;
use App\Models\PerformanceAppraisal;
use App\Models\ProfessionalLicence;
use App\Models\Registration;
use App\Models\ReportDefinition;
use App\Models\Role;
use App\Models\User;
use App\Ops\DataClassification;
use App\Services\Reports\BuiltInReports;
use App\Services\Reports\ReportRunner;
use App\Services\Reports\ReportRunService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** RPT-03: the system-administrator reports carry the RFP's filters and columns, and statistics come by year, quarter and month. */
class RfpReportsTest extends TestCase
{
    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->head = $this->makeUser(Role::TRAINING_HEAD);
    }

    /** Two schools (government and private) with teachers who differ in licence, appraisal and experience. */
    private function staff(): array
    {
        $gov = $this->makeSchool(['name_ar' => 'مدرسة حكومية', 'type' => 'government']);
        $priv = $this->makeSchool(['name_ar' => 'مدرسة خاصة', 'type' => 'private']);
        $path = CareerPath::create(['type' => 'teacher', 'title_ar' => 'مسار المعلم', 'title_en' => 'Teacher path', 'is_active' => true]);
        $a = $this->makeEmployee(['school_id' => $gov->id, 'experience_years' => 12, 'experience_moe_years' => 10, 'experience_outside_years' => 2]);
        $b = $this->makeEmployee(['school_id' => $gov->id, 'experience_years' => 3]);
        $c = $this->makeEmployee(['school_id' => $priv->id, 'experience_years' => 7]);
        ProfessionalLicence::create(['employee_id' => $a->id, 'path_id' => $path->id, 'level_no' => 2, 'licence_no' => 'L-1', 'issued_at' => now()->subYear(), 'expires_at' => now()->addYears(2), 'status' => 'active', 'source' => 'manual']);
        ProfessionalLicence::create(['employee_id' => $b->id, 'path_id' => $path->id, 'level_no' => 1, 'licence_no' => 'L-2', 'issued_at' => now()->subYears(4), 'expires_at' => now()->subMonth(), 'status' => 'expired', 'source' => 'manual']);
        PerformanceAppraisal::create(['employee_id' => $a->id, 'year' => 2024, 'rating_code' => 'G', 'score' => 70, 'source' => 'manual']);
        PerformanceAppraisal::create(['employee_id' => $a->id, 'year' => 2025, 'rating_code' => 'E', 'score' => 92, 'source' => 'manual']);
        PerformanceAppraisal::create(['employee_id' => $b->id, 'year' => 2025, 'rating_code' => 'NI', 'score' => 50, 'source' => 'manual']);

        return compact('gov', 'priv', 'path', 'a', 'b', 'c');
    }

    private function report(array $def, array $filters = [], ?User $as = null): array
    {
        return app(ReportRunner::class)->run($def, ['filters' => $filters], $as ?? $this->head, 1, 100);
    }

    private function headcount(string $dataset, array $filters, ?User $as = null): int
    {
        return $this->report(['dataset' => $dataset, 'columns' => [['field' => 'employee_no']]], $filters, $as)['total'];
    }

    public function test_the_employee_report_filters_by_licence_appraisal_entity_type_and_experience(): void
    {
        $s = $this->staff();

        $this->assertSame(1, $this->headcount('employees', [['field' => 'licence_status', 'operator' => 'eq', 'value' => 'active']]));
        $this->assertSame(1, $this->headcount('employees', [['field' => 'appraisal_year', 'operator' => 'eq', 'value' => 2025], ['field' => 'appraisal_rating', 'operator' => 'eq', 'value' => 'E']]));
        $this->assertSame(1, $this->headcount('employees', [['field' => 'entity_type', 'operator' => 'eq', 'value' => 'private']]));
        $this->assertSame(1, $this->headcount('employees', [['field' => 'experience_band', 'operator' => 'eq', 'value' => '10-14']]));
        $this->assertSame(1, $this->headcount('employees', [['field' => 'workplace', 'operator' => 'contains', 'value' => 'خاصة']]));

        $row = collect($this->report(['dataset' => 'employees', 'columns' => [['field' => 'employee_no'], ['field' => 'licence_level'], ['field' => 'licence_path'], ['field' => 'experience_moe_years'], ['field' => 'appraisal_score']]])['rows'])
            ->firstWhere('employee_no', $s['a']->employee_no);
        $this->assertEquals(2, $row['licence_level']);
        $this->assertSame('مسار المعلم', $row['licence_path']);
        $this->assertEquals(10, $row['experience_moe_years']);
        $this->assertEquals(92, $row['appraisal_score']);
    }

    public function test_the_course_reports_link_the_real_career_path(): void
    {
        $s = $this->staff();
        $inPath = $this->makeProgram(['title_ar' => 'برنامج المسار']);
        $other = $this->makeProgram(['title_ar' => 'برنامج آخر']);
        CareerPathLevel::create(['path_id' => $s['path']->id, 'level_no' => 2, 'title_ar' => 'المستوى الثاني', 'title_en' => 'Level 2', 'conditions' => [], 'required_programs' => [['program_ids' => [$inPath->id], 'min' => 1]], 'min_pd_hours' => 0, 'validity_months' => 36, 'renewal_conditions' => []]);
        foreach ([$inPath, $other] as $p) {
            Registration::create(['program_id' => $p->id, 'employee_id' => $s['a']->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED]);
        }

        $rows = $this->report(['dataset' => 'registrations', 'columns' => [['field' => 'program'], ['field' => 'career_path'], ['field' => 'career_path_level']]], [['field' => 'career_path', 'operator' => 'contains', 'value' => 'المعلم']])['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('برنامج المسار', $rows[0]['program']);
        $this->assertEquals(2, $rows[0]['career_path_level']);
        $this->assertSame(1, $this->headcount('registrations', [['field' => 'entity_type', 'operator' => 'eq', 'value' => 'government'], ['field' => 'program', 'operator' => 'contains', 'value' => 'المسار']]));
    }

    public function test_statistics_group_by_quarter_across_the_quarter_boundary(): void
    {
        $s = $this->staff();
        foreach (['2026-03-31 23:30:00', '2026-04-01 00:10:00', '2026-04-02 10:00:00', '2026-12-31 12:00:00'] as $at) {
            $r = Registration::create(['program_id' => $this->makeProgram()->id, 'employee_id' => $s['c']->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
            $r->forceFill(['created_at' => Carbon::parse($at)])->save();
        }

        $rows = $this->report(['dataset' => 'registrations', 'columns' => [['field' => 'year'], ['field' => 'quarter'], ['field' => 'registrations', 'aggregate' => 'count']], 'sort' => [['field' => 'quarter']]])['rows'];

        $this->assertSame([[2026, 1, 1], [2026, 2, 2], [2026, 4, 1]], array_map(fn ($r) => [(int) $r['year'], (int) $r['quarter'], (int) $r['registrations__count']], $rows));

        app(BuiltInReports::class)->ensure();
        $periodic = app(ReportRunService::class)->preview(ReportDefinition::where('key', 'statistics_periodic')->firstOrFail(), [], $this->makeUser(Role::SUPER_ADMIN), 1, 20);
        $this->assertSame(3, $periodic['parts']);
    }

    public function test_the_new_joins_never_show_another_schools_staff(): void
    {
        $s = $this->staff();
        $schoolAdmin = $this->makeEmployee(['school_id' => $s['priv']->id], $this->makeUser(Role::SCHOOL_ADMIN))->user->load('roles');

        $this->assertSame(0, $this->headcount('employees', [['field' => 'licence_status', 'operator' => 'eq', 'value' => 'active']], $schoolAdmin));
        $this->assertSame(0, $this->headcount('employees', [['field' => 'appraisal_rating', 'operator' => 'eq', 'value' => 'E']], $schoolAdmin));
    }

    public function test_the_built_in_admin_reports_offer_the_rfp_filters(): void
    {
        $filters = fn (string $key) => array_column(collect(BuiltInReports::all())->firstWhere('key', $key)['filters'] ?? [], 'field');

        foreach (['entity_type', 'workplace', 'licence_status', 'job_title', 'experience_band', 'appraisal_year', 'appraisal_rating'] as $f) {
            $this->assertContains($f, $filters('employees_data'), $f);
        }
        foreach (['career_path', 'program', 'entity_type', 'job_title', 'qualification', 'created_at'] as $f) {
            $this->assertContains($f, $filters('employee_courses'), $f);
        }
        $this->assertContains('career_path', array_column(collect(BuiltInReports::all())->firstWhere('key', 'programs_paths_groups')['columns'], 'field'));
    }

    public function test_category_names_are_public_reference_data_and_stay_readable_in_copies(): void
    {
        $c = app(DataClassification::class);

        $this->assertSame('public', $c->classify('program_categories', 'name_ar'));
        $this->assertSame('public', $c->classify('program_categories', 'name_en'));
        $this->assertSame('public', $c->classify('program_categories', 'program_type'));
        $this->assertSame('confidential', $c->classify('users', 'name_ar'));   // a person's name stays confidential
    }

    public function test_the_report_hub_describes_each_adjustable_filter(): void
    {
        app(BuiltInReports::class)->ensure();
        $id = ReportDefinition::where('key', 'employees_data')->value('id');

        $adjustable = collect($this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson("/api/v1/admin/report-definitions/{$id}")->assertOk()->json('data.adjustable'))->keyBy('field');

        $this->assertSame('حالة الرخصة', $adjustable['licence_status']['label']['ar']);
        $this->assertSame('enum', $adjustable['licence_status']['type']);
        $this->assertContains('expired', $adjustable['licence_status']['values']);
        $this->assertSame('number', $adjustable['appraisal_year']['type']);
    }

    public function test_coded_values_read_as_words_on_screen_and_in_exported_files(): void
    {
        $s = $this->staff();
        $def = new ReportDefinition(['dataset' => 'employees', 'columns' => [['field' => 'employee_no'], ['field' => 'entity_type'], ['field' => 'licence_status']], 'title_ar' => 'ت', 'title_en' => 'T']);

        $result = $this->report($def->toArray());
        $this->assertSame('خاصة', collect($result['columns'])->firstWhere('key', 'entity_type')['labels']['private']['ar']);

        $rows = app(ReportRunService::class)->document($def, [], $this->head, 'ar')['sections'][0]['table']['rows'];
        $a = collect($rows)->first(fn ($r) => $r[0] === $s['a']->employee_no);
        $this->assertSame(['حكومية', 'سارية'], [$a[1], $a[2]]);
        $en = collect(app(ReportRunService::class)->document($def, [], $this->head, 'en')['sections'][0]['table']['rows'])->first(fn ($r) => $r[0] === $s['c']->employee_no);
        $this->assertSame('Private', $en[1]);
    }
}
