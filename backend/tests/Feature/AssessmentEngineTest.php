<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingRoom;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentEngineTest extends TestCase
{
    private function bank(): QuestionBank
    {
        return QuestionBank::create(['title_ar' => 'بنك', 'title_en' => 'Bank', 'visibility' => 'center']);
    }

    private function sc(QuestionBank $bank, string $difficulty = 'easy', string $stem = 'س'): Question
    {
        return Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => $stem.uniqid(), 'difficulty' => $difficulty, 'points' => 1, 'version' => 1, 'status' => 'active',
            'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
    }

    private function essay(QuestionBank $bank): Question
    {
        return Question::create(['bank_id' => $bank->id, 'type' => 'essay', 'stem_ar' => 'اكتب '.uniqid(), 'difficulty' => 'medium', 'points' => 10, 'version' => 1, 'status' => 'active', 'payload' => ['max_words' => 100, 'min_words' => null, 'allow_file' => false, 'rubric' => []]]);
    }

    /** @return array{0: Program, 1: Registration, 2: User} */
    private function trainee(): array
    {
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$program, $reg, $employee->user];
    }

    private function assessment($program, array $questions, array $extra = []): Assessment
    {
        $a = Assessment::create($extra + ['program_id' => $program->id, 'kind' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'max_attempts' => 2, 'pass_percent' => 60, 'status' => 'published', 'feedback_mode' => 'after_submit', 'show_correct_answers' => true]);
        $a->sections()->create(['selection' => 'fixed', 'question_ids' => array_map(fn ($q) => $q->id, $questions), 'sort_order' => 0]);

        return $a;
    }

    private function answerAll(array $attempt, string $value = 'a'): array
    {
        $answers = [];
        foreach ($attempt['questions'] as $q) {
            $answers[$q['id']] = $value;
        }

        return $answers;
    }

    public function test_a_random_draw_respects_the_difficulty_mix_and_the_bank_must_have_enough_questions(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        [$program] = $this->trainee();
        $bank = $this->bank();
        foreach (range(1, 4) as $i) {
            $this->sc($bank, 'easy');
        }
        $this->sc($bank, 'hard');
        $a = $this->asUser($head)->postJson("/api/v1/admin/programs/{$program->id}/assessments", ['kind' => 'quiz', 'title_ar' => 'ا', 'title_en' => 'Q', 'max_attempts' => 1, 'pass_percent' => 50])->assertCreated()->json('data.id');

        $this->asUser($head)->putJson("/api/v1/admin/assessments/{$a}/sections", ['sections' => [['selection' => 'random', 'bank_id' => $bank->id, 'difficulty_mix' => ['easy' => 3, 'hard' => 3]]]])->assertOk();
        $v = $this->asUser($head)->postJson("/api/v1/admin/assessments/{$a}/validate")->assertOk()->json('data');
        $this->assertFalse($v['valid']);
        $this->assertSame(['difficulty' => 'hard', 'needed' => 3, 'available' => 1], collect($v['sections'][0]['shortages'])->first());
        $this->asUser($head)->postJson("/api/v1/admin/assessments/{$a}/publish")->assertStatus(422);

        $this->asUser($head)->putJson("/api/v1/admin/assessments/{$a}/sections", ['sections' => [['selection' => 'random', 'bank_id' => $bank->id, 'difficulty_mix' => ['easy' => 3, 'hard' => 1]]]])->assertOk();
        $this->assertTrue($this->asUser($head)->postJson("/api/v1/admin/assessments/{$a}/validate")->json('data.valid'));
        $this->asUser($head)->postJson("/api/v1/admin/assessments/{$a}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_an_attempt_draws_freezes_autosaves_submits_grades_and_hides_answers_until_allowed(): void
    {
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $qs = [$this->sc($bank), $this->sc($bank), $this->sc($bank), $this->sc($bank)];
        $a = $this->assessment($program, $qs, ['time_limit_minutes' => 30, 'shuffle_questions' => true]);

        $start = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertOk()->json('data');
        $this->assertCount(4, $start['questions']);
        $this->assertStringNotContainsString('correct', json_encode($start['questions']));
        $this->assertNotNull($start['expires_at']);
        $id = $start['id'];

        $answers = $this->answerAll($start, 'a');
        $answers[array_key_last($answers)] = 'b';
        $save = $this->asUser($user)->putJson("/api/v1/me/attempts/{$id}/answers", ['answers' => $answers])->assertOk();
        $this->assertGreaterThan(0, $save->json('data.remaining_seconds'));

        $res = $this->asUser($user)->postJson("/api/v1/me/attempts/{$id}/submit")->assertOk()->json('data');
        $this->assertEquals(75, $res['score_percent']);
        $this->assertTrue($res['passed']);
        $this->assertSame('graded', $res['status']);
        $this->assertNotEmpty($res['review']);
        $this->assertSame('a', $res['review'][0]['correct_answer']);

        $this->asUser($user)->putJson("/api/v1/me/attempts/{$id}/answers", ['answers' => $answers])->assertStatus(422);
    }

    public function test_feedback_mode_never_hides_the_score_and_after_close_waits_for_release(): void
    {
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $a = $this->assessment($program, [$this->sc($bank)], ['feedback_mode' => 'after_close', 'show_correct_answers' => true]);
        $id = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->json('data.id');
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$id}/answers", ['answers' => $this->answerAll($this->asUser($user)->getJson("/api/v1/me/attempts/{$id}/result")->json('data'))]);

        $sub = $this->asUser($user)->postJson("/api/v1/me/attempts/{$id}/submit")->assertOk()->json('data');
        $this->assertNull($sub['score_percent'], 'the score stays hidden until the results are released');
        $this->assertSame([], $sub['review']);

        $a->update(['released_at' => now()]);
        $this->assertNotNull($this->asUser($user)->getJson("/api/v1/me/attempts/{$id}/result")->json('data.score_percent'));

        $b = $this->assessment($program, [$this->sc($bank)], ['feedback_mode' => 'never', 'show_score' => false]);
        $id2 = $this->asUser($user)->postJson("/api/v1/me/assessments/{$b->id}/start")->json('data.id');
        $r = $this->asUser($user)->postJson("/api/v1/me/attempts/{$id2}/submit")->assertOk()->json('data');
        $this->assertNull($r['score_percent']);
        $this->assertArrayHasKey('passed', $r);
    }

    public function test_the_window_attempt_limit_and_cooldown_are_enforced(): void
    {
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $closed = $this->assessment($program, [$this->sc($bank)], ['window_opens_at' => now()->addDay()]);
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$closed->id}/start")->assertStatus(422)->assertJsonPath('code', 'assessment_not_open');
        $draft = $this->assessment($program, [$this->sc($bank)], ['status' => 'draft']);
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$draft->id}/start")->assertStatus(404);

        $a = $this->assessment($program, [$this->sc($bank)], ['max_attempts' => 2, 'attempt_cooldown_hours' => 2]);
        $first = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->json('data.id');
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertOk()->assertJsonPath('data.id', $first);   // resumes the open attempt
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$first}/submit")->assertOk();
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertStatus(422)->assertJsonPath('code', 'cooldown');

        Carbon::setTestNow(now()->addHours(3));
        $second = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertOk()->json('data');
        $this->assertSame(2, AssessmentAttempt::find($second['id'])->attempt_no);
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$second['id']}/submit")->assertOk();
        Carbon::setTestNow(now()->addHours(3));
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertStatus(422)->assertJsonPath('code', 'attempts_exhausted');
        Carbon::setTestNow();
    }

    public function test_the_server_owns_the_clock_and_an_expired_attempt_is_submitted_with_what_was_saved(): void
    {
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $a = $this->assessment($program, [$this->sc($bank), $this->sc($bank)], ['time_limit_minutes' => 10]);
        $start = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->json('data');
        $first = array_slice($this->answerAll($start), 0, 1, true);
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$start['id']}/answers", ['answers' => $first])->assertOk();

        Carbon::setTestNow(now()->addMinutes(11));
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$start['id']}/answers", ['answers' => $this->answerAll($start)])->assertStatus(422)->assertJsonPath('code', 'attempt_expired');
        $result = $this->asUser($user)->getJson("/api/v1/me/attempts/{$start['id']}/result")->assertOk()->json('data');
        Carbon::setTestNow();

        $this->assertSame('graded', $result['status']);
        $this->assertEquals(50, $result['score_percent']);
    }

    public function test_in_centre_exams_need_the_secret_code_static_or_rotating(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $a = $this->assessment($program, [$this->sc($bank)], ['delivery' => 'in_center', 'access_code_mode' => 'static']);
        $room = TrainingRoom::create(['code' => 'R1', 'name_ar' => 'ق', 'name_en' => 'R', 'capacity' => 20, 'status' => 'active']);

        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertStatus(422)->assertJsonPath('code', 'access_code_required');
        $code = $this->asUser($head)->postJson("/api/v1/admin/assessments/{$a->id}/access-codes", ['room_id' => $room->id, 'valid_from' => now()->subHour()->toDateTimeString(), 'valid_to' => now()->addHour()->toDateTimeString()])->assertCreated()->json('data.code');
        $this->assertDatabaseMissing('assessment_access_codes', ['code_hash' => $code]);

        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start", ['access_code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'access_code_invalid');
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start", ['access_code' => $code, 'room_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'access_code_invalid');
        $ok = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start", ['access_code' => $code, 'room_id' => $room->id])->assertOk()->json('data');
        $this->assertSame('in_center', AssessmentAttempt::find($ok['id'])->delivery);

        $b = $this->assessment($program, [$this->sc($bank)], ['delivery' => 'in_center', 'access_code_mode' => 'rotating']);
        $this->asUser($head)->postJson("/api/v1/admin/assessments/{$b->id}/access-codes", ['valid_from' => now()->subHour()->toDateTimeString(), 'valid_to' => now()->addHour()->toDateTimeString()])->assertCreated();
        $live = $this->asUser($head)->getJson("/api/v1/admin/assessments/{$b->id}/live")->assertOk()->json('data');
        $first = $this->asUser($user)->postJson("/api/v1/me/assessments/{$b->id}/start", ['access_code' => $live['rotating_code']['code']])->assertOk()->json('data.id');
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$first}/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/assessments/{$b->id}/access-codes", ['valid_from' => now()->subHour()->toDateTimeString(), 'valid_to' => now()->addHour()->toDateTimeString()]);
        Carbon::setTestNow(now()->addMinutes(5));
        $this->asUser($user)->postJson("/api/v1/me/assessments/{$b->id}/start", ['access_code' => $live['rotating_code']['code']])->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_integrity_events_warn_flag_or_auto_submit_and_nothing_is_collected_when_proctoring_is_off(): void
    {
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $off = $this->assessment($program, [$this->sc($bank)]);
        $idOff = $this->asUser($user)->postJson("/api/v1/me/assessments/{$off->id}/start")->json('data.id');
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$idOff}/events", ['type' => 'visibility_hidden'])->assertOk()->assertJsonPath('data.ignored', true);
        $this->assertNull(AssessmentAttempt::find($idOff)->integrity);

        $on = $this->assessment($program, [$this->sc($bank)], ['proctoring' => ['enabled' => true, 'tab_switch_limit' => 2, 'action' => 'auto_submit']]);
        $id = $this->asUser($user)->postJson("/api/v1/me/assessments/{$on->id}/start")->json('data.id');
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$id}/events", ['type' => 'visibility_hidden'])->assertOk()->assertJsonPath('data.warnings', 1)->assertJsonPath('data.submitted', false);
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$id}/events", ['type' => 'blur'])->assertOk();
        $last = $this->asUser($user)->postJson("/api/v1/me/attempts/{$id}/events", ['type' => 'visibility_hidden'])->assertOk()->json('data');
        $this->assertTrue($last['submitted']);
        $this->assertSame('graded', AssessmentAttempt::find($id)->status);
        $this->assertContains('tab_switches', AssessmentAttempt::find($id)->integrity['flags']);

        $flag = $this->assessment($program, [$this->sc($bank)], ['proctoring' => ['enabled' => true, 'fullscreen_exit_limit' => 1, 'action' => 'flag']]);
        $id2 = $this->asUser($user)->postJson("/api/v1/me/assessments/{$flag->id}/start")->json('data.id');
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$id2}/events", ['type' => 'fullscreen_exit'])->assertOk();
        $r = $this->asUser($user)->postJson("/api/v1/me/attempts/{$id2}/events", ['type' => 'fullscreen_exit'])->assertOk()->json('data');
        $this->assertFalse($r['submitted']);
        $this->assertContains('fullscreen_exits', AssessmentAttempt::find($id2)->integrity['flags']);
    }

    public function test_essays_wait_for_a_grader_then_the_score_is_final_and_regrade_follows_question_corrections(): void
    {
        $trainer = $this->makeUser(Role::TRAINER);
        [$program, , $user] = $this->trainee();
        $bank = $this->bank();
        $sc = $this->sc($bank);
        $es = $this->essay($bank);
        $a = $this->assessment($program, [$sc, $es], ['pass_percent' => 50]);
        $start = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->json('data');
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$start['id']}/answers", ['answers' => [$sc->id => 'a', $es->id => ['text' => 'إجابتي المقالية']]])->assertOk();

        $sub = $this->asUser($user)->postJson("/api/v1/me/attempts/{$start['id']}/submit")->assertOk()->json('data');
        $this->assertSame('grading', $sub['status']);
        $this->assertNull($sub['passed']);

        $queue = $this->asUser($trainer)->getJson("/api/v1/admin/assessments/{$a->id}/grading")->assertOk()->json('data');
        $this->assertCount(1, $queue);
        $this->asUser($trainer)->postJson("/api/v1/admin/attempts/{$start['id']}/grade", ['grades' => [['question_id' => $es->id, 'points' => 20]]])->assertStatus(422);
        $this->asUser($trainer)->postJson("/api/v1/admin/attempts/{$start['id']}/grade", ['grades' => [['question_id' => $es->id, 'points' => 8, 'comment' => 'جيد']], 'feedback' => 'أحسنت'])->assertOk();

        $att = AssessmentAttempt::find($start['id']);
        $this->assertSame('graded', $att->status);
        $this->assertEquals(81.82, round($att->score_percent, 2));   // (1 + 8) / 11
        $this->assertTrue($att->passed);

        // The question key was wrong: fix it and recompute everyone's attempt.
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $new = $this->asUser($head)->putJson("/api/v1/admin/questions/{$sc->id}", ['type' => 'single_choice', 'stem_ar' => $sc->stem_ar, 'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح'], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => true]]]])->assertOk()->json('data.id');
        $this->asUser($head)->postJson("/api/v1/admin/assessments/{$a->id}/regrade", ['replace' => [$sc->id => $new]])->assertOk()->assertJsonPath('data.regraded', 1);
        $this->assertEquals(72.73, round($att->fresh()->score_percent, 2));   // 0 + 8 of 11
        $this->assertDatabaseHas('audit_logs', ['action' => 'assessment_regraded']);
    }
}
