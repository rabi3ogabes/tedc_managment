<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\GroupTrainer;
use App\Models\ImpactSurvey;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SatisfactionAlert;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Services\ComparativeAnalysis;
use App\Services\EvaluationService;
use App\Services\EvaluationSettings;
use App\Services\ImpactService;
use App\Services\ProgramEvaluationService;
use App\Services\SatisfactionAlertService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class EvaluationSuiteTest extends TestCase
{
    private function reg($program, array $extra = []): Registration
    {
        $employee = $this->makeEmployee();

        return Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED] + $extra);
    }

    private function group($program): TrainingGroup
    {
        return TrainingGroup::where('program_id', $program->id)->first();
    }

    public function test_end_of_group_forms_go_to_the_trainer_the_supervisor_and_the_planning_specialists_once(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $trainerUser = $this->makeUser(Role::TRAINER);
        $program = $this->makeProgram(['coordinator_id' => $coordinator->id]);
        $group = $this->group($program);
        $trainer = Trainer::create(['user_id' => $trainerUser->id, 'name_en' => 'T', 'name_ar' => 'م', 'status' => 'active']);
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'status' => 'approved']);

        $svc = app(EvaluationService::class);
        $this->assertSame(3, $svc->assignForGroup($group));
        $this->assertSame(0, $svc->assignForGroup($group), 'running it again must not duplicate');
        $kinds = EvaluationAssignment::with('form')->get()->mapWithKeys(fn ($a) => [$a->form->kind => $a->respondent_user_id]);
        $this->assertSame($trainerUser->id, $kinds['trainer_reflection']);
        $this->assertSame($coordinator->id, $kinds['supervisor_feedback']);
        $this->assertSame($specialist->id, $kinds['specialist_feedback']);
    }

    public function test_the_manager_impact_form_goes_out_after_the_configured_number_of_days(): void
    {
        $program = $this->makeProgram();
        $manager = $this->makeEmployee([], $this->makeUser(Role::SUPERVISOR));
        $r = $this->reg($program);
        $r->employee->update(['supervisor_id' => $manager->id]);
        $r->update(['status' => Registration::STATUS_COMPLETED, 'completed_at' => now()->subDays(61)]);
        $svc = app(EvaluationService::class);

        app(EvaluationSettings::class)->update(['impact' => ['manager_days' => 90]]);
        $this->assertSame(0, $svc->dispatchManagerImpact(), 'not yet at 90 days');
        app(EvaluationSettings::class)->update(['impact' => ['manager_days' => 60]]);
        $this->assertSame(1, $svc->dispatchManagerImpact());
        $this->assertSame(0, $svc->dispatchManagerImpact());
        $a = EvaluationAssignment::first();
        $this->assertSame($manager->user_id, $a->respondent_user_id);
        $this->assertSame('manager', $a->respondent_type);

        // The manager answers through the existing impact endpoint: the assignment closes.
        $this->asUser($manager->user)->postJson("/api/v1/me/team/registrations/{$r->id}/evaluation", ['application_score' => 70])->assertCreated();
        $this->assertSame('submitted', $a->fresh()->status);
    }

    public function test_the_trainee_impact_form_is_scheduled_at_forty_five_days_with_an_optional_ninety(): void
    {
        $program = $this->makeProgram();
        $r = $this->reg($program);
        $r->update(['status' => Registration::STATUS_COMPLETED, 'completed_at' => now()]);
        app(ImpactService::class)->scheduleFollowUps($r->fresh());
        $this->assertSame([45], ImpactSurvey::where('registration_id', $r->id)->pluck('stage_days')->all());
        $this->assertSame(now()->addDays(45)->toDateString(), ImpactSurvey::first()->scheduled_for->toDateString());
    }

    public function test_reminders_go_out_once_and_late_forms_expire(): void
    {
        $user = $this->makeUser(Role::TRAINER);
        $program = $this->makeProgram();
        $form = app(EvaluationService::class)->defaultFor('trainer_reflection');
        $a = app(EvaluationService::class)->assign($form, $program, $this->group($program), $user, 'trainer');
        $a->update(['sent_at' => now()->subDays(8)]);

        $this->assertSame(['reminded' => 1, 'expired' => 0], app(EvaluationService::class)->remindAndExpire());
        $this->assertSame(['reminded' => 0, 'expired' => 0], app(EvaluationService::class)->remindAndExpire());
        $a->update(['due_at' => now()->subHour()]);
        $this->assertSame(1, app(EvaluationService::class)->remindAndExpire()['expired']);
        $this->asUser($user)->postJson("/api/v1/me/evaluations/{$a->id}", ['answers' => ['engagement' => 4]])->assertStatus(422)->assertJsonPath('code', 'evaluation_expired');
    }

    public function test_answers_carry_evidence_files_and_links_with_validation(): void
    {
        Storage::fake('local');
        $user = $this->makeUser(Role::TRAINER);
        $program = $this->makeProgram();
        $a = app(EvaluationService::class)->assign(app(EvaluationService::class)->defaultFor('trainer_reflection'), $program, $this->group($program), $user, 'trainer');
        $answers = ['engagement' => 4, 'objectives' => 5, 'content_time' => 4, 'own_delivery' => 5];

        $this->asUser($user)->post("/api/v1/me/evaluations/{$a->id}", ['answers' => $answers, 'evidence' => ['difficulty_details' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]]], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('evidence.difficulty_details');
        $this->asUser($user)->postJson("/api/v1/me/evaluations/{$a->id}", ['answers' => $answers, 'evidence' => ['difficulty_details' => ['ftp://x', 'https://a.test/1']]])->assertStatus(422);
        $this->asUser($user)->postJson("/api/v1/me/evaluations/{$a->id}", ['answers' => $answers, 'evidence' => ['difficulty_details' => ['https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://a.test/4']]])->assertStatus(422);
        $this->assertSame('pending', $a->fresh()->status);

        $this->asUser($user)->post("/api/v1/me/evaluations/{$a->id}", ['answers' => $answers, 'evidence' => ['difficulty_details' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'), 'https://a.test/photo']]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('submitted', $a->fresh()->status);
        $stored = $a->fresh()->response->evidence['difficulty_details'];
        $this->assertEqualsCanonicalizing(['file', 'link'], array_column($stored, 'type'));
        $this->assertGreaterThan(0, (float) $a->fresh()->response->score);
        $this->asUser($user)->postJson("/api/v1/me/evaluations/{$a->id}", ['answers' => $answers])->assertStatus(422)->assertJsonPath('code', 'evaluation_submitted');
    }

    public function test_satisfaction_results_are_hidden_until_enough_people_answered(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();
        foreach ([80, 90] as $score) {
            $r = $this->reg($program);
            Evaluation::create(['registration_id' => $r->id, 'program_id' => $program->id, 'employee_id' => $r->employee_id, 'ratings' => ['content' => 4], 'satisfaction_score' => $score, 'submitted_at' => now()]);
        }
        $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/evaluations/satisfaction/results")->assertOk()->assertJsonPath('data.hidden_until', 3)->assertJsonPath('data.questions', []);

        $r = $this->reg($program);
        Evaluation::create(['registration_id' => $r->id, 'program_id' => $program->id, 'employee_id' => $r->employee_id, 'ratings' => ['content' => 5], 'satisfaction_score' => 70, 'submitted_at' => now()]);
        $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/evaluations/satisfaction/results")->assertOk()->assertJsonPath('data.responses', 3)->assertJsonPath('data.average_score', 80);
        $this->assertDatabaseCount('evaluation_responses', 0);
    }

    public function test_comparative_analysis_numbers_on_seeded_attempts(): void
    {
        $program = $this->makeProgram();
        $pre = Assessment::create(['program_id' => $program->id, 'kind' => 'pre_test', 'title_ar' => 'ق', 'title_en' => 'Pre', 'status' => 'published']);
        $post = Assessment::create(['program_id' => $program->id, 'kind' => 'post_test', 'title_ar' => 'ب', 'title_en' => 'Post', 'status' => 'published']);
        foreach ([[50, 75], [40, 60], [60, 72], [50, 50]] as [$a, $b]) {
            $r = $this->reg($program);
            foreach ([[$pre, $a], [$post, $b]] as [$test, $score]) {
                AssessmentAttempt::create(['assessment_id' => $test->id, 'registration_id' => $r->id, 'started_at' => now(), 'questions' => [], 'status' => 'graded', 'score_percent' => $score, 'passed' => true]);
            }
        }
        $r = app(ComparativeAnalysis::class)->forProgram($program->id);
        $this->assertSame(4, $r['participants_with_both']);
        $this->assertEquals(50.0, $r['pre_average']);
        $this->assertEquals(64.25, $r['post_average']);
        $this->assertEquals(30.0, $r['average_gain_percent']);                 // gains 50, 50, 20, 0 %
        $this->assertFalse($r['target_met']);
        $this->assertSame(['below_zero' => 0, 'zero_to_10' => 1, 'ten_to_25' => 1, 'twenty_five_to_50' => 0, 'above_50' => 2], $r['distribution']);
        $this->assertTrue($r['significance']['enough']);
        $this->assertEquals(14.25, $r['significance']['mean_difference']);
        $this->assertSame('insufficient', app(ComparativeAnalysis::class)->significance([5.0, 6.0])['level']);
    }

    public function test_classification_follows_the_thresholds_and_they_can_be_edited(): void
    {
        $svc = app(ProgramEvaluationService::class);
        $m = fn ($sat, $gain, $impact) => ['satisfaction' => ['average' => $sat], 'knowledge_gain' => ['average_percent' => $gain], 'impact' => ['score' => $impact]];

        $this->assertSame('successful_continue', $svc->classify($m(85, 40, 75))['classification']);
        $this->assertSame('needs_review', $svc->classify($m(85, 30, 75))['classification']);            // one metric below its good mark
        $this->assertSame('weak_stop', $svc->classify($m(50, 10, 75))['classification']);               // two under their lower bounds
        $this->assertSame('needs_review', $svc->classify($m(null, null, null))['classification']);     // nothing to judge yet
        $this->assertSame('needs_review', $svc->classify($m(90, null, null))['classification']);        // one metric is not enough for "successful"
        $this->assertSame('successful_continue', $svc->classify($m(85, 40, null))['classification']);

        app(EvaluationSettings::class)->update(['classification' => ['gain_good' => 25]]);
        $this->assertSame('successful_continue', $svc->classify($m(85, 30, 75))['classification']);
    }

    public function test_report_approval_needs_review_first_and_the_right_permission(): void
    {
        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $program = $this->makeProgram();

        $id = $this->asUser($specialist)->postJson("/api/v1/admin/programs/{$program->id}/evaluation-reports", [])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->asUser($head)->postJson("/api/v1/admin/evaluation-reports/{$id}/approve")->assertStatus(422)->assertJsonPath('code', 'report_not_reviewed');
        $this->asUser($specialist)->putJson("/api/v1/admin/evaluation-reports/{$id}", ['status' => 'reviewed', 'recommendations_ar' => 'توصية'])->assertOk();
        $this->asUser($specialist)->postJson("/api/v1/admin/evaluation-reports/{$id}/approve")->assertForbidden();
        $this->asUser($head)->postJson("/api/v1/admin/evaluation-reports/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->asUser($specialist)->putJson("/api/v1/admin/evaluation-reports/{$id}", ['recommendations_ar' => 'x'])->assertStatus(422)->assertJsonPath('code', 'report_locked');
    }

    public function test_the_low_satisfaction_alert_follows_the_rule_and_fires_once_per_group(): void
    {
        $leader = $this->makeUser(Role::CENTER_LEADERSHIP);
        $program = $this->makeProgram();
        $group = $this->group($program);
        $regs = collect(range(1, 5))->map(fn () => $this->reg($program, ['training_group_id' => $group->id]));
        $answer = fn ($r, $score) => Evaluation::create(['registration_id' => $r->id, 'program_id' => $program->id, 'employee_id' => $r->employee_id, 'ratings' => ['content' => 1], 'satisfaction_score' => $score, 'submitted_at' => now()]);
        $svc = app(SatisfactionAlertService::class);

        foreach ($regs->take(3) as $r) {
            $answer($r, 40);
        }
        $this->assertNull($svc->evaluate($program, $group), '3 of 5 is 60 %: below the 80 % response rate');
        $answer($regs[3], 40);
        $this->assertNotNull($svc->evaluate($program, $group), '4 of 5 = 80 % and the average 40 % is under 50 %');
        $this->assertNull($svc->evaluate($program, $group), 'once per group');
        $this->assertSame(1, SatisfactionAlert::count());
        $this->assertSame(1, AppNotification::where('user_id', $leader->id)->where('type', 'satisfaction.low_alert')->count());
    }

    public function test_the_alert_does_not_fire_for_a_good_average_and_uses_edited_thresholds(): void
    {
        $program = $this->makeProgram();
        $group = $this->group($program);
        foreach (range(1, 4) as $i) {
            $r = $this->reg($program, ['training_group_id' => $group->id]);
            Evaluation::create(['registration_id' => $r->id, 'program_id' => $program->id, 'employee_id' => $r->employee_id, 'ratings' => ['content' => 3], 'satisfaction_score' => 60, 'submitted_at' => now()]);
        }
        $svc = app(SatisfactionAlertService::class);
        $this->assertNull($svc->evaluate($program, $group), '60 % is not under 50 %');
        app(EvaluationSettings::class)->update(['alerts' => ['threshold' => 70]]);
        $this->assertNotNull($svc->evaluate($program, $group));
    }

    public function test_exports_open_as_excel_pdf_and_word_and_historical_evaluations_are_in_the_report(): void
    {
        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $program = $this->makeProgram(['title_ar' => 'برنامج التقييم']);
        foreach ([80, 85, 90] as $score) {
            $r = $this->reg($program);
            Evaluation::create(['registration_id' => $r->id, 'program_id' => $program->id, 'employee_id' => $r->employee_id, 'ratings' => ['content' => 4], 'satisfaction_score' => $score, 'submitted_at' => now()]);
        }
        $id = $this->asUser($specialist)->postJson("/api/v1/admin/programs/{$program->id}/evaluation-reports", [])->assertCreated()->assertJsonPath('data.metrics.satisfaction.responses', 3)->assertJsonPath('data.metrics.satisfaction.average', 85)->json('data.id');

        $xlsx = $this->asUser($specialist)->get("/api/v1/admin/evaluation-reports/{$id}/export?format=xlsx")->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $xlsx);
        $book = IOFactory::load($path);
        $this->assertGreaterThanOrEqual(2, $book->getSheetCount());
        @unlink($path);

        $pdf = $this->asUser($specialist)->get("/api/v1/admin/evaluation-reports/{$id}/export?format=pdf")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        foreach (['xlsx', 'pdf', 'docx', 'csv'] as $format) {
            $this->asUser($specialist)->get("/api/v1/admin/programs/{$program->id}/satisfaction/export?format={$format}")->assertOk();
        }

        $docx = $this->asUser($specialist)->get("/api/v1/admin/evaluation-reports/{$id}/export?format=docx&lang=ar")->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'd').'.docx';
        file_put_contents($path, $docx);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('برنامج التقييم', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'the document XML must be well formed');
        $zip->close();
        @unlink($path);
    }

    public function test_forms_follow_the_approval_flow_and_a_changed_approved_form_needs_approving_again(): void
    {
        $manager = $this->makeUser(Role::PLANNING_HEAD);
        app(EvaluationService::class)->ensureDefaults();
        $q = [['type' => 'rating', 'title' => 'سؤال', 'required' => true], ['type' => 'long_text', 'title' => 'ملاحظات', 'evidence' => true]];
        $id = $this->asUser($manager)->postJson('/api/v1/admin/evaluation-forms', ['kind' => 'custom', 'title_ar' => 'نموذج', 'title_en' => 'Form', 'questions' => $q])->assertCreated()->assertJsonPath('data.approval_status', 'draft')->json('data.id');
        $this->asUser($manager)->postJson("/api/v1/admin/evaluation-forms/{$id}/submit-approval")->assertOk()->assertJsonPath('data.approval_status', 'pending');
        $this->asUser($manager)->postJson("/api/v1/admin/evaluation-forms/{$id}/approve")->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->assertTrue(EvaluationForm::find($id)->questions[1]['evidence']);

        $this->asUser($manager)->putJson("/api/v1/admin/evaluation-forms/{$id}", ['questions' => [['type' => 'rating', 'title' => 'سؤال آخر']]])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.approval_status', 'draft');
        $system = EvaluationForm::where('is_system', true)->firstOrFail();
        $this->asUser($manager)->putJson("/api/v1/admin/evaluation-forms/{$system->id}", ['title_ar' => 'x'])->assertStatus(422)->assertJsonPath('code', 'system_form');
    }

    public function test_the_evaluation_centre_board_and_manual_assignment_for_the_planning_team(): void
    {
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $member = $this->makeUser(Role::PLANNING_SPECIALIST);
        $program = $this->makeProgram();
        $group = $this->group($program);
        $form = EvaluationForm::create(['kind' => 'planning_evaluation', 'title_ar' => 'ت', 'title_en' => 'P', 'questions' => [['id' => 'q1', 'type' => 'rating', 'title' => 'س', 'required' => true, 'scale' => ['min' => 1, 'max' => 5], 'mode' => 'competence']], 'approval_status' => 'approved']);

        $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/evaluations/assign", ['form_id' => $form->id, 'group_id' => $group->id, 'user_ids' => [$member->id]])->assertOk()->assertJsonPath('data.assigned', 1);
        $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/evaluations/assign", ['form_id' => $form->id, 'group_id' => $group->id, 'user_ids' => [$member->id]])->assertOk()->assertJsonPath('data.assigned', 0);

        $a = EvaluationAssignment::first();
        $this->asUser($member)->getJson('/api/v1/me/evaluations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'planning_evaluation');
        $this->asUser($member)->postJson("/api/v1/me/evaluations/{$a->id}", ['answers' => []])->assertStatus(422);   // the required question is missing
        $this->asUser($member)->postJson("/api/v1/me/evaluations/{$a->id}", ['answers' => ['q1' => 5]])->assertCreated()->assertJsonPath('data.score', 100);

        $board = $this->asUser($head)->getJson("/api/v1/admin/programs/{$program->id}/evaluations?group={$group->id}")->assertOk();
        $row = collect($board->json('data.instruments'))->firstWhere('key', 'planning_evaluation');
        $this->assertSame([1, 1], [$row['expected'], $row['responses']]);
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/admin/programs/{$program->id}/evaluations")->assertForbidden();
    }
}
