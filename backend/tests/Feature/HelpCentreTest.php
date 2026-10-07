<?php

namespace Tests\Feature;

use App\Help\HelpService;
use App\Models\HelpArticle;
use App\Models\Role;
use Database\Seeders\HelpArticlesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HelpCentreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(HelpArticlesSeeder::class);
    }

    public function test_seeded_articles_are_bilingual_and_cover_every_manual_role_group(): void
    {
        $this->assertGreaterThanOrEqual(28, HelpArticle::count());
        foreach (HelpArticle::all() as $a) {
            $this->assertNotSame('', trim((string) $a->title_ar), $a->slug);
            $this->assertNotSame('', trim(strip_tags((string) $a->body_en)), $a->slug);
            $this->assertNotSame('', trim(strip_tags((string) $a->body_ar)), $a->slug);
        }
        foreach ([Role::EMPLOYEE, Role::TRAINER, Role::COORDINATOR, Role::SCHOOL_ADMIN, Role::PLANNING_HEAD, Role::LOGISTICS_OFFICER, Role::CENTER_LEADERSHIP, Role::KIT_DEVELOPER, Role::QA_REVIEWER, Role::FINANCE_OFFICER] as $role) {
            $this->assertGreaterThan(3, app(HelpService::class)->manualArticles($role)->count(), $role);
        }
        $this->seed(HelpArticlesSeeder::class);                       // running it again never duplicates
        $this->assertSame(HelpArticle::count(), HelpArticle::distinct('slug')->count('slug'));
    }

    public function test_articles_follow_the_role_and_the_page(): void
    {
        $trainee = $this->makeUser(Role::EMPLOYEE);
        $list = $this->asUser($trainee)->getJson('/api/v1/me/help/articles')->assertOk()->json('data');
        $slugs = array_column($list, 'slug');
        $this->assertContains('getting-started', $slugs);               // for everyone
        $this->assertContains('register-for-a-program', $slugs);
        $this->assertNotContains('trainer-tasks-grading', $slugs);      // trainers only
        $this->assertNotContains('users-roles', $slugs);

        $page = $this->asUser($trainee)->getJson('/api/v1/me/help/articles?route=/learn/abc-123?x=1')->assertOk()->json('data');
        $this->assertSame(['taking-an-e-course'], array_column($page, 'slug'));   // wildcard route, query string ignored

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->assertContains('trainer-tasks-grading', array_column($this->asUser($admin)->getJson('/api/v1/me/help/articles')->json('data'), 'slug'));

        $this->asUser($trainee)->getJson('/api/v1/me/help/articles/trainer-tasks-grading')->assertStatus(422)->assertJsonPath('code', 'help_not_found');
        $this->asUser($trainee)->getJson('/api/v1/me/help/articles/register-for-a-program')->assertOk()->assertJsonPath('data.slug', 'register-for-a-program');
    }

    public function test_search_ranks_title_matches_first_in_both_languages(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE);
        $en = $this->asUser($user)->getJson('/api/v1/me/help/articles?q=certificates')->assertOk()->json('data');
        $this->assertSame('certificates-and-verification', $en[0]['slug']);
        $ar = $this->asUser($user)->getJson('/api/v1/me/help/articles?q='.urlencode('الإشعارات'))->assertOk()->json('data');
        $this->assertSame('notifications-and-preferences', $ar[0]['slug']);
        $this->assertSame([], $this->asUser($user)->getJson('/api/v1/me/help/articles?q=zzzzqqqq')->json('data'));
    }

    public function test_the_support_channels_and_service_levels_are_listed(): void
    {
        $r = $this->asUser($this->makeUser())->getJson('/api/v1/me/help/articles')->assertOk();
        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($r->json('support.sla'), 'priority'));
        $this->assertSame(['phone', 'email', 'saaed'], array_column($r->json('support.channels'), 'key'));
    }

    public function test_editing_sanitises_versions_and_rolls_back(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/help/articles', [
            'slug' => 'my-new-article', 'title_ar' => 'مقالة', 'title_en' => 'Article', 'body_en' => '<p>Hello</p><script>alert(1)</script><img src="x" onerror="alert(2)">', 'body_ar' => '<p>مرحبا</p>',
            'roles' => [Role::TRAINER], 'related_routes' => ['tasks'], 'status' => 'published',
        ])->assertCreated()->json('data.id');
        $a = HelpArticle::find($id);
        $this->assertStringNotContainsString('<script', $a->body_en);
        $this->assertStringNotContainsString('onerror', $a->body_en);
        $this->assertSame(['/tasks'], $a->related_routes);
        $this->assertSame(1, $a->version);

        $this->asUser($admin)->putJson("/api/v1/admin/help/articles/{$id}", ['body_en' => '<p>Second</p>'])->assertOk()->assertJsonPath('data.version', 2);
        $this->asUser($admin)->putJson("/api/v1/admin/help/articles/{$id}", ['sort_order' => 5])->assertOk()->assertJsonPath('data.version', 2);   // a non-text change keeps the version
        $this->asUser($admin)->postJson("/api/v1/admin/help/articles/{$id}/rollback/1")->assertOk()->assertJsonPath('data.version', 3);
        $this->assertStringContainsString('Hello', HelpArticle::find($id)->body_en);
        $this->assertSame(3, HelpArticle::find($id)->versions()->count());

        $this->asUser($admin)->postJson('/api/v1/admin/help/articles', ['slug' => 'my-new-article', 'title_ar' => 'x', 'title_en' => 'x'])->assertStatus(422);   // slug is unique
        $this->asUser($admin)->postJson('/api/v1/admin/help/articles', ['slug' => 'Bad Slug', 'title_ar' => 'x', 'title_en' => 'x'])->assertStatus(422);
    }

    public function test_only_people_with_the_permission_can_edit(): void
    {
        $trainee = $this->makeUser(Role::EMPLOYEE);
        $this->asUser($trainee)->getJson('/api/v1/admin/help/articles')->assertForbidden();
        $this->asUser($trainee)->postJson('/api/v1/admin/help/articles', ['slug' => 'x', 'title_ar' => 'x', 'title_en' => 'x'])->assertForbidden();
        $this->asUser($trainee)->getJson('/api/v1/admin/help/analytics')->assertForbidden();
    }

    public function test_feedback_is_counted_and_shown_to_authors_worst_first(): void
    {
        $a = HelpArticle::where('slug', 'getting-started')->first();
        $b = HelpArticle::where('slug', 'notifications-and-preferences')->first();
        $u1 = $this->makeUser(Role::EMPLOYEE);
        $u2 = $this->makeUser(Role::EMPLOYEE);
        $this->asUser($u1)->postJson("/api/v1/me/help/articles/{$a->slug}/feedback", ['helpful' => true])->assertCreated();
        $this->asUser($u1)->postJson("/api/v1/me/help/articles/{$a->slug}/feedback", ['helpful' => false, 'comment' => 'unclear'])->assertCreated();   // one vote per person and version
        $this->asUser($u2)->postJson("/api/v1/me/help/articles/{$b->slug}/feedback", ['helpful' => false, 'comment' => 'too short <b>x</b>'])->assertCreated();
        $this->asUser($u2)->postJson("/api/v1/me/help/articles/{$b->slug}/feedback", ['comment' => 'no vote'])->assertStatus(422);

        $rows = $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/help/analytics')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $byslug = collect($rows)->keyBy('slug');
        $this->assertSame(1, $byslug['getting-started']['not_helpful']);
        $this->assertSame(0, $byslug['getting-started']['helpful']);
        $this->assertSame('too short x', $byslug['notifications-and-preferences']['comments'][0]);
    }

    public function test_role_manuals_download_as_pdf_in_both_languages_for_own_role_only(): void
    {
        $trainee = $this->makeUser(Role::EMPLOYEE);
        foreach (['ar', 'en'] as $lang) {
            $r = $this->asUser($trainee)->get("/api/v1/me/help/manuals/employee/pdf?lang={$lang}")->assertOk();
            $this->assertStringStartsWith('%PDF', $r->getContent());
            $this->assertStringContainsString('application/pdf', (string) $r->headers->get('Content-Type'));
        }
        $this->asUser($trainee)->getJson('/api/v1/me/help/manuals/trainer/pdf')->assertStatus(422);
        $this->asUser($trainee)->getJson('/api/v1/me/help/manuals/not-a-role/pdf')->assertStatus(422);
        $names = array_column($this->asUser($trainee)->getJson('/api/v1/me/help/manuals')->json('data'), 'role');
        $this->assertSame(['employee'], $names);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->assertGreaterThan(10, count($this->asUser($admin)->getJson('/api/v1/me/help/manuals')->json('data')));
        $this->asUser($admin)->get('/api/v1/me/help/manuals/trainer/pdf')->assertOk();
    }

    public function test_a_manual_pdf_is_built_from_every_manual_role(): void
    {
        foreach (HelpService::MANUAL_ROLES as $role) {
            $pdf = app(HelpService::class)->manualPdf($role, 'ar');
            $this->assertStringStartsWith('%PDF', $pdf, $role);
        }
    }

    public function test_tours_run_first_login_then_whats_new_then_stop_and_can_replay(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE);
        $t = $this->asUser($user)->getJson('/api/v1/me/tours')->assertOk()->json('data');
        $this->assertSame('first-login:employee', $t['key']);
        $this->assertGreaterThanOrEqual(3, count($t['steps']));
        $this->assertSame('my-training', collect($t['steps'])->last()['target']);

        $this->asUser($user)->postJson('/api/v1/me/tours', ['key' => $t['key'], 'state' => 'dismissed'])->assertCreated();
        $n = $this->asUser($user)->getJson('/api/v1/me/tours')->json('data');
        $this->assertSame('whats-new:'.HelpService::RELEASE, $n['key']);
        $this->asUser($user)->postJson('/api/v1/me/tours', ['key' => $n['key']])->assertCreated();
        $this->assertNull($this->asUser($user)->getJson('/api/v1/me/tours')->json('data'));

        $this->asUser($user)->deleteJson('/api/v1/me/tours')->assertOk();
        $this->assertSame('first-login:employee', $this->asUser($user)->getJson('/api/v1/me/tours')->json('data.key'));
        $this->asUser($user)->postJson('/api/v1/me/tours', ['key' => 'bad key!'])->assertStatus(422);

        $trainer = $this->makeUser(Role::TRAINER);
        $this->assertSame('first-login:trainer', $this->asUser($trainer)->getJson('/api/v1/me/tours')->json('data.key'));
    }

    public function test_screenshots_and_video_attach_to_an_article_and_appear_in_the_manual(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $a = HelpArticle::where('slug', 'getting-started')->first();
        $png = UploadedFile::fake()->image('shot.png', 400, 300);
        $r = $this->asUser($admin)->post("/api/v1/admin/help/articles/{$a->id}/screenshots", ['file' => $png, 'caption_ar' => 'الصفحة الرئيسية', 'caption_en' => 'Home page'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertCount(1, $r->json('data.screenshots'));
        $this->assertNotEmpty($r->json('data.screenshots.0.url'));
        $this->assertNotEmpty(app(HelpService::class)->manualPdf(Role::EMPLOYEE, 'en'));

        $this->asUser($admin)->post("/api/v1/admin/help/articles/{$a->id}/screenshots", ['file' => UploadedFile::fake()->create('evil.php', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asUser($admin)->deleteJson("/api/v1/admin/help/articles/{$a->id}/screenshots/0")->assertOk()->assertJsonCount(0, 'data.screenshots');
        $this->asUser($admin)->post("/api/v1/admin/help/articles/{$a->id}/video", ['file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_video', true);
    }

    public function test_support_values_come_from_the_centre_s_configuration(): void
    {
        config(['tedc.support' => ['phone' => '+974 4000 0000', 'email' => 'help@example.qa', 'saaed_url' => 'https://saaed.example.qa']]);
        $channels = collect($this->asUser($this->makeUser())->getJson('/api/v1/me/help/articles')->json('support.channels'))->keyBy('key');
        $this->assertSame('+974 4000 0000', $channels['phone']['value']);
        $this->assertSame('help@example.qa', $channels['email']['value']);
        $this->assertSame('https://saaed.example.qa', $channels['saaed']['value']);
    }

    public function test_the_list_shows_the_intro_paragraph_as_an_excerpt_without_glued_words(): void
    {
        $rows = $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/me/help/articles')->assertOk()->json('data');
        $a = collect($rows)->firstWhere('slug', 'getting-started');
        $this->assertStringStartsWith('The portal is one place', $a['excerpt_en']);
        $this->assertStringNotContainsString('Steps', $a['excerpt_en']);
        $this->assertDoesNotMatchRegularExpression('/[a-z][A-Z]/', $a['excerpt_en']);
    }

    public function test_a_release_adds_missing_starter_articles_to_an_existing_database_without_touching_edited_ones(): void
    {
        HelpArticle::where('slug', 'getting-started')->update(['title_en' => 'Edited by the centre']);
        HelpArticle::where('slug', 'smart-assistant')->delete();
        $this->artisan('tedc:deploy', ['--no-cache' => true])->assertSuccessful();
        $this->assertTrue(HelpArticle::where('slug', 'smart-assistant')->exists());
        $this->assertSame('Edited by the centre', HelpArticle::where('slug', 'getting-started')->value('title_en'));
    }
}
