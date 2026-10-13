<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LearnerMastery;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** AI-03: practice that gets harder or easier with the learner's mastery, explained, and remedial lessons only for those who need them. */
class AdaptivePracticeTest extends TestCase
{
    private Program $program;

    private Skill $skill;

    private Assessment $practice;

    /** @var array<string, string> question id => difficulty */
    private array $difficulty = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->program = $this->makeProgram(['has_course' => true]);
        $this->skill = Skill::create(['code' => 'Q-ASK', 'name_ar' => 'طرح الأسئلة', 'name_en' => 'Questioning', 'category' => 'pedagogy']);
        $bank = QuestionBank::create(['title_ar' => 'بنك', 'title_en' => 'Bank', 'visibility' => 'center']);
        foreach (['easy', 'medium', 'hard'] as $level) {
            foreach (range(1, 4) as $i) {
                $q = Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => "{$level} {$i}", 'difficulty' => $level, 'points' => 1, 'version' => 1, 'status' => 'active', 'skill_ids' => [$this->skill->id],
                    'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
                $this->difficulty[$q->id] = $level;
            }
        }
        $this->practice = Assessment::create(['program_id' => $this->program->id, 'kind' => 'practice', 'title_ar' => 'تدريب', 'title_en' => 'Practice', 'max_attempts' => 9, 'pass_percent' => 60,
            'status' => 'published', 'feedback_mode' => 'after_submit', 'adaptive' => true]);
        $this->practice->sections()->create(['selection' => 'random', 'bank_id' => $bank->id, 'count' => 5, 'sort_order' => 0]);
    }

    /** @return array{0: User, 1: Registration} */
    private function learner(?float $mastery = null): array
    {
        $employee = $this->makeEmployee();
        $r = Registration::create(['program_id' => $this->program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        if ($mastery !== null) {
            LearnerMastery::create(['registration_id' => $r->id, 'skill_id' => $this->skill->id, 'mastery' => $mastery, 'evidence_count' => 3]);
        }

        return [$employee->user, $r];
    }

    private function start(User $u): TestResponse
    {
        return $this->asUser($u)->postJson("/api/v1/me/assessments/{$this->practice->id}/start")->assertOk();
    }

    private function answer(User $u, string $attemptId, string $questionId, bool $right): TestResponse
    {
        return $this->asUser($u)->postJson("/api/v1/me/attempts/{$attemptId}/adaptive-answer", ['question_id' => $questionId, 'answer' => $right ? 'a' : 'b'])->assertOk();
    }

    public function test_low_mastery_draws_easy_high_draws_hard_and_no_evidence_starts_in_the_middle(): void
    {
        foreach ([[0.2, 'easy'], [0.9, 'hard'], [null, 'medium']] as [$mastery, $level]) {
            [$u] = $this->learner($mastery);
            $state = $this->start($u)->json('data');
            $this->assertCount(1, $state['questions'], 'one question at a time');
            $this->assertSame($level, $this->difficulty[$state['questions'][0]['id']], "mastery {$mastery}");
            $this->assertSame(5, $state['adaptive']['total']);
            $this->assertNotEmpty($state['adaptive']['why']['ar']);
            $this->assertNotEmpty($state['adaptive']['why']['en']);
        }
    }

    public function test_the_staircase_steps_up_after_two_correct_answers_and_down_after_a_wrong_one(): void
    {
        [$u] = $this->learner();
        $state = $this->start($u)->json('data');
        $id = $state['id'];
        $q = $state['questions'][0]['id'];
        $this->assertSame('medium', $this->difficulty[$q]);

        $r1 = $this->answer($u, $id, $q, true)->json('data');
        $this->assertTrue($r1['correct']);
        $this->assertSame('medium', $this->difficulty[$r1['next']['id']]);
        $r2 = $this->answer($u, $id, $r1['next']['id'], true)->json('data');
        $this->assertSame('hard', $this->difficulty[$r2['next']['id']]);
        $this->assertStringContainsString('two', mb_strtolower($r2['why']['en']));
        $r3 = $this->answer($u, $id, $r2['next']['id'], false)->json('data');
        $this->assertFalse($r3['correct']);
        $this->assertSame('medium', $this->difficulty[$r3['next']['id']]);

        $this->asUser($u)->postJson("/api/v1/me/attempts/{$id}/adaptive-answer", ['question_id' => $q, 'answer' => 'a'])->assertStatus(422);   // already answered
    }

    public function test_the_last_answer_ends_the_practice_and_stopping_early_counts_what_was_not_asked(): void
    {
        [$u] = $this->learner(0.5);
        $state = $this->start($u)->json('data');
        $id = $state['id'];
        $this->answer($u, $id, $state['questions'][0]['id'], true);

        $this->asUser($u)->postJson("/api/v1/me/attempts/{$id}/submit")->assertOk();

        $attempt = AssessmentAttempt::findOrFail($id);
        $this->assertCount(5, $attempt->questions);
        $this->practice->update(['show_correct_answers' => true]);
        $review = $this->asUser($u)->getJson("/api/v1/me/attempts/{$id}/result")->assertOk()->json('data.review.0');
        $this->assertSame('صح', collect($review['options'])->firstWhere('id', 'a')['text_ar']);   // the learner sees the option, not its id
        $trail = $this->asUser($this->makeUser(Role::TRAINING_HEAD))->getJson("/api/v1/admin/attempts/{$id}/review")->assertOk()->json('data.adaptive.steps');
        $this->assertSame([false, true, false, false, false], array_column($trail, 'unanswered'));   // on screen when the learner stopped
        $this->assertSame([false, false, true, true, true], array_column($trail, 'skipped'));
        $this->assertEquals(5, $attempt->max_score);
        $this->assertEquals(20, $attempt->score_percent);

        [$v] = $this->learner(0.5);
        $s = $this->start($v)->json('data');
        $next = $s['questions'][0]['id'];
        for ($i = 0; $i < 5; $i++) {
            $res = $this->answer($v, $s['id'], $next, true)->json('data');
            $next = $res['next']['id'] ?? null;
        }
        $this->assertTrue($res['done']);
        $this->assertNull($next);
    }

    public function test_teachers_see_the_difficulty_trajectory_in_the_review(): void
    {
        [$u] = $this->learner();
        $state = $this->start($u)->json('data');
        $r1 = $this->answer($u, $state['id'], $state['questions'][0]['id'], true)->json('data');
        $this->answer($u, $state['id'], $r1['next']['id'], false);

        $review = $this->asUser($this->makeUser(Role::TRAINING_HEAD))->getJson("/api/v1/admin/attempts/{$state['id']}/review")->assertOk()->json('data');

        $this->assertSame(['medium', 'medium', 'easy'], array_column($review['adaptive']['steps'], 'level'));
        $this->assertSame([true, false, null], array_column($review['adaptive']['steps'], 'correct'));
        $this->assertSame('صح', $review['items'][0]['options'][0]['text_ar']);   // the review names the options, not just their ids
    }

    public function test_the_thresholds_come_from_the_ai_settings(): void
    {
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/settings/ai', ['adaptive' => ['easy_below' => 95, 'hard_above' => 98]])->assertOk()
            ->assertJsonPath('data.policy.adaptive.easy_below', 95);
        [$u] = $this->learner(0.9);

        $state = $this->start($u)->json('data');

        $this->assertSame('easy', $this->difficulty[$state['questions'][0]['id']]);
        $this->assertStringContainsString('95%', $state['adaptive']['why']['en']);
    }

    public function test_an_adaptive_quiz_without_immediate_feedback_never_says_whether_an_answer_was_right(): void
    {
        $this->practice->update(['kind' => 'quiz', 'feedback_mode' => 'after_submit']);
        [$u] = $this->learner();
        $state = $this->start($u)->json('data');

        $r1 = $this->answer($u, $state['id'], $state['questions'][0]['id'], true)->json('data');
        $r2 = $this->answer($u, $state['id'], $r1['next']['id'], true)->json('data');

        $this->assertNull($r1['correct']);
        $this->assertSame('hard', $this->difficulty[$r2['next']['id']]);          // it still adapts
        $this->assertStringNotContainsString('correct', mb_strtolower($r2['why']['en']));
        $view = $this->asUser($u)->getJson("/api/v1/me/attempts/{$state['id']}/result")->assertOk()->json('data.adaptive');
        $this->assertSame([null, null, null], array_column($view['steps'], 'correct'));
    }

    public function test_only_the_question_on_screen_takes_a_draft_answer(): void
    {
        [$u] = $this->learner();
        $state = $this->start($u)->json('data');
        $first = $state['questions'][0]['id'];
        $r1 = $this->answer($u, $state['id'], $first, false)->json('data');

        $this->asUser($u)->putJson("/api/v1/me/attempts/{$state['id']}/answers", ['answers' => [$first => 'a', $r1['next']['id'] => 'a']])->assertOk();

        $answers = AssessmentAttempt::findOrFail($state['id'])->answers;
        $this->assertSame('b', $answers[$first]);                                  // a settled answer cannot be changed
        $this->assertSame('a', $answers[$r1['next']['id']]);
    }

    public function test_two_competencies_take_turns_each_from_its_own_mastery(): void
    {
        $other = Skill::create(['code' => 'Q-FB', 'name_ar' => 'التغذية الراجعة', 'name_en' => 'Feedback', 'category' => 'pedagogy']);
        $bank = QuestionBank::firstOrFail();
        foreach (['easy', 'medium', 'hard'] as $level) {
            foreach (range(1, 3) as $i) {
                $q = Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => "ر {$level} {$i}", 'difficulty' => $level, 'points' => 1, 'version' => 1, 'status' => 'active', 'skill_ids' => [$other->id],
                    'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
                $this->difficulty[$q->id] = $level;
            }
        }
        [$u, $r] = $this->learner(0.9);                                             // strong in questioning
        LearnerMastery::create(['registration_id' => $r->id, 'skill_id' => $other->id, 'mastery' => 0.1, 'evidence_count' => 2]);   // weak in feedback

        $state = $this->start($u)->json('data');
        $r1 = $this->answer($u, $state['id'], $state['questions'][0]['id'], true)->json('data');

        $levels = [$this->difficulty[$state['questions'][0]['id']], $this->difficulty[$r1['next']['id']]];
        sort($levels);
        $this->assertSame(['easy', 'hard'], $levels);                              // one of each, each at its own level
        $this->assertNotSame($state['adaptive']['skill']['en'], $r1['skill']['en']);
    }

    public function test_the_builder_warns_when_an_adaptive_practice_has_one_level_only(): void
    {
        $admin = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($admin)->getJson("/api/v1/admin/assessments/{$this->practice->id}")->assertOk()->assertJsonPath('validation.warnings', []);

        Question::where('difficulty', '!=', 'easy')->update(['status' => 'retired']);

        $this->asUser($admin)->getJson("/api/v1/admin/assessments/{$this->practice->id}")->assertOk()->assertJsonPath('validation.warnings', ['adaptive_one_level']);
    }

    public function test_only_practice_and_quiz_assessments_may_adapt(): void
    {
        $admin = $this->makeUser(Role::TRAINING_HEAD);
        $final = Assessment::create(['program_id' => $this->program->id, 'kind' => 'final', 'title_ar' => 'نهائي', 'title_en' => 'Final', 'max_attempts' => 1, 'pass_percent' => 60, 'status' => 'draft', 'feedback_mode' => 'after_submit']);

        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$final->id}", ['adaptive' => true])->assertStatus(422)->assertJsonValidationErrors('adaptive');
        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$final->id}", ['kind' => 'quiz', 'adaptive' => true])->assertOk()->assertJsonPath('data.adaptive', true);
        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$final->id}", ['kind' => 'final'])->assertStatus(422)->assertJsonValidationErrors('adaptive');
        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$final->id}", ['allow_offline' => true])->assertStatus(422)->assertJsonValidationErrors('allow_offline');
    }

    public function test_remedial_lessons_reach_only_the_learner_who_needs_them_and_leave_once_mastered(): void
    {
        $module = CourseModule::create(['program_id' => $this->program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 1]);
        CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'درس', 'title_en' => 'Lesson', 'status' => 'published', 'sort_order' => 1, 'is_required' => true]);
        $remedial = CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'مراجعة', 'title_en' => 'Review', 'status' => 'published', 'sort_order' => 900,
            'is_required' => false, 'settings' => ['remedial' => true, 'skill_id' => $this->skill->id]]);
        $quiz = Assessment::create(['program_id' => $this->program->id, 'kind' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'max_attempts' => 9, 'pass_percent' => 60, 'status' => 'published', 'feedback_mode' => 'after_submit']);
        $quiz->sections()->create(['selection' => 'fixed', 'question_ids' => array_slice(array_keys($this->difficulty), 0, 2), 'sort_order' => 0]);
        [$weak, $weakReg] = $this->learner();
        [$other, $otherReg] = $this->learner();
        $take = function (User $u, string $answer) use ($quiz) {
            $at = $this->asUser($u)->postJson("/api/v1/me/assessments/{$quiz->id}/start")->assertOk()->json('data');
            $this->asUser($u)->putJson("/api/v1/me/attempts/{$at['id']}/answers", ['answers' => collect($at['questions'])->mapWithKeys(fn ($q) => [$q['id'] => $answer])->all()])->assertOk();
            $this->asUser($u)->postJson("/api/v1/me/attempts/{$at['id']}/submit")->assertOk();
        };
        $sees = fn (User $u, Registration $r) => collect($this->asUser($u)->getJson("/api/v1/me/registrations/{$r->id}/course")->assertOk()->json('data.modules'))->flatMap(fn ($m) => $m['lessons'])->pluck('id')->contains($remedial->id);

        $take($weak, 'b');                                // all wrong: mastery 0 → the review lesson is assigned
        $this->assertTrue($sees($weak, $weakReg));
        $this->assertFalse($sees($other, $otherReg));
        $this->asUser($other)->getJson("/api/v1/me/lessons/{$remedial->id}")->assertNotFound();
        $path = $this->asUser($weak)->getJson("/api/v1/me/courses/{$weakReg->id}/path")->assertOk()->json('data');
        $this->assertSame('assigned', collect($path['remedial'])->firstWhere('lesson_id', $remedial->id)['reason']);
        $otherPath = $this->asUser($other)->getJson("/api/v1/me/courses/{$otherReg->id}/path")->assertOk()->json('data.modules');
        $this->assertFalse(collect($otherPath)->flatMap(fn ($m) => $m['lessons'])->pluck('id')->contains($remedial->id));

        foreach (range(1, 3) as $i) {
            $take($weak, 'a');                            // 0 → 0.5 → 0.75 → 0.875: mastered
        }
        $this->assertFalse($sees($weak, $weakReg));
        $this->assertDatabaseHas('notifications', ['user_id' => $weak->id, 'type' => 'course.remedial_assigned']);
    }

    public function test_an_assigned_review_lesson_is_never_locked_behind_the_course_order(): void
    {
        $this->program->update(['course_sequential' => true]);
        $module = CourseModule::create(['program_id' => $this->program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 1]);
        CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'درس', 'title_en' => 'Lesson', 'body_ar' => 'نص', 'status' => 'published', 'sort_order' => 1, 'is_required' => true]);
        [$u, $r] = $this->learner(0.2);
        $review = CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'مراجعة', 'title_en' => 'Review', 'body_ar' => 'نص', 'status' => 'published', 'sort_order' => 900,
            'settings' => ['remedial' => true, 'skill_id' => $this->skill->id]]);

        $row = collect($this->asUser($u)->getJson("/api/v1/me/registrations/{$r->id}/course")->assertOk()->json('data.modules'))->flatMap(fn ($m) => $m['lessons'])->firstWhere('id', $review->id);

        $this->assertFalse($row['locked']);
        $this->asUser($u)->getJson("/api/v1/me/lessons/{$review->id}")->assertOk();
    }

    public function test_a_review_lesson_published_later_reaches_the_learners_already_below_the_threshold(): void
    {
        $module = CourseModule::create(['program_id' => $this->program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 1]);
        CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'درس', 'title_en' => 'Lesson', 'status' => 'published', 'sort_order' => 1, 'is_required' => true]);
        [$weak, $weakReg] = $this->learner(0.3);
        [$strong, $strongReg] = $this->learner(0.95);
        $draft = CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'مراجعة', 'title_en' => 'Review', 'status' => 'draft', 'sort_order' => 900,
            'is_required' => true, 'settings' => ['remedial' => true, 'skill_id' => $this->skill->id]]);
        $this->assertFalse($draft->is_required);                                  // review lessons are never required of everyone

        $draft->update(['status' => 'published']);

        $outline = fn (User $u, Registration $r) => collect($this->asUser($u)->getJson("/api/v1/me/registrations/{$r->id}/course")->assertOk()->json('data.modules'))->flatMap(fn ($m) => $m['lessons'])->keyBy('id');
        $mine = $outline($weak, $weakReg);
        $this->assertTrue($mine[$draft->id]['remedial']);
        $this->assertStringContainsString('30%', $mine[$draft->id]['why']['en']);
        $this->assertFalse($outline($strong, $strongReg)->has($draft->id));
    }
}
