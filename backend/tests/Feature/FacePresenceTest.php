<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\FileStorage;
use Tests\TestCase;

/** EXM-10: face presence (never recognition) during remote exams, with consent, a fair alternative, reviewed flags and retention. */
class FacePresenceTest extends TestCase
{
    private Program $program;

    private User $learner;

    private QuestionBank $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/features/proctoring', ['enabled' => true, 'reason' => 'test'])->assertOk();
        $this->program = $this->makeProgram();
        $employee = $this->makeEmployee();
        Registration::create(['program_id' => $this->program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $this->learner = $employee->user;
        $this->bank = QuestionBank::create(['title_ar' => 'بنك', 'title_en' => 'Bank', 'visibility' => 'center']);
    }

    private function assessment(array $proctoring = []): Assessment
    {
        $q = Question::create(['bank_id' => $this->bank->id, 'type' => 'single_choice', 'stem_ar' => 'س'.uniqid(), 'difficulty' => 'easy', 'points' => 1, 'version' => 1, 'status' => 'active',
            'payload' => ['options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]]]]);
        $a = Assessment::create(['program_id' => $this->program->id, 'kind' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'max_attempts' => 3, 'pass_percent' => 60, 'status' => 'published',
            'feedback_mode' => 'after_submit', 'proctoring' => $proctoring === [] ? null : ['enabled' => true] + $proctoring]);
        $a->sections()->create(['selection' => 'fixed', 'question_ids' => [$q->id], 'sort_order' => 0]);

        return $a;
    }

    private function start(Assessment $a, ?array $consent = null)
    {
        return $this->asUser($this->learner)->postJson("/api/v1/me/assessments/{$a->id}/start", $consent === null ? [] : ['consent' => $consent]);
    }

    private function event(string $attemptId, string $type, array $extra = [])
    {
        return $this->asUser($this->learner)->postJson("/api/v1/me/attempts/{$attemptId}/events", ['type' => $type] + $extra);
    }

    public function test_face_check_is_off_by_default(): void
    {
        $a = $this->assessment(['enabled' => true]);
        $this->assertFalse((bool) ($a->proctoring['face_check'] ?? false));
        $this->start($a)->assertOk();

        // Saved without the switch, an assessment keeps it off; the settings carry the conservative defaults.
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $res = $this->asUser($admin)->getJson("/api/v1/admin/assessments/{$a->id}")->assertOk();
        $this->assertFalse((bool) $res->json('data.proctoring.face_check'));
        $this->assertSame(10, $res->json('data.proctoring.face_absent_seconds'));
        $this->assertSame(3, $res->json('data.proctoring.face_absent_limit'));
        $this->assertSame(1, $res->json('data.proctoring.multiple_faces_limit'));
        $this->assertSame(5, $res->json('data.proctoring.looking_away_limit'));
        $this->assertSame(1000, $res->json('data.proctoring.face_check_interval_ms'));
        $this->assertSame('centre', $res->json('data.proctoring.face_fallback'));
    }

    public function test_starting_without_consent_is_refused_and_consent_is_recorded(): void
    {
        $a = $this->assessment(['face_check' => true]);
        // The refusal tells the exam page what to show: the fallback, the retention period and the wording's version.
        $this->start($a)->assertStatus(422)->assertJsonPath('code', 'face_consent_required')->assertJsonPath('details.fallback', 'centre')
            ->assertJsonPath('details.retention_days', 90)->assertJsonPath('details.consent_version', '2026-10');
        $this->start($a, ['given' => false, 'camera' => true, 'version' => '2026-10'])->assertStatus(422)->assertJsonPath('code', 'face_consent_required');

        $id = $this->start($a, ['given' => true, 'camera' => true, 'version' => '2026-10'])->assertOk()->assertJsonPath('data.proctoring.face_check', true)->json('data.id');

        $consent = AssessmentAttempt::find($id)->integrity['consent'];
        $this->assertTrue($consent['camera']);
        $this->assertSame('2026-10', $consent['version']);
        $this->assertNotEmpty($consent['given_at']);
    }

    public function test_without_a_camera_the_fallback_decides(): void
    {
        $centre = $this->assessment(['face_check' => true, 'face_fallback' => 'centre']);
        $this->start($centre, ['given' => true, 'camera' => false, 'version' => '2026-10'])->assertStatus(422)->assertJsonPath('code', 'face_check_in_centre');
        $this->assertSame(0, AssessmentAttempt::where('assessment_id', $centre->id)->count());

        $flagged = $this->assessment(['face_check' => true, 'face_fallback' => 'flagged']);
        $id = $this->start($flagged, ['given' => true, 'camera' => false, 'version' => '2026-10'])->assertOk()->json('data.id');
        $at = AssessmentAttempt::find($id);
        $this->assertContains('no_camera', $at->integrity['flags']);
        $this->assertFalse($at->integrity['consent']['camera']);
    }

    public function test_face_events_count_toward_their_limits_and_raise_flags(): void
    {
        $a = $this->assessment(['face_check' => true, 'face_absent_limit' => 1, 'multiple_faces_limit' => 0, 'looking_away_limit' => 2, 'action' => 'flag']);
        $id = $this->start($a, ['given' => true, 'camera' => true, 'version' => '2026-10'])->json('data.id');

        $this->event($id, 'face_absent')->assertOk()->assertJsonPath('data.flags', []);
        $this->event($id, 'face_absent')->assertOk()->assertJsonPath('data.flags', ['face_absent']);
        $this->event($id, 'multiple_faces')->assertOk();
        $this->event($id, 'looking_away');
        $this->event($id, 'looking_away');
        $flags = $this->event($id, 'looking_away')->json('data.flags');
        $this->assertEqualsCanonicalizing(['face_absent', 'multiple_faces', 'looking_away'], $flags);

        $at = AssessmentAttempt::find($id);
        $this->assertSame('in_progress', $at->status, 'flags go to people; nothing ends the attempt unless the assessment says so');
        $this->assertSame(2, $at->integrity['events']['face_absent']);
        // A minute-by-minute timeline for the reviewers.
        $this->assertSame(2, $at->integrity['timeline'][0]['face_absent']);

        // A camera that stops during the exam is flagged at once; a slow device is noted for the reviewer, never flagged.
        $this->event($id, 'face_check_degraded')->assertOk();
        $this->assertNotContains('face_check_degraded', AssessmentAttempt::find($id)->integrity['flags']);
        $this->assertContains('no_camera', $this->event($id, 'camera_off')->json('data.flags'));
    }

    public function test_auto_submit_follows_the_configured_limit(): void
    {
        $a = $this->assessment(['face_check' => true, 'multiple_faces_limit' => 1, 'action' => 'auto_submit']);
        $id = $this->start($a, ['given' => true, 'camera' => true, 'version' => '2026-10'])->json('data.id');

        $this->event($id, 'multiple_faces')->assertJsonPath('data.submitted', false);
        $this->event($id, 'multiple_faces')->assertJsonPath('data.submitted', true);
        $this->assertNotSame('in_progress', AssessmentAttempt::find($id)->status);
    }

    public function test_unknown_events_are_refused_and_face_events_carry_no_image(): void
    {
        $a = $this->assessment(['face_check' => true]);
        $id = $this->start($a, ['given' => true, 'camera' => true, 'version' => '2026-10'])->json('data.id');

        $this->event($id, 'face_recognised')->assertStatus(422);
        $this->event($id, 'face_absent', ['image' => 'data:image/jpeg;base64,'.base64_encode('face')])->assertStatus(422);

        $this->event($id, 'face_absent')->assertOk();
        $stored = json_encode(AssessmentAttempt::find($id)->integrity);
        $this->assertStringNotContainsString('base64', $stored);
        $this->assertStringNotContainsString('image', $stored);
    }

    public function test_face_events_are_ignored_when_face_check_is_off_or_the_feature_is_off(): void
    {
        $a = $this->assessment(['face_check' => false, 'tab_switch_limit' => 5]);
        $id = $this->start($a)->json('data.id');
        $this->event($id, 'face_absent')->assertOk()->assertJsonPath('data.ignored', true);

        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/features/proctoring', ['enabled' => false, 'reason' => 'test'])->assertOk();
        $off = $this->assessment(['face_check' => true]);
        // With the feature off, face check is not asked for and nothing about faces is counted.
        $id2 = $this->start($off)->assertOk()->assertJsonPath('data.proctoring.face_check', false)->json('data.id');
        $this->event($id2, 'face_absent')->assertOk()->assertJsonPath('data.ignored', true);
    }

    public function test_a_reviewer_clears_or_upholds_a_flag_with_a_note(): void
    {
        $a = $this->assessment(['face_check' => true, 'face_absent_limit' => 0]);
        $id = $this->start($a, ['given' => true, 'camera' => true, 'version' => '2026-10'])->json('data.id');
        $this->event($id, 'face_absent');

        $invigilator = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($invigilator)->postJson("/api/v1/admin/attempts/{$id}/flags/face_absent/review", ['decision' => 'clear'])->assertStatus(422);
        $this->asUser($invigilator)->postJson("/api/v1/admin/attempts/{$id}/flags/tab_switches/review", ['decision' => 'clear', 'note' => 'x'])->assertStatus(422);
        $this->asUser($invigilator)->postJson("/api/v1/admin/attempts/{$id}/flags/face_absent/review", ['decision' => 'clear', 'note' => 'Poor lighting; the learner stayed at the desk.'])->assertOk();

        $review = AssessmentAttempt::find($id)->integrity['reviews']['face_absent'];
        $this->assertSame('clear', $review['decision']);
        $this->assertSame($invigilator->id, $review['by']);
        $this->assertTrue(AuditLog::where('action', 'integrity_flag_reviewed')->where('auditable_id', $id)->exists());

        $this->asUser($invigilator)->getJson("/api/v1/admin/attempts/{$id}/review")->assertOk()
            ->assertJsonPath('data.integrity.reviews.face_absent.decision', 'clear')
            ->assertJsonPath('data.integrity.consent.camera', true)
            ->assertJsonPath('data.integrity.timeline.0.minute', 0);
        $this->asUser($invigilator)->getJson("/api/v1/admin/assessments/{$a->id}/live")->assertOk()->assertJsonPath('data.attempts.0.reviews.face_absent.decision', 'clear');

        $this->asUser($this->learner)->postJson("/api/v1/admin/attempts/{$id}/flags/face_absent/review", ['decision' => 'uphold', 'note' => 'n'])->assertForbidden();
    }

    public function test_the_retention_job_deletes_snapshots_and_logs_past_the_limit_but_keeps_the_flags(): void
    {
        $a = $this->assessment(['face_check' => true, 'face_absent_limit' => 0, 'snapshots' => true]);
        $id = $this->start($a, ['given' => true, 'camera' => true, 'version' => '2026-10'])->json('data.id');
        $this->event($id, 'face_absent');
        $this->asUser($this->learner)->postJson("/api/v1/me/attempts/{$id}/snapshot", ['image' => 'data:image/jpeg;base64,'.base64_encode('jpeg-bytes')])->assertOk();
        $this->asUser($this->learner)->postJson("/api/v1/me/attempts/{$id}/submit")->assertOk();
        $path = AssessmentAttempt::find($id)->integrity['snapshots'][0];
        $this->assertTrue(app(FileStorage::class)->exists('submissions', $path));

        $recent = $this->assessment(['face_check' => true, 'face_absent_limit' => 0]);
        $keep = $this->start($recent, ['given' => true, 'camera' => true, 'version' => '2026-10'])->json('data.id');
        $this->event($keep, 'face_absent');

        AssessmentAttempt::whereKey($id)->update(['started_at' => now()->subDays(100), 'submitted_at' => now()->subDays(100)]);
        $this->artisan('tedc:proctoring-retention')->assertSuccessful();

        $old = AssessmentAttempt::find($id)->integrity;
        $this->assertSame(['face_absent'], $old['flags']);
        $this->assertArrayNotHasKey('log', $old);
        $this->assertArrayNotHasKey('timeline', $old);
        $this->assertArrayNotHasKey('snapshots', $old);
        $this->assertNotEmpty($old['purged_at']);
        $this->assertFalse(app(FileStorage::class)->exists('submissions', $path));
        $this->assertArrayHasKey('log', AssessmentAttempt::find($keep)->integrity, 'recent attempts keep their record');

        // The retention period is a setting.
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($admin)->getJson('/api/v1/admin/settings/proctoring')->assertOk()->assertJsonPath('data.integrity_retention_days', 90);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/proctoring', ['integrity_retention_days' => 30])->assertOk()->assertJsonPath('data.integrity_retention_days', 30);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/proctoring', ['integrity_retention_days' => 2])->assertStatus(422);
    }

    public function test_the_learner_list_says_when_a_camera_check_applies_and_it_is_never_taken_offline(): void
    {
        $a = $this->assessment(['face_check' => true, 'face_fallback' => 'flagged']);
        $a->update(['allow_offline' => true]);
        $row = collect($this->asUser($this->learner)->getJson('/api/v1/me/assessments')->assertOk()->json('data'))->firstWhere('id', $a->id);
        $this->assertTrue($row['face_check']);
        $this->assertSame('flagged', $row['face_fallback']);
        // The phone cannot run the camera check, so an exam that needs it is not handed out for offline use.
        $this->assertFalse($row['allow_offline']);
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/features/offline_mobile', ['enabled' => true, 'reason' => 'test'])->assertOk();
        $this->asUser($this->learner)->postJson("/api/v1/me/assessments/{$a->id}/offline-token", ['device_id' => 'device-0123456789'])->assertStatus(422)->assertJsonPath('code', 'offline_not_allowed');
    }
}
