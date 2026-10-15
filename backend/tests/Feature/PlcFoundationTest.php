<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\PlcDecision;
use App\Models\PlcWorkshop;
use App\Models\Program;
use App\Models\Role;
use App\Services\FeatureSettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 1: scope isolation, the needs register, the workshop workflow with a two-step path, source freezing, the legacy bridge, dashboard counts. */
class PlcFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('plc', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function deputy(?string $schoolId = null): array
    {
        $user = $this->makeUser(Role::ACADEMIC_DEPUTY);
        $employee = $this->makeEmployee($schoolId ? ['school_id' => $schoolId] : [], $user);

        return [$user, $employee];
    }

    public function test_school_users_submit_internal_workshops_inside_their_school_and_never_see_other_schools(): void
    {
        [$deputyA, $empA] = $this->deputy();
        [$deputyB] = $this->deputy();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'x'])->assertForbidden();

        // The internal preset: host = own school, scenario internal, source school, even when the request says "shared".
        $w = $this->asUser($deputyA)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة داخلية', 'scenario' => 'shared', 'source' => 'centre', 'start_date' => today()->addDays(10)->toDateString()])
            ->assertCreated()->json('data');
        $this->assertSame('internal', $w['scenario']);
        $this->assertSame('school', $w['source']);
        $this->assertSame($empA->school_id, $w['host_school']['id']);
        $this->assertSame('draft', $w['state']);
        $this->assertSame('school', Program::find($w['id'])->owner_type);

        // Another school sees nothing, cannot open or edit it; the centre sees it.
        $this->asUser($deputyB)->getJson('/api/v1/admin/plc/workshops')->assertOk()->assertJsonPath('meta.total', 0);
        $this->asUser($deputyB)->getJson('/api/v1/admin/plc/workshops/'.$w['id'])->assertForbidden();
        $this->asUser($deputyB)->putJson('/api/v1/admin/plc/workshops/'.$w['id'], ['title_ar' => 'hack'])->assertForbidden();
        $this->asUser($this->makeUser(Role::COORDINATOR))->getJson('/api/v1/admin/plc/workshops')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asUser($deputyA)->getJson('/api/v1/admin/plc/dashboard')->assertOk()->assertJsonPath('data.workshops.total', 1)->assertJsonPath('data.rased_integration', false);
    }

    public function test_the_two_step_path_changes_requests_resubmission_and_source_freezing(): void
    {
        [$deputy, $emp] = $this->deputy();
        $principal = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $emp->school_id], $principal);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $id = $this->asUser($deputy)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة القراءة', 'title_en' => 'Reading workshop'])->assertCreated()->json('data.id');
        // Submission needs a start date.
        $this->asUser($deputy)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertStatus(422)->assertJsonPath('code', 'plc_incomplete');
        $this->asUser($deputy)->putJson("/api/v1/admin/plc/workshops/$id", ['start_date' => today()->addDays(14)->toDateString(), 'end_date' => today()->addDays(14)->toDateString()])->assertOk();
        $this->asUser($deputy)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk()->assertJsonPath('data.state', 'submitted')->assertJsonPath('data.pending_step.key', 'school');
        $this->assertTrue(AppNotification::where('user_id', $principal->id)->where('type', 'plc.workshop_submitted')->exists());

        // The head cannot take the school step; the principal can; then the centre step is the head's.
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve")->assertStatus(422)->assertJsonPath('code', 'plc_not_your_step');
        $this->asUser($principal)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk()->assertJsonPath('data.state', 'submitted')->assertJsonPath('data.pending_step.key', 'centre');
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/request-changes", ['reason' => ''])->assertStatus(422);
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/request-changes", ['reason' => 'أضف الأهداف'])->assertOk()->assertJsonPath('data.state', 'needs_changes');
        $this->assertTrue(AppNotification::where('user_id', $deputy->id)->where('type', 'plc.workshop_changes')->exists());

        // Resubmission starts the path again from the school step.
        $this->asUser($deputy)->putJson("/api/v1/admin/plc/workshops/$id", ['objectives' => ['تحسين القراءة']])->assertOk();
        $this->asUser($deputy)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk()->assertJsonPath('data.pending_step.key', 'school');
        $this->asUser($principal)->postJson("/api/v1/admin/plc/workshops/$id/approve")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve")->assertOk()->assertJsonPath('data.state', 'approved');
        $program = Program::find($id);
        $this->assertSame('approved', $program->approval_status);
        $this->assertSame(Program::STATUS_REGISTRATION_OPEN, $program->status);

        // The source is frozen after approval unless an exception with a reason is recorded by someone allowed to.
        $this->asUser($deputy)->putJson("/api/v1/admin/plc/workshops/$id", ['source' => 'centre'])->assertStatus(422)->assertJsonPath('code', 'plc_locked');
        $this->asUser($head)->putJson("/api/v1/admin/plc/workshops/$id", ['source' => 'centre'])->assertStatus(422)->assertJsonPath('code', 'plc_source_frozen');
        $this->asUser($head)->putJson("/api/v1/admin/plc/workshops/$id", ['source' => 'centre', 'exception_reason' => 'خطأ في الإدخال'])->assertOk()->assertJsonPath('data.source', 'centre');

        // Cancelling needs a reason, keeps the record and is logged; nothing can follow.
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/cancel", ['reason' => 'تعذر الموعد'])->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertStatus(422)->assertJsonPath('code', 'plc_bad_transition');
        $this->assertSame(Program::STATUS_CANCELLED, Program::find($id)->status);
        $kinds = PlcDecision::where('subject_id', $id)->orderBy('created_at')->pluck('decision')->all();
        $this->assertSame(['internal', 'submitted', 'school', 'needs_changes', 'submitted', 'school', 'centre', 'approved', 'source_changed', 'cancelled'], $kinds);
        $this->assertTrue(AuditLog::where('action', 'plc.exception.source_changed')->where('user_id', $head->id)->exists());
    }

    public function test_needs_are_registered_by_hand_with_a_reference_and_convert_into_workshops(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $school = $this->makeSchool();
        $this->asUser($officer)->postJson('/api/v1/admin/plc/needs', ['source' => 'other', 'title' => 'x'])->assertStatus(422);
        $need = $this->asUser($officer)->postJson('/api/v1/admin/plc/needs', [
            'source' => 'rased', 'reference_no' => 'RSD-2026-0417', 'title' => 'ضعف في استراتيجيات التقويم', 'domain' => 'assessment', 'school_ids' => [$school->id], 'received_at' => today()->toDateString(),
        ])->assertCreated()->json('data');
        $this->assertSame('new', $need['review_status']);
        $this->asUser($officer)->getJson('/api/v1/admin/plc/workshops/catalog')->assertOk()->assertJsonPath('data.rased_integration', false)->assertJsonPath('data.can.manage', true);

        $w = $this->asUser($officer)->postJson("/api/v1/admin/plc/needs/{$need['id']}/convert", ['host_school_id' => $school->id, 'start_date' => today()->addDays(20)->toDateString()])->assertCreated()->json('data');
        $this->assertSame('rased', $w['source']);
        $this->assertSame('RSD-2026-0417', $w['source_ref']);
        $this->assertSame('shared', $w['scenario']);
        $this->assertSame($need['id'], $w['need']['id']);
        $this->assertSame('converted', $this->asUser($officer)->getJson("/api/v1/admin/plc/needs/{$need['id']}")->json('data.review_status'));
        // A second workshop from the same need is allowed; the source of a converted need is frozen.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/needs/{$need['id']}/convert", ['title_ar' => 'ورشة ثانية'])->assertCreated();
        $this->asUser($officer)->getJson("/api/v1/admin/plc/needs/{$need['id']}")->assertJsonPath('data.workshops', 2);
        $this->asUser($officer)->putJson("/api/v1/admin/plc/needs/{$need['id']}", ['source' => 'centre'])->assertStatus(422)->assertJsonPath('code', 'plc_source_frozen');
        $dash = $this->asUser($officer)->getJson('/api/v1/admin/plc/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $dash['workshops']['by_source']['rased']);
        $this->assertSame(1, $dash['needs']['by_status']['converted']);

        // The shared workshop needs the centre step only.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/{$w['id']}/submit")->assertOk()->assertJsonPath('data.pending_step.key', 'centre');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/{$w['id']}/approve")->assertForbidden(); // no approval permission at all
        $principal = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $school->id], $principal);
        $this->asUser($principal)->postJson("/api/v1/admin/plc/workshops/{$w['id']}/approve")->assertStatus(422)->assertJsonPath('code', 'plc_not_your_step');
        $this->asUser($this->makeUser(Role::TRAINING_HEAD))->postJson("/api/v1/admin/plc/workshops/{$w['id']}/reject", ['reason' => 'مكرر'])->assertOk()->assertJsonPath('data.state', 'rejected');
    }

    public function test_the_legacy_internal_workshop_route_lands_in_the_engine_and_communities_are_scoped(): void
    {
        [$deputy, $emp] = $this->deputy();
        $pid = $this->asUser($deputy)->postJson('/api/v1/admin/internal-workshops', ['title_ar' => 'ورشة قديمة', 'title_en' => 'Legacy', 'total_hours' => 3, 'capacity' => 20,
            'start_date' => today()->addDays(7)->toDateString(), 'end_date' => today()->addDays(7)->toDateString()])->assertCreated()->json('data.id');
        $w = PlcWorkshop::find($pid);
        $this->assertNotNull($w);
        $this->assertSame('internal', $w->scenario);
        $this->assertSame('submitted', $w->workflow_state);
        $this->assertSame($emp->school_id, $w->host_school_id);

        $officer = $this->makeUser(Role::COORDINATOR);
        $other = $this->makeSchool();
        $c = $this->asUser($officer)->postJson('/api/v1/admin/plc/communities', ['title_ar' => 'مجتمع الرياضيات', 'domain' => 'math', 'host_school_id' => $emp->school_id, 'school_ids' => [$other->id]])->assertCreated()->json('data');
        $this->assertSame(2, $c['schools']);
        $this->assertSame('host', collect($c['school_list'])->firstWhere('id', $emp->school_id)['role']);
        $this->asUser($deputy)->getJson('/api/v1/admin/plc/communities')->assertOk()->assertJsonCount(1, 'data');
        [$outsider] = $this->deputy();
        $this->asUser($outsider)->getJson('/api/v1/admin/plc/communities')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($outsider)->getJson("/api/v1/admin/plc/communities/{$c['id']}")->assertForbidden();
        $this->asUser($deputy)->postJson('/api/v1/admin/plc/communities', ['title_ar' => 'x'])->assertForbidden();
        $this->asUser($officer)->deleteJson("/api/v1/admin/plc/communities/{$c['id']}")->assertOk()->assertJsonPath('data.status', 'archived');
    }
}
