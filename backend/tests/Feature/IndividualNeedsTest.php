<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ClassroomObservation;
use App\Models\Employee;
use App\Models\IndividualNeed;
use App\Models\NeedsRule;
use App\Models\NeedsSurvey;
use App\Models\PerformanceAppraisal;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class IndividualNeedsTest extends TestCase
{
    private function skill(string $code = 'digital'): Skill
    {
        return Skill::firstOrCreate(['code' => $code], ['name_en' => ucfirst($code), 'name_ar' => $code, 'category' => 'pedagogy']);
    }

    /** @return array{0: User, 1: Employee, 2: Employee} manager user, manager employee, report */
    private function team(): array
    {
        $manager = $this->makeUser(Role::SUPERVISOR);
        $me = $this->makeEmployee([], $manager);
        $report = $this->makeEmployee(['supervisor_id' => $me->id]);

        return [$manager, $me, $report];
    }

    public function test_an_employee_declares_a_need_and_the_manager_is_notified_and_decides_in_bulk(): void
    {
        [$manager, , $report] = $this->team();
        $other = $this->makeEmployee();
        $skill = $this->skill();

        $this->asUser($report->user)->postJson('/api/v1/me/needs', ['skill_id' => $skill->id, 'justification' => 'New curriculum'])->assertCreated()->assertJsonPath('data.status', 'pending_manager');
        $this->asUser($other->user)->postJson('/api/v1/me/needs', ['skill_id' => $skill->id, 'justification' => 'x'])->assertCreated();
        $this->assertTrue(AppNotification::where('user_id', $manager->id)->where('type', 'individual_need.awaiting_manager')->exists());

        $list = $this->asUser($manager)->getJson('/api/v1/admin/individual-needs')->assertOk()->json('data');
        $this->assertCount(1, $list, 'a manager sees only their own staff');
        $strangerNeed = IndividualNeed::where('employee_id', $other->id)->first();

        $this->asUser($manager)->postJson('/api/v1/admin/individual-needs/decide', ['ids' => [$list[0]['id']], 'decision' => 'rejected'])->assertStatus(422);
        $res = $this->asUser($manager)->postJson('/api/v1/admin/individual-needs/decide', ['ids' => [$list[0]['id'], $strangerNeed->id], 'decision' => 'approved'])->assertOk();
        $this->assertSame(1, $res->json('data.decided'));
        $this->assertSame(1, $res->json('data.skipped'));
        $this->assertSame('pending_manager', $strangerNeed->fresh()->status);
        $this->assertTrue(AppNotification::where('user_id', $report->user_id)->where('type', 'individual_need.decided')->exists());
    }

    public function test_needs_a_manager_ignores_are_approved_automatically_and_audited(): void
    {
        [, , $report] = $this->team();
        $need = IndividualNeed::create(['employee_id' => $report->id, 'skill_id' => $this->skill()->id, 'source' => 'self', 'status' => 'pending_manager']);
        IndividualNeed::whereKey($need->id)->update(['created_at' => now()->subDays(20)]);

        $this->artisan('tedc:needs-daily')->assertSuccessful();

        $this->assertSame('auto_approved', $need->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'individual_need_auto_approved']);
    }

    public function test_rules_create_explained_needs_for_new_hires_appraisals_and_observations_without_duplicates(): void
    {
        $skill = $this->skill('induction');
        $newHire = $this->makeEmployee(['hire_date' => today()->subMonths(2)]);
        $veteran = $this->makeEmployee(['hire_date' => today()->subYears(5)]);
        PerformanceAppraisal::create(['employee_id' => $veteran->id, 'year' => 2025, 'rating_code' => 'weak']);
        ClassroomObservation::create(['employee_id' => $newHire->id, 'observed_on' => today()->subMonth(), 'scores' => [$skill->id => 1], 'source' => 'import']);
        NeedsRule::create(['name_ar' => 'ج', 'name_en' => 'New hires', 'trigger' => 'new_hire', 'conditions' => ['months' => 6], 'action' => ['skill_ids' => [$skill->id], 'required_level' => 3]]);
        NeedsRule::create(['name_ar' => 'ت', 'name_en' => 'Weak', 'trigger' => 'appraisal', 'conditions' => ['years' => [2025], 'ratings' => ['weak']], 'action' => ['skill_ids' => [$skill->id], 'required_level' => 3]]);
        NeedsRule::create(['name_ar' => 'م', 'name_en' => 'Observed', 'trigger' => 'observation', 'conditions' => ['below_level' => 3], 'action' => ['skill_ids' => [$skill->id], 'required_level' => 3]]);

        $this->artisan('tedc:needs-daily')->assertSuccessful();
        $this->artisan('tedc:needs-daily')->assertSuccessful();

        $this->assertSame(1, IndividualNeed::where('employee_id', $newHire->id)->where('source', 'new_hire')->count());
        $this->assertSame(1, IndividualNeed::where('employee_id', $veteran->id)->where('source', 'appraisal')->count());
        $this->assertSame(1, IndividualNeed::where('employee_id', $newHire->id)->where('source', 'observation')->count());
        $this->assertSame(0, IndividualNeed::where('employee_id', $veteran->id)->where('source', 'new_hire')->count());
        $this->assertNotEmpty(IndividualNeed::where('source', 'appraisal')->first()->explanation_en);
    }

    public function test_appraisal_imports_report_every_problem_and_are_idempotent(): void
    {
        $planner = $this->makeUser(Role::PLANNING_HEAD);
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $csv = "employee_no,year,rating,score\n{$a->employee_no},2025,weak,40\n{$b->employee_no},2025,ممتاز,95\nNOPE,2025,good,70\n{$a->employee_no},2025,good,70\n{$b->employee_no},2025,bad,1\n";

        $res = $this->asUser($planner)->post('/api/v1/admin/performance/appraisals/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, $res->json('data.imported'));
        $this->assertCount(3, $res->json('data.errors'));
        $this->assertSame('excellent', PerformanceAppraisal::where('employee_id', $b->id)->value('rating_code'));

        $again = $this->asUser($planner)->post('/api/v1/admin/performance/appraisals/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', "employee_no,year,rating,score\n{$a->employee_no},2025,weak,40\n")], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, $again->json('data.imported'));
        $this->assertSame(1, $again->json('data.updated'));
        $this->assertSame(2, PerformanceAppraisal::count());
    }

    public function test_the_weak_performer_report_filters_by_year_and_rating_and_targets_with_one_click(): void
    {
        $planner = $this->makeUser(Role::PLANNING_HEAD);
        $weak = $this->makeEmployee();
        $fine = $this->makeEmployee();
        PerformanceAppraisal::create(['employee_id' => $weak->id, 'year' => 2025, 'rating_code' => 'weak']);
        PerformanceAppraisal::create(['employee_id' => $weak->id, 'year' => 2023, 'rating_code' => 'weak']);
        PerformanceAppraisal::create(['employee_id' => $fine->id, 'year' => 2025, 'rating_code' => 'good']);
        $skill = $this->skill('feedback');

        $rows = $this->asUser($planner)->getJson('/api/v1/admin/performance/weak?years=2025&ratings=weak,acceptable')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['ratings']);

        $this->asUser($planner)->postJson('/api/v1/admin/performance/weak/target?years=2025&ratings=weak', ['skill_ids' => [$skill->id]])->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame(1, IndividualNeed::where('employee_id', $weak->id)->where('source', 'appraisal')->count());
    }

    public function test_observation_import_maps_competency_columns(): void
    {
        $planner = $this->makeUser(Role::PLANNING_HEAD);
        $e = $this->makeEmployee();
        $skill = $this->skill('questioning');
        $csv = "employee_no,observed_on,observer_role,subject,skill:questioning\n{$e->employee_no},2026-03-01,principal,Maths,2\n{$e->employee_no},not-a-date,principal,Maths,2\n";

        $res = $this->asUser($planner)->post('/api/v1/admin/performance/observations/import', ['file' => UploadedFile::fake()->createWithContent('o.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertCount(1, $res->json('data.errors'));
        $this->assertSame(2, ClassroomObservation::where('employee_id', $e->id)->first()->scores[$skill->id]);
    }

    public function test_instrument_approval_is_audited_returned_with_a_note_and_needs_the_planning_head(): void
    {
        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $id = $this->asUser($specialist)->postJson('/api/v1/admin/needs-surveys', ['title' => 'S', 'questions' => [['id' => 'q', 'type' => 'short_text', 'title' => 'Q']]])->assertCreated()->json('data.id');

        $this->asUser($specialist)->postJson("/api/v1/admin/needs-surveys/{$id}/submit-approval")->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $head->id)->where('type', 'instrument.awaiting_approval')->exists());
        $this->asUser($specialist)->postJson("/api/v1/admin/needs-surveys/{$id}/approve")->assertForbidden();
        $this->asUser($head)->postJson("/api/v1/admin/needs-surveys/{$id}/return")->assertStatus(422);
        $this->asUser($head)->postJson("/api/v1/admin/needs-surveys/{$id}/return", ['note' => 'Shorten it'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $specialist->id)->where('type', 'instrument.decided')->exists());
        $this->asUser($head)->postJson("/api/v1/admin/needs-surveys/{$id}/approve")->assertOk();
        $this->assertSame($head->id, NeedsSurvey::find($id)->approved_by);
    }

    public function test_survey_answers_below_the_required_level_become_needs_for_the_manager(): void
    {
        [$manager, , $report] = $this->team();
        $skill = $this->skill('rubrics');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/needs-surveys', ['title' => 'S', 'questions' => [['id' => 'm', 'type' => 'matrix', 'title' => 'Level', 'scale' => ['min' => 1, 'max' => 5], 'rows' => [['id' => 'r1', 'label' => 'Rubrics', 'skill_id' => $skill->id]]]], 'audience' => ['school_ids' => [$report->school_id]]])->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/{$id}/submit-approval");
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/{$id}/approve");
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/{$id}/publish")->assertOk();

        $this->asUser($report->user)->postJson("/api/v1/me/needs-surveys/{$id}", ['answers' => ['m' => ['r1' => 1]]])->assertOk();

        $need = IndividualNeed::where('employee_id', $report->id)->where('source', 'self_survey')->first();
        $this->assertNotNull($need);
        $this->assertSame(1, $need->current_level);
        $this->assertSame('pending_manager', $need->status);
        $this->assertTrue(AppNotification::where('user_id', $manager->id)->where('type', 'individual_need.awaiting_manager')->exists());
    }
}
