<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Survey;
use App\Models\SurveyEvent;
use App\Models\SurveyFile;
use App\Models\SurveyInvitation;
use App\Models\SurveyParticipant;
use App\Models\SurveySession;
use App\Models\SurveySubmission;
use App\Ops\DataSubjectService;
use App\Services\FeatureSettings;
use App\Services\Surveys\SurveyCollector;
use App\Services\Surveys\SurveyDataSubject;
use App\Services\Surveys\SurveyRetention;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Survey Studio v2, Gate 7: retention of drafts, invitations, pseudonym keys and answers; a person's survey data on access and erasure. */
class SurveyRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('survey_studio_v2', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function publish($admin, array $data): Survey
    {
        $id = $this->asUser($admin)->postJson('/api/v1/admin/surveys', $data + ['audience' => [], 'questions' => [['id' => 'sat', 'type' => 'rating', 'title' => 'الرضا', 'required' => true, 'scale' => ['min' => 1, 'max' => 5]], ['id' => 'f', 'type' => 'file_upload', 'title' => 'ملف']]])->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$id}/publish")->assertOk();

        return Survey::findOrFail($id);
    }

    public function test_the_daily_run_expires_drafts_purges_invitations_drops_keys_and_retires_answers_keeping_aggregates(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $people = collect(range(1, 3))->map(fn () => $this->makeEmployee());
        $survey = $this->publish($admin, ['title_ar' => 'سرية', 'privacy_mode' => 'confidential']);
        foreach ($people as $i => $e) {
            $this->asUser($e->user)->postJson("/api/v1/me/studio-surveys/{$survey->id}", ['answers' => ['sat' => 3 + ($i % 2)]])->assertOk();
        }
        // A draft that expired, a file never bound to an answer, and a personal invitation.
        $this->asUser($people[0]->user)->putJson("/api/v1/me/studio-surveys/{$survey->id}/draft", ['answers' => ['sat' => 2]])->assertOk();
        SurveySession::where('survey_id', $survey->id)->update(['expires_at' => now()->subDay()]);
        $orphan = $this->asUser($people[1]->user)->post("/api/v1/me/studio-surveys/{$survey->id}/files", ['question_id' => 'f', 'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        SurveyFile::where('id', $orphan)->update(['created_at' => now()->subDays(40)]);
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/invitations", ['people' => [['email' => 'guest@example.test']]])->assertCreated();
        $retention = app(SurveyRetention::class);

        // Open survey: drafts and orphans go, nothing else.
        $this->assertSame(['drafts' => 1, 'orphan_files' => 1, 'invitations' => 0, 'keys' => 0, 'answers' => 0], array_intersect_key($retention->run(true), array_flip(['drafts', 'orphan_files', 'invitations', 'keys', 'answers'])));
        $this->assertSame(1, SurveySession::count(), 'a dry run changes nothing');
        $this->artisan('tedc:surveys-retention')->assertSuccessful();
        $this->assertSame(0, SurveySession::count());
        $this->assertNull(SurveyFile::find($orphan));
        $this->assertSame(1, SurveyInvitation::where('survey_id', $survey->id)->count());

        // Closed 31 days: the key goes (answers stay, unlinkable); the invitation waits for 90 days.
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/close")->assertOk();
        $survey->forceFill(['closed_at' => now()->subDays(31)])->save();
        $r = $retention->run();
        $this->assertSame([1, 0, 0], [$r['keys'], $r['invitations'], $r['answers']]);
        $survey->refresh();
        $this->assertNull($survey->pseudonym_key);
        $this->assertNotNull($survey->key_deleted_at);
        $this->assertSame(3, SurveySubmission::where('survey_id', $survey->id)->count());
        $this->assertNull(app(SurveyCollector::class)->submissionOf($survey, $people[0]->user), 'without the key a confidential answer cannot be found again');
        $this->assertSame(1, SurveyEvent::where('survey_id', $survey->id)->where('event', 'key_deleted')->count());
        $this->assertSame(3, $this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/results")->assertOk()->json('data.summary.submissions'), 'aggregates still work without the key');

        // Closed 91 days: the invitation goes.
        $survey->forceFill(['closed_at' => now()->subDays(91)])->save();
        $this->assertSame(1, $retention->run()['invitations']);
        $this->assertSame(0, SurveyInvitation::where('survey_id', $survey->id)->count());

        // A legal hold stops retirement; lifting it lets the answers go once the retention period passed, aggregates kept.
        $survey->forceFill(['closed_at' => now()->subDays(1100), 'legal_hold' => true])->save();
        $this->assertSame(0, $retention->run()['answers']);
        $report = $this->asUser($admin)->getJson('/api/v1/admin/surveys/retention')->assertOk()->json('data');
        $this->assertSame($survey->id, $report['held'][0]['id']);
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/{$survey->id}", ['legal_hold' => false])->assertOk()->assertJsonPath('data.legal_hold', false);
        $this->assertSame(1, $this->asUser($admin)->getJson('/api/v1/admin/surveys/retention')->json('data.pending.answers'));
        $run = $this->asUser($admin)->postJson('/api/v1/admin/surveys/retention', ['dry_run' => false])->assertOk()->json('data');
        $this->assertSame(1, $run['answers']);
        $survey->refresh();
        $this->assertNotNull($survey->answers_purged_at);
        $this->assertSame(0, SurveySubmission::where('survey_id', $survey->id)->count());
        $this->assertSame(0, SurveyParticipant::where('survey_id', $survey->id)->count());
        $this->assertSame(3, $survey->aggregates['summary']['submissions']);
        $this->assertSame(3.33, (float) collect($survey->aggregates['questions'])->firstWhere('id', 'sat')['average']);
        $res = $this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/results")->assertOk()->json('data');
        $this->assertSame(3, $res['summary']['submissions']);
        $this->assertNotNull($res['purged_at']);
        $this->assertSame(1, SurveyEvent::where('survey_id', $survey->id)->where('event', 'answers_purged')->count());
        $this->assertSame(0, $retention->run()['answers'], 'retired once');
    }

    public function test_a_person_sees_their_identified_answers_and_erasure_detaches_them(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $handler = $this->makeUser(Role::SUPER_ADMIN);
        $e = $this->makeEmployee();
        $other = $this->makeEmployee();
        $named = $this->publish($admin, ['title_ar' => 'معرّفة', 'privacy_mode' => 'identified']);
        $secret = $this->publish($admin, ['title_ar' => 'سرية', 'privacy_mode' => 'confidential']);
        $fileId = $this->asUser($e->user)->post("/api/v1/me/studio-surveys/{$named->id}/files", ['question_id' => 'f', 'file' => UploadedFile::fake()->create('me.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->asUser($e->user)->postJson("/api/v1/me/studio-surveys/{$named->id}", ['answers' => ['sat' => 4, 'f' => [$fileId]]])->assertOk();
        $this->asUser($e->user)->postJson("/api/v1/me/studio-surveys/{$secret->id}", ['answers' => ['sat' => 2]])->assertOk();
        $this->asUser($other->user)->postJson("/api/v1/me/studio-surveys/{$named->id}", ['answers' => ['sat' => 5]])->assertOk();

        // Access: the person's export lists both participations and only the identified answers, by question title.
        $export = app(DataSubjectService::class)->export($e->user);
        $this->assertCount(2, $export['surveys']['participations']);
        $this->assertCount(1, $export['surveys']['identified_answers']);
        $this->assertSame(4, $export['surveys']['identified_answers'][0]['answers']['الرضا']);
        $this->assertStringContainsString('cannot be linked', $export['surveys']['note']);

        // Erasure by a handler: the answer stays anonymous, the file and the participation rows go, the other person is untouched.
        $preview = $this->asUser($handler)->getJson("/api/v1/admin/privacy/subjects/{$e->user_id}/surveys")->assertOk()->json('data');
        $this->assertSame([1, 1, 2], [$preview['identified_answers'], $preview['files'], $preview['participations']]);
        $this->asUser($e->user)->getJson("/api/v1/admin/privacy/subjects/{$e->user_id}/surveys")->assertForbidden();
        $this->asUser($handler)->postJson("/api/v1/admin/privacy/subjects/{$e->user_id}/surveys/erase")->assertOk()->assertJsonPath('data.identified_answers', 1);
        $this->assertSame(0, SurveySubmission::where('respondent_user_id', $e->user_id)->count());
        $this->assertSame(2, SurveySubmission::where('survey_id', $named->id)->count(), 'the answers stay, one of them anonymous');
        $this->assertNull(SurveyFile::find($fileId));
        $this->assertSame(0, SurveyParticipant::where('user_id', $e->user_id)->count());
        $this->assertSame($other->user_id, SurveySubmission::where('survey_id', $named->id)->whereNotNull('respondent_user_id')->value('respondent_user_id'));
        $anon = SurveySubmission::where('survey_id', $named->id)->whereNull('respondent_user_id')->firstOrFail();
        $this->assertSame(['sat' => 4], $anon->answers);
        $this->assertNull($anon->segment_snapshot);
        $this->assertSame(1, SurveyEvent::where('survey_id', $named->id)->where('event', 'subject_erased')->count());
        $this->assertSame(['identified_answers' => 0, 'files' => 0, 'participations' => 0, 'drafts' => 0], app(SurveyDataSubject::class)->preview($e->user));
    }
}
