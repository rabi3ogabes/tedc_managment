<?php

namespace Tests\Feature;

use App\Ai\Adaptive\AdaptivePath;
use App\Ai\AiGuard;
use App\Ai\AiPolicy;
use App\Ai\Forecast\ForecastService;
use App\Ai\Forecast\RiskService;
use App\Ai\Rag\Indexer;
use App\Ai\Rag\LocalEmbedder;
use App\Ai\Rag\Retriever;
use App\Ai\Recommend\HybridRecommender;
use App\Ai\Recommend\SimilarityBuilder;
use App\Ai\Redactor;
use App\Models\AdaptiveRule;
use App\Models\AiFeedbackDraft;
use App\Models\AiLog;
use App\Models\AiRecommendationEvent;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssistantMessage;
use App\Models\AuditLog;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\CourseQuestion;
use App\Models\Embedding;
use App\Models\Forecast;
use App\Models\ItemSimilarity;
use App\Models\LearnerMastery;
use App\Models\LessonProgress;
use App\Models\ProgramCategory;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\RiskFlag;
use App\Models\Role;
use App\Models\Skill;
use App\Models\SupportTicket;
use App\Models\TrainingGroup;
use App\Models\TrainingNeed;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanItem;
use App\Models\User;
use App\Services\Ai\AiModelSettings;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiCompletionTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
    }

    /** A text model on a connection with the given residency, assigned to text work. */
    private function model(string $residency, string $driver = 'custom'): void
    {
        $settings = app(AiModelSettings::class);
        $c = $settings->saveConnection(['name' => 'Test', 'driver' => $driver, 'base_url' => 'https://ai.test/v1', 'api_key' => 'sk-test', 'data_residency' => $residency]);
        $m = $settings->saveModel(['connection_id' => $c['id'], 'model' => 'gpt-test', 'tasks' => ['text']]);
        $settings->assign(['text' => $m['id']]);
    }

    private function course(): array
    {
        $program = $this->makeProgram(['has_course' => true]);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 0]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'إدارة الصف', 'title_en' => 'Classroom management', 'body_ar' => 'تعتمد إدارة الصف على وضع قواعد واضحة وتعزيز السلوك الإيجابي وتنظيم وقت الحصة.', 'body_en' => 'Classroom management relies on clear rules, positive reinforcement and planning lesson time.', 'sort_order' => 0, 'status' => 'published']);
        $emp = $this->makeEmployee();
        $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $emp->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$program, $module, $lesson, $emp->user, $reg];
    }

    // ---- residency, redaction, logs --------------------------------------------------------------

    public function test_redaction_hides_identifiers_and_restores_them(): void
    {
        $r = new Redactor(['Fatima Al-Kuwari']);
        $out = $r->redact('Contact Fatima Al-Kuwari at fatima@moe.gov.qa or +974 5512 3456, ID 29850123456, ref 123456789012. Kuwari agrees.');
        foreach (['fatima@moe.gov.qa', '5512 3456', '29850123456', '123456789012', 'Fatima', 'Kuwari'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
        $this->assertStringContainsString('[EMAIL_1]', $out);
        $this->assertSame('Dear Fatima Al-Kuwari, ok', $r->restore('Dear '.$r->redact('Fatima Al-Kuwari').', ok'));
    }

    public function test_external_models_are_blocked_for_personal_data_and_logged(): void
    {
        $this->model('external');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => 'hello']]]])]);
        $guard = app(AiGuard::class);
        $this->assertNull($guard->text('assistant', 'sys', 'hi', $this->admin, true));
        Http::assertNothingSent();
        $this->assertSame('residency', AiLog::where('status', 'blocked')->value('reason'));

        // Work that carries no personal data may use it; allowing a feature explicitly lifts the block.
        $this->assertSame('hello', $guard->text('assistant', 'sys', 'hi', $this->admin, false)['text']);
        app(AiPolicy::class)->save(['features' => ['assistant' => ['allow_external' => true]]]);
        $this->assertSame('hello', $guard->text('assistant', 'sys', 'hi', $this->admin, true)['text']);
        // And enforcement can be turned off by an administrator.
        app(AiPolicy::class)->save(['features' => ['assistant' => ['allow_external' => false]], 'residency_enforced' => false]);
        $this->assertNotNull($guard->text('assistant', 'sys', 'hi', $this->admin, true));
    }

    public function test_the_prompt_leaves_without_personal_data_and_the_answer_gets_it_back(): void
    {
        $this->model('qatar');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => 'Hello [NAME_1], write to [EMAIL_1]']]]])]);
        $res = app(AiGuard::class)->text('assistant', 'You help Sara Hassan.', 'My email is sara@moe.gov.qa and my number 55123456', $this->admin, true, ['Sara Hassan']);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return ! str_contains($body, 'sara@moe.gov.qa') && ! str_contains($body, '55123456') && ! str_contains($body, 'Sara') && str_contains($body, '[EMAIL_1]');
        });
        $this->assertSame('Hello Sara Hassan, write to sara@moe.gov.qa', $res['text']);
        $log = AiLog::where('status', 'ok')->first();
        $this->assertGreaterThanOrEqual(3, $log->redactions);
        $this->assertSame('qatar', $log->residency);
    }

    public function test_azure_uses_the_deployment_path_and_api_key_header(): void
    {
        $this->model('qatar', 'azure');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        app(AiGuard::class)->text('assistant', 's', 'q', $this->admin, false);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/openai/deployments/gpt-test/chat/completions?api-version=') && $r->hasHeader('api-key', 'sk-test'));
    }

    public function test_settings_and_logs_need_the_permission_and_prompts_are_never_stored(): void
    {
        $this->asUser($this->makeUser())->getJson('/api/v1/admin/settings/ai')->assertForbidden();
        $this->model('qatar');
        $r = $this->asUser($this->admin)->putJson('/api/v1/admin/settings/ai', ['retention_days' => 7, 'features' => ['assistant' => ['enabled' => false]], 'recommendations' => ['weights' => ['peers' => 50], 'ab_test' => true, 'ab_share' => 30]])->assertOk();
        $this->assertSame(7, $r->json('data.policy.retention_days'));
        $this->assertFalse($r->json('data.policy.features.assistant.enabled'));
        $this->assertFalse($r->json('data.policy.features.assistant.usable'));
        $this->assertSame(50, $r->json('data.policy.recommendations.weights.peers'));
        $this->assertSame('qatar', $r->json('data.connections.0.data_residency'));
        $this->assertNull(app(AiGuard::class)->text('assistant', 's', 'q', $this->admin, false));   // switched off → the caller falls back
        $this->assertSame('feature_off', AiLog::latest('created_at')->value('reason'));

        AiLog::query()->update(['created_at' => now()->subDays(10)]);
        $this->assertGreaterThan(0, app(AiGuard::class)->prune());
        $this->assertFalse(array_key_exists('prompt', (new AiLog)->getAttributes()));
    }

    // ---- retrieval and the assistant ---------------------------------------------------------------

    public function test_embeddings_find_arabic_and_english_text_and_ignore_spelling_variants(): void
    {
        $e = new LocalEmbedder;
        $q = $e->embed('كيف أدير الصف؟');
        $near = LocalEmbedder::cosine($q, $e->embed('تعتمد إدارة الصف على قواعد واضحة'));
        $far = LocalEmbedder::cosine($q, $e->embed('جدول الحصص والإجازات الرسمية للمدرسة'));
        $this->assertGreaterThan($far, $near);
        $this->assertEqualsWithDelta(1.0, LocalEmbedder::cosine($e->embed('أنظمة التقييم'), $e->embed('انظمه التقييم')), 0.001);
        $this->assertGreaterThan(LocalEmbedder::cosine($e->embed('classroom management rules'), $e->embed('fire safety')), LocalEmbedder::cosine($e->embed('classroom management rules'), $e->embed('rules for managing a classroom')));
    }

    public function test_retrieval_respects_registration_and_publication_and_follows_the_content(): void
    {
        [$program, $module, $lesson, $user] = $this->course();
        $stranger = $this->makeUser();
        $retriever = app(Retriever::class);

        $this->assertNotEmpty($retriever->search($user, 'classroom management rules'));
        $this->assertEmpty($retriever->search($stranger, 'classroom management rules'));   // lessons are for the people registered in the programme

        $draft = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'سري', 'title_en' => 'Secret exam answers', 'body_en' => 'The exam answers are hidden in this unpublished lesson about exam answers.', 'sort_order' => 1, 'status' => 'draft']);
        $this->assertEmpty(array_filter($retriever->search($user, 'exam answers'), fn ($h) => $h['source_id'] === $draft->id));
        $draft->update(['status' => 'published']);                                      // publishing indexes it
        $this->assertNotEmpty(array_filter($retriever->search($user, 'exam answers'), fn ($h) => $h['source_id'] === $draft->id));
        $draft->update(['status' => 'draft']);                                          // unpublishing takes it out
        $this->assertEmpty(array_filter($retriever->search($user, 'exam answers'), fn ($h) => $h['source_id'] === $draft->id));
        $this->assertSame(0, Embedding::where('source_id', $draft->id)->count());
        $this->assertGreaterThan(0, app(Indexer::class)->all());
    }

    public function test_the_assistant_answers_with_citations_declines_off_topic_and_never_reads_other_peoples_data(): void
    {
        [$program, , $lesson, $user, $reg] = $this->course();
        $other = $this->makeEmployee();
        Registration::create(['program_id' => $this->makeProgram(['title_en' => 'Other persons secret programme'])->id, 'employee_id' => $other->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        $a = $this->asUser($user)->postJson('/api/v1/me/assistant/messages', ['message' => 'What does classroom management rely on?'])->assertOk()->json('data');
        $this->assertSame('rules', $a['message']['meta']['source']);                   // no model assigned: quoted passages
        $this->assertSame($lesson->id, collect($a['message']['citations'])->first()['title'] === 'إدارة الصف' ? $lesson->id : null);
        $this->assertStringContainsString('[1]', $a['message']['content']);

        $off = $this->asUser($user)->postJson('/api/v1/me/assistant/messages', ['message' => 'Write me a poem about football', 'conversation_id' => $a['conversation_id']])->assertOk()->json('data.message');
        $this->assertTrue($off['meta']['refused']);
        $this->assertEmpty($off['citations']);

        $mine = $this->asUser($user)->postJson('/api/v1/me/assistant/messages', ['message' => 'When is my next session and how many hours do I have?'])->assertOk()->json('data.message');
        $this->assertContains('hours', $this->asUser($user)->getJson('/api/v1/me/assistant/conversations')->status() === 200 ? ['hours'] : []);
        $this->assertStringNotContainsString('secret programme', $mine['content']);
        $this->assertEqualsCanonicalizing(['schedule', 'hours'], array_values(AssistantMessage::find($mine['id'])->meta['tools']));

        $this->asUser($this->makeUser())->getJson("/api/v1/me/assistant/conversations/{$a['conversation_id']}")->assertNotFound();   // another person's conversation
        $this->asUser($user)->getJson("/api/v1/me/assistant/conversations/{$a['conversation_id']}")->assertOk()->assertJsonCount(4, 'data.messages');
        $this->asUser($user)->postJson("/api/v1/me/assistant/messages/{$mine['id']}/feedback", ['feedback' => 'down', 'reason' => 'wrong'])->assertOk();
        $this->assertSame('down', AssistantMessage::find($mine['id'])->feedback);
    }

    public function test_with_a_model_the_answer_is_generated_from_the_context_only_and_sent_without_the_name(): void
    {
        [, , , $user] = $this->course();
        $user->update(['name' => 'Khalid Mansour']);
        $this->model('qatar');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => 'Clear rules and positive reinforcement [1].']]]])]);
        $m = $this->asUser($user)->postJson('/api/v1/me/assistant/messages', ['message' => 'Khalid Mansour asks: what does classroom management rely on?'])->assertOk()->json('data.message');
        $this->assertSame('ai', $m['meta']['source']);
        $this->assertSame('Clear rules and positive reinforcement [1].', $m['content']);
        Http::assertSent(fn ($r) => ! str_contains(json_encode($r->data()), 'Khalid') && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'CONTEXT'));
    }

    public function test_when_unsure_it_offers_the_trainer_and_support_and_the_hand_over_works(): void
    {
        [$program, , , $user] = $this->course();
        $m = $this->asUser($user)->postJson('/api/v1/me/assistant/messages', ['message' => 'Can I get a refund for my programme registration fee?'])->assertOk()->json('data.message');
        $this->assertFalse($m['meta']['confident']);
        $this->assertSame($program->id, $m['meta']['escalate']['trainer_programs'][0]['id']);

        $this->asUser($user)->postJson("/api/v1/me/assistant/messages/{$m['id']}/escalate", ['target' => 'trainer'])->assertStatus(422);   // which programme?
        $q = $this->asUser($user)->postJson("/api/v1/me/assistant/messages/{$m['id']}/escalate", ['target' => 'trainer', 'program_id' => $program->id])->assertCreated()->json('data');
        $this->assertSame('trainer', $q['target']);
        $this->assertNotNull(CourseQuestion::find($q['id']));
        $t = $this->asUser($user)->postJson("/api/v1/me/assistant/messages/{$m['id']}/escalate", ['target' => 'support'])->assertCreated()->json('data');
        $this->assertNotNull(SupportTicket::find($t['id']));
    }

    // ---- recommendations ---------------------------------------------------------------------------

    public function test_recommendations_are_explained_learn_from_colleagues_and_record_feedback(): void
    {
        $category = ProgramCategory::first() ?? ProgramCategory::create(['name_ar' => 'ف', 'name_en' => 'C', 'code' => 'C']);
        $done = $this->makeProgram(['category_id' => $category->id, 'status' => 'completed']);
        $next = $this->makeProgram(['category_id' => $category->id, 'title_en' => 'Next programme']);
        $unrelated = $this->makeProgram(['title_en' => 'Unrelated programme']);
        $me = $this->makeEmployee();
        Registration::create(['program_id' => $done->id, 'employee_id' => $me->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED]);
        foreach (range(1, 3) as $i) {   // colleagues who finished the same first programme and went on to the next
            $c = $this->makeEmployee();
            Registration::create(['program_id' => $done->id, 'employee_id' => $c->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED]);
            Registration::create(['program_id' => $next->id, 'employee_id' => $c->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_COMPLETED]);
        }
        $this->assertGreaterThan(0, app(SimilarityBuilder::class)->build(2));
        $this->assertSame(3, ItemSimilarity::where(['item_id' => $done->id, 'other_id' => $next->id])->value('support'));

        $res = app(HybridRecommender::class)->forUser($me->user, 6);
        $ids = array_column($res['items'], 'id');
        $this->assertSame('hybrid', $res['variant']);
        $this->assertContains($next->id, $ids);
        $first = collect($res['items'])->firstWhere('id', $next->id);
        $this->assertNotEmpty($first['reasons']);
        $this->assertGreaterThan(0, $first['components']['peers']);
        $this->assertTrue(collect($res['items'])->search(fn ($i) => $i['id'] === $next->id) <= (collect($res['items'])->search(fn ($i) => $i['id'] === $unrelated->id) ?: 99));
        $this->assertNotContains($done->id, $ids);   // already completed

        // The existing endpoint serves it, and feedback is recorded; a dismissed programme stays away.
        $api = $this->asUser($me->user)->getJson('/api/v1/me/recommendations')->assertOk()->json('data');
        $this->assertNotEmpty($api[0]['reasons']);
        $this->assertSame('hybrid', $api[0]['variant']);
        $this->asUser($me->user)->postJson("/api/v1/me/recommendations/{$next->id}/feedback", ['event' => 'dismissed', 'note' => 'not now'])->assertCreated();
        $this->assertNotContains($next->id, array_column(app(HybridRecommender::class)->forUser($me->user, 6)['items'], 'id'));
        $this->assertSame(1, AiRecommendationEvent::where(['user_id' => $me->user->id, 'event' => 'dismissed'])->count());
    }

    public function test_enrolling_counts_as_a_conversion_and_ab_testing_splits_the_people(): void
    {
        $p = $this->makeProgram();
        $me = $this->makeEmployee();
        app(HybridRecommender::class)->forUser($me->user, 6);
        Registration::create(['program_id' => $p->id, 'employee_id' => $me->id, 'source' => 'self', 'status' => Registration::STATUS_PENDING]);
        $this->assertSame(1, AiRecommendationEvent::where(['user_id' => $me->user->id, 'event' => 'enrolled'])->count());
        $stats = $this->asUser($this->admin)->getJson('/api/v1/admin/ai/recommendation-stats')->assertOk()->json('data');
        $this->assertSame(1, $stats['hybrid']['enrolled']);

        app(AiPolicy::class)->save(['recommendations' => ['ab_test' => true, 'ab_share' => 50]]);
        $variants = collect(range(1, 40))->map(fn () => app(HybridRecommender::class)->variantFor($this->makeUser()))->countBy();
        $this->assertGreaterThan(5, $variants['rules'] ?? 0);
        $this->assertGreaterThan(5, $variants['hybrid'] ?? 0);
    }

    public function test_switching_ai_off_returns_the_rule_engine_alone(): void
    {
        $this->makeProgram();
        $me = $this->makeEmployee();
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/ai', ['enabled' => false, 'reason' => 'test'])->assertOk();
        $rows = $this->asUser($me->user)->getJson('/api/v1/me/recommendations')->assertOk()->json('data');
        foreach ($rows as $r) {
            $this->assertArrayNotHasKey('variant', $r);
        }
        $this->asUser($me->user)->postJson('/api/v1/me/assistant/messages', ['message' => 'hello'])->assertForbidden();
    }

    // ---- assessment feedback -----------------------------------------------------------------------

    private function exam(array $questions, $program): Assessment
    {
        $a = Assessment::create(['program_id' => $program->id, 'kind' => 'quiz', 'title_ar' => 'ا', 'title_en' => 'A', 'max_attempts' => 3, 'pass_percent' => 50, 'status' => 'published', 'feedback_mode' => 'immediate', 'show_score' => true, 'show_correct_answers' => true]);
        $a->sections()->create(['selection' => 'fixed', 'question_ids' => array_map(fn ($q) => $q->id, $questions), 'sort_order' => 0]);

        return $a;
    }

    private function take(User $user, Assessment $a, array $answersByStem): array
    {
        $s = $this->asUser($user)->postJson("/api/v1/me/assessments/{$a->id}/start")->assertOk()->json('data');
        $answers = [];
        foreach ($s['questions'] as $q) {
            $answers[$q['id']] = $answersByStem[$q['stem_en']] ?? null;
        }
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$s['id']}/answers", ['answers' => array_filter($answers, fn ($v) => $v !== null)])->assertOk();
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$s['id']}/submit")->assertOk();

        return $s;
    }

    public function test_objective_feedback_explains_the_mistake_and_shows_how_common_it_is(): void
    {
        [$program, , $lesson, $user] = $this->course();
        $bank = QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
        $skill = Skill::create(['code' => 'CM', 'name_ar' => 'إدارة الصف', 'name_en' => 'Classroom management', 'category' => 'pedagogy']);
        $q = Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => 'ما أساس إدارة الصف؟', 'stem_en' => 'What is the basis of classroom management?', 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active', 'skill_ids' => [$skill->id],
            'explanation_en' => 'Clear rules come first.', 'payload' => ['options' => [['id' => 'a', 'text_ar' => 'قواعد واضحة', 'text_en' => 'Clear rules', 'correct' => true], ['id' => 'b', 'text_ar' => 'العقوبة', 'text_en' => 'Punishment', 'correct' => false]]]]);
        $exam = $this->exam([$q], $program);
        foreach (range(1, 6) as $i) {   // six colleagues who also chose the wrong answer
            $c = $this->makeEmployee();
            Registration::create(['program_id' => $program->id, 'employee_id' => $c->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
            $this->take($c->user, $exam, ['What is the basis of classroom management?' => 'b']);
        }
        $s = $this->take($user, $exam, ['What is the basis of classroom management?' => 'b']);

        $f = $this->asUser($user)->getJson("/api/v1/me/attempts/{$s['id']}/smart-feedback")->assertOk()->json('data');
        $this->assertTrue($f['available']);
        $row = $f['questions'][0];
        $this->assertFalse($row['correct']);
        $this->assertSame('Punishment', $row['your_choice']['en']);
        $this->assertSame('Clear rules', $row['right_choice']['en']);
        $this->assertSame('Clear rules come first.', $row['explanation_en']);
        $this->assertSame(100, $row['same_mistake_percent']);
        $this->assertSame('Classroom management', $row['skills'][0]['en']);
        $this->assertSame($lesson->id, $f['review'][0]['lesson_id']);                  // "what to review" points into the course
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/me/attempts/{$s['id']}/smart-feedback")->assertNotFound();
    }

    public function test_feedback_is_withheld_when_the_assessment_hides_answers(): void
    {
        [$program, , , $user] = $this->course();
        $bank = QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
        $q = Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => 'س', 'stem_en' => 'Q1', 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active', 'payload' => ['options' => [['id' => 'a', 'text_ar' => 'ص', 'correct' => true], ['id' => 'b', 'text_ar' => 'خ', 'correct' => false]]]]);
        $exam = $this->exam([$q], $program);
        $exam->update(['feedback_mode' => 'never', 'show_correct_answers' => false]);
        $s = $this->take($user, $exam, ['Q1' => 'b']);
        $this->asUser($user)->getJson("/api/v1/me/attempts/{$s['id']}/smart-feedback")->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_an_essay_gets_a_draft_for_the_grader_and_is_never_graded_by_itself(): void
    {
        [$program, , , $user] = $this->course();
        $grader = $this->makeUser(Role::TRAINER);
        $bank = QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
        $essay = Question::create(['bank_id' => $bank->id, 'type' => 'essay', 'stem_ar' => 'اشرح', 'stem_en' => 'Explain classroom rules', 'difficulty' => 'easy', 'points' => 10, 'version' => 1, 'status' => 'active',
            'payload' => ['min_words' => 5, 'rubric' => [['id' => 'r1', 'text' => 'mentions clear rules', 'points' => 6], ['id' => 'r2', 'text' => 'mentions positive reinforcement', 'points' => 4]]]]);
        $exam = $this->exam([$essay], $program);
        $s = $this->asUser($user)->postJson("/api/v1/me/assessments/{$exam->id}/start")->assertOk()->json('data');
        $this->asUser($user)->putJson("/api/v1/me/attempts/{$s['id']}/answers", ['answers' => [$s['questions'][0]['id'] => 'Teachers should set clear rules at the start and keep them consistent.']])->assertOk();
        $this->asUser($user)->postJson("/api/v1/me/attempts/{$s['id']}/submit")->assertOk();

        $at = AssessmentAttempt::find($s['id']);
        $this->assertSame('grading', $at->status);                                       // waiting for a person
        $draft = AiFeedbackDraft::where('attempt_id', $at->id)->firstOrFail();
        $this->assertSame('rules', $draft->source);
        $this->assertEquals(6.0, $draft->suggested_score);                               // only the first criterion is covered
        $this->assertSame('draft', $draft->status);
        $this->assertNull($at->fresh()->score_percent);

        $list = $this->asUser($grader)->getJson("/api/v1/admin/attempts/{$at->id}/ai-feedback")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->asUser($user)->postJson("/api/v1/admin/ai-feedback/{$draft->id}/accept")->assertForbidden();
        $this->asUser($grader)->postJson("/api/v1/admin/ai-feedback/{$draft->id}/edit", ['score' => 11, 'comment' => 'x'])->assertStatus(422);   // above the maximum
        $this->asUser($grader)->postJson("/api/v1/admin/ai-feedback/{$draft->id}/edit", ['score' => 8, 'comment' => 'Good, add reinforcement.'])->assertOk()->assertJsonPath('data.status', 'edited');
        $this->assertSame('graded', $at->fresh()->status);
        $this->assertEquals(80.0, (float) $at->fresh()->score_percent);
        $this->asUser($grader)->postJson("/api/v1/admin/ai-feedback/{$draft->id}/accept")->assertStatus(422);   // already reviewed
    }

    public function test_an_ai_essay_draft_uses_the_model_only_when_it_may_and_is_still_a_draft(): void
    {
        [$program, , , $user] = $this->course();
        $bank = QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
        $essay = Question::create(['bank_id' => $bank->id, 'type' => 'essay', 'stem_ar' => 'اشرح', 'stem_en' => 'Explain', 'difficulty' => 'easy', 'points' => 10, 'version' => 1, 'status' => 'active', 'payload' => ['rubric' => [['id' => 'r1', 'text' => 'rules', 'points' => 10]]]]);
        $exam = $this->exam([$essay], $program);
        $this->model('external');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['feedback' => 'Good start.', 'criteria' => [['id' => 'r1', 'met' => 0.7, 'comment' => 'ok']]])]]]])]);
        $this->take($user, $exam, ['Explain' => 'Rules are important. Contact me at teacher@moe.gov.qa']);
        $this->assertSame('rules', AiFeedbackDraft::first()->source);                    // external model blocked for student text
        Http::assertNothingSent();

        AiFeedbackDraft::query()->delete();
        AssessmentAttempt::query()->update(['status' => 'in_progress']);
        $this->model('qatar');
        AssessmentAttempt::query()->each(fn ($a) => $a->update(['status' => 'grading']));
        $d = AiFeedbackDraft::first();
        $this->assertSame('ai', $d->source);
        $this->assertEquals(7.0, $d->suggested_score);
        $this->assertSame('draft', $d->status);
        Http::assertSent(fn ($r) => ! str_contains(json_encode($r->data()), 'teacher@moe.gov.qa'));
    }

    // ---- adaptive learning -------------------------------------------------------------------------

    public function test_mastery_skips_mastered_modules_with_a_record_and_adds_remedial_lessons_for_weak_skills(): void
    {
        [$program, $module, $lesson, $user, $reg] = $this->course();
        $skill = Skill::create(['code' => 'S1', 'name_ar' => 'مهارة', 'name_en' => 'Questioning', 'category' => 'x']);
        $weak = Skill::create(['code' => 'S2', 'name_ar' => 'ضعف', 'name_en' => 'Feedback', 'category' => 'x']);
        $m2 = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'ثانٍ', 'title_en' => 'Second', 'sort_order' => 1]);
        $skippable = CourseLesson::create(['program_id' => $program->id, 'module_id' => $m2->id, 'type' => 'article', 'title_ar' => 'ت', 'title_en' => 'Basics of questioning', 'sort_order' => 0, 'status' => 'published', 'is_required' => true]);
        $remedial = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'ع', 'title_en' => 'Feedback refresher', 'sort_order' => 5, 'status' => 'published', 'is_required' => false]);
        $trainer = $this->makeUser(Role::TRAINER);

        $this->asUser($user)->putJson("/api/v1/admin/programs/{$program->id}/adaptive-rules", ['rules' => []])->assertForbidden();
        $this->asUser($trainer)->putJson("/api/v1/admin/programs/{$program->id}/adaptive-rules", ['rules' => [['skill_id' => $skill->id, 'action' => 'skip_module']]])->assertStatus(422);
        $this->asUser($trainer)->putJson("/api/v1/admin/programs/{$program->id}/adaptive-rules", ['rules' => [
            ['skill_id' => $skill->id, 'action' => 'skip_module', 'module_id' => $m2->id, 'skip_at' => 0.8],
            ['skill_id' => $weak->id, 'action' => 'add_lesson', 'lesson_id' => $remedial->id, 'remedial_below' => 0.5],
        ]])->assertOk();
        $this->assertSame(2, AdaptiveRule::where('program_id', $program->id)->count());

        // A pre-test: both skills asked about; strong on one, weak on the other.
        $bank = QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
        $mk = fn (string $stem, string $skillId) => Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => $stem, 'stem_en' => $stem, 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active', 'skill_ids' => [$skillId],
            'payload' => ['options' => [['id' => 'a', 'text_ar' => 'ص', 'correct' => true], ['id' => 'b', 'text_ar' => 'خ', 'correct' => false]]]]);
        $exam = $this->exam([$mk('Q-strong-1', $skill->id), $mk('Q-strong-2', $skill->id), $mk('Q-weak', $weak->id)], $program);
        $this->take($user, $exam, ['Q-strong-1' => 'a', 'Q-strong-2' => 'a', 'Q-weak' => 'b']);

        $this->assertEquals(1.0, (float) LearnerMastery::where(['registration_id' => $reg->id, 'skill_id' => $skill->id])->value('mastery'));
        $this->assertEquals(0.0, (float) LearnerMastery::where(['registration_id' => $reg->id, 'skill_id' => $weak->id])->value('mastery'));
        $this->assertSame('completed', LessonProgress::where(['registration_id' => $reg->id, 'lesson_id' => $skippable->id])->value('status'));   // test-out recorded
        $this->assertNull(LessonProgress::where(['registration_id' => $reg->id, 'lesson_id' => $lesson->id])->value('status'));
        $this->assertSame(1, AuditLog::where('action', 'adaptive_skip')->where('auditable_id', $reg->id)->count());

        $path = $this->asUser($user)->getJson("/api/v1/me/courses/{$reg->id}/path")->assertOk()->json('data');
        $states = collect($path['modules'])->pluck('state', 'module_id');
        $this->assertSame('skipped', $states[$m2->id]);
        $this->assertSame('required', $states[$module->id]);
        $this->assertSame($remedial->id, $path['remedial'][0]['lesson_id']);
        $this->assertSame('weak', $path['remedial'][0]['reason']);
        $this->assertSame(0, app(AdaptivePath::class)->apply($reg->fresh()));              // nothing is skipped twice
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/me/courses/{$reg->id}/path")->assertNotFound();
    }

    public function test_remedial_content_is_a_draft_for_the_trainer_to_review(): void
    {
        [$program] = $this->course();
        $skill = Skill::create(['code' => 'S9', 'name_ar' => 'إدارة الصف', 'name_en' => 'Classroom management', 'category' => 'x']);
        $trainer = $this->makeUser(Role::TRAINER);
        $id = $this->asUser($trainer)->postJson("/api/v1/admin/programs/{$program->id}/adaptive/remedial", ['skill_id' => $skill->id])->assertCreated()->json('data.lesson_id');
        $lesson = CourseLesson::find($id);
        $this->assertSame('draft', $lesson->status);                                      // reaches learners only after the trainer publishes it
        $this->assertTrue($lesson->settings['remedial']);
        $this->assertFalse($lesson->settings['ai_generated']);
        $this->assertStringContainsString('review', strtolower($lesson->body_en));

        $this->model('qatar');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['summary_ar' => 'ملخص', 'summary_en' => 'Summary', 'key_points_ar' => ['أ'], 'key_points_en' => ['A'], 'practice' => ['Try this']])]]]])]);
        $id2 = $this->asUser($trainer)->postJson("/api/v1/admin/programs/{$program->id}/adaptive/remedial", ['skill_id' => $skill->id])->assertCreated()->assertJsonPath('data.ai_generated', true)->json('data.lesson_id');
        $this->assertSame('draft', CourseLesson::find($id2)->status);
        $this->assertStringContainsString('Summary', CourseLesson::find($id2)->body_en);
    }

    // ---- forecasts and risks -----------------------------------------------------------------------

    public function test_forecast_functions_on_synthetic_series(): void
    {
        $up = ForecastService::project([10, 12, 14, 16, 18]);
        $this->assertSame('ets', $up['model']);
        $this->assertGreaterThan(18, $up['value']);
        $this->assertGreaterThanOrEqual($up['value'], $up['high']);
        $this->assertGreaterThanOrEqual(0, $up['low']);
        $this->assertLessThan($up['value'], $up['low']);

        $flat = ForecastService::project([20, 20, 20, 20]);
        $this->assertEqualsWithDelta(20, $flat['value'], 1.5);
        $this->assertSame('naive', ForecastService::project([5])['model']);
        $this->assertSame(0.0, ForecastService::project([])['value']);
        $this->assertGreaterThanOrEqual(0, ForecastService::project([10, 4, 1, 0])['value']);   // never negative
    }

    public function test_forecasts_are_built_from_needs_history_explained_and_added_to_a_draft_plan(): void
    {
        $skill = Skill::create(['code' => 'F1', 'name_ar' => 'تقييم', 'name_en' => 'Assessment literacy', 'category' => 'x']);
        $school = $this->makeSchool();
        $submitter = $this->makeUser(Role::SCHOOL_ADMIN);
        $year = (int) now()->year;
        foreach ([[$year - 3, 4], [$year - 2, 6], [$year - 1, 8]] as [$y, $n]) {
            $need = TrainingNeed::create(['school_id' => $school->id, 'submitted_by' => $submitter->id, 'skill_id' => $skill->id, 'skill_name' => 'Assessment literacy', 'employees_count' => $n, 'priority' => 'medium', 'reason' => 'Needed', 'status' => 'submitted']);
            $need->forceFill(['created_at' => Carbon::create($y, 6, 1)])->saveQuietly();
        }
        $planner = $this->makeUser(Role::PLANNING_HEAD);
        $this->asUser($this->makeUser())->getJson('/api/v1/admin/forecasts')->assertForbidden();
        $this->asUser($planner)->postJson('/api/v1/admin/forecasts/run')->assertOk();
        $rows = $this->asUser($planner)->getJson('/api/v1/admin/forecasts?dimension=competency')->assertOk()->json('data');
        $this->assertSame('Assessment literacy', $rows[0]['label']);
        $this->assertGreaterThan(8, $rows[0]['value']);
        $this->assertSame('ets', $rows[0]['model']);
        $this->assertStringContainsString('smoothing', $rows[0]['explanation']);
        $this->assertEquals([4, 6, 8], $rows[0]['history']['values']);
        $this->assertGreaterThan(0, $rows[0]['confidence']);
        $this->assertNotEmpty($this->asUser($planner)->getJson('/api/v1/admin/forecasts?dimension=school')->json('data'));

        $plan = TrainingPlan::create(['year' => $year + 1, 'version' => 1, 'title_ar' => 'خطة', 'title_en' => 'Plan', 'status' => 'draft']);
        $r = $this->asUser($planner)->postJson('/api/v1/admin/forecasts/to-plan', ['plan_id' => $plan->id, 'forecast_ids' => [$rows[0]['id']]])->assertCreated();
        $item = TrainingPlanItem::find($r->json('data.added.0'));
        $this->assertSame('forecast', $item->source);
        $this->assertSame($rows[0]['id'], $item->source_refs['forecast_id']);
        $this->assertGreaterThan(0, $item->planned_seats);
        $this->assertSame(1, Forecast::where('dimension', 'competency')->count());
    }

    public function test_risk_flags_are_raised_with_reasons_and_resolved_when_they_clear(): void
    {
        $program = $this->makeProgram();
        $group = TrainingGroup::where('program_id', $program->id)->first() ?? TrainingGroup::create(['program_id' => $program->id, 'code' => 'G1', 'title_ar' => 'م', 'title_en' => 'G', 'sequence' => 1, 'delivery_mode' => 'online', 'start_date' => now()->addDays(10), 'end_date' => now()->addDays(14), 'capacity' => 20, 'status' => 'published']);
        $group->update(['start_date' => now()->addDays(10), 'end_date' => now()->addDays(14), 'capacity' => 20]);
        $res = app(RiskService::class)->run();
        $this->assertGreaterThanOrEqual(1, $res['raised']);
        $planner = $this->makeUser(Role::PLANNING_HEAD);
        $risks = $this->asUser($planner)->getJson('/api/v1/admin/risks?type=group_underfill')->assertOk()->json('data');
        $this->assertSame($group->id, $risks[0]['subject_id']);
        $this->assertStringContainsString('0 of 20 seats', $risks[0]['reasons'][0]);

        foreach (range(1, 10) as $i) {
            Registration::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        }
        $res2 = app(RiskService::class)->run();
        $this->assertGreaterThanOrEqual(1, $res2['resolved']);
        $this->assertCount(0, $this->asUser($planner)->getJson('/api/v1/admin/risks?type=group_underfill')->json('data'));
        $this->assertNotNull(RiskFlag::where('subject_id', $group->id)->value('resolved_at'));   // kept for history
    }

    public function test_the_nightly_command_runs_everything(): void
    {
        $this->course();
        $this->artisan('tedc:ai-nightly', ['--forecasts' => true])->assertSuccessful();
        $this->assertGreaterThan(0, Embedding::count());
    }
}
