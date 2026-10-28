<?php

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\ProgramInstrument;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Services\EvaluationService;
use Tests\TestCase;

/** «استمارات التقييم» (docs/saber/07-programme-forms.md): each programme's trainee survey and trainer self-reflection as generated links. */
class SaberFormsTest extends TestCase
{
    private function trainee(Program $program): array
    {
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$employee->user, $registration];
    }

    public function test_every_programme_has_its_two_form_links_with_where_they_stand(): void
    {
        $program = $this->makeProgram(['code' => 'FRM-01', 'title_ar' => 'برنامج الاستمارات']);
        [, $answered] = $this->trainee($program);
        $this->trainee($program);
        Evaluation::create(['registration_id' => $answered->id, 'program_id' => $program->id, 'employee_id' => $answered->employee_id, 'ratings' => [5], 'satisfaction_score' => 100, 'submitted_at' => now()]);
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $forms = $this->asUser($coordinator)->getJson("/api/v1/admin/saber/programs/{$program->id}/forms")->assertOk()->json('data');

        $this->assertStringEndsWith('/f/t/FRM-01', $forms['trainee']['url']);
        $this->assertStringEndsWith('/f/r/FRM-01', $forms['reflection']['url']);
        $this->assertSame(['open', 2, 1], [$forms['trainee']['status'], $forms['trainee']['expected'], $forms['trainee']['answered']]);
        $this->assertSame('waiting', $forms['reflection']['status'], 'the self-reflection opens when a group ends');
        $this->assertNotEmpty($forms['reflection']['form']['title']);

        // An automatic survey that has not opened yet is scheduled; a switched-off one says so.
        $program->update(['survey_mode' => 'auto', 'survey_auto_hours' => 24]);
        $scheduled = $this->asUser($coordinator)->getJson("/api/v1/admin/saber/programs/{$program->id}/forms")->json('data.trainee');
        $this->assertSame('scheduled', $scheduled['status']);
        $this->assertNotNull($scheduled['opens_at']);
        ProgramInstrument::create(['program_id' => $program->id, 'kind' => 'trainer_reflection', 'enabled' => false]);
        $this->assertSame('off', $this->asUser($coordinator)->getJson("/api/v1/admin/saber/programs/{$program->id}/forms")->json('data.reflection.status'));

        $list = $this->asUser($coordinator)->getJson('/api/v1/admin/saber/forms?q=FRM-01')->assertOk();
        $this->assertSame([$program->id], array_column(array_column($list->json('data'), 'program'), 'id'));
        $this->assertSame([], $this->asUser($coordinator)->getJson('/api/v1/admin/saber/forms?q=FRM-01&when=ended')->json('data'));
        $this->asUser($coordinator)->get('/api/v1/admin/saber/forms/export?q=FRM-01')->assertOk()->assertDownload('saber-forms.xlsx');

        $this->asUser($coordinator)->getJson("/api/v1/admin/saber/programs/{$program->id}/details")->assertOk()->assertJsonPath('data.forms.trainee.url', $forms['trainee']['url']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/saber/forms')->assertForbidden();
    }

    public function test_the_trainee_link_takes_each_trainee_to_their_own_evaluation_or_says_why_not(): void
    {
        $program = $this->makeProgram(['code' => 'FRM-02']);
        [$user, $registration] = $this->trainee($program);

        $this->asUser($user)->getJson('/api/v1/me/form-links/trainee/FRM-02')->assertOk()
            ->assertJsonPath('data.state', 'open')->assertJsonPath('data.registration_id', $registration->id);

        $program->update(['survey_mode' => 'auto', 'survey_auto_hours' => 24]);
        $this->asUser($user)->getJson('/api/v1/me/form-links/trainee/FRM-02')->assertJsonPath('data.state', 'scheduled');
        $program->update(['survey_mode' => 'manual', 'survey_opened_at' => now()->subDay(), 'survey_closed_at' => now()]);
        $this->asUser($user)->getJson('/api/v1/me/form-links/trainee/FRM-02')->assertJsonPath('data.state', 'closed');
        $program->update(['survey_mode' => 'always', 'survey_opened_at' => null, 'survey_closed_at' => null]);

        Evaluation::create(['registration_id' => $registration->id, 'program_id' => $program->id, 'employee_id' => $registration->employee_id, 'ratings' => [5], 'satisfaction_score' => 100, 'submitted_at' => now()]);
        $this->asUser($user)->getJson('/api/v1/me/form-links/trainee/FRM-02')->assertJsonPath('data.state', 'submitted');

        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/me/form-links/trainee/FRM-02')->assertJsonPath('data.state', 'not_registered');
        $pending = $this->makeEmployee();
        Registration::create(['program_id' => $program->id, 'employee_id' => $pending->id, 'source' => 'self', 'status' => Registration::STATUS_PENDING]);
        $this->asUser($pending->user)->getJson('/api/v1/me/form-links/trainee/FRM-02')->assertJsonPath('data.state', 'pending');
        $this->asUser($user)->getJson('/api/v1/me/form-links/trainee/NOPE')->assertNotFound();
    }

    public function test_the_reflection_link_takes_each_trainer_to_their_own_self_reflection(): void
    {
        $program = $this->makeProgram(['code' => 'FRM-03']);
        $group = $program->groups()->first();
        $trainerUser = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $trainerUser->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'status' => 'active', 'source' => 'center']);
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'status' => 'approved']);

        $this->asUser($trainerUser)->getJson('/api/v1/me/form-links/reflection/FRM-03')->assertOk()->assertJsonPath('data.state', 'waiting');
        $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/me/form-links/reflection/FRM-03')->assertJsonPath('data.state', 'not_trainer');

        // The group ends: the reflection is assigned, and the link opens it.
        app(EvaluationService::class)->assignForGroup($group);
        $assignment = EvaluationAssignment::where('respondent_user_id', $trainerUser->id)->where('respondent_type', 'trainer')->firstOrFail();
        $this->asUser($trainerUser)->getJson('/api/v1/me/form-links/reflection/FRM-03')->assertJsonPath('data.state', 'open')->assertJsonPath('data.assignment_id', $assignment->id);
        $forms = $this->asUser($this->makeUser(Role::COORDINATOR))->getJson("/api/v1/admin/saber/programs/{$program->id}/forms")->json('data.reflection');
        $this->assertSame(['open', 1, 1, 0], [$forms['status'], $forms['trainers'], $forms['assigned'], $forms['answered']]);

        $assignment->update(['status' => 'submitted']);
        $this->asUser($trainerUser)->getJson('/api/v1/me/form-links/reflection/FRM-03')->assertJsonPath('data.state', 'submitted');
        $this->assertSame('done', $this->asUser($this->makeUser(Role::COORDINATOR))->getJson("/api/v1/admin/saber/programs/{$program->id}/forms")->json('data.reflection.status'));
    }

    public function test_a_programme_published_from_saber_gets_both_forms_from_the_start(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $row = $this->asUser($coordinator)->postJson('/api/v1/admin/saber/programs', ['title' => 'برنامج بالاستمارات', 'delivery_mode' => 'in_person', 'total_hours' => 5, 'groups' => 1])->assertCreated()->json('data');
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$row['id']}/draft/submit")->assertOk();
        $rev = $this->asUser($this->makeUser(Role::TRAINING_HEAD))->getJson('/api/v1/admin/saber/reviews')->json('data.0.id');
        $this->asUser($this->makeUser(Role::TRAINING_HEAD))->postJson("/api/v1/admin/saber/reviews/{$rev}/publish")->assertOk();

        $program = Program::find($row['id']);
        $this->assertEqualsCanonicalizing(['trainee_survey', 'trainer_reflection'], ProgramInstrument::where('program_id', $program->id)->pluck('kind')->all());
        $this->assertSame(['auto', 24], [$program->survey_mode, $program->survey_auto_hours], 'the trainee survey opens by itself a day after the programme ends');
    }
}
