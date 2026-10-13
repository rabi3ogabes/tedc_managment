<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Role;
use App\Models\TrainingNeed;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanItem;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** NDS-08: the year's rules — the five programme types, mandatory categories and the seat and hour caps — are applied, explained and audited. */
class PlanRulesEnforcementTest extends TestCase
{
    private User $specialist;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $this->head = $this->makeUser(Role::PLANNING_HEAD);
    }

    private function category(string $slug, ?string $type): ProgramCategory
    {
        return ProgramCategory::create(['slug' => $slug, 'name_ar' => 'فئة '.$slug, 'name_en' => 'Category '.$slug, 'program_type' => $type]);
    }

    private function need(string $skill, int $seats, string $priority = 'high', ?Program $program = null): TrainingNeed
    {
        return TrainingNeed::create(['school_id' => $this->makeSchool()->id, 'skill_name' => $skill, 'employees_count' => $seats, 'priority' => $priority, 'reason' => 'x', 'status' => 'approved', 'program_id' => $program?->id]);
    }

    private function plan(array $rules = []): string
    {
        $id = $this->asUser($this->specialist)->postJson('/api/v1/admin/plans', ['year' => 2027, 'title_ar' => 'خطة', 'title_en' => 'Plan'])->assertCreated()->json('data.id');
        if ($rules) {
            $this->asUser($this->specialist)->putJson("/api/v1/admin/plans/{$id}/rules", ['rules' => $rules])->assertOk();
        }

        return $id;
    }

    public function test_the_five_programme_types_are_offered_with_the_rfp_names(): void
    {
        $types = $this->asUser($this->specialist)->getJson('/api/v1/admin/program-types')->assertOk()->json('data');

        $this->assertSame(['licensing', 'empowerment', 'professionalisation', 'specialisation', 'electives'], array_column($types, 'id'));
        $this->assertSame(['ترخيص', 'تمكين', 'تمهين', 'تخصيص', 'تخيير'], array_column($types, 'name_ar'));
    }

    public function test_a_programme_takes_its_type_from_its_category_unless_set_itself(): void
    {
        $cat = $this->category('lic', 'licensing');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson("/api/v1/admin/categories/{$cat->id}", ['program_type' => 'empowerment'])->assertOk()->assertJsonPath('data.program_type', 'empowerment');

        $inherited = $this->makeProgram(['category_id' => $cat->id]);
        $own = $this->makeProgram(['category_id' => $cat->id, 'program_type' => 'electives']);

        $this->assertSame('empowerment', $inherited->fresh()->effectiveType());
        $this->assertSame('electives', $own->fresh()->effectiveType());
        $this->asUser($admin)->putJson("/api/v1/admin/categories/{$cat->id}", ['program_type' => 'apprentice'])->assertStatus(422);
        $this->assertSame('empowerment', collect($this->getJson('/api/v1/public/categories')->assertOk()->json('data'))->firstWhere('id', $cat->id)['program_type']);
    }

    public function test_needs_of_a_type_outside_the_year_are_excluded_with_a_reason(): void
    {
        $lic = $this->makeProgram(['category_id' => $this->category('lic', 'licensing')->id]);
        $ele = $this->makeProgram(['category_id' => $this->category('ele', 'electives')->id]);
        $this->need('Licence preparation', 20, 'high', $lic);
        $this->need('Photography club', 15, 'low', $ele);
        $this->need('No programme yet', 5);
        $id = $this->plan(['program_types' => ['licensing']]);

        $res = $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $this->assertEqualsCanonicalizing(['Licence preparation', 'No programme yet'], array_column($res->json('data.items'), 'title_en'));
        $this->assertSame('Photography club', $res->json('data.excluded.0.title'));
        $this->assertSame('type_not_allowed', $res->json('data.excluded.0.reason'));
        $this->assertSame('licensing', TrainingPlanItem::where('title_en', 'Licence preparation')->value('program_type'));
        $compliance = $this->asUser($this->specialist)->getJson("/api/v1/admin/plans/{$id}/compliance")->assertOk()->json('data');
        $this->assertSame(1, $compliance['by_type']['licensing']);
        $this->assertSame('type_missing', $compliance['warnings'][0]['code']);
    }

    public function test_an_empty_type_list_and_empty_caps_mean_no_rule(): void
    {
        $this->need('Anything', 900, 'medium', $this->makeProgram(['category_id' => $this->category('ele', 'electives')->id]));
        $id = $this->plan(['program_types' => [], 'max_seats_total' => null, 'max_hours_total' => null]);

        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk()->assertJsonCount(1, 'data.items');

        $this->assertSame([], $this->asUser($this->specialist)->getJson("/api/v1/admin/plans/{$id}/compliance")->json('data.violations'));
    }

    public function test_a_missing_mandatory_category_gets_a_placeholder_item(): void
    {
        $safety = $this->category('safety', 'empowerment');
        $id = $this->plan(['mandatory_categories' => [$safety->id]]);

        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $item = TrainingPlanItem::where('plan_id', $id)->where('source', 'mandatory')->firstOrFail();
        $this->assertSame($safety->id, $item->category_id);
        $this->assertSame('empowerment', $item->program_type);
        $this->assertStringContainsString('mandatory', $item->rationale_en);
        $this->assertTrue($this->asUser($this->specialist)->getJson("/api/v1/admin/plans/{$id}/compliance")->json('data.mandatory.0.covered'));
    }

    public function test_the_totals_cap_defers_the_lowest_scored_items_with_the_reason(): void
    {
        $this->need('Critical skill', 30, 'critical');
        $this->need('Middle skill', 20, 'medium');
        $this->need('Low skill', 25, 'low');
        $id = $this->plan(['max_seats_total' => 55, 'max_seats_per_group' => 25]);

        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $deferred = TrainingPlanItem::where('plan_id', $id)->where('status', 'deferred')->get();
        $this->assertSame(['Low skill'], $deferred->pluck('title_en')->all());
        $this->assertSame('seats_over_cap', $deferred->first()->deferred_reason);
        $c = $this->asUser($this->specialist)->getJson("/api/v1/admin/plans/{$id}/compliance")->json('data');
        $this->assertSame(50, $c['caps']['seats']['used']);
        $this->assertSame(55, $c['caps']['seats']['max']);
        $this->assertSame([], $c['violations']);
    }

    public function test_an_item_bigger_than_the_cap_is_deferred_without_taking_smaller_ones_with_it_and_comes_back_when_the_cap_grows(): void
    {
        $this->need('Huge priority', 400, 'critical');
        $this->need('Small one', 40, 'high');
        $this->need('Small two', 30, 'medium');
        $id = $this->plan(['max_seats_total' => 300, 'max_seats_per_group' => 25]);

        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $status = fn () => TrainingPlanItem::where('plan_id', $id)->pluck('status', 'title_en')->all();
        $this->assertSame(['Huge priority' => 'deferred', 'Small one' => 'planned', 'Small two' => 'planned'], collect($status())->sortKeys()->all());

        $this->asUser($this->specialist)->putJson("/api/v1/admin/plans/{$id}/rules", ['rules' => ['max_seats_total' => 500]])->assertOk();
        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $this->assertSame(['Huge priority' => 'planned', 'Small one' => 'planned', 'Small two' => 'planned'], collect($status())->sortKeys()->all());
        $this->assertNull(TrainingPlanItem::where('plan_id', $id)->where('title_en', 'Huge priority')->value('deferred_reason'));
    }

    public function test_submit_refuses_a_plan_that_breaks_the_rules_and_lists_every_violation(): void
    {
        $mandatory = $this->category('safety', 'empowerment');
        $id = $this->plan(['program_types' => ['licensing'], 'max_hours_total' => 10]);
        $ele = $this->category('ele', 'electives');
        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/items", ['title_ar' => 'نادي', 'title_en' => 'Club', 'category_id' => $ele->id, 'planned_hours' => 30])->assertCreated();
        $this->asUser($this->specialist)->putJson("/api/v1/admin/plans/{$id}/rules", ['rules' => ['mandatory_categories' => [$mandatory->id]]])->assertOk();

        $res = $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'plan_rules_broken');

        $this->assertEqualsCanonicalizing(['type_not_allowed', 'mandatory_missing', 'hours_over_cap'], array_column($res->json('details.violations'), 'code'));
        $this->assertSame(TrainingPlan::DRAFT, TrainingPlan::find($id)->status);
    }

    public function test_the_planning_head_may_go_ahead_with_a_documented_exception_which_is_audited(): void
    {
        $id = $this->plan(['max_hours_total' => 10]);
        $this->asUser($this->head)->postJson("/api/v1/admin/plans/{$id}/items", ['title_ar' => 'برنامج', 'title_en' => 'Big programme', 'planned_hours' => 40])->assertCreated();

        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/submit", ['exception_reason' => 'Ministry directive'])->assertStatus(422);   // no approval right
        $this->asUser($this->head)->postJson("/api/v1/admin/plans/{$id}/submit")->assertStatus(422);
        $this->asUser($this->head)->postJson("/api/v1/admin/plans/{$id}/submit", ['exception_reason' => 'Ministry directive 12/2027'])->assertOk();
        $this->asUser($this->head)->postJson("/api/v1/admin/plans/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'exception_reason_required');
        $this->asUser($this->head)->postJson("/api/v1/admin/plans/{$id}/approve", ['exception_reason' => 'Ministry directive 12/2027'])->assertOk()->assertJsonPath('data.status', 'approved');

        $log = AuditLog::where('action', 'plan_rule_exception')->where('auditable_id', $id)->latest('created_at')->firstOrFail();
        $this->assertSame('Ministry directive 12/2027', $log->new_values['reason']);
        $this->assertSame('hours_over_cap', $log->new_values['violations'][0]['code']);
        $this->assertSame('Ministry directive 12/2027', TrainingPlan::find($id)->rule_exception['reason']);
    }

    public function test_items_keep_the_type_they_were_planned_with(): void
    {
        $cat = $this->category('lic', 'licensing');
        $this->need('Licence preparation', 20, 'high', $this->makeProgram(['category_id' => $cat->id]));
        $id = $this->plan();
        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $cat->update(['program_type' => 'electives']);

        $this->assertSame('licensing', TrainingPlanItem::where('plan_id', $id)->value('program_type'));
    }

    public function test_plans_saved_with_the_old_slug_read_it_as_professionalisation(): void
    {
        $plan = TrainingPlan::create(['year' => 2025, 'version' => 1, 'title_ar' => 'قديمة', 'title_en' => 'Old', 'status' => TrainingPlan::APPROVED, 'rules' => ['program_types' => ['licensing', 'apprenticeship']]]);

        $rules = $this->asUser($this->specialist)->getJson("/api/v1/admin/plans/{$plan->id}")->assertOk()->json('data.rules');

        $this->assertSame(['licensing', 'professionalisation'], $rules['program_types']);
        $this->asUser($this->specialist)->getJson("/api/v1/admin/plans/{$plan->id}/compliance")->assertOk();
    }

    public function test_the_plan_export_carries_the_type_and_the_compliance_summary(): void
    {
        $this->need('Licence preparation', 20, 'high', $this->makeProgram(['category_id' => $this->category('lic', 'licensing')->id]));
        $id = $this->plan(['max_seats_total' => 100]);
        $this->asUser($this->specialist)->postJson("/api/v1/admin/plans/{$id}/generate")->assertOk();

        $bytes = $this->asUser($this->specialist)->get("/api/v1/admin/plans/{$id}/export?format=xlsx")->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $bytes);
        $cells = collect(IOFactory::load($path)->getActiveSheet()->toArray())->flatten()->filter()->map(fn ($v) => (string) $v)->all();
        @unlink($path);

        $this->assertContains('ترخيص', $cells);
        $this->assertContains('20 / 100', $cells);
        $this->asUser($this->specialist)->get("/api/v1/admin/plans/{$id}/export?format=pdf")->assertOk();
    }
}
