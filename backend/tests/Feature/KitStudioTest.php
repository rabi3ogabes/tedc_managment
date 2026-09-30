<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\KitFile;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KitStudioTest extends TestCase
{
    private User $admin;

    private User $dev;

    private User $qa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->dev = $this->makeUser(Role::KIT_DEVELOPER, ['name' => 'Dev One']);
        $this->qa = $this->makeUser(Role::QA_REVIEWER, ['name' => 'QA One']);
    }

    private function kit(array $extra = []): string
    {
        return $this->asUser($this->admin)->postJson('/api/v1/admin/kits', $extra + [
            'title_ar' => 'حقيبة إدارة الصف', 'title_en' => 'Classroom Management Kit', 'audience' => 'معلمو المرحلة الابتدائية', 'duration_hours' => 6,
            'objectives' => ['أن يطبق المعلم استراتيجيات إدارة الصف', 'أن يقيّم المعلم سلوك الطلاب'],
            'owner_id' => $this->dev->id, 'members' => [['user_id' => $this->qa->id, 'role' => 'qa']],
        ])->assertCreated()->json('data.id');
    }

    private function generatedDeck(string $kitId): string
    {
        return $this->asUser($this->dev)->postJson("/api/v1/admin/kits/$kitId/ai/deck", ['topic' => 'إدارة الصف', 'slide_count' => 10])->assertCreated()->assertJsonPath('data.provider', 'template')->json('data.file.id');
    }

    public function test_access_follows_membership_and_roles(): void
    {
        $id = $this->kit();
        $other = $this->makeUser(Role::KIT_DEVELOPER);

        $this->asUser($this->dev)->getJson('/api/v1/admin/kits')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->qa)->getJson("/api/v1/admin/kits/$id")->assertOk()->assertJsonPath('data.my_role', 'qa')->assertJsonPath('data.can.review', true)->assertJsonPath('data.can.manage', false);
        $this->asUser($this->admin)->getJson('/api/v1/admin/kits')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($other)->getJson('/api/v1/admin/kits')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($other)->getJson("/api/v1/admin/kits/$id")->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/kits')->assertForbidden();
        $this->asUser($this->qa)->putJson("/api/v1/admin/kits/$id", ['title_ar' => 'x'])->assertForbidden();
        $this->assertNotNull(AppNotification::where('user_id', $this->qa->id)->where('type', 'kit.assigned')->first());
    }

    public function test_generated_deck_is_editable_by_both_developer_and_qa_with_slide_level_merging(): void
    {
        $id = $this->kit();
        $fileId = $this->generatedDeck($id);
        $base = "/api/v1/admin/kits/$id/files/$fileId";

        $deck = $this->asUser($this->dev)->getJson("$base/deck")->assertOk()->assertJsonPath('data.can_edit', true)->json('data.deck');
        $this->assertGreaterThanOrEqual(8, count($deck['slides']));
        $this->assertSame('title', $deck['slides'][0]['layout']);
        $this->assertSame('rtl', $deck['theme']['dir']);
        $this->assertSame(1, $deck['slides'][0]['rev']);

        // Developer edits slide 2, QA edits slide 3 from the same starting point: both survive.
        $s2 = $deck['slides'][1];
        $s2['elements'][1]['paragraphs'][0]['text'] = 'تعديل المعد';
        $s3 = $deck['slides'][2];
        $s3['notes'] = 'ملاحظة فريق الجودة';
        $this->asUser($this->dev)->putJson("$base/deck", ['slides' => [$s2]])->assertOk()->assertJsonPath('data.changed', 1);
        $saved = $this->asUser($this->qa)->putJson("$base/deck", ['slides' => [$s3]])->assertOk()->assertJsonPath('data.changed', 1)->json('data.deck');
        $this->assertSame('تعديل المعد', $saved['slides'][1]['elements'][1]['paragraphs'][0]['text']);
        $this->assertSame('ملاحظة فريق الجودة', $saved['slides'][2]['notes']);
        $this->assertSame($this->qa->displayName(), $saved['slides'][2]['by_name']);
        $this->assertSame($this->dev->displayName(), $saved['slides'][1]['by_name']);

        // QA saves slide 2 from a stale copy: reported as a conflict, developer's text is kept.
        $stale = $deck['slides'][1];
        $stale['notes'] = 'قديم';
        $res = $this->asUser($this->qa)->putJson("$base/deck", ['slides' => [$stale]])->assertStatus(409);
        $this->assertSame($s2['id'], $res->json('data.conflicts.0.slide_id'));
        $this->assertSame('تعديل المعد', $res->json('data.conflicts.0.server.elements.1.paragraphs.0.text'));
        // "Keep mine" overwrites deliberately.
        $this->asUser($this->qa)->putJson("$base/deck", ['slides' => [$stale], 'force' => true])->assertOk()->assertJsonPath('data.changed', 1);

        // Add, delete and reorder.
        $new = ['id' => 's_new1', 'layout' => 'blank', 'elements' => [['type' => 'text', 'x' => 10, 'y' => 10, 'w' => 500, 'h' => 80, 'paragraphs' => [['text' => 'شريحة جديدة', 'size' => 30]]]]];
        $current = $this->asUser($this->dev)->getJson("$base/deck")->json('data.deck');
        $order = array_column($current['slides'], 'id');
        array_splice($order, 1, 0, 's_new1');
        $after = $this->asUser($this->dev)->putJson("$base/deck", ['slides' => [$new], 'deleted' => [['id' => $current['slides'][5]['id'], 'rev' => 1]], 'order' => array_values(array_diff($order, [$current['slides'][5]['id']]))])->assertOk()->json('data.deck');
        $this->assertSame('s_new1', $after['slides'][1]['id']);
        $this->assertCount(count($current['slides']), $after['slides']);

        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson("$base/deck", ['slides' => []])->assertForbidden();
    }

    public function test_upload_import_and_versions(): void
    {
        $id = $this->kit();
        $base = "/api/v1/admin/kits/$id/files";

        $file = $this->asUser($this->dev)->post($base, ['file' => UploadedFile::fake()->create('عرض.pptx', 300), 'category' => 'presentation'], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.kind', 'presentation')->assertJsonPath('data.is_deck', false)->json('data');
        $this->asUser($this->dev)->post($base, ['file' => UploadedFile::fake()->create('dalil.pdf', 100)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.kind', 'pdf')->assertJsonPath('data.category', 'handout');
        $this->asUser($this->dev)->post($base, ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();

        // The browser extracted the slides from the uploaded PPTX and attaches them (with a data-URI picture).
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $deck = ['slides' => [['id' => 's1', 'layout' => 'blank', 'elements' => [
            ['type' => 'text', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 60, 'paragraphs' => [['text' => 'مرحبا', 'size' => 32]]],
            ['type' => 'image', 'x' => 0, 'y' => 100, 'w' => 200, 'h' => 200, 'src' => $png],
        ]]]];
        $imported = $this->asUser($this->dev)->postJson("$base/{$file['id']}/deck/import", ['deck' => $deck])->assertOk()->json('data');
        $img = $imported['deck']['slides'][0]['elements'][1];
        $this->assertNotEmpty($img['asset_id']);
        $this->assertStringContainsString('/files/', $img['src'], 'images are served through signed URLs');
        $this->assertCount(1, $this->asUser($this->dev)->getJson("$base/{$file['id']}/versions")->json('data'), 'the original upload is kept as version 1');

        // Manual version, edit, then restore.
        $this->asUser($this->dev)->postJson("$base/{$file['id']}/versions", ['note' => 'قبل التعديل'])->assertOk();
        $edited = $imported['deck']['slides'][0];
        $edited['elements'][0]['paragraphs'][0]['text'] = 'نص جديد';
        $this->asUser($this->dev)->putJson("$base/{$file['id']}/deck", ['slides' => [$edited]])->assertOk();
        $versions = $this->asUser($this->dev)->getJson("$base/{$file['id']}/versions")->json('data');
        $manual = collect($versions)->firstWhere('note', 'قبل التعديل');
        $restored = $this->asUser($this->dev)->postJson("$base/{$file['id']}/versions/{$manual['id']}/restore")->assertOk()->json('data.deck');
        $this->assertSame('مرحبا', $restored['slides'][0]['elements'][0]['paragraphs'][0]['text']);

        // Export stores the PPTX built in the browser as a new version of the binary.
        $this->asUser($this->dev)->post("$base/{$file['id']}/export", ['file' => UploadedFile::fake()->create('export.pptx', 50)], ['Accept' => 'application/json'])->assertOk();
        $this->asUser($this->dev)->getJson("$base/{$file['id']}/download")->assertOk()->assertJsonStructure(['data' => ['url', 'name']]);
    }

    public function test_review_workflow_comments_and_approval_gates(): void
    {
        $id = $this->kit();
        $fileId = $this->generatedDeck($id);
        $kit = "/api/v1/admin/kits/$id";
        $slideId = $this->asUser($this->dev)->getJson("$kit/files/$fileId/deck")->json('data.deck.slides.2.id');

        $this->asUser($this->dev)->postJson("$kit/approve")->assertForbidden();
        $this->asUser($this->dev)->postJson("$kit/submit", ['note' => 'جاهزة'])->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.review_round', 1);
        $this->assertNotNull(AppNotification::where('user_id', $this->qa->id)->where('type', 'kit.review_requested')->first());
        $this->asUser($this->dev)->postJson("$kit/submit")->assertUnprocessable();

        // QA pins a critical comment on a slide; a developer cannot raise severity.
        $comment = $this->asUser($this->qa)->postJson("$kit/comments", [
            'file_id' => $fileId, 'body' => 'المعلومة غير دقيقة', 'severity' => 'critical', 'category' => 'accuracy', 'assignee_id' => $this->dev->id,
            'anchor' => ['type' => 'point', 'slide_id' => $slideId, 'slide_index' => 2, 'x' => 320, 'y' => 200],
        ])->assertCreated()->assertJsonPath('data.severity', 'critical')->assertJsonPath('data.anchor.slide_id', $slideId)->json('data');
        $this->assertNotNull(AppNotification::where('user_id', $this->dev->id)->where('type', 'kit.comment')->first());
        $this->asUser($this->dev)->postJson("$kit/comments", ['file_id' => $fileId, 'body' => 'سؤال', 'severity' => 'critical'])->assertCreated()->assertJsonPath('data.severity', 'minor');
        $this->asUser($this->dev)->postJson("$kit/comments", ['body' => 'تم', 'parent_id' => $comment['id']])->assertCreated()->assertJsonPath('data.parent_id', $comment['id']);

        // Approval is blocked while the critical comment is open or only "addressed".
        $this->asUser($this->qa)->postJson("$kit/approve")->assertUnprocessable()->assertJsonPath('code', 'kit_blocked');
        $this->asUser($this->dev)->postJson("$kit/comments/{$comment['id']}/status", ['status' => 'addressed'])->assertOk()->assertJsonPath('data.status', 'addressed');
        $this->asUser($this->dev)->postJson("$kit/comments/{$comment['id']}/status", ['status' => 'resolved'])->assertForbidden();
        $this->asUser($this->qa)->postJson("$kit/approve")->assertUnprocessable();

        $this->asUser($this->qa)->postJson("$kit/request-changes", [])->assertUnprocessable();
        $this->asUser($this->qa)->postJson("$kit/request-changes", ['note' => 'عالجوا الملاحظات'])->assertOk()->assertJsonPath('data.status', 'changes_requested');
        $this->assertNotNull(AppNotification::where('user_id', $this->dev->id)->where('type', 'kit.changes_requested')->first());

        // QA verifies the fix, developer re-submits (round 2), QA approves, admin publishes.
        $this->asUser($this->qa)->postJson("$kit/comments/{$comment['id']}/status", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->asUser($this->dev)->postJson("$kit/submit")->assertOk()->assertJsonPath('data.review_round', 2);
        $this->asUser($this->qa)->postJson("$kit/comments", ['file_id' => $fileId, 'body' => 'تحسين لغوي', 'category' => 'language'])->assertCreated();
        $this->asUser($this->qa)->postJson("$kit/approve", ['note' => 'ممتاز'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->asUser($this->qa)->postJson("$kit/publish")->assertForbidden();
        $this->asUser($this->admin)->postJson("$kit/publish")->assertOk()->assertJsonPath('data.status', 'published');

        // A published kit is locked until reopened as a new version.
        $this->asUser($this->dev)->putJson("$kit/files/$fileId/deck", ['slides' => []])->assertForbidden();
        $this->asUser($this->admin)->postJson("$kit/reopen")->assertOk()->assertJsonPath('data.status', 'in_development')->assertJsonPath('data.version', 2);
        $this->assertCount(2, $this->asUser($this->qa)->getJson("$kit/reviews")->json('data'));
        $this->asUser($this->qa)->getJson("$kit/activity")->assertOk()->assertJsonPath('meta.total', fn ($n) => $n > 8);
    }

    public function test_forced_approval_needs_publisher_rights(): void
    {
        $id = $this->kit();
        $fileId = $this->generatedDeck($id);
        $kit = "/api/v1/admin/kits/$id";
        $this->asUser($this->dev)->postJson("$kit/submit")->assertOk();
        $this->asUser($this->qa)->postJson("$kit/comments", ['file_id' => $fileId, 'body' => 'خطأ جوهري', 'severity' => 'major'])->assertCreated();

        $this->asUser($this->qa)->postJson("$kit/approve", ['force' => true])->assertUnprocessable();
        $this->asUser($this->admin)->postJson("$kit/approve", ['force' => true, 'note' => 'استثناء'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_ai_features_work_without_an_api_key_and_the_analyzer_finds_real_issues(): void
    {
        $id = $this->kit();
        $fileId = $this->generatedDeck($id);
        $kit = "/api/v1/admin/kits/$id";

        // Image generation falls back to an on-brand abstract illustration and returns a signed URL.
        $img = $this->asUser($this->dev)->postJson("$kit/ai/image", ['prompt' => 'معلمة تشرح لطلاب', 'aspect' => '16:9'])->assertCreated()->json('data');
        $this->assertSame('placeholder', $img['provider']);
        $this->assertSame('image/svg+xml', $img['mime']);
        $this->assertStringContainsString('/files/', $img['url']);

        $story = $this->asUser($this->dev)->postJson("$kit/ai/storyboard", ['topic' => 'إدارة الصف', 'scenes' => 4, 'seconds' => 40])->assertOk()->json('data');
        $this->assertCount(5, $story['scenes'], 'the template plans five scenes');
        $this->assertSame('template', $story['provider']);
        $this->assertGreaterThan(0, $story['scenes'][0]['seconds']);

        $this->asUser($this->dev)->postJson("$kit/files/$fileId/ai/slides", ['topic' => 'التغذية الراجعة', 'count' => 2])->assertOk()->assertJsonCount(2, 'data.slides');
        $this->asUser($this->dev)->postJson("$kit/ai/rewrite", ['text' => 'نص', 'mode' => 'improve'])->assertOk()->assertJsonPath('data.changed', false);
        $this->asUser($this->qa)->postJson("$kit/ai/image", ['prompt' => 'x y z'])->assertForbidden();

        // Add a dense, low-contrast, tiny-text slide and a picture with no alt text: the analyzer must flag each.
        $deck = $this->asUser($this->dev)->getJson("$kit/files/$fileId/deck")->json('data.deck');
        $bad = ['id' => 's_bad', 'layout' => 'content', 'background' => ['color' => '#FFFFFF'], 'elements' => [
            ['type' => 'text', 'x' => 40, 'y' => 40, 'w' => 300, 'h' => 60, 'paragraphs' => [['text' => str_repeat('كلمة ', 120), 'size' => 12, 'color' => '#EEEEEE']]],
            ['type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'asset_id' => $img['id'], 'alt' => ''],
        ]];
        $this->asUser($this->dev)->putJson("$kit/files/$fileId/deck", ['slides' => [$bad], 'order' => array_merge(array_column($deck['slides'], 'id'), ['s_bad'])])->assertOk();

        $report = $this->asUser($this->qa)->postJson("$kit/files/$fileId/analyze")->assertOk()->json('data');
        $titles = collect($report['findings'])->where('slide_id', 's_bad')->pluck('title')->implode(' | ');
        $this->assertStringContainsString('too dense', $titles);
        $this->assertStringContainsString('Low contrast', $titles);
        $this->assertStringContainsString('Very small text', $titles);
        $this->assertStringContainsString('no description', $titles);
        $this->assertLessThan(100, $report['score']);
        $this->assertSame('major', $report['findings'][0]['severity'] === 'critical' ? 'major' : $report['findings'][0]['severity']);

        // A finding becomes a pinned comment with one call.
        $finding = collect($report['findings'])->firstWhere('slide_id', 's_bad');
        $this->asUser($this->qa)->postJson("$kit/comments/bulk", ['comments' => [[
            'file_id' => $fileId, 'body' => $finding['title'].' - '.$finding['suggestion'], 'severity' => $finding['severity'], 'category' => $finding['category'], 'anchor' => ['type' => 'slide', 'slide_id' => 's_bad'],
        ]]])->assertCreated()->assertJsonCount(1, 'data');
    }

    public function test_completeness_suggestions_and_board(): void
    {
        $id = $this->kit();
        $kit = "/api/v1/admin/kits/$id";
        $before = $this->asUser($this->dev)->getJson($kit)->assertOk()->json('data');
        $keys = collect($this->asUser($this->dev)->getJson("$kit/suggestions")->assertOk()->json('data'))->pluck('key')->all();
        $this->assertContains('presentation', $keys);
        $this->assertContains('trainer_guide', $keys);

        $this->generatedDeck($id);
        $after = $this->asUser($this->dev)->getJson($kit)->json('data');
        $this->assertGreaterThan($before['completeness']['percent'], $after['completeness']['percent']);
        $this->assertNotContains('presentation', collect($this->asUser($this->dev)->getJson("$kit/suggestions")->json('data'))->pluck('key')->all());

        $board = $this->asUser($this->admin)->getJson('/api/v1/admin/kits/board')->assertOk()->json('data');
        $this->assertSame(1, collect($board)->firstWhere('status', 'draft')['count']);
        $stats = $this->asUser($this->admin)->getJson('/api/v1/admin/kits/stats')->assertOk()->json('data');
        $this->assertSame(1, $stats['total']);
        $this->assertNotEmpty($stats['recent']);
        $this->assertSame(0, TrainingKit::where('status', 'in_review')->count());
        $this->assertSame(KitFile::count(), 1);
    }
}
