<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Survey;
use App\Models\SurveyEvent;
use App\Models\SurveySession;
use App\Models\SurveySubmission;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\FeatureSettings;
use App\Services\Surveys\AnswerValidator;
use App\Services\Surveys\LogicEngine;
use App\Services\Surveys\SurveyDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Survey Studio v2, Gate 2: the engine core (definition, logic, versions, privacy modes, edits, audit). */
class SurveyEngineTest extends TestCase
{
    private function questions(): array
    {
        return [
            ['id' => 'intro', 'type' => 'instructions', 'title' => 'مرحباً', 'description' => 'أجب بصدق.'],
            ['id' => 'role', 'type' => 'single', 'title' => 'ما دورك؟', 'required' => true, 'options' => [['id' => 'teacher', 'label' => 'معلم'], ['id' => 'leader', 'label' => 'قائد']],
                'jumps' => [['op' => 'equals', 'value' => 'leader', 'to' => 'p2']]],
            ['id' => 'subject', 'type' => 'short_text', 'title' => 'ما مادتك؟', 'required' => true],
            ['id' => 'p2', 'type' => 'page_break', 'title' => 'الرضا'],
            ['id' => 'sat', 'type' => 'likert', 'title' => 'أنا راضٍ عن التدريب', 'required' => true, 'options' => ['لا أوافق بشدة', 'لا أوافق', 'محايد', 'أوافق', 'أوافق بشدة']],
            ['id' => 'why', 'type' => 'long_text', 'title' => 'لماذا؟', 'required' => true,
                'visible_if' => ['match' => 'any', 'conditions' => [['question' => 'sat', 'op' => 'equals', 'value' => 'o1'], ['question' => 'sat', 'op' => 'equals', 'value' => 'o2']]]],
            ['id' => 'contact', 'type' => 'email', 'title' => 'بريدك'],
            ['id' => 'files', 'type' => 'file_upload', 'title' => 'شواهد', 'max_files' => 2],
        ];
    }

    private function flag(bool $on): void
    {
        app(FeatureSettings::class)->set('survey_studio_v2', $on, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function enable(): void
    {
        $this->flag(true);
    }

    private function createSurvey(User $admin, array $overrides = []): string
    {
        return $this->asUser($admin)->postJson('/api/v1/admin/surveys', $overrides + [
            'title_ar' => 'استبانة الرضا', 'title_en' => 'Satisfaction survey', 'questions' => $this->questions(), 'audience' => ['specializations' => ['رياضيات']],
        ])->assertCreated()->json('data.id');
    }

    public function test_the_definition_normalises_new_types_and_rejects_bad_logic(): void
    {
        $clean = SurveyDefinition::normalize($this->questions());
        $this->assertSame(['instructions', 'single', 'short_text', 'page_break', 'likert', 'long_text', 'email', 'file_upload'], array_column($clean, 'type'));
        $this->assertSame('o1', $clean[4]['options'][0]['id']);
        $this->assertSame(['pdf', 'image'], $clean[7]['accept']);
        $this->assertSame(2, $clean[7]['max_files']);
        $this->assertSame('any', $clean[5]['visible_if']['match']);
        $this->assertFalse($clean[0]['required']);
        // The checksum does not depend on the key order inside a question.
        $this->assertSame(SurveyDefinition::checksum($clean), SurveyDefinition::checksum(array_map('array_reverse', $clean)));

        // A legacy show_if becomes a one-condition group.
        $legacy = SurveyDefinition::normalize([['id' => 'a', 'type' => 'yes_no', 'title' => 'أ'], ['id' => 'b', 'type' => 'short_text', 'title' => 'ب', 'show_if' => ['question' => 'a', 'op' => 'equals', 'value' => 'no']]]);
        $this->assertSame([['question' => 'a', 'op' => 'equals', 'value' => 'no']], $legacy[1]['visible_if']['conditions']);

        // Logic may only point backwards; jumps only forwards.
        try {
            SurveyDefinition::normalize([['id' => 'a', 'type' => 'short_text', 'title' => 'أ', 'visible_if' => ['conditions' => [['question' => 'b', 'op' => 'answered']]]], ['id' => 'b', 'type' => 'short_text', 'title' => 'ب']]);
            $this->fail('forward reference accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('questions.0.visible_if', $e->errors());
        }
        try {
            SurveyDefinition::normalize([['id' => 'a', 'type' => 'yes_no', 'title' => 'أ'], ['id' => 'b', 'type' => 'yes_no', 'title' => 'ب', 'jumps' => [['op' => 'equals', 'value' => 'yes', 'to' => 'a']]]]);
            $this->fail('backward jump accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('questions.1.jumps', $e->errors());
        }
        $this->expectException(ValidationException::class);
        SurveyDefinition::normalize([['type' => 'section', 'title' => 'فقط عنوان']]);
    }

    public function test_the_logic_engine_hides_pages_skips_on_jumps_and_the_validator_follows_it(): void
    {
        $q = SurveyDefinition::normalize($this->questions());

        // A leader jumps over "subject" straight to page 2; "why" appears only for the two lowest Likert points.
        $leader = LogicEngine::evaluate($q, ['role' => 'leader', 'sat' => 'o2']);
        $this->assertSame(['intro', 'role', 'p2', 'sat', 'why', 'contact', 'files'], $leader['visible']);
        $this->assertSame([['intro', 'role'], ['sat', 'why', 'contact', 'files']], $leader['pages']);

        $teacher = LogicEngine::evaluate($q, ['role' => 'teacher', 'sat' => 'o5']);
        $this->assertContains('subject', $teacher['visible']);
        $this->assertNotContains('why', $teacher['visible']);

        // The validator ignores answers to skipped questions and enforces required ones that are visible.
        $clean = AnswerValidator::validate($q, ['role' => 'leader', 'subject' => 'ignored', 'sat' => 'o5', 'contact' => ' Someone@Example.COM ']);
        $this->assertSame(['role' => 'leader', 'sat' => 'o5', 'contact' => 'someone@example.com'], $clean);

        try {
            AnswerValidator::validate($q, ['role' => 'teacher', 'sat' => 'o1', 'contact' => 'not-an-email', 'files' => ['a', 'b', 'c']]);
            $this->fail('invalid answers accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['answers.subject', 'answers.why', 'answers.contact', 'answers.files'], array_keys($e->errors()));
        }

        // Page-level visibility hides every question of the page.
        $paged = SurveyDefinition::normalize([
            ['id' => 'a', 'type' => 'yes_no', 'title' => 'أ'],
            ['id' => 'pb', 'type' => 'page_break', 'visible_if' => ['conditions' => [['question' => 'a', 'op' => 'equals', 'value' => 'yes']]]],
            ['id' => 'b', 'type' => 'short_text', 'title' => 'ب', 'required' => true],
            ['id' => 'pb2', 'type' => 'page_break'],
            ['id' => 'end', 'type' => 'nps', 'title' => 'ج', 'jumps' => [['op' => 'lte', 'value' => 6, 'to' => 'end']]],
            ['id' => 'after', 'type' => 'short_text', 'title' => 'د', 'required' => true],
        ]);
        $this->assertSame(['a', 'pb2', 'end', 'after'], LogicEngine::evaluate($paged, ['a' => 'no'])['visible']);
        $this->assertSame(['a', 'pb', 'b', 'pb2', 'end'], LogicEngine::evaluate($paged, ['a' => 'yes', 'end' => 3])['visible'], 'a detractor ends the survey');
        $this->assertSame(['a' => 'no', 'end' => 2], AnswerValidator::validate($paged, ['a' => 'no', 'b' => 'hidden', 'end' => 2, 'after' => 'skipped']));
    }

    public function test_publishing_freezes_versions_and_the_feature_flag_and_permissions_guard_the_routes(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->flag(false);
        $this->asUser($admin)->postJson('/api/v1/admin/surveys', ['title_ar' => 'x', 'questions' => $this->questions()])->assertForbidden()->assertJsonPath('code', 'feature_disabled');
        $this->enable();

        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/surveys', ['title_ar' => 'x', 'questions' => $this->questions()])->assertForbidden();
        $this->asUser($admin)->getJson('/api/v1/admin/surveys/catalog')->assertOk()->assertJsonCount(21, 'data.types');

        $specialist = $this->makeUser(Role::PLANNING_SPECIALIST);
        $id = $this->createSurvey($specialist);
        $this->makeEmployee(['specialization' => 'رياضيات']);
        $this->makeEmployee(['specialization' => 'رياضيات']);

        // A specialist may not publish before approval, and may not approve.
        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/publish")->assertStatus(422)->assertJsonPath('code', 'survey_not_approved');
        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/approve")->assertForbidden();
        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/submit-review")->assertOk()->assertJsonPath('data.status', 'in_review');
        $head = $this->makeUser(Role::PLANNING_HEAD);
        $this->asUser($head)->postJson("/api/v1/admin/surveys/$id/return", ['note' => 'أضف سؤالاً'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/submit-review")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/surveys/$id/approve")->assertOk()->assertJsonPath('data.approval.approved', true);

        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/publish")->assertOk()->assertJsonPath('version', 1)->assertJsonPath('added', 2);
        $this->assertSame(2, AppNotification::where('type', 'survey.invite')->count());
        $v1 = SurveyVersion::where('survey_id', $id)->first();
        $this->assertSame(1, $v1->number);

        // Publishing again without changes adds no version; an edit changes the draft only and needs approval again.
        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/publish")->assertOk()->assertJsonPath('version', 1);
        $changed = $this->questions();
        $changed[] = ['id' => 'extra', 'type' => 'nps', 'title' => 'هل توصي بالتدريب؟'];
        $this->asUser($specialist)->putJson("/api/v1/admin/surveys/$id", ['questions' => $changed])->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.has_unpublished_changes', true)->assertJsonPath('data.approval.approved', false);
        $this->asUser($specialist)->postJson("/api/v1/admin/surveys/$id/publish")->assertStatus(422)->assertJsonPath('code', 'survey_not_approved');
        // An approver may publish straight away (recorded as a self-approval).
        $this->asUser($head)->postJson("/api/v1/admin/surveys/$id/publish")->assertOk()->assertJsonPath('version', 2);
        $this->assertSame(2, SurveyVersion::where('survey_id', $id)->count());
        $this->assertSame($v1->checksum, $v1->fresh()->checksum);
        $this->assertCount(8, $v1->fresh()->questions);
        $this->assertFalse($v1->fresh()->update(['questions' => []]), 'versions are append-only');

        // Audit: lifecycle events are mirrored to the audit log with the actor.
        $this->assertSame(['created', 'submitted_for_review', 'returned', 'submitted_for_review', 'approved', 'published', 'published', 'updated', 'approved', 'published'],
            SurveyEvent::where('survey_id', $id)->orderBy('created_at')->pluck('event')->all());
        $this->assertTrue(AuditLog::where('action', 'survey.published')->where('user_id', $head->id)->exists());

        $this->asUser($head)->getJson("/api/v1/admin/surveys/$id/versions")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.current', true);
        $this->asUser($head)->getJson('/api/v1/admin/surveys?status=published')->assertOk()->assertJsonPath('meta.total', 1);
        $this->asUser($head)->postJson("/api/v1/admin/surveys/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->asUser($head)->postJson("/api/v1/admin/surveys/$id/reopen")->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_identified_surveys_keep_the_person_and_allow_edits_with_history_until_close(): void
    {
        $this->enable();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $teacher = $this->makeEmployee(['specialization' => 'رياضيات', 'experience_years' => 7]);
        $id = $this->createSurvey($admin);
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/publish")->assertOk()->assertJsonPath('added', 1);

        // Outsiders are refused; the participant sees the published version and autosaves a draft.
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/me/studio-surveys/$id")->assertForbidden();
        $this->asUser($teacher->user)->getJson('/api/v1/me/studio-surveys')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.answered', false);
        $this->asUser($teacher->user)->putJson("/api/v1/me/studio-surveys/$id/draft", ['answers' => ['role' => 'teacher'], 'page' => 0])->assertOk();
        $this->asUser($teacher->user)->getJson("/api/v1/me/studio-surveys/$id")->assertOk()->assertJsonPath('data.draft.answers.role', 'teacher')->assertJsonPath('data.version', 1);
        $this->assertSame($teacher->user_id, SurveySession::first()->respondent_key);

        // A bound submission: user id, full segment snapshot, bound to version 1; the draft is gone.
        $this->asUser($teacher->user)->postJson("/api/v1/me/studio-surveys/$id", ['answers' => ['role' => 'teacher', 'subject' => 'رياضيات', 'sat' => 'o4'], 'duration_seconds' => 90])
            ->assertOk()->assertJsonPath('edited', false)->assertJsonPath('can_edit', true);
        $s = SurveySubmission::first();
        $this->assertSame($teacher->user_id, $s->respondent_user_id);
        $this->assertNull($s->respondent_pseudonym);
        $this->assertSame('6-10', $s->segment_snapshot['experience_band']);
        $this->assertSame($teacher->school_id, $s->segment_snapshot['school_id']);
        $this->assertSame(0, SurveySession::count());
        $this->asUser($teacher->user)->getJson('/api/v1/me/studio-surveys')->assertJsonPath('data.0.answered', true)->assertJsonPath('data.0.can_edit', true);

        // Editing keeps the previous answers.
        $this->asUser($teacher->user)->postJson("/api/v1/me/studio-surveys/$id", ['answers' => ['role' => 'teacher', 'subject' => 'علوم', 'sat' => 'o5']])->assertOk()->assertJsonPath('edited', true);
        $s->refresh();
        $this->assertSame('علوم', $s->answers['subject']);
        $this->assertSame(1, $s->edit_count);
        $this->assertSame('رياضيات', $s->edits()->first()->previous_answers['subject']);
        $this->assertSame(1, SurveySubmission::count());

        // After close nothing can be sent; with the "never" policy a second submission is refused.
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/close")->assertOk();
        $this->asUser($teacher->user)->postJson("/api/v1/me/studio-surveys/$id", ['answers' => ['role' => 'leader', 'sat' => 'o5']])->assertStatus(422)->assertJsonPath('code', 'survey_not_open');
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/reopen")->assertOk();
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/$id", ['settings' => ['edit_policy' => 'never']])->assertOk();
        $this->asUser($teacher->user)->postJson("/api/v1/me/studio-surveys/$id", ['answers' => ['role' => 'leader', 'sat' => 'o5']])->assertStatus(422)->assertJsonPath('code', 'survey_edits_closed');

        // Respondent events never carry the person and never reach the audit log.
        $this->assertSame(2, SurveyEvent::where('survey_id', $id)->whereIn('event', ['submission_received', 'submission_edited'])->whereNull('actor_id')->count());
        $this->assertFalse(AuditLog::where('action', 'like', 'survey.submission%')->exists());
    }

    public function test_confidential_surveys_store_a_pseudonym_and_anonymous_surveys_store_nothing_about_the_person(): void
    {
        $this->enable();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $a = $this->makeEmployee(['specialization' => 'رياضيات', 'gender' => 'female', 'nationality' => 'Qatar']);
        $b = $this->makeEmployee(['specialization' => 'رياضيات']);
        $answers = ['role' => 'leader', 'sat' => 'o3'];

        // Confidential: no user id, an HMAC pseudonym, only the ticked segment fields, duplicates refused by pseudonym, edits possible.
        $conf = $this->createSurvey($admin, ['privacy_mode' => 'confidential', 'settings' => ['segment_fields' => ['gender', 'experience_band']]]);
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$conf/publish")->assertOk()->assertJsonPath('added', 2);
        $this->asUser($a->user)->postJson("/api/v1/me/studio-surveys/$conf", ['answers' => $answers])->assertOk();
        $s = SurveySubmission::where('survey_id', $conf)->first();
        $this->assertNull($s->respondent_user_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $s->respondent_pseudonym);
        $this->assertSame(['gender' => 'female', 'experience_band' => '3-5'], $s->segment_snapshot);
        $this->assertStringNotContainsString($a->user_id, json_encode($s->toArray()));
        $this->asUser($a->user)->postJson("/api/v1/me/studio-surveys/$conf", ['answers' => ['role' => 'leader', 'sat' => 'o4']])->assertOk()->assertJsonPath('edited', true);
        $this->assertSame(1, SurveySubmission::where('survey_id', $conf)->count());
        $this->asUser($a->user)->getJson("/api/v1/me/studio-surveys/$conf")->assertOk()->assertJsonPath('data.submission.answers.sat', 'o4');
        $this->assertNotSame($a->user_id, SurveySession::where('survey_id', $conf)->value('respondent_key'));
        // Once anyone answered, the privacy mode is frozen.
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/$conf", ['privacy_mode' => 'identified'])->assertStatus(422)->assertJsonPath('code', 'privacy_frozen');

        // Anonymous: neither id nor pseudonym, no segment fields unless ticked, a second answer refused through the registry, no edit.
        $anon = $this->createSurvey($admin, ['privacy_mode' => 'anonymous']);
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$anon/publish")->assertOk();
        $this->asUser($b->user)->postJson("/api/v1/me/studio-surveys/$anon", ['answers' => $answers])->assertOk()->assertJsonPath('can_edit', false);
        $s = SurveySubmission::where('survey_id', $anon)->first();
        $this->assertNull($s->respondent_user_id);
        $this->assertNull($s->respondent_pseudonym);
        $this->assertNull($s->segment_snapshot);
        $this->asUser($b->user)->postJson("/api/v1/me/studio-surveys/$anon", ['answers' => $answers])->assertStatus(422)->assertJsonPath('code', 'survey_already_answered');
        $this->asUser($b->user)->getJson("/api/v1/me/studio-surveys/$anon")->assertOk()->assertJsonPath('data.answered', true)->assertJsonPath('data.submission', null)->assertJsonPath('data.can_edit', false);
        $this->assertSame(1, SurveySubmission::where('survey_id', $anon)->count());

        // The pseudonym key never leaves the server.
        $this->asUser($admin)->getJson("/api/v1/admin/surveys/$conf")->assertOk()->assertJsonMissingPath('data.pseudonym_key');
        $this->assertStringNotContainsString('pseudonym_key', json_encode($this->asUser($admin)->getJson("/api/v1/admin/surveys/$conf")->json()));
    }

    public function test_the_studio_preview_duplicate_and_archive_work_and_archived_surveys_are_read_only(): void
    {
        $this->enable();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->createSurvey($admin);
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/preview-logic", ['answers' => ['role' => 'leader']])->assertOk()
            ->assertJsonPath('data.visible', ['intro', 'role', 'p2', 'sat', 'contact', 'files']);
        $copy = $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/duplicate")->assertCreated()->json('data');
        $this->assertSame('draft', $copy['status']);
        $this->assertStringContainsString('نسخة', $copy['title_ar']);
        $this->asUser($admin)->deleteJson("/api/v1/admin/surveys/$id")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertNotNull(Survey::find($id), 'archiving never deletes');
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/$id", ['title_ar' => 'جديد'])->assertStatus(422)->assertJsonPath('code', 'survey_archived');
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/$id/publish")->assertStatus(422)->assertJsonPath('code', 'survey_bad_transition');
    }
}
