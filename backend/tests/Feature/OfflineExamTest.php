<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\OfflineExamToken;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** TYP-12: an exam taken in the app without a connection — single-use, device-bound, time-checked, graded like online. */
class OfflineExamTest extends TestCase
{
    private User $user;

    private Registration $r;

    private Assessment $a;

    private string $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/features/offline_mobile', ['enabled' => true, 'reason' => 'test'])->assertOk();
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $this->user = $employee->user;
        $this->r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $bank = QuestionBank::create(['title_ar' => 'بنك', 'title_en' => 'Bank', 'visibility' => 'center']);
        $questions = [];
        foreach (range(1, 4) as $i) {
            $questions[] = Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => "سؤال {$i}", 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active',
                'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
        }
        $this->a = Assessment::create(['program_id' => $program->id, 'kind' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'max_attempts' => 2, 'pass_percent' => 60, 'status' => 'published',
            'feedback_mode' => 'after_submit', 'time_limit_minutes' => 10, 'allow_offline' => true, 'offline_window_hours' => 72]);
        $this->a->sections()->create(['selection' => 'fixed', 'question_ids' => array_map(fn ($q) => $q->id, $questions), 'sort_order' => 0]);
        $this->device = (string) Str::uuid();
    }

    private function token(?string $device = null): TestResponse
    {
        return $this->asUser($this->user)->postJson("/api/v1/me/assessments/{$this->a->id}/offline-token", ['device_id' => $device ?? $this->device]);
    }

    private function submit(array $package, array $extra = []): TestResponse
    {
        $answers = [];
        foreach ($package['questions'] as $q) {
            $answers[$q['id']] = 'a';
        }
        $started = now()->subMinutes(5);

        return $this->asUser($this->user)->postJson('/api/v1/me/attempts/offline', $extra + [
            'token_id' => $package['token_id'], 'nonce' => $package['nonce'], 'signature' => $package['signature'], 'device_id' => $this->device, 'answers' => $answers,
            'device_elapsed_seconds' => 240, 'client_started_at' => $started->toIso8601String(), 'client_submitted_at' => $started->copy()->addSeconds(240)->toIso8601String(),
        ]);
    }

    public function test_the_package_never_contains_the_correct_answers(): void
    {
        $package = $this->token()->assertCreated()->json('data');

        $this->assertCount(4, $package['questions']);
        $this->assertSame(10, $package['time_limit_minutes']);
        $flat = json_encode($package);
        $this->assertStringNotContainsString('"correct"', $flat);
        $this->assertStringNotContainsString('explanation', $flat);
        $this->assertNotEmpty($package['signature']);
    }

    public function test_answers_are_graded_like_online_and_the_token_is_single_use_and_device_bound(): void
    {
        $package = $this->token()->assertCreated()->json('data');

        $this->submit($package, ['device_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'offline_token_invalid');
        $res = $this->submit($package)->assertCreated();
        $this->assertSame('graded', $res->json('data.status'));
        $this->assertEquals(100, $res->json('data.score_percent'));
        $attempt = AssessmentAttempt::firstOrFail();
        $this->assertSame('offline', $attempt->delivery);
        $this->assertSame(240, (int) $attempt->device_elapsed_seconds);
        $this->submit($package)->assertStatus(422)->assertJsonPath('code', 'offline_token_used');

        $tampered = $this->token()->json('data');
        $tampered['signature'] = str_repeat('0', 64);
        $this->submit($tampered)->assertStatus(422)->assertJsonPath('code', 'offline_token_invalid');
    }

    public function test_an_expired_token_and_an_overlong_attempt_are_refused(): void
    {
        $package = $this->token()->json('data');
        $this->travel(5)->days();
        $this->submit($package)->assertStatus(422)->assertJsonPath('code', 'offline_token_expired');
        $this->travelBack();

        $fresh = $this->token()->json('data');
        $this->submit($fresh, ['device_elapsed_seconds' => 10 * 60 + 600])->assertStatus(422)->assertJsonPath('code', 'offline_time_exceeded');
    }

    public function test_an_implausibly_short_attempt_is_graded_but_flagged_for_invigilation(): void
    {
        $package = $this->token()->json('data');

        $this->submit($package, ['device_elapsed_seconds' => 3])->assertCreated();

        $attempt = AssessmentAttempt::firstOrFail();
        $this->assertContains('offline_too_fast', $attempt->integrity['flags'] ?? []);
        $live = $this->asUser($this->makeUser(Role::TRAINING_HEAD))->getJson("/api/v1/admin/assessments/{$this->a->id}/live")->assertOk()->json('data.attempts.0');
        $this->assertSame('offline', $live['delivery']);
        $this->assertContains('offline_too_fast', $live['flags']);
    }

    public function test_the_attempt_limit_counts_outstanding_tokens(): void
    {
        $this->token()->assertCreated();
        $this->token((string) Str::uuid())->assertCreated();
        $this->token((string) Str::uuid())->assertStatus(422)->assertJsonPath('code', 'attempts_exhausted');
        $this->assertSame(2, OfflineExamToken::count());
    }

    public function test_the_builder_saves_the_offline_switch_and_window(): void
    {
        $admin = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$this->a->id}", ['allow_offline' => false, 'offline_window_hours' => 48])->assertOk()
            ->assertJsonPath('data.allow_offline', false)->assertJsonPath('data.offline_window_hours', 48);
        $this->asUser($admin)->putJson("/api/v1/admin/assessments/{$this->a->id}", ['offline_window_hours' => 0])->assertStatus(422)->assertJsonValidationErrors('offline_window_hours');
    }

    public function test_only_assessments_that_allow_offline_issue_a_package(): void
    {
        $this->a->update(['allow_offline' => false]);
        $this->token()->assertStatus(422)->assertJsonPath('code', 'offline_not_allowed');
        $this->a->update(['allow_offline' => true, 'kind' => 'practice', 'adaptive' => true]);   // adaptive practice needs the server for every question
        $this->token()->assertStatus(422)->assertJsonPath('code', 'offline_not_allowed');
        $this->a->update(['adaptive' => false, 'kind' => 'quiz']);
        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/me/assessments/{$this->a->id}/offline-token", ['device_id' => $this->device])->assertNotFound();
    }
}
