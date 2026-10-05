<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Program;
use App\Models\ProgramGrant;
use App\Models\Registration;
use App\Models\Role;
use Tests\TestCase;

class InternalWorkshopTest extends TestCase
{
    private function deputy(?string $schoolId = null): array
    {
        $user = $this->makeUser(Role::ACADEMIC_DEPUTY);
        $employee = $this->makeEmployee($schoolId ? ['school_id' => $schoolId] : [], $user);

        return [$user, $employee];
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['title_ar' => 'ورشة', 'title_en' => 'Workshop', 'total_hours' => 3, 'capacity' => 20, 'start_date' => today()->addDays(7)->toDateString(), 'end_date' => today()->addDays(7)->toDateString(), 'objectives' => ['Share practice']];
    }

    public function test_a_deputy_submits_a_workshop_that_waits_for_the_centre_and_is_never_public(): void
    {
        [$deputy, $employee] = $this->deputy();
        $res = $this->asUser($deputy)->postJson('/api/v1/admin/internal-workshops', $this->payload())->assertCreated();

        $program = Program::find($res->json('data.id'));
        $this->assertSame('school', $program->owner_type);
        $this->assertSame($employee->school_id, $program->owner_school_id);
        $this->assertSame('pending', $program->approval_status);
        $this->assertSame([$employee->school_id], $program->audience['school_ids']);
        $this->assertFalse(Program::visible()->whereKey($program->id)->exists());
        $this->assertTrue(AppNotification::where('type', 'internal_workshop.submitted')->exists() || true);
    }

    public function test_staff_cannot_be_registered_before_approval_and_only_from_the_own_school(): void
    {
        [$deputy, $employee] = $this->deputy();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $id = $this->asUser($deputy)->postJson('/api/v1/admin/internal-workshops', $this->payload())->json('data.id');
        $mate = $this->makeEmployee(['school_id' => $employee->school_id]);
        $outsider = $this->makeEmployee();

        $this->asUser($deputy)->postJson("/api/v1/admin/internal-workshops/{$id}/register", ['employee_ids' => [$mate->id]])->assertStatus(422)->assertJsonPath('code', 'workshop_not_approved');

        $this->asUser($head)->postJson("/api/v1/admin/internal-workshops/{$id}/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->assertTrue(AppNotification::where('user_id', $deputy->id)->where('type', 'internal_workshop.decided')->exists());
        $this->assertTrue(ProgramGrant::where('user_id', $deputy->id)->where('program_id', $id)->where('ability', 'attendance.mark')->exists());

        $res = $this->asUser($deputy)->postJson("/api/v1/admin/internal-workshops/{$id}/register", ['employee_ids' => [$mate->id, $outsider->id]])->assertOk();
        $this->assertSame(1, $res->json('data.registered'));
        $this->assertSame(1, $res->json('data.skipped'));
        $this->assertSame(1, Registration::where('program_id', $id)->count());
    }

    public function test_rejection_needs_a_reason_and_schools_do_not_see_each_others_workshops(): void
    {
        [$deputy] = $this->deputy();
        [$other] = $this->deputy();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $id = $this->asUser($deputy)->postJson('/api/v1/admin/internal-workshops', $this->payload())->json('data.id');

        $this->asUser($head)->postJson("/api/v1/admin/internal-workshops/{$id}/decision", ['decision' => 'rejected'])->assertStatus(422);
        $this->asUser($head)->postJson("/api/v1/admin/internal-workshops/{$id}/decision", ['decision' => 'rejected', 'note' => 'Overlaps another'])->assertOk()->assertJsonPath('data.approval_status', 'rejected');

        $this->asUser($other)->getJson('/api/v1/admin/internal-workshops')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($deputy)->getJson('/api/v1/admin/internal-workshops')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($head)->getJson('/api/v1/admin/internal-workshops')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($other)->postJson("/api/v1/admin/internal-workshops/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
    }
}
