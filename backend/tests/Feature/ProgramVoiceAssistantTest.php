<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\Role;
use App\Models\Trainer;
use App\Services\Ai\AiModelSettings;
use App\Services\FeatureSettings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The spoken / typed order to create a programme: read by the rules in Arabic and English, refined by a permitted model with names hidden, and applied to a draft the person verifies; English fields optional; the audience saved with the programme. */
class ProgramVoiceAssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
    }

    private function trainer(string $ar, string $en): Trainer
    {
        return Trainer::create(['name_ar' => $ar, 'name_en' => $en, 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4]);
    }

    public function test_arabic_and_english_orders_are_read_by_the_rules_with_the_database_as_the_judge(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $noora = $this->trainer('د. نورة المهندي', 'Dr. Noora Al-Mohannadi');
        $this->trainer('د. خالد السليطي', 'Dr. Khalid Al-Sulaiti');
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');

        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'برنامج'])->assertForbidden();
        $this->asUser($admin)->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'ب'])->assertStatus(422);
        $this->assertFalse($this->asUser($admin)->getJson('/api/v1/admin/programs/assistant')->assertOk()->json('data.ai_available'));

        $r = $this->asUser($admin)->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'أنشئ برنامج التقويم من أجل التعلم للمعلمين بخبرة من ٣ إلى ٥ سنوات، إناث، مع المدربة نورة المهندي إن توفرت، اثنتي عشرة ساعة، 25 متدربة، حضوري', 'source' => 'voice'])->assertOk()->json('data');
        $this->assertSame(['rules', 'ar', []], [$r['engine'], $r['language'], $r['missing']]);
        $d = $r['draft'];
        $this->assertSame('التقويم من أجل التعلم', $d['topic']); // the person's own spelling, not the normalised one
        $this->assertSame([$teacher], $d['job_title_ids']);
        $this->assertSame(['female'], $d['genders']);
        $this->assertSame([3.0, 5.0], [(float) $d['experience_min'], (float) $d['experience_max']]);
        $this->assertSame([$noora->id, true], [$d['trainer']['id'], $d['trainer']['optional']]);
        $this->assertSame([12.0, 25, 'in_person'], [(float) $d['total_hours'], $d['capacity'], $d['delivery_mode']]);
        $this->assertSame('برنامج التقويم من أجل التعلم', $r['program']['title_ar']);
        $this->assertSame([['id' => $noora->id, 'role' => 'lead']], $r['program']['trainers']);
        $this->assertEquals(['job_title_ids' => [$teacher], 'genders' => ['female'], 'experience_min' => 3, 'experience_max' => 5], $r['program']['audience']);
        $this->assertStringContainsString('من 3 إلى 5 سنوات', $r['understood']['ar']);
        $this->assertStringContainsString('نورة المهندي', $r['understood']['ar']);
        $this->assertGreaterThanOrEqual(0.9, $r['confidence']);
        $this->assertTrue(AuditLog::where('action', 'program_request_parsed')->where('user_id', $admin->id)->exists());

        $r = $this->asUser($admin)->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'Create a programme on assessment for learning for school leaders and vice principals with more than ten years of experience, male, with trainer Khalid Al Sulaiti, twenty seats, online, next month'])->assertOk()->json('data');
        $d = $r['draft'];
        $this->assertSame('en', $r['language']);
        $this->assertSame('assessment for learning', $d['topic']);
        $this->assertEqualsCanonicalizing(JobTitle::where('category', 'leadership')->pluck('id')->all(), $d['job_title_ids']); // "school leaders" is the leadership category; vice principals are inside it
        $this->assertSame(['male'], $d['genders']);
        $this->assertSame([10.0, null], [(float) $d['experience_min'], $d['experience_max']]);
        $this->assertSame('د. خالد السليطي', $d['trainer']['name']);
        $this->assertSame([20, 'online'], [$d['capacity'], $d['delivery_mode']]);
        $this->assertSame(today()->addMonthNoOverflow()->startOfMonth()->toDateString(), $d['start_date']);
        $this->assertSame('Assessment For Learning Programme', $r['program']['title_en']);
        $this->assertTrue($r['program']['english']);

        // Unknown trainer and no audience: reported, never guessed.
        $r = $this->asUser($admin)->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'برنامج الصحة النفسية مع المدرب سعيد الغامدي 8 ساعات'])->assertOk()->json('data');
        $this->assertSame(['audience'], $r['missing']);
        $this->assertNull($r['draft']['trainer']['id']);
        $this->assertSame('trainer_not_found', $r['notes'][0]['code']);
        $this->assertSame([], $r['program']['trainers']);
    }

    public function test_a_permitted_model_refines_the_reading_with_names_hidden_and_a_blocked_one_leaves_the_rules_alone(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        app(FeatureSettings::class)->set('ai', true, 'test', $admin);
        $noora = $this->trainer('د. نورة المهندي', 'Dr. Noora Al-Mohannadi');
        $teacher = JobTitle::where('code', 'TEACHER');
        $settings = app(AiModelSettings::class);
        $model = function (string $residency) use ($settings) {
            $c = $settings->saveConnection(['name' => 'Test', 'driver' => 'custom', 'base_url' => 'https://ai.test/v1', 'api_key' => 'sk-test', 'data_residency' => $residency]);
            $m = $settings->saveModel(['connection_id' => $c['id'], 'model' => 'gpt-test', 'tasks' => ['text']]);
            $settings->assign(['text' => $m['id']]);
            Cache::flush();
        };

        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'topic' => 'التقويم من أجل التعلم', 'title_ar' => 'التقويم من أجل التعلم: الممارسات الصفية', 'title_en' => 'Assessment for Learning: Classroom Practices',
            'summary_ar' => 'برنامج يطوّر ممارسات التقويم التكويني.', 'summary_en' => 'Develops formative assessment practices.',
            'job_title_codes' => ['TEACHER'], 'genders' => ['female'], 'experience_min' => 3, 'experience_max' => 5, 'trainer_name' => '[NAME_1]', 'trainer_optional' => true, 'total_hours' => 12, 'capacity' => 25, 'delivery_mode' => 'in_person', 'level' => 'intermediate', 'start_date' => null,
        ], JSON_UNESCAPED_UNICODE)]]]])]);

        // An external model may not see the spoken text: the rules alone answer, and the status says so.
        $model('external');
        $this->assertFalse($this->asUser($admin)->getJson('/api/v1/admin/programs/assistant')->json('data.ai_available'));
        $this->assertSame('rules', $this->asUser($admin)->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'برنامج التقويم للمعلمين مع المدربة نورة المهندي'])->assertOk()->json('data.engine'));
        Http::assertNothingSent();

        // A model inside Qatar refines: a polished title, a summary, the audience by code; the trainer's name never leaves.
        $model('qatar');
        $r = $this->asUser($admin)->postJson('/api/v1/admin/programs/assistant/parse', ['text' => 'أنشئ برنامج التقويم من أجل التعلم للمعلمات بخبرة من 3 إلى 5 سنوات مع المدربة نورة المهندي إن توفرت'])->assertOk()->json('data');
        $this->assertSame('ai+rules', $r['engine']);
        $this->assertSame('التقويم من أجل التعلم: الممارسات الصفية', $r['program']['title_ar']);
        $this->assertSame('Assessment for Learning: Classroom Practices', $r['program']['title_en']);
        $this->assertSame([$teacher->value('id')], $r['draft']['job_title_ids']);
        $this->assertSame($noora->id, $r['draft']['trainer']['id']);
        $this->assertSame('gpt-test', $r['draft']['model']);
        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'التقويم من أجل التعلم') && str_contains($body, 'TEACHER') && ! str_contains($body, 'نورة المهندي') && ! str_contains($body, 'Noora');
        });
    }

    public function test_english_is_optional_and_the_audience_is_saved_with_the_programme(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', ['code' => 'VOICE-1', 'title_ar' => 'برنامج التقويم من أجل التعلم', 'title_en' => '', 'total_hours' => 12, 'capacity' => 25,
            'target_groups' => [['job_title_id' => $teacher]], 'audience' => ['job_title_ids' => [$teacher], 'genders' => ['female'], 'experience_min' => 3, 'experience_max' => 5]])->assertCreated()->json('data.id');
        $p = Program::with('eligibilityRules')->findOrFail($id);
        $this->assertSame('برنامج التقويم من أجل التعلم', $p->title_en); // Arabic copied when English is left empty
        $this->assertSame(['female'], $p->audience['genders']);
        $this->assertSame(['job_title', 'gender', 'experience_years', 'experience_years'], $p->eligibilityRules->pluck('field')->all());
        $this->assertTrue($p->eligibilityRules->every(fn ($r) => $r->is_generated));

        $this->asUser($admin)->putJson("/api/v1/admin/programs/$id", ['title_en' => 'Assessment for Learning', 'audience' => ['job_title_ids' => [$teacher]]])->assertOk();
        $p->refresh()->load('eligibilityRules');
        $this->assertSame('Assessment for Learning', $p->title_en);
        $this->assertSame(['job_title'], $p->eligibilityRules->pluck('field')->all());
    }
}
