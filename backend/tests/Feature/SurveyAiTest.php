<?php

namespace Tests\Feature;

use App\Ai\AiPolicy;
use App\Models\AiLog;
use App\Models\Role;
use App\Models\Survey;
use App\Models\SurveyAiInsight;
use App\Models\SurveyEvent;
use App\Services\Ai\AiModelSettings;
use App\Services\FeatureSettings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Survey Studio v2, Gate 8: AI summaries of opted-in open text (redacted, logged, kept) and question suggestions. */
class SurveyAiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        app(FeatureSettings::class)->set('survey_studio_v2', true, 'test', $admin);
        app(FeatureSettings::class)->set('ai', true, 'test', $admin);
        Cache::flush();
    }

    private function model(string $residency): void
    {
        $settings = app(AiModelSettings::class);
        $c = $settings->saveConnection(['name' => 'Test', 'driver' => 'custom', 'base_url' => 'https://ai.test/v1', 'api_key' => 'sk-test', 'data_residency' => $residency]);
        $m = $settings->saveModel(['connection_id' => $c['id'], 'model' => 'gpt-test', 'tasks' => ['text']]);
        $settings->assign(['text' => $m['id']]);
        Cache::flush();
    }

    private function publish($admin, array $data): Survey
    {
        $id = $this->asUser($admin)->postJson('/api/v1/admin/surveys', $data + ['audience' => [], 'questions' => [['id' => 'sat', 'type' => 'rating', 'title' => 'الرضا', 'scale' => ['min' => 1, 'max' => 5]], ['id' => 'why', 'type' => 'long_text', 'title' => 'ما الذي ينقص التدريب؟'], ['id' => 'mail', 'type' => 'email', 'title' => 'بريدك']]])->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$id}/publish")->assertOk();

        return Survey::findOrFail($id);
    }

    public function test_summaries_need_the_opt_in_a_permitted_model_and_enough_text_and_send_only_redacted_answers(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $people = collect(range(1, 4))->map(fn () => $this->makeEmployee());
        $survey = $this->publish($admin, ['title_ar' => 'معرّفة', 'privacy_mode' => 'identified']);
        $texts = ['الوقت قصير جداً ولا يكفي للتطبيق', 'المدرب ممتاز لكن القاعة حارة', 'نحتاج أمثلة عملية أكثر', 'لا شيء يُذكر'];
        foreach ($people as $i => $e) {
            $this->asUser($e->user)->postJson("/api/v1/me/studio-surveys/{$survey->id}", ['answers' => ['sat' => 4, 'why' => $texts[$i].' — '.$e->user->name, 'mail' => 'x@example.test']])->assertOk();
        }
        // Not opted in: refused before any model is touched.
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/summaries")->assertStatus(422)->assertJsonPath('code', 'survey_ai_opt_out');
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/{$survey->id}", ['settings' => ['ai_opt_in' => true]])->assertOk();
        $this->assertTrue($this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/ai")->assertOk()->json('data.opt_in'));

        // No model assigned: unavailable, logged as a fallback, nothing stored.
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/summaries")->assertStatus(422)->assertJsonPath('code', 'survey_ai_unavailable');
        $this->assertSame(0, SurveyAiInsight::count());

        // An external model with residency enforced may not see personal text.
        $this->model('external');
        $this->assertFalse($this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/ai")->json('data.available'));
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/summaries")->assertStatus(422)->assertJsonPath('code', 'survey_ai_unavailable');
        $this->assertSame('residency', AiLog::where('feature', 'surveys')->latest('created_at')->value('reason'));

        // A model inside Qatar: the call goes out with the respondents' names and the e-mail hidden, the result is kept and recorded.
        $this->model('qatar');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'summary' => 'يطلب المشاركون وقتاً أطول وأمثلة عملية.', 'sentiment' => ['positive' => 1, 'neutral' => 1, 'negative' => 2],
            'themes' => [['title' => 'الوقت', 'count' => 2, 'quotes' => ['الوقت قصير جداً']], ['title' => 'التطبيق العملي', 'count' => 1, 'quotes' => ['نحتاج أمثلة عملية أكثر']]],
            'recommendations' => ['إطالة مدة الورشة', 'إضافة تطبيقات عملية'],
        ], JSON_UNESCAPED_UNICODE)]]]])]);
        $r = $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/summaries", ['question_id' => 'why'])->assertCreated()->json('data');
        $this->assertSame(['summary', 'why', 4], [$r['kind'], $r['question_id'], $r['answers_count']]);
        $this->assertSame(50, $r['payload']['themes'][0]['share']);
        $this->assertSame('الوقت قصير جداً', $r['payload']['themes'][0]['quotes'][0]);
        $this->assertGreaterThanOrEqual(1, $r['redactions']);
        Http::assertSent(function ($request) use ($people) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'الوقت قصير') && ! str_contains($body, $people[0]->user->name) && ! str_contains($body, 'x@example.test');
        });
        $this->assertSame(1, SurveyAiInsight::where('survey_id', $survey->id)->count());
        $this->assertSame(1, SurveyEvent::where('survey_id', $survey->id)->where('event', 'ai_summarised')->count());
        $this->assertSame('ok', AiLog::where('feature', 'surveys')->latest('created_at')->value('status'));
        $this->assertCount(1, $this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/ai")->json('data.insights'));

        // Too few answers on a fresh survey; a viewer without results rights is refused.
        $few = $this->publish($admin, ['title_ar' => 'قليلة', 'privacy_mode' => 'anonymous', 'settings' => ['ai_opt_in' => true]]);
        $this->asUser($people[0]->user)->postJson("/api/v1/me/studio-surveys/{$few->id}", ['answers' => ['why' => 'نص واحد']])->assertOk();
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$few->id}/ai/summaries")->assertStatus(422)->assertJsonPath('code', 'survey_ai_too_few');
        $this->asUser($this->makeEmployee()->user)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/summaries")->assertForbidden();

        // The AI policy can switch the feature off for surveys alone.
        app(AiPolicy::class)->save(['features' => ['surveys' => ['enabled' => false]]]);
        Cache::flush();
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/summaries")->assertStatus(422)->assertJsonPath('code', 'survey_ai_unavailable');
    }

    public function test_question_suggestions_come_back_normalised_in_the_engine_format(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $survey = $this->publish($admin, ['title_ar' => 'رضا المتدربين']);
        $this->model('external'); // nothing personal is sent, so an external model may answer
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode(['questions' => [
            ['type' => 'single', 'title' => 'ما الصيغة الأنسب؟', 'title_en' => 'Best format?', 'required' => true, 'options' => ['حضوري', 'عن بعد', 'مدمج']],
            ['type' => 'matrix', 'title' => 'قيّم الجوانب', 'rows' => ['المحتوى', 'المدرب'], 'scale' => ['min' => 1, 'max' => 5]],
            ['type' => 'file_upload', 'title' => 'ليس مسموحاً'],
            ['type' => 'single', 'title' => 'بلا خيارات'],
        ]], JSON_UNESCAPED_UNICODE)]]]])]);
        $r = $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/suggestions", ['goal' => 'قياس رضا المتدربين عن ورشة التقويم'])->assertCreated()->json('data');
        $qs = $r['payload']['questions'];
        $this->assertCount(2, $qs, 'a disallowed type and an invalid question are dropped');
        $this->assertSame(['single', 'matrix'], array_column($qs, 'type'));
        $this->assertSame('o1', $qs[0]['options'][0]['id']);
        $this->assertSame('المحتوى', $qs[1]['rows'][0]['label']);
        $this->assertSame(1, SurveyEvent::where('survey_id', $survey->id)->where('event', 'ai_suggested')->count());
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/ai/suggestions", ['goal' => 'x'])->assertStatus(422);
    }
}
