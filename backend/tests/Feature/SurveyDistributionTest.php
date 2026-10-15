<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SurveyDistribution;
use App\Models\SurveyFile;
use App\Models\SurveyInvitation;
use App\Models\SurveySubmission;
use App\Services\FeatureSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Survey Studio v2, Gate 4: public links with duplicate rules, QR, e-mail codes, invitations, file answers. */
class SurveyDistributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('survey_studio_v2', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function publish(array $overrides = []): string
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/surveys', $overrides + [
            'title_ar' => 'استبانة عامة', 'privacy_mode' => 'anonymous', 'settings' => ['requires_approval' => false],
            'questions' => [
                ['id' => 'q1', 'type' => 'single', 'title' => 'التقييم', 'required' => true, 'options' => [['id' => 'a', 'label' => 'جيد'], ['id' => 'b', 'label' => 'ضعيف']]],
                ['id' => 'f1', 'type' => 'file_upload', 'title' => 'شاهد', 'max_files' => 1, 'max_mb' => 1, 'accept' => ['pdf']],
            ],
        ])->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/publish")->assertOk();

        return $id;
    }

    public function test_a_public_link_with_the_device_rule_keeps_one_answer_per_device_and_can_be_capped_and_revoked(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->publish();
        $d = $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/distributions/public", ['duplicate_rule' => 'device', 'max_responses' => 2])->assertCreated()->json('data');
        $this->assertSame('device', $d['duplicate_rule']);
        $this->assertStringEndsWith('/s/'.$d['slug'], $d['link']);
        $slug = $d['slug'];

        // Anyone with the link reads the published version; the QR carries the link.
        $this->getJson("/api/v1/public/surveys/$slug")->assertOk()->assertJsonPath('data.rule', 'device')->assertJsonPath('data.requires_code', false)->assertJsonCount(2, 'data.questions');
        $svg = $this->asUser($admin)->get("/api/v1/admin/surveys/$id/distributions/{$d['id']}/qr.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());

        // A device answers once; a second submission from the same device is an edit; a third device is refused by the cap.
        $one = Str::random(24);
        $this->postJson("/api/v1/public/surveys/$slug", ['answers' => ['q1' => 'a']])->assertStatus(422)->assertJsonPath('code', 'survey_client_required');
        $this->putJson("/api/v1/public/surveys/$slug/draft", ['client_id' => $one, 'answers' => ['q1' => 'a'], 'page' => 0])->assertOk();
        $this->postJson("/api/v1/public/surveys/$slug/resume", ['client_id' => $one])->assertOk()->assertJsonPath('data.draft.answers.q1', 'a')->assertJsonPath('data.submission', null);
        $this->postJson("/api/v1/public/surveys/$slug", ['client_id' => $one, 'answers' => ['q1' => 'a'], 'duration_seconds' => 30])->assertOk()->assertJsonPath('edited', false)->assertJsonPath('can_edit', true);
        $this->postJson("/api/v1/public/surveys/$slug", ['client_id' => $one, 'answers' => ['q1' => 'b']])->assertOk()->assertJsonPath('edited', true);
        $this->assertSame(1, SurveySubmission::where('survey_id', $id)->count());
        $s = SurveySubmission::where('survey_id', $id)->first();
        $this->assertNull($s->respondent_user_id);
        $this->assertNotNull($s->respondent_pseudonym);
        $this->assertSame('public', $s->source);
        $this->assertStringNotContainsString($one, $s->respondent_pseudonym);
        $this->postJson("/api/v1/public/surveys/$slug", ['client_id' => Str::random(24), 'answers' => ['q1' => 'a']])->assertOk();
        $this->postJson("/api/v1/public/surveys/$slug", ['client_id' => Str::random(24), 'answers' => ['q1' => 'a']])->assertStatus(422)->assertJsonPath('code', 'survey_link_full');
        $this->getJson("/api/v1/public/surveys/$slug")->assertStatus(410)->assertJsonPath('code', 'survey_link_full');

        // Regenerating revokes the old address; deactivating closes the link; the open rule keeps nothing.
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/$id/distributions/{$d['id']}", ['max_responses' => null, 'duplicate_rule' => 'open'])->assertOk();
        $new = $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/distributions/{$d['id']}/regenerate")->assertOk()->json('data');
        $this->assertNotSame($slug, $new['slug']);
        $this->assertSame(2, $new['qr_version']);
        $this->getJson("/api/v1/public/surveys/$slug")->assertNotFound();
        $this->postJson("/api/v1/public/surveys/{$new['slug']}", ['client_id' => $one, 'answers' => ['q1' => 'a']])->assertOk()->assertJsonPath('edited', false);
        $this->assertSame(1, SurveySubmission::where('survey_id', $id)->whereNull('respondent_pseudonym')->count(), 'the open rule stores no key');
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/$id/distributions/{$d['id']}", ['is_active' => false])->assertOk();
        $this->getJson("/api/v1/public/surveys/{$new['slug']}")->assertStatus(410)->assertJsonPath('code', 'survey_link_closed');
        $this->assertSame(3, SurveyDistribution::find($d['id'])->submissions_count, 'edits are not counted');
    }

    public function test_the_email_code_rule_needs_a_verified_address_and_keeps_one_answer_per_address(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->publish();
        $d = $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/distributions/public", ['duplicate_rule' => 'email_code'])->assertCreated()->json('data');
        $slug = $d['slug'];
        $this->getJson("/api/v1/public/surveys/$slug")->assertOk()->assertJsonPath('data.requires_code', true);
        // Mail is not configured in tests: the code cannot be sent and the server says so instead of pretending.
        $this->postJson("/api/v1/public/surveys/$slug/code", ['email' => 'someone@example.qa'])->assertStatus(422)->assertJsonPath('code', 'survey_mail_off');
        // Plant a code the way the sender would, then verify.
        $dist = SurveyDistribution::find($d['id']);
        Cache::put('survey-code:'.$dist->id.':'.hash('sha256', 'someone@example.qa'), hash('sha256', '123456'), now()->addMinutes(15));
        $this->postJson("/api/v1/public/surveys/$slug/verify", ['email' => 'someone@example.qa', 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'survey_code_invalid');
        $token = $this->postJson("/api/v1/public/surveys/$slug/verify", ['email' => 'Someone@Example.qa', 'code' => '123456'])->assertOk()->json('data.respondent_token');
        $this->postJson("/api/v1/public/surveys/$slug", ['answers' => ['q1' => 'a']])->assertStatus(422)->assertJsonPath('code', 'survey_code_required');
        $this->postJson("/api/v1/public/surveys/$slug", ['respondent_token' => $token, 'answers' => ['q1' => 'a']])->assertOk()->assertJsonPath('edited', false);
        $this->postJson("/api/v1/public/surveys/$slug", ['respondent_token' => $token, 'answers' => ['q1' => 'b']])->assertOk()->assertJsonPath('edited', true);
        $this->assertSame(1, SurveySubmission::where('survey_id', $id)->count());
        $this->assertStringNotContainsString('example', SurveySubmission::first()->respondent_pseudonym);
    }

    public function test_invitations_are_single_use_tokens_returned_for_manual_sharing_when_mail_is_off(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->publish();
        $r = $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/invitations", ['people' => [['email' => 'a@example.qa', 'name' => 'أ'], ['email' => 'b@example.qa'], ['email' => 'A@example.qa']]])->assertCreated()->json('data');
        $this->assertSame(2, $r['created']);
        $this->assertSame(2, $r['manual']);
        $this->assertCount(2, $r['links']);
        $token = substr($r['links'][0]['link'], strrpos($r['links'][0]['link'], '/') + 1);
        $this->assertSame(48, strlen($token));
        $this->assertNull(SurveyInvitation::where('email', 'a@example.qa')->first()->opened_at);

        $this->getJson("/api/v1/public/surveys/i/$token")->assertOk()->assertJsonPath('data.rule', 'invitation')->assertJsonPath('data.name', 'أ')->assertJsonPath('data.answered', false);
        $this->assertNotNull(SurveyInvitation::where('email', 'a@example.qa')->first()->opened_at);
        $this->getJson('/api/v1/public/surveys/i/'.Str::random(48))->assertNotFound();
        $this->putJson("/api/v1/public/surveys/i/$token/draft", ['answers' => ['q1' => 'a']])->assertOk();
        $this->postJson("/api/v1/public/surveys/i/$token", ['answers' => ['q1' => 'a']])->assertOk()->assertJsonPath('edited', false);
        $inv = SurveyInvitation::where('email', 'a@example.qa')->first();
        $this->assertNotNull($inv->responded_at);
        // The same token edits its own answer while the policy allows; it never creates a second one.
        $this->postJson("/api/v1/public/surveys/i/$token", ['answers' => ['q1' => 'b']])->assertOk()->assertJsonPath('edited', true);
        $this->assertSame(1, SurveySubmission::where('survey_id', $id)->count());
        $this->assertSame('invitation', SurveySubmission::first()->source);
        $list = $this->asUser($admin)->getJson("/api/v1/admin/surveys/$id/distributions")->assertOk()->json('data');
        $this->assertSame(2, $list['invitation_stats']['total']);
        $this->assertSame(1, $list['invitation_stats']['responded']);
        $this->assertFalse($list['mail_ready']);
        // Nothing can be reminded by the system when mail is off; the answer says so.
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/invitations/remind")->assertOk()->assertJsonPath('data.sent', 0)->assertJsonPath('data.mail_ready', false);
        // A closed survey refuses the invitation.
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/close")->assertOk();
        $other = substr($r['links'][1]['link'], strrpos($r['links'][1]['link'], '/') + 1);
        $this->getJson("/api/v1/public/surveys/i/$other")->assertStatus(410)->assertJsonPath('code', 'survey_link_closed');
    }

    public function test_file_answers_are_checked_by_content_and_bound_to_the_submission(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->publish();
        $d = $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/distributions/public", ['duplicate_rule' => 'device'])->assertCreated()->json('data');
        $slug = $d['slug'];
        $client = Str::random(24);
        $pdf = UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n%".str_repeat('x', 2000));
        $exe = UploadedFile::fake()->createWithContent('tool.exe', 'MZ'.str_repeat('x', 100));
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('x', 1024 * 1024 + 10));

        $this->post("/api/v1/public/surveys/$slug/files", ['client_id' => $client, 'question_id' => 'q1', 'file' => $pdf], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/public/surveys/$slug/files", ['client_id' => $client, 'question_id' => 'f1', 'file' => $exe], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'survey_file_type');
        $this->post("/api/v1/public/surveys/$slug/files", ['client_id' => $client, 'question_id' => 'f1', 'file' => $big], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'survey_file_size');
        $file = $this->post("/api/v1/public/surveys/$slug/files", ['client_id' => $client, 'question_id' => 'f1', 'file' => $pdf], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->post("/api/v1/public/surveys/$slug/files", ['client_id' => $client, 'question_id' => 'f1', 'file' => $pdf], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'survey_file_count');

        // Another respondent cannot reference the file; the owner can, and the file is bound to the submission.
        $this->postJson("/api/v1/public/surveys/$slug", ['client_id' => Str::random(24), 'answers' => ['q1' => 'a', 'f1' => [$file['id']]]])->assertStatus(422)->assertJsonPath('code', 'survey_file_unknown');
        $this->postJson("/api/v1/public/surveys/$slug", ['client_id' => $client, 'answers' => ['q1' => 'a', 'f1' => [$file['id']]]])->assertOk();
        $row = SurveyFile::find($file['id']);
        $this->assertNotNull($row->submission_id);
        $this->assertSame('application/pdf', $row->mime);
        $this->assertSame([$file['id']], SurveySubmission::first()->answers['f1']);
        // Someone allowed to see results gets a short-lived link; a viewer without the permission does not.
        $this->asUser($admin)->getJson("/api/v1/admin/surveys/$id/files/{$file['id']}")->assertOk()->assertJsonPath('data.name', 'evidence.pdf');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/admin/surveys/$id/files/{$file['id']}")->assertForbidden();
    }
}
