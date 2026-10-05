<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CareerPath;
use App\Models\Certificate;
use App\Models\KnowledgeTransfer;
use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\PdAnnualTarget;
use App\Models\PdRecognitionRequest;
use App\Models\ProfessionalLicence;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Services\AnnualHoursService;
use App\Services\CareerPathEngine;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;
use App\Services\KnowledgeTransferService;
use App\Services\LicenceService;
use App\Services\PdService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CareerAndPdTest extends TestCase
{
    private function path(array $levels, string $type = 'licence'): CareerPath
    {
        $p = CareerPath::create(['type' => $type, 'title_ar' => 'مسار', 'title_en' => 'Path']);
        foreach ($levels as $i => $l) {
            $p->levels()->create($l + ['level_no' => $i + 1, 'title_ar' => 'مستوى '.($i + 1), 'title_en' => 'Level '.($i + 1)]);
        }

        return $p->fresh('levels');
    }

    private function type(): PdActivityType
    {
        app(PdService::class)->ensureTypes();

        return PdActivityType::where('code', 'conference')->first();
    }

    public function test_a_level_is_eligible_only_when_every_condition_is_met_and_the_gain_is_told_once(): void
    {
        $employee = $this->makeEmployee(['experience_years' => 4]);
        $manager = $this->makeEmployee([], $this->makeUser(Role::SUPERVISOR));
        $employee->update(['supervisor_id' => $manager->id]);
        $program = $this->makeProgram();
        $path = $this->path([['conditions' => [['field' => 'experience_years', 'operator' => 'gte', 'value' => 3]], 'required_programs' => [['program_ids' => [$program->id], 'min' => 1]], 'min_pd_hours' => 10]]);
        $engine = app(CareerPathEngine::class);

        $row = $engine->evaluatePath($employee->fresh(), $path);
        $this->assertSame('in_progress', $row->status);
        $this->assertSame([true, false, false], array_column($row->explanation, 'met'));

        Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED, 'completed_at' => now()]);
        Certificate::create(['certificate_no' => 'C1', 'verification_code' => 'V1', 'registration_id' => Registration::first()->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 12, 'status' => 'valid']);
        $this->assertSame('eligible', $engine->evaluatePath($employee->fresh(), $path)->status);
        $engine->evaluatePath($employee->fresh(), $path);
        $this->assertSame(1, AppNotification::where('user_id', $employee->user_id)->where('type', 'path.eligible')->count());
        $this->assertSame(1, AppNotification::where('user_id', $manager->user_id)->where('type', 'path.eligible')->count());

        // Losing a condition is told once as well.
        $employee->update(['experience_years' => 1]);
        $this->assertSame('in_progress', $engine->evaluatePath($employee->fresh(), $path)->status);
        $this->assertSame(1, AppNotification::where('user_id', $employee->user_id)->where('type', 'path.condition_lost')->count());
    }

    public function test_achieving_a_licence_level_issues_a_licence_that_unlocks_level_restricted_programs(): void
    {
        $employee = $this->makeEmployee();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $path = $this->path([['validity_months' => 36], ['conditions' => [['field' => 'licence_level', 'operator' => 'gte', 'value' => 1]]]]);
        $restricted = $this->makeProgram();
        $restricted->eligibilityRules()->create(['field' => 'licence_level', 'operator' => 'gte', 'value' => 1, 'is_mandatory' => true]);
        $eligibility = fn () => app(EligibilityEngine::class)->evaluate($restricted->fresh(), $employee->fresh())->eligible;

        $this->assertFalse($eligibility());
        $this->asUser($admin)->postJson("/api/v1/admin/career-paths/{$path->id}/achieve", ['employee_id' => $employee->id])->assertOk();   // level 1 has no conditions
        $licence = ProfessionalLicence::first();
        $this->assertSame(1, $licence->level_no);
        $this->assertSame(today()->addMonths(36)->toDateString(), $licence->expires_at->toDateString());
        $this->assertTrue($eligibility());

        // Not eligible → cannot be advanced by hand.
        $other = $this->makeEmployee(['experience_years' => 0]);
        $path2 = $this->path([['conditions' => [['field' => 'experience_years', 'operator' => 'gte', 'value' => 9]]]]);
        $this->asUser($admin)->postJson("/api/v1/admin/career-paths/{$path2->id}/achieve", ['employee_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'not_eligible');
    }

    public function test_licence_import_is_idempotent_and_expiry_and_reminders_fire_once(): void
    {
        $employee = $this->makeEmployee();
        $svc = app(LicenceService::class);
        $rows = [['employee_no' => $employee->employee_no, 'level_no' => 2, 'licence_no' => 'L-1', 'issued_at' => '2025-01-01', 'expires_at' => today()->addDays(25)->toDateString()], ['employee_no' => 'NOPE', 'level_no' => 1, 'licence_no' => 'L-2', 'issued_at' => '2025-01-01']];

        $first = $svc->import($rows);
        $this->assertSame([1, 0, 1], [$first['created'], $first['updated'], count($first['errors'])]);
        $second = $svc->import($rows);
        $this->assertSame([0, 1], [$second['created'], $second['updated']]);
        $this->assertSame(1, ProfessionalLicence::count());

        $this->assertSame(['expired' => 0, 'reminded' => 1], $svc->monitor());          // 25 days left: the 30-day reminder
        $this->assertSame(['expired' => 0, 'reminded' => 0], $svc->monitor());
        ProfessionalLicence::first()->update(['expires_at' => today()->subDay()]);
        $this->assertSame(['expired' => 1, 'reminded' => 0], $svc->monitor());
        $this->assertSame('expired', ProfessionalLicence::first()->status);
        $this->assertSame(1, AppNotification::where('user_id', $employee->user_id)->where('type', 'licence.expired')->count());
        $this->assertSame(['expired' => 0, 'reminded' => 0], $svc->monitor());
    }

    public function test_pd_hours_follow_the_participation_level_and_caps_and_the_manager_decides(): void
    {
        Storage::fake('local');
        $manager = $this->makeEmployee([], $this->makeUser(Role::SUPERVISOR));
        $employee = $this->makeEmployee();
        $employee->update(['supervisor_id' => $manager->id]);
        $type = $this->type();                                       // presenter ×1.5, capped at 40 h per year
        $svc = app(PdService::class);
        $this->assertSame(1.5 * 10, $svc->compute($type, 'presenter', 10.0));
        $this->assertSame(10.0, $svc->compute($type, 'attendee', 10.0));

        $payload = ['type_id' => $type->id, 'title' => 'مؤتمر', 'starts_on' => today()->toDateString(), 'duration_hours' => 30, 'participation_level' => 'presenter'];
        $id = $this->asUser($employee->user)->postJson('/api/v1/me/pd-activities', $payload)->assertCreated()->assertJsonPath('data.computed_hours', 45)->json('data.id');
        $this->asUser($employee->user)->postJson("/api/v1/me/pd-activities/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'evidence_required');
        $this->asUser($employee->user)->post("/api/v1/me/pd-activities/{$id}", $payload + ['evidence' => [UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertOk();
        $this->asUser($employee->user)->postJson("/api/v1/me/pd-activities/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_manager')->assertJsonPath('data.manager_id', $manager->user_id);

        $this->asUser($manager->user)->getJson('/api/v1/me/team/pd-activities')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($manager->user)->postJson("/api/v1/me/team/pd-activities/{$id}/decision", ['decision' => 'return'])->assertStatus(422)->assertJsonPath('code', 'note_required');
        $this->asUser($manager->user)->postJson("/api/v1/me/team/pd-activities/{$id}/decision", ['decision' => 'return', 'note' => 'add details'])->assertOk()->assertJsonPath('data.status', 'returned');
        $this->asUser($employee->user)->postJson("/api/v1/me/pd-activities/{$id}/submit")->assertOk();
        $this->asUser($manager->user)->postJson("/api/v1/me/team/pd-activities/{$id}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.approved_hours', 40);   // the yearly cap of the type

        $other = $this->makeEmployee();
        $this->asUser($other->user)->postJson("/api/v1/me/pd-activities/{$id}/submit")->assertNotFound();
        $this->asUser($this->makeEmployee([], $this->makeUser(Role::SUPERVISOR))->user)->postJson("/api/v1/me/team/pd-activities/{$id}/decision", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_recognition_sets_the_hours_and_the_equivalent_programs_count_as_completed(): void
    {
        $employee = $this->makeEmployee();
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $program = $this->makeProgram();
        $type = $this->type();
        $a = PdActivity::create(['employee_id' => $employee->id, 'type_id' => $type->id, 'title' => 'x', 'starts_on' => today(), 'duration_hours' => 10, 'computed_hours' => 10, 'status' => 'pending_manager', 'recognition_request' => true, 'evidence' => [['type' => 'link', 'url' => 'https://a.test']], 'manager_id' => $head->id]);
        app(PdService::class)->decide($a, 'approve', null, $head);
        $r = PdRecognitionRequest::first();
        $this->assertNotNull($r);

        $this->asUser($head)->postJson("/api/v1/admin/pd-recognitions/{$r->id}/decision", ['decision' => 'approved', 'recognised_hours' => 6, 'equivalent_program_ids' => [$program->id]])->assertOk();
        $this->assertEquals(6.0, (float) $a->fresh()->approved_hours);
        $ctx = EmployeeContext::fromEmployee($employee->fresh());
        $this->assertContains($program->code, $ctx->completedPrograms);
        $this->asUser($head)->postJson("/api/v1/admin/pd-recognitions/{$r->id}/decision", ['decision' => 'rejected'])->assertStatus(422)->assertJsonPath('code', 'already_decided');
    }

    public function test_annual_hours_add_up_every_source_in_calendar_and_fiscal_years_and_alert_on_shortfall(): void
    {
        $employee = $this->makeEmployee();
        $manager = $this->makeEmployee();
        $employee->update(['supervisor_id' => $manager->id]);
        $svc = app(AnnualHoursService::class);
        $type = $this->type();
        $program = $this->makeProgram();
        $school = $this->makeProgram(['owner_type' => 'school']);
        $reg = fn ($p) => Registration::create(['program_id' => $p->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED]);
        Certificate::create(['certificate_no' => 'A', 'verification_code' => 'A', 'registration_id' => $reg($program)->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => Carbon::create(2026, 3, 1), 'hours' => 12, 'status' => 'valid']);
        Certificate::create(['certificate_no' => 'B', 'verification_code' => 'B', 'registration_id' => $reg($school)->id, 'employee_id' => $employee->id, 'program_id' => $school->id, 'issued_at' => Carbon::create(2026, 4, 1), 'hours' => 4, 'status' => 'valid']);
        PdActivity::create(['employee_id' => $employee->id, 'type_id' => $type->id, 'title' => 'x', 'starts_on' => Carbon::create(2026, 5, 1), 'duration_hours' => 8, 'computed_hours' => 8, 'approved_hours' => 8, 'status' => 'approved']);
        KnowledgeTransfer::create(['registration_id' => Registration::first()->id, 'employee_id' => $employee->id, 'delivered_on' => Carbon::create(2026, 6, 1), 'hours' => 3, 'status' => 'approved']);
        PdAnnualTarget::create(['year' => 2026, 'min_hours' => 40, 'counts' => ['center' => true, 'internal' => true, 'external' => true, 'knowledge_transfer' => true, 'caps' => ['external' => 5]]]);

        $s = $svc->summary($employee->fresh(), 2026);
        $this->assertEquals(['center' => 12.0, 'internal' => 4.0, 'external' => 5.0, 'knowledge_transfer' => 3.0], $s['counted']);   // external capped at 5
        $this->assertEquals(24.0, $s['total']);
        $this->assertEquals(16.0, $s['shortfall']);

        // A fiscal year that starts in September puts March 2026 in the 2025 year.
        SiteSetting::create(['key' => 'pd', 'value' => ['year_start_month' => 9]]);
        $this->assertSame(2025, $svc->yearOf(Carbon::create(2026, 3, 1)));
        $this->assertEquals(0.0, $svc->summary($employee->fresh(), 2026)['raw']['center']);
        SiteSetting::where('key', 'pd')->delete();

        // Quarterly alert on the first day of a quarter, once.
        $this->assertSame(1, $svc->alertShortfalls(Carbon::create(2026, 4, 1)));
        $this->assertSame(0, $svc->alertShortfalls(Carbon::create(2026, 4, 1)));
        $this->assertSame(1, AppNotification::where('user_id', $manager->user_id)->where('type', 'hours.shortfall')->count());
        $this->assertSame(0, $svc->alertShortfalls(Carbon::create(2026, 4, 15)));
    }

    public function test_knowledge_transfer_needs_evidence_beneficiaries_and_hours_and_counts_after_approval(): void
    {
        Storage::fake('local');
        $employee = $this->makeEmployee();
        $head = $this->makeUser(Role::COORDINATOR);
        $program = $this->makeProgram(['knowledge_transfer' => ['required' => true, 'min_beneficiaries' => 2, 'min_hours' => 1, 'deadline_days' => 10]]);
        $r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED, 'completed_at' => now()]);
        $kt = app(KnowledgeTransferService::class)->createFor($r);
        $this->assertSame(today()->addDays(10)->toDateString(), $kt->due_on->toDateString());

        $base = ['delivered_on' => today()->toDateString(), 'hours' => 2, 'method' => 'workshop', 'beneficiaries' => [['name' => 'سارة'], ['name' => 'أحمد']]];
        $this->asUser($employee->user)->postJson("/api/v1/me/knowledge-transfers/{$kt->id}", ['beneficiaries' => [['name' => 'سارة']]] + $base)->assertStatus(422)->assertJsonValidationErrors('beneficiaries');
        $this->asUser($employee->user)->postJson("/api/v1/me/knowledge-transfers/{$kt->id}", $base)->assertStatus(422)->assertJsonValidationErrors('evidence');
        $this->asUser($employee->user)->post("/api/v1/me/knowledge-transfers/{$kt->id}", $base + ['evidence' => [UploadedFile::fake()->create('sheet.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.beneficiary_count', 2);

        $this->assertSame(0, app(KnowledgeTransferService::class)->reach($program->id)['indirect_beneficiaries']);
        $this->asUser($head)->postJson("/api/v1/admin/knowledge-transfers/{$kt->id}/decision", ['decision' => 'approve'])->assertOk();
        $reach = app(KnowledgeTransferService::class)->reach($program->id);
        $this->assertSame([1, 2, 2.0], [$reach['direct_trainees'], $reach['indirect_beneficiaries'], $reach['hours']]);
        $this->assertEquals(2.0, app(AnnualHoursService::class)->bySource($employee)['knowledge_transfer']);
    }

    public function test_knowledge_transfer_reminders_and_the_passing_criterion(): void
    {
        $employee = $this->makeEmployee();
        $program = $this->makeProgram(['knowledge_transfer' => ['required' => true, 'deadline_days' => 2]]);
        $r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED, 'completed_at' => now()]);
        $svc = app(KnowledgeTransferService::class);
        $kt = $svc->createFor($r);
        $this->assertSame(['due' => 1, 'overdue' => 0], $svc->remind());                         // 2 days left
        $this->assertSame(['due' => 0, 'overdue' => 0], $svc->remind());
        $kt->update(['due_on' => today()->subDay(), 'reminded_at' => now()->subDays(3)]);
        $this->assertSame(['due' => 0, 'overdue' => 1], $svc->remind());
        $this->assertSame(['due' => 0, 'overdue' => 0], $svc->remind());

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson("/api/v1/admin/passing-policies/program/{$program->id}", ['mode' => 'all_required', 'criteria' => [['key' => 'knowledge_transfer', 'required' => true, 'min' => 100]]])->assertOk();
        $this->assertSame('pending', $r->fresh()->pass_status);
        $kt->update(['status' => 'pending_review', 'hours' => 2, 'beneficiary_count' => 5]);
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->postJson("/api/v1/admin/knowledge-transfers/{$kt->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame('passed', $r->fresh()->pass_status);
    }

    public function test_managers_only_see_their_own_staffs_pd_and_the_compliance_matrix_exports(): void
    {
        $managerA = $this->makeEmployee([], $this->makeUser(Role::SUPERVISOR));
        $managerB = $this->makeEmployee([], $this->makeUser(Role::SUPERVISOR));
        $e = $this->makeEmployee();
        $e->update(['supervisor_id' => $managerA->id]);
        $type = $this->type();
        PdActivity::create(['employee_id' => $e->id, 'type_id' => $type->id, 'title' => 'x', 'starts_on' => today(), 'duration_hours' => 4, 'computed_hours' => 4, 'status' => 'pending_manager', 'manager_id' => $managerA->user_id]);
        $this->asUser($managerA->user)->getJson('/api/v1/me/team/pd-activities')->assertJsonCount(1, 'data');
        $this->asUser($managerB->user)->getJson('/api/v1/me/team/pd-activities')->assertJsonCount(0, 'data');

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $path = $this->path([['conditions' => [['field' => 'experience_years', 'operator' => 'gte', 'value' => 99]]]]);
        app(CareerPathEngine::class)->evaluate($e->fresh());
        $this->asUser($admin)->getJson("/api/v1/admin/career-paths/{$path->id}/compliance")->assertOk()->assertJsonPath('data.totals.not_started', 1)->assertJsonPath('data.matrix.0.status', 'not_started');
        $this->asUser($admin)->get("/api/v1/admin/career-paths/{$path->id}/compliance?format=xlsx")->assertOk();
    }
}
