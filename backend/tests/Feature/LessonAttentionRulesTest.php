<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\Registration;
use App\Services\CertificateService;
use App\Services\EvaluationService;
use Tests\TestCase;

/** The anti-distraction settings an author picks for a lesson, and the evaluation form a certificate waits for, are enforced. */
class LessonAttentionRulesTest extends TestCase
{
    private function lesson(string $type, array $settings, array $extra = []): array
    {
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true]);
        $employee = $this->makeEmployee();
        Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 0]);
        $lesson = CourseLesson::create($extra + ['program_id' => $program->id, 'module_id' => $module->id, 'type' => $type, 'title_ar' => 'د', 'title_en' => 'L', 'sort_order' => 0,
            'is_required' => true, 'status' => 'published', 'duration_seconds' => 100, 'settings' => $settings]);

        return [$lesson, $employee->user];
    }

    public function test_a_lesson_that_requires_full_screen_counts_no_time_outside_full_screen(): void
    {
        [$video, $user] = $this->lesson('video', ['require_fullscreen' => true]);

        $rules = $this->asUser($user)->getJson("/api/v1/me/lessons/{$video->id}")->assertOk()->json('data.rules');
        $this->assertTrue($rules['require_fullscreen']);

        $windowed = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 10, 'duration' => 100, 'fullscreen' => false])->assertOk()->json('data');
        $this->assertEquals(0, $windowed['credited']);
        $full = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 10, 'duration' => 100, 'fullscreen' => true])->assertOk()->json('data');
        $this->assertGreaterThan(0, $full['credited']);
        // A player that cannot tell (an app, a browser without the full-screen API) is not penalised.
        $unknown = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 10, 'to' => 20, 'duration' => 100])->assertOk()->json('data');
        $this->assertGreaterThan(0, $unknown['credited']);
    }

    public function test_the_pause_limit_is_counted_on_the_server_and_survives_a_reload(): void
    {
        [$video, $user] = $this->lesson('video', ['lock_pause' => 2]);

        $rules = $this->asUser($user)->getJson("/api/v1/me/lessons/{$video->id}")->assertOk()->json('data.rules');
        $this->assertSame(2, $rules['max_pauses']);
        $this->assertSame(2, $rules['pauses_left']);

        $first = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 10, 'duration' => 100, 'paused' => true])->assertOk()->json('data');
        $this->assertSame(1, $first['pauses_left']);
        // An automatic pause (an in-video question, a hidden tab) is not the learner's and is not counted.
        $auto = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 10, 'to' => 20, 'duration' => 100])->assertOk()->json('data');
        $this->assertSame(1, $auto['pauses_left']);
        $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 20, 'to' => 30, 'duration' => 100, 'paused' => true])->assertOk();
        $last = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 30, 'to' => 40, 'duration' => 100, 'paused' => true])->assertOk()->json('data');
        $this->assertSame(0, $last['pauses_left']);

        $this->assertSame(0, $this->asUser($user)->getJson("/api/v1/me/lessons/{$video->id}")->json('data.rules.pauses_left'));
    }

    public function test_a_lesson_without_a_pause_limit_reports_none(): void
    {
        [$video, $user] = $this->lesson('video', []);

        $rules = $this->asUser($user)->getJson("/api/v1/me/lessons/{$video->id}")->assertOk()->json('data.rules');
        $this->assertNull($rules['max_pauses']);
        $this->assertNull($rules['pauses_left']);
        $this->assertFalse($rules['require_fullscreen']);
        $beat = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video->id}/heartbeat", ['from' => 0, 'to' => 10, 'duration' => 100, 'paused' => true])->assertOk()->json('data');
        $this->assertNull($beat['pauses_left']);
    }

    public function test_a_slide_counts_only_after_the_minimum_time_on_it(): void
    {
        [$deck, $user] = $this->lesson('presentation', ['min_seconds_per_slide' => 20, 'min_view_percent' => 100], ['slide_count' => 3]);

        $this->assertSame(20, $this->asUser($user)->getJson("/api/v1/me/lessons/{$deck->id}")->assertOk()->json('data.rules.min_seconds_per_slide'));

        $this->travel(21)->seconds(); // the player reports a slide once it has been on screen long enough
        $this->asUser($user)->postJson("/api/v1/me/lessons/{$deck->id}/slide", ['slide' => 1, 'total' => 3])->assertOk();
        $this->travel(3)->seconds();
        $rushed = $this->asUser($user)->postJson("/api/v1/me/lessons/{$deck->id}/slide", ['slide' => 2, 'total' => 3])->assertOk()->json('data');
        $this->assertEqualsWithDelta(33.33, $rushed['percent'], 0.01); // slide 2 came too soon: only slide 1 counts

        $this->travel(25)->seconds();
        $read = $this->asUser($user)->postJson("/api/v1/me/lessons/{$deck->id}/slide", ['slide' => 2, 'total' => 3])->assertOk()->json('data');
        $this->assertEqualsWithDelta(66.67, $read['percent'], 0.01);
    }

    public function test_the_certificate_waits_for_an_evaluation_form_marked_required_for_it(): void
    {
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        Evaluation::create(['registration_id' => $registration->id, 'program_id' => $program->id, 'employee_id' => $employee->id, 'ratings' => [5], 'satisfaction_score' => 100, 'submitted_at' => now()]);
        $certificate = Certificate::create(['registration_id' => $registration->id, 'employee_id' => $employee->id, 'program_id' => $program->id, 'certificate_no' => 'C-'.uniqid(),
            'verification_code' => strtoupper(uniqid()), 'issued_at' => now(), 'hours' => 10, 'status' => 'valid']);
        $service = app(CertificateService::class);
        $this->assertTrue($service->downloadable($certificate));

        $form = EvaluationForm::create(['kind' => 'custom', 'title_ar' => 'ن', 'title_en' => 'F', 'questions' => [['id' => 'q1', 'type' => 'rating', 'title' => 'س', 'required' => true, 'scale' => ['min' => 1, 'max' => 5]]], 'approval_status' => 'approved',
            'settings' => ['required_for_certificate' => true]]);
        $assignment = EvaluationAssignment::create(['form_id' => $form->id, 'program_id' => $program->id, 'respondent_type' => 'trainee', 'respondent_user_id' => $employee->user_id, 'status' => 'pending']);
        $this->assertFalse($service->downloadable($certificate->fresh()));

        $this->assertNull($certificate->fresh()->available_notified_at);
        app(EvaluationService::class)->submit($assignment, ['q1' => 5], [], $employee->user);
        $this->assertSame('submitted', $assignment->fresh()->status);
        $this->assertTrue($service->downloadable($certificate->fresh()));
        $this->assertNotNull($certificate->fresh()->available_notified_at, 'the holder is told the certificate is ready');

        // A form that is not marked required does not hold the certificate back.
        $optional = EvaluationForm::create(['kind' => 'custom', 'title_ar' => 'ن', 'title_en' => 'F2', 'questions' => [['id' => 'q1', 'type' => 'rating', 'title' => 'س', 'required' => true, 'scale' => ['min' => 1, 'max' => 5]]], 'approval_status' => 'approved', 'settings' => []]);
        EvaluationAssignment::create(['form_id' => $optional->id, 'program_id' => $program->id, 'respondent_type' => 'trainee', 'respondent_user_id' => $employee->user_id, 'status' => 'pending']);
        $this->assertTrue($service->downloadable($certificate->fresh()));
    }
}
