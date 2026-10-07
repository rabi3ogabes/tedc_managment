<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\Role;
use App\Models\WaitingList;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RegistrationWorkflowTest extends TestCase
{
    public function test_self_registration_is_pending_and_notifies(): void
    {
        $employee = $this->makeEmployee();
        $program = $this->makeProgram();

        $this->asUser($employee->user)->postJson("/api/v1/me/programs/{$program->id}/register")
            ->assertCreated()
            ->assertJsonPath('data.status', Registration::STATUS_PENDING)
            ->assertJsonPath('data.source', 'self');

        $this->assertDatabaseHas('notifications', ['user_id' => $employee->user_id, 'type' => 'registration.pending']);
        $this->asUser($employee->user)->postJson("/api/v1/me/programs/{$program->id}/register")->assertStatus(422)->assertJsonPath('code', 'duplicate');
    }

    public function test_ineligible_registration_returns_explanation(): void
    {
        $program = $this->makeProgram();
        $program->eligibilityRules()->create(['field' => 'experience_years', 'operator' => 'gte', 'value' => 10]);

        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/me/programs/{$program->id}/register")
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_eligible')
            ->assertJsonPath('details.color', 'red');
    }

    public function test_full_program_waitlists_and_promotes_on_cancellation(): void
    {
        $program = $this->makeProgram(['capacity' => 1]);
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();

        $this->asUser($first->user)->postJson("/api/v1/me/programs/{$program->id}/register")->assertCreated();
        $this->asUser($second->user)->postJson("/api/v1/me/programs/{$program->id}/register")
            ->assertCreated()->assertJsonPath('data.status', Registration::STATUS_WAITLISTED);
        $this->assertSame(1, WaitingList::count());

        $firstRegistration = Registration::where('employee_id', $first->id)->first();
        $this->asUser($first->user)->postJson("/api/v1/me/registrations/{$firstRegistration->id}/withdraw")->assertOk();

        $this->assertSame(Registration::STATUS_PENDING, Registration::where('employee_id', $second->id)->value('status'));
        $this->assertSame('promoted', WaitingList::first()->status);
    }

    public function test_closed_registration_window(): void
    {
        $program = $this->makeProgram(['registration_closes_at' => now()->subDay()]);

        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/me/programs/{$program->id}/register")
            ->assertStatus(422)->assertJsonPath('code', 'registration_closed');
    }

    public function test_center_nomination_is_auto_approved_and_can_override(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $program = $this->makeProgram();
        $program->eligibilityRules()->create(['field' => 'experience_years', 'operator' => 'gte', 'value' => 20]);
        $employee = $this->makeEmployee();

        $this->asUser($coordinator)->postJson("/api/v1/admin/programs/{$program->id}/nominations", ['employee_ids' => [$employee->id]])
            ->assertCreated()->assertJsonPath('data.0.ok', false);

        $this->asUser($coordinator)->postJson("/api/v1/admin/programs/{$program->id}/nominations", ['employee_ids' => [$employee->id], 'override' => true])
            ->assertCreated()->assertJsonPath('data.0.ok', true)->assertJsonPath('data.0.status', Registration::STATUS_APPROVED);

        $this->assertDatabaseHas('nominations', ['employee_id' => $employee->id, 'nominator_type' => 'training_center', 'status' => 'converted']);
    }

    public function test_school_admin_can_only_nominate_own_school(): void
    {
        $adminEmployee = $this->makeEmployee([], $this->makeUser(Role::SCHOOL_ADMIN));
        $colleague = $this->makeEmployee(['school_id' => $adminEmployee->school_id]);
        $outsider = $this->makeEmployee();
        $program = $this->makeProgram();

        $response = $this->asUser($adminEmployee->user)
            ->postJson("/api/v1/admin/programs/{$program->id}/nominations", ['employee_ids' => [$colleague->id, $outsider->id]])
            ->assertCreated();

        $results = collect($response->json('data'))->keyBy('employee_id');
        $this->assertTrue($results[$colleague->id]['ok']);
        $this->assertSame(Registration::STATUS_PENDING, $results[$colleague->id]['status']);
        $this->assertFalse($results[$outsider->id]['ok']);
    }

    public function test_bulk_excel_import(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $program = $this->makeProgram();
        $a = $this->makeEmployee(['employee_no' => 'E-1']);
        $this->makeEmployee(['employee_no' => 'E-2']);

        $csv = UploadedFile::fake()->createWithContent('import.csv', "employee_no,notes\nE-1,first\nE-2,\nE-404,missing\n");

        $this->asUser($coordinator)->post("/api/v1/admin/programs/{$program->id}/registrations/import", ['file' => $csv], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.failed', 1);

        $this->assertSame('first', Registration::where('employee_id', $a->id)->value('notes'));
        $this->assertSame(Registration::STATUS_APPROVED, Registration::where('employee_id', $a->id)->value('status'));
    }
}
