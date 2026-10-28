<?php

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\GroupTrainer;
use App\Models\ProgramInstrument;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Services\EvaluationService;
use App\Services\TraineeSurvey;
use App\Support\EvaluationTemplates;
use Tests\TestCase;

/** The centre's two forms in Shaheen (docs/saber/08-centre-forms.md): the trainee form and the trainer self-evaluation. */
class CentreFormsTest extends TestCase
{
    /** Every statement answered, the strongest agreement on the first row of each grid. */
    private function traineeAnswers(int $value = 5): array
    {
        $answers = [];
        foreach (EvaluationTemplates::trainee()['questions'] as $q) {
            if ($q['type'] === 'matrix') {
                $answers[$q['id']] = collect($q['rows'])->mapWithKeys(fn ($r) => [$r['id'] => $value])->all();
            }
        }

        return $answers + ['overall' => 'excellent', 'opinion' => 'ورشة مفيدة جداً'];
    }

    public function test_the_trainee_form_is_the_centre_form_and_is_stored_one_rating_per_statement(): void
    {
        $program = $this->makeProgram(['code' => 'CF-01', 'title_ar' => 'برنامج الاستمارة']);
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED, 'training_group_id' => $program->groups()->first()->id]);

        $form = $this->asUser($employee->user)->getJson("/api/v1/me/registrations/{$registration->id}/evaluation")->assertOk()->json('data');
        $this->assertStringContainsString('أخي المتدرب', $form['intro']);
        $this->assertSame(['trainer', 'content', 'environment', 'impact', 'overall', 'opinion'], array_column($form['questions'], 'id'));
        $this->assertCount(6, $form['questions'][0]['rows']);
        $this->assertSame(['غير موافق بشدة', 'غير موافق', 'محايد', 'أوافق', 'أوافق بشدة'], $form['questions'][0]['scale']['labels']);
        $this->assertSame('desc', $form['questions'][0]['scale']['order']);
        $this->assertSame(['برنامج الاستمارة', true, false], [$form['context']['program'], $form['open'], $form['submitted']]);

        // Every statement must be answered, and the comment is required, as on the original form.
        $partial = $this->traineeAnswers();
        unset($partial['trainer']['punctual'], $partial['opinion']);
        $this->asUser($employee->user)->postJson("/api/v1/me/registrations/{$registration->id}/evaluation", ['answers' => $partial])->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.trainer', 'answers.opinion']);

        $answers = $this->traineeAnswers(4);
        $answers['impact'] = ['knowledge' => 5, 'apply' => 5, 'quality' => 5];
        $this->asUser($employee->user)->postJson("/api/v1/me/registrations/{$registration->id}/evaluation", ['answers' => $answers, 'allow_testimonial' => true])->assertCreated();

        $evaluation = Evaluation::where('registration_id', $registration->id)->firstOrFail();
        $this->assertSame(4, $evaluation->ratings['trainer.clarity']);
        $this->assertSame(5, $evaluation->ratings['impact.apply']);
        $this->assertSame(5, $evaluation->ratings['overall'], 'ممتاز is the top of the scale');
        $this->assertSame('ورشة مفيدة جداً', $evaluation->comments);
        $this->assertEqualsWithDelta((15 * 4 + 3 * 5) / 18 / 5 * 100, $evaluation->satisfaction_score, 0.01);
        $this->assertTrue($registration->fresh()->evaluation_completed);
        $this->assertSame('وضوح العرض والشرح', app(TraineeSurvey::class)->labels()['trainer.clarity'], 'reports show the statement, not the key');
    }

    public function test_older_app_versions_still_send_the_four_ratings(): void
    {
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);

        $this->asUser($employee->user)->postJson("/api/v1/me/registrations/{$registration->id}/evaluation", ['ratings' => ['content' => 5, 'trainer' => 4, 'organization' => 4, 'relevance' => 5], 'comments' => 'جيد'])->assertCreated();
        $this->assertSame(90.0, Evaluation::where('registration_id', $registration->id)->value('satisfaction_score'));
    }

    public function test_the_trainer_self_evaluation_is_the_centre_form_with_the_general_information_filled_in(): void
    {
        $program = $this->makeProgram(['title_ar' => 'برنامج التأمل']);
        $group = $program->groups()->first();
        $user = $this->makeUser(Role::TRAINER, ['name_ar' => 'مدرب الاختبار']);
        $trainer = Trainer::create(['user_id' => $user->id, 'name_ar' => 'مدرب الاختبار', 'name_en' => 'Test trainer', 'title_ar' => 'مدرب معتمد', 'status' => 'active', 'source' => 'center']);
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'status' => 'approved']);

        app(EvaluationService::class)->assignForGroup($group);
        $assignment = EvaluationAssignment::where('respondent_user_id', $user->id)->where('respondent_type', 'trainer')->firstOrFail();
        $this->assertSame(EvaluationTemplates::REFLECTION, $assignment->form->settings['template']);

        $f = $this->asUser($user)->getJson("/api/v1/me/evaluations/{$assignment->id}")->assertOk()->json('data');
        $this->assertSame('التقييم الذاتي للمدرب', $f['title_ar']);
        $this->assertSame(['centre', 'delivery', 'overall'], array_column($f['questions'], 'id'));
        $this->assertSame([8, 9], [count($f['questions'][0]['rows']), count($f['questions'][1]['rows'])]);
        $this->assertSame(['لا أوافق أبداً', 'لا أوافق', 'أوافق', 'أوافق تماماً'], $f['questions'][0]['scale']['labels']);
        $this->assertSame(['ممتاز', 'جيد', 'مرضي', 'غير مرضي'], array_column($f['questions'][2]['options'], 'label'));
        $this->assertSame(['مدرب الاختبار', 'مدرب معتمد', 'برنامج التأمل'], [$f['context']['name'], $f['context']['job'], $f['context']['program']]);
        $this->assertNotNull($f['context']['from']);

        $answers = ['centre' => collect($f['questions'][0]['rows'])->mapWithKeys(fn ($r) => [$r['id'] => 4])->all(), 'delivery' => collect($f['questions'][1]['rows'])->mapWithKeys(fn ($r) => [$r['id'] => 3])->all(), 'overall' => 'good'];
        $this->asUser($user)->postJson("/api/v1/me/evaluations/{$assignment->id}", ['answers' => $answers])->assertCreated();
        $this->assertSame('submitted', $assignment->fresh()->status);
        $this->assertEqualsWithDelta((100 + 200 / 3) / 2, (float) $assignment->fresh()->score, 0.05, 'the grids count towards the score');
    }

    public function test_the_live_data_moves_to_the_centre_forms_and_keeps_every_earlier_form(): void
    {
        app(EvaluationService::class)->ensureDefaults();
        // As on a database set up before: an older default self-reflection used by a programme, and a satisfaction form without questions.
        EvaluationForm::where('kind', 'trainer_reflection')->delete();
        $old = EvaluationForm::create(['kind' => 'trainer_reflection', 'title_ar' => 'تأمل المدرب الذاتي', 'title_en' => 'Trainer self-reflection', 'questions' => [['id' => 'engagement', 'type' => 'rating', 'title' => 'التفاعل', 'required' => true, 'scale' => ['min' => 1, 'max' => 5]]], 'approval_status' => 'approved', 'is_default' => true, 'settings' => []]);
        $chosen = EvaluationForm::create(['kind' => 'trainer_reflection', 'title_ar' => 'نموذج خاص', 'title_en' => 'Own form', 'questions' => [['id' => 'x', 'type' => 'long_text', 'title' => 'س', 'required' => false]], 'approval_status' => 'approved', 'is_default' => false, 'settings' => []]);
        EvaluationForm::where('kind', 'satisfaction')->update(['questions' => '[]', 'settings' => '{"evidence":false}']);
        $usesOld = ProgramInstrument::create(['program_id' => $this->makeProgram()->id, 'kind' => 'trainer_reflection', 'enabled' => true, 'form_id' => $old->id]);
        $usesOwn = ProgramInstrument::create(['program_id' => $this->makeProgram()->id, 'kind' => 'trainer_reflection', 'enabled' => true, 'form_id' => $chosen->id]);

        $migration = require database_path('migrations/2026_01_01_009200_centre_evaluation_forms.php');
        $migration->up();
        $migration->up();   // safe to run twice

        $centre = EvaluationForm::where('kind', 'trainer_reflection')->where('is_default', true)->sole();
        $this->assertSame(EvaluationTemplates::REFLECTION, $centre->settings['template']);
        $this->assertSame(1, EvaluationForm::where('kind', 'trainer_reflection')->get()->filter(fn ($f) => ($f->settings['template'] ?? null) === EvaluationTemplates::REFLECTION)->count());
        $this->assertNotNull($old->fresh(), 'the earlier form and its answers stay');
        $this->assertSame($centre->id, $usesOld->fresh()->form_id);
        $this->assertSame($chosen->id, $usesOwn->fresh()->form_id, 'a programme that chose its own form keeps it');
        $satisfaction = EvaluationForm::where('kind', 'satisfaction')->first();
        $this->assertSame('trainer', $satisfaction->questions[0]['id']);
        $this->assertStringContainsString('أخي المتدرب', $satisfaction->settings['intro_ar']);
    }

    public function test_a_database_that_missed_the_migration_switches_the_first_time_the_forms_are_needed(): void
    {
        app(EvaluationService::class)->ensureDefaults();
        EvaluationForm::where('kind', 'trainer_reflection')->delete();
        EvaluationForm::create(['kind' => 'trainer_reflection', 'title_ar' => 'تأمل المدرب الذاتي', 'title_en' => 'Trainer self-reflection', 'questions' => [['id' => 'engagement', 'type' => 'rating', 'title' => 'التفاعل', 'required' => true, 'scale' => ['min' => 1, 'max' => 5]]], 'approval_status' => 'approved', 'is_default' => true, 'settings' => []]);
        EvaluationForm::where('kind', 'satisfaction')->update(['questions' => '[]', 'settings' => '{"evidence":false}']);

        // A group ends: the self-reflection it hands out is already the centre's form.
        $program = $this->makeProgram();
        $user = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $user->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'status' => 'active', 'source' => 'center']);
        GroupTrainer::create(['group_id' => $program->groups()->first()->id, 'trainer_id' => $trainer->id, 'status' => 'approved']);
        app(EvaluationService::class)->assignForGroup($program->groups()->first());

        $assignment = EvaluationAssignment::where('respondent_user_id', $user->id)->firstOrFail();
        $this->assertSame(EvaluationTemplates::REFLECTION, $assignment->form->settings['template']);
        $this->assertSame(EvaluationTemplates::TRAINEE, EvaluationForm::where('kind', 'satisfaction')->first()->settings['template']);
    }
}
