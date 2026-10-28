<?php

namespace Tests\Feature;

use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\IndividualNeed;
use App\Models\NeedsSurvey;
use App\Models\NeedsSurveyRecipient;
use App\Models\NeedsSurveyResponse;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveyMigration;
use App\Models\SurveyParticipant;
use App\Models\SurveySubmission;
use App\Models\TrainingGroup;
use App\Services\EvaluationService;
use App\Services\FeatureSettings;
use App\Services\ProgramEvaluationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Survey Studio v2, Gate 5: the legacy needs surveys and evaluation forms move onto the engine, keep working, and can be rolled back. */
class SurveyMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('survey_studio_v2', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
    }

    public function test_needs_surveys_move_with_their_people_and_answers_keep_reporting_and_roll_back(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $school = $this->makeSchool();
        $a = $this->makeEmployee(['school_id' => $school->id, 'experience_years' => 4, 'specialization' => 'رياضيات']);
        $b = $this->makeEmployee(['school_id' => $school->id, 'experience_years' => 12, 'specialization' => 'علوم']);
        $c = $this->makeEmployee(['school_id' => $school->id, 'experience_years' => 2]);
        $skill = Skill::where('code', 'classroom_management')->firstOrFail();
        $questions = [
            ['id' => 'm', 'type' => 'matrix', 'title' => 'مستوى التمكن', 'required' => true, 'scale' => ['min' => 1, 'max' => 5], 'rows' => [['id' => 'r1', 'label' => 'إدارة الصف', 'skill_id' => $skill->id]]],
            ['id' => 'ai', 'type' => 'yes_no', 'title' => 'هل تستخدم الذكاء الاصطناعي؟', 'required' => true],
            ['id' => 'why', 'type' => 'long_text', 'title' => 'لماذا لا؟', 'required' => true, 'show_if' => ['question' => 'ai', 'op' => 'equals', 'value' => 'no']],
        ];
        // An anonymous legacy survey, published, two answers with the employee snapshot the legacy table kept.
        $anon = NeedsSurvey::create(['title' => 'استبانة مجهولة', 'status' => 'published', 'questions' => $questions, 'audience' => ['school_ids' => [$school->id]], 'settings' => ['anonymous' => true, 'accent' => '#8a1538'],
            'created_by' => $admin->id, 'published_at' => now()->subDays(3), 'approval_status' => 'approved', 'approved_by' => $admin->id, 'approved_at' => now()->subDays(4)]);
        foreach ([[$a, ['m' => ['r1' => 1], 'ai' => 'no', 'why' => 'لا أعرف'], 120], [$b, ['m' => ['r1' => 5], 'ai' => 'yes'], 80]] as [$e, $answers, $duration]) {
            NeedsSurveyRecipient::create(['survey_id' => $anon->id, 'user_id' => $e->user_id, 'employee_id' => $e->id, 'notified_at' => now()->subDays(3), 'responded_at' => now()->subDay()]);
            NeedsSurveyResponse::create(['survey_id' => $anon->id, 'user_id' => $e->user_id, 'school_id' => $e->school_id, 'job_title_id' => $e->job_title_id, 'experience_years' => $e->experience_years, 'specialization' => $e->specialization, 'answers' => $answers, 'duration_seconds' => $duration, 'submitted_at' => now()->subDay()]);
        }
        NeedsSurveyRecipient::create(['survey_id' => $anon->id, 'user_id' => $c->user_id, 'employee_id' => $c->id, 'notified_at' => now()->subDays(3)]);
        // An identified legacy survey nobody answered yet.
        $named = NeedsSurvey::create(['title' => 'استبانة معرّفة', 'status' => 'published', 'questions' => [['id' => 'cm', 'type' => 'rating', 'title' => 'إدارة الصف', 'required' => true, 'scale' => ['min' => 1, 'max' => 5], 'mode' => 'competence', 'skill_id' => $skill->id]],
            'audience' => [], 'settings' => [], 'created_by' => $admin->id, 'published_at' => now()->subDay(), 'approval_status' => 'approved']);
        NeedsSurveyRecipient::create(['survey_id' => $named->id, 'user_id' => $c->user_id, 'employee_id' => $c->id, 'notified_at' => now()]);

        // A dry run changes nothing.
        $this->artisan('tedc:surveys:migrate', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, Survey::count());
        $plan = $this->asUser($admin)->getJson('/api/v1/admin/surveys/migration')->assertOk()->json('data');
        $this->assertSame(2, $plan['needs_surveys']['count']);
        $this->assertSame(2, $plan['needs_surveys']['responses']);

        $run = $this->asUser($admin)->postJson('/api/v1/admin/surveys/migration', ['only' => 'needs'])->assertOk()->json('data');
        $this->assertSame([2, 4, 2], [$run['needs_surveys'], $run['participants'], $run['submissions']]);
        $this->assertSame([], $run['skipped']);

        $anon->refresh();
        $s = Survey::findOrFail($anon->survey_id);
        $this->assertSame(['needs', 'confidential', 'published', 'needs_survey', $anon->id], [$s->kind, $s->privacy_mode, $s->status, $s->legacy_type, $s->legacy_id]);
        $this->assertSame(1, $s->currentVersion->number);
        $this->assertSame('#8a1538', $s->settings['accent']);
        $this->assertSame(3, SurveyParticipant::where('survey_id', $s->id)->count());
        $this->assertSame(2, SurveyParticipant::where('survey_id', $s->id)->whereNotNull('responded_at')->count());
        $subs = SurveySubmission::where('survey_id', $s->id)->get();
        $this->assertCount(2, $subs);
        $this->assertTrue($subs->every(fn ($x) => $x->respondent_user_id === null && $x->respondent_pseudonym !== null && $x->source === 'import'), 'anonymous answers carry a pseudonym, never the person');
        $this->assertSame($school->id, $subs->first()->segment_snapshot['school_id']);
        $this->assertSame(['m', 'ai', 'why'], array_column($s->currentVersion->questions, 'id'));
        $this->assertSame('all', $s->currentVersion->questions[2]['visible_if']['match']);

        // The legacy report, export and list keep working from the engine's answers; the legacy row is read-only.
        $report = $this->asUser($admin)->getJson("/api/v1/admin/needs-surveys/{$anon->id}/report")->assertOk()->json('data');
        $this->assertSame([3, 2], [$report['summary']['recipients'], $report['summary']['responses']]);
        $this->assertSame(66.7, (float) $report['summary']['response_rate']);
        $this->assertSame($skill->id, $report['needs'][0]['skill_id']);
        $this->assertSame(1, $report['needs'][0]['in_need']);
        $this->assertSame(1, $this->asUser($admin)->getJson("/api/v1/admin/needs-surveys/{$anon->id}/report?experience_band=11-15")->assertOk()->json('data.summary.filtered_responses'));
        $this->assertStringContainsString('إدارة الصف', $this->asUser($admin)->get("/api/v1/admin/needs-surveys/{$anon->id}/export")->assertOk()->streamedContent());
        $list = collect($this->asUser($admin)->getJson('/api/v1/admin/needs-surveys')->assertOk()->json('data'));
        $this->assertSame($named->fresh()->survey_id, $list->firstWhere('id', $named->id)['survey_id']);
        $this->assertSame([3, 2], [$list->firstWhere('id', $anon->id)['recipients_count'], $list->firstWhere('id', $anon->id)['responses_count']]);
        $this->asUser($admin)->putJson("/api/v1/admin/needs-surveys/{$anon->id}", ['title' => 'x'])->assertStatus(422)->assertJsonPath('code', 'survey_migrated');
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/{$anon->id}/migrate")->assertStatus(422)->assertJsonPath('code', 'survey_migrated');

        // People find the surveys in the engine's list only, and a new answer feeds the needs register (identified survey).
        $this->asUser($c->user)->getJson('/api/v1/me/needs-surveys')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($c->user)->getJson("/api/v1/me/needs-surveys/{$anon->id}")->assertStatus(410)->assertJsonPath('code', 'survey_migrated');
        $this->asUser($c->user)->getJson('/api/v1/me/studio-surveys')->assertOk()->assertJsonCount(2, 'data');
        $this->asUser($a->user)->getJson("/api/v1/me/studio-surveys/{$s->id}")->assertOk()->assertJsonPath('data.answered', true);
        $engineNamed = $named->fresh()->survey_id;
        $this->asUser($c->user)->postJson("/api/v1/me/studio-surveys/{$engineNamed}", ['answers' => ['cm' => 1]])->assertOk();
        $this->assertSame(1, IndividualNeed::where('employee_id', $c->id)->where('skill_id', $skill->id)->where('source', 'self_survey')->count());

        // Rolling back is refused while an engine-era answer exists; forced, it hands everything back untouched.
        $batch = $run['batch'];
        $this->asUser($admin)->postJson('/api/v1/admin/surveys/migration/rollback', ['batch' => $batch])->assertStatus(422)->assertJsonPath('code', 'survey_rollback_blocked');
        $this->assertSame(1, collect($this->asUser($admin)->getJson('/api/v1/admin/surveys/migration')->json('data.batches'))->firstWhere('batch', $batch)['late_answers']);
        $this->artisan('tedc:surveys:migrate', ['--rollback' => $batch, '--force' => true])->assertSuccessful();
        $this->assertSame(0, Survey::count());
        $this->assertSame(0, SurveyMigration::count());
        $this->assertNull($anon->fresh()->survey_id);
        $this->assertSame(2, $anon->responses()->count());
        $this->asUser($admin)->putJson("/api/v1/admin/needs-surveys/{$anon->id}", ['title' => 'عاد'])->assertOk();
        $this->asUser($c->user)->getJson('/api/v1/me/needs-surveys')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_evaluation_forms_move_to_the_engine_and_assignments_are_answered_through_it(): void
    {
        Storage::fake('local');
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $trainerA = $this->makeUser(Role::TRAINER);
        $trainerB = $this->makeUser(Role::TRAINER);
        $program = $this->makeProgram();
        $group = TrainingGroup::where('program_id', $program->id)->firstOrFail();
        $svc = app(EvaluationService::class);
        $form = $this->legacyReflectionForm();
        $answers = ['engagement' => 4, 'objectives' => 5, 'content_time' => 4, 'own_delivery' => 5];

        // One assignment answered the legacy way, with a file and a link as evidence; one still pending.
        $done = $svc->assign($form, $program, $group, $trainerA, 'trainer');
        $this->asUser($trainerA)->post("/api/v1/me/evaluations/{$done->id}", ['answers' => $answers + ['difficulties' => 'yes', 'difficulty_details' => 'انقطاع الكهرباء'], 'evidence' => ['difficulty_details' => [UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'), 'https://a.test/photo']]], ['Accept' => 'application/json'])->assertCreated();
        $pending = $svc->assign($form, $program, $group, $trainerB, 'trainer');
        $legacyPath = collect(EvaluationResponse::where('assignment_id', $done->id)->firstOrFail()->evidence['difficulty_details'])->firstWhere('type', 'file')['path'];

        $surveyId = $this->asUser($head)->postJson("/api/v1/admin/evaluation-forms/{$form->id}/migrate")->assertCreated()->json('data.survey_id');
        $survey = Survey::with('currentVersion')->findOrFail($surveyId);
        $this->assertSame(['trainer_reflection', 'published', 'identified', 'evaluation_form'], [$survey->kind, $survey->status, $survey->privacy_mode, $survey->legacy_type]);
        $this->assertSame((int) $form->version, $survey->currentVersion->number);
        $ids = array_column($survey->currentVersion->questions, 'id');
        $this->assertContains('difficulty_details_ev', $ids);
        $this->assertContains('difficulty_details_evl', $ids);
        $this->assertSame('file_upload', collect($survey->currentVersion->questions)->firstWhere('id', 'difficulty_details_ev')['type']);

        // The answered assignment moved: its submission keeps the assignment as context, the file points at the same object, the link is an answer.
        $done->refresh();
        $this->assertSame($surveyId, $done->survey_id);
        $sub = SurveySubmission::findOrFail($done->submission_id);
        $this->assertSame([$done->id, $trainerA->id, 'import'], [$sub->context_id, $sub->respondent_user_id, $sub->source]);
        $this->assertSame('https://a.test/photo', $sub->answers['difficulty_details_evl']);
        $file = SurveyFile::where('submission_id', $sub->id)->firstOrFail();
        $this->assertSame(['evidence', $legacyPath, 'difficulty_details_ev'], [$file->bucket, $file->path, $file->question_id]);
        $this->assertSame([$file->id], $sub->answers['difficulty_details_ev']);
        $this->assertNotNull($done->score);
        $this->asUser($head)->putJson("/api/v1/admin/evaluation-forms/{$form->id}", ['title_ar' => 'x'])->assertStatus(422)->assertJsonPath('code', 'form_migrated');

        // The pending assignment is answered through the engine: definition, draft, a file, submit, no second answer.
        $show = $this->asUser($trainerB)->getJson("/api/v1/me/evaluations/{$pending->id}")->assertOk()->json('data');
        $this->assertSame($surveyId, $show['engine']['survey_id']);
        $this->assertNotContains('file_upload', array_column($show['questions'], 'type'));
        $this->assertContains('file_upload', array_column($show['engine']['questions'], 'type'));
        $this->asUser($trainerB)->putJson("/api/v1/me/evaluations/{$pending->id}/draft", ['answers' => ['engagement' => 3], 'page' => 0])->assertOk();
        $this->assertNotNull($this->asUser($trainerB)->getJson("/api/v1/me/evaluations/{$pending->id}")->json('data.engine.draft'));
        $fileId = $this->asUser($trainerB)->post("/api/v1/me/evaluations/{$pending->id}/files", ['question_id' => 'difficulty_details_ev', 'file' => UploadedFile::fake()->create('plan.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->asUser($trainerB)->postJson("/api/v1/me/evaluations/{$pending->id}", ['answers' => ['engagement' => 4]])->assertStatus(422);
        $this->asUser($trainerB)->postJson("/api/v1/me/evaluations/{$pending->id}", ['answers' => $answers + ['difficulties' => 'yes', 'difficulty_details' => 'x', 'difficulty_details_ev' => [$fileId]], 'duration_seconds' => 90])->assertCreated()->assertJsonPath('data.score', 90);
        $pending->refresh();
        $this->assertSame('submitted', $pending->status);
        $this->assertSame($pending->id, SurveySubmission::findOrFail($pending->submission_id)->context_id);
        $this->assertSame($pending->submission_id, SurveyFile::findOrFail($fileId)->submission_id);
        $this->asUser($trainerB)->postJson("/api/v1/me/evaluations/{$pending->id}", ['answers' => $answers])->assertStatus(422)->assertJsonPath('code', 'evaluation_submitted');
        $this->assertDatabaseCount('evaluation_responses', 1);

        // Results, the board, the report metrics and the export read both answers alike, evidence included.
        $results = $this->asUser($head)->getJson("/api/v1/admin/programs/{$program->id}/evaluations/trainer_reflection/results")->assertOk()->json('data');
        $this->assertSame(2, $results['responses']);
        $this->assertNotNull($results['average_score']);
        $this->assertSame(['file', 'file', 'link'], collect($results['evidence'])->pluck('type')->sort()->values()->all());
        $this->assertTrue(collect($results['evidence'])->every(fn ($e) => ! empty($e['url'])));
        $this->assertNotContains('difficulty_details_ev', array_column($results['questions'], 'id'));
        $row = collect($this->asUser($head)->getJson("/api/v1/admin/programs/{$program->id}/evaluations?group={$group->id}")->assertOk()->json('data.instruments'))->firstWhere('key', 'trainer_reflection');
        $this->assertSame([2, 2], [$row['expected'], $row['responses']]);
        $this->assertSame(2, app(ProgramEvaluationService::class)->metrics($program)['trainer_reflection']['responses']);
        $this->asUser($head)->get("/api/v1/admin/evaluation-forms/{$form->id}/export?program_id={$program->id}&format=csv")->assertOk();

        // New assignments of the migrated form are engine-backed from the start.
        $this->assertSame($surveyId, $svc->assign($form->fresh(), $program, $group, $this->makeUser(Role::TRAINER), 'trainer')->survey_id);

        // Rollback: the legacy answer is still there, the engine answer would be lost, so it is refused unless forced.
        $batch = SurveyMigration::where('survey_id', $surveyId)->firstOrFail()->batch;
        $this->artisan('tedc:surveys:migrate', ['--rollback' => $batch])->assertFailed();
        $this->artisan('tedc:surveys:migrate', ['--rollback' => $batch, '--force' => true])->assertSuccessful();
        $this->assertNull($form->fresh()->survey_id);
        $this->assertNull($done->fresh()->survey_id);
        $this->assertSame(1, EvaluationResponse::where('assignment_id', $done->id)->count());
        $this->assertSame(0, Survey::count());
        $this->asUser($head)->putJson("/api/v1/admin/evaluation-forms/{$form->id}", ['title_ar' => 'عاد'])->assertOk();
        $this->assertSame(0, EvaluationForm::whereNotNull('survey_id')->count());
    }
}
