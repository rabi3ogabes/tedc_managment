<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Evaluation;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuizQuestion;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Skill;
use Carbon\Carbon;
use Tests\TestCase;

class AssessmentExtrasTest extends TestCase
{
    private function bank(): QuestionBank
    {
        return QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
    }

    private function sc(QuestionBank $bank, array $extra = []): Question
    {
        return Question::create($extra + ['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => 'س'.uniqid(), 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active',
            'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
    }

    private function reg($program): array
    {
        $employee = $this->makeEmployee();

        return [Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]), $employee->user, $employee];
    }

    private function assessment($program, array $questions, array $extra = []): Assessment
    {
        $a = Assessment::create($extra + ['program_id' => $program->id, 'kind' => 'quiz', 'title_ar' => 'ا', 'title_en' => 'A', 'max_attempts' => 3, 'pass_percent' => 50, 'status' => 'published']);
        $a->sections()->create(['selection' => 'fixed', 'question_ids' => array_map(fn ($q) => $q->id, $questions), 'sort_order' => 0]);

        return $a;
    }

    /** Takes an attempt answering `$right` of the questions correctly. */
    private function take($user, Assessment $a, int $right): array
    {
        $s = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertOk()->json('data');
        $answers = [];
        foreach ($s['questions'] as $i => $q) {
            $answers[$q['id']] = $i < $right ? 'a' : 'b';
        }
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$s['id']}/answers", ['answers' => $answers])->assertOk();

        return $this->asUser($user)->postJson("/api/v1/me/attempts/{$s['id']}/submit")->assertOk()->json('data');
    }

    public function test_pre_and_post_tests_give_the_knowledge_gain_and_the_evaluation_reads_them_from_attempts(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $program = $this->makeProgram(['requires_evaluation' => false]);
        [, $user] = $this->reg($program);
        $bank = $this->bank();
        $qs = array_map(fn () => $this->sc($bank), range(1, 4));
        $pre = $this->assessment($program, $qs, ['kind' => 'pre_test']);
        $post = $this->assessment($program, $qs, ['kind' => 'post_test']);

        $this->take($user, $pre, 2);
        $this->take($user, $post, 4);

        $gain = $this->asUser($coordinator)->getJson("/api/v1/admin/programs/{$program->id}/knowledge-gain")->assertOk()->json('data');
        $this->assertEquals(50, $gain['rows'][0]['pre']);
        $this->assertEquals(100, $gain['rows'][0]['post']);
        $this->assertEquals(100.0, $gain['rows'][0]['gain_percent']);
        $this->assertEquals(100.0, $gain['average_gain_percent']);

        $registration = Registration::where('program_id', $program->id)->first();
        $registration->update(['status' => 'completed']);
        $this->asUser($user)->postJson("/api/v1/me/registrations/{$registration->id}/evaluation", ['ratings' => ['content' => 5, 'trainer' => 5, 'organization' => 5, 'relevance' => 5], 'pre_test_score' => 10, 'post_test_score' => 20])->assertCreated();
        $ev = Evaluation::where('registration_id', $registration->id)->first();
        $this->assertEquals(50, $ev->pre_test_score);
        $this->assertEquals(100, $ev->post_test_score);
    }

    public function test_analytics_show_the_distribution_pass_rate_and_item_indices(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $program = $this->makeProgram();
        $bank = $this->bank();
        $qs = array_map(fn () => $this->sc($bank), range(1, 4));
        $a = $this->assessment($program, $qs, ['pass_percent' => 50]);
        foreach ([4, 3, 1, 0] as $right) {
            [, $user] = $this->reg($program);
            $this->take($user, $a, $right);
        }

        $d = $this->asUser($head)->getJson("/api/v1/admin/assessments/{$a->id}/analytics")->assertOk()->json('data');
        $this->assertSame(4, $d['attempts']);
        $this->assertEquals(50.0, $d['pass_rate']);
        $this->assertEquals(50.0, $d['average']);
        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 1, 0, 1], $d['distribution']);
        $this->assertCount(4, $d['items']);
        $this->assertEquals(0.25, $d['items'][0]['difficulty_index']);
        $this->assertEquals(0.75, $d['items'][3]['difficulty_index']);
        $this->assertNotNull($d['items'][0]['discrimination']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/admin/assessments/{$a->id}/analytics")->assertForbidden();
    }

    public function test_a_diagnostic_test_updates_the_competency_evidence(): void
    {
        $program = $this->makeProgram();
        [, $user, $employee] = $this->reg($program);
        $skill = Skill::create(['code' => 'dig', 'name_en' => 'Digital', 'name_ar' => 'رقمي', 'category' => 'x']);
        $bank = $this->bank();
        $qs = array_map(fn () => $this->sc($bank, ['skill_ids' => [$skill->id]]), range(1, 4));
        $a = $this->assessment($program, $qs, ['kind' => 'diagnostic']);

        $this->take($user, $a, 3);

        $row = \DB::table('employee_skills')->where('employee_id', $employee->id)->where('skill_id', $skill->id)->first();
        $this->assertSame('test', $row->source);
        $this->assertSame(4, (int) $row->level);   // 1 + 4 * 0.75
        $this->assertNotNull($row->verified_at);
    }

    public function test_a_failed_chapter_quiz_needs_the_lessons_reopened_before_the_next_try(): void
    {
        $program = $this->makeProgram();
        [$reg, $user] = $this->reg($program);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 0]);
        $study = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'د', 'title_en' => 'L', 'sort_order' => 0, 'is_required' => true, 'status' => 'published']);
        $quizLesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'quiz', 'title_ar' => 'ا', 'title_en' => 'Q', 'sort_order' => 1, 'is_required' => true, 'status' => 'published']);
        $bank = $this->bank();
        $a = $this->assessment($program, [$this->sc($bank), $this->sc($bank)], ['lesson_id' => $quizLesson->id, 'require_restudy_on_fail' => true, 'pass_percent' => 100]);
        LessonProgress::create(['lesson_id' => $study->id, 'registration_id' => $reg->id, 'employee_id' => $reg->employee_id, 'status' => 'completed', 'last_activity_at' => now()->subHour()]);

        $r = $this->take($user, $a, 0);
        $this->assertFalse($r['passed']);
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertStatus(422)->assertJsonPath('code', 'restudy_required');

        Carbon::setTestNow(now()->addMinutes(5));
        LessonProgress::where('lesson_id', $study->id)->update(['last_activity_at' => now()]);
        $ok = $this->take($user, $a, 2);
        Carbon::setTestNow();
        $this->assertTrue($ok['passed']);
        $this->assertSame('completed', LessonProgress::where('lesson_id', $quizLesson->id)->where('registration_id', $reg->id)->value('status'));
    }

    public function test_interactive_video_blocks_progress_until_the_required_questions_are_answered(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true]);
        [$reg, $user] = $this->reg($program);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 0]);
        $video = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'video', 'title_ar' => 'ف', 'title_en' => 'V', 'sort_order' => 0, 'is_required' => true, 'status' => 'published', 'duration_seconds' => 100, 'settings' => ['allow_seeking' => true, 'min_watch_percent' => 90]]);
        $q = $this->sc($this->bank());

        $this->asUser($head)->putJson("/api/v1/admin/course/lessons/{$video->id}/interactions", ['interactions' => [['at_seconds' => 40, 'type' => 'question', 'question_id' => $q->id, 'required' => true, 'blocks_progress' => true, 'require_correct' => true], ['at_seconds' => 70, 'type' => 'reflection', 'prompt_ar' => 'ماذا تعلمت؟', 'required' => false, 'blocks_progress' => false]]])->assertOk();

        $list = $this->asUser($user)->getJson("/api/v1/me/lessons/{$video->id}/interactions")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertStringNotContainsString('correct', json_encode($list[0]['question']));

        $beat = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 60, 'duration' => 100])->assertOk()->json('data');
        $this->assertEquals(40, $beat['furthest']);
        $this->assertSame($list[0]['id'], $beat['blocked_by']);

        $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/interactions/{$list[0]['id']}/answer", ['answer' => 'b'])->assertOk()->assertJsonPath('data.correct', false);
        $still = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 40, 'to' => 60, 'duration' => 100])->json('data');
        $this->assertSame($list[0]['id'], $still['blocked_by'], 'a wrong answer does not unlock a require-correct question');

        $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/interactions/{$list[0]['id']}/answer", ['answer' => 'a'])->assertOk()->assertJsonPath('data.correct', true);
        $after = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 40, 'to' => 100, 'duration' => 100])->assertOk()->json('data');
        $this->assertNull($after['blocked_by']);
        $this->assertGreaterThan(40, $after['furthest']);
        $this->assertNotNull($reg);
    }

    public function test_the_lesson_does_not_count_time_when_the_player_is_hidden(): void
    {
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true]);
        [, $user] = $this->reg($program);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 0]);
        $video = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'video', 'title_ar' => 'ف', 'title_en' => 'V', 'sort_order' => 0, 'is_required' => true, 'status' => 'published', 'duration_seconds' => 100, 'settings' => ['require_visible' => true]]);

        $hidden = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 10, 'duration' => 100, 'visible' => false])->assertOk()->json('data');
        $this->assertEquals(0, $hidden['credited']);
        $shown = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 10, 'duration' => 100, 'visible' => true])->assertOk()->json('data');
        $this->assertGreaterThan(0, $shown['credited']);
        $this->assertNotNull(AssessmentAttempt::query());
    }

    public function test_existing_lesson_quizzes_are_copied_into_banks_without_touching_the_original(): void
    {
        $program = $this->makeProgram();
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 0]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'sort_order' => 0, 'is_required' => true, 'status' => 'published', 'settings' => ['pass_percent' => 80]]);
        QuizQuestion::create(['lesson_id' => $lesson->id, 'type' => 'single', 'text_ar' => 'س', 'options' => [['id' => 'a', 'text_ar' => 'ص', 'correct' => true], ['id' => 'b', 'text_ar' => 'خ', 'correct' => false]], 'points' => 2, 'sort_order' => 0]);

        $this->artisan('tedc:migrate-lesson-quizzes')->assertSuccessful();
        $this->artisan('tedc:migrate-lesson-quizzes')->assertSuccessful();

        $a = Assessment::where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($a);
        $this->assertEquals(80, $a->pass_percent);
        $this->assertSame(1, Assessment::where('lesson_id', $lesson->id)->count());
        $this->assertSame(1, $lesson->fresh()->questions()->count());
        $this->assertSame(1, Question::where('stem_ar', 'س')->count());
    }
}
