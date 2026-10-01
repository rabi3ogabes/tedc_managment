<?php

namespace Tests\Feature;

use App\Models\CourseLesson;
use App\Models\QuizQuestion;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Services\CourseService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnlineCourseTest extends TestCase
{
    private function build(): array
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram(['delivery_mode' => 'online', 'requires_evaluation' => false]);
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED, 'attendance_percent' => 100]);

        $module = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/course/modules", ['title_ar' => 'الوحدة 1', 'title_en' => 'Unit 1'])->assertCreated()->json('data');
        $video = $this->asUser($admin)->postJson("/api/v1/admin/course/modules/{$module['id']}/lessons", ['type' => 'video', 'title_ar' => 'فيديو', 'title_en' => 'Video', 'duration_seconds' => 100, 'status' => 'published', 'settings' => ['allow_seeking' => false]])->assertCreated()->json('data');
        $quiz = $this->asUser($admin)->postJson("/api/v1/admin/course/modules/{$module['id']}/lessons", ['type' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz', 'status' => 'published'])->assertCreated()->json('data');
        $this->asUser($admin)->putJson("/api/v1/admin/course/lessons/{$quiz['id']}/questions", ['questions' => [[
            'type' => 'single', 'text_ar' => 'سؤال', 'points' => 2,
            'options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]],
        ]]])->assertOk();

        return [$admin, $program, $employee, $registration, $video, $quiz];
    }

    public function test_lessons_unlock_in_order_and_completing_the_course_unlocks_the_certificate(): void
    {
        [, $program, $employee, $registration, $video, $quiz] = $this->build();
        $user = $employee->user;

        $this->asUser($user)->getJson("/api/v1/me/registrations/{$registration->id}/course")->assertOk()
            ->assertJsonPath('data.summary.required', 2)->assertJsonPath('data.modules.0.lessons.1.locked', true);
        $this->asUser($user)->getJson("/api/v1/me/lessons/{$quiz['id']}")->assertStatus(422)->assertJsonPath('code', 'lesson_locked');

        // Watching: a script claiming the whole video in one call earns only what wall-clock time allows.
        $this->asUser($user)->getJson("/api/v1/me/lessons/{$video['id']}")->assertOk()->assertJsonPath('data.rules.allow_seeking', false);
        $res = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video['id']}/heartbeat", ['from' => 0, 'to' => 100])->assertOk();
        $this->assertLessThan(60, $res->json('data.percent'));
        // Skipping ahead when seeking is off earns nothing.
        $skip = $this->asUser($user)->postJson("/api/v1/me/lessons/{$video['id']}/heartbeat", ['from' => 80, 'to' => 90])->assertOk();
        $this->assertSame(0.0, (float) $skip->json('data.credited'));

        // Honest watching over time completes the lesson.
        $service = app(CourseService::class);
        $lesson = CourseLesson::find($video['id']);
        $position = 4.0;
        $done = ['completed' => false];
        for ($i = 0; $i < 6 && ! $done['completed']; $i++) {
            $this->travel(20)->seconds();
            $done = $service->heartbeat($lesson, $registration->fresh(), $position, $position + 20);
            $position = $done['position'];
        }
        $this->assertTrue($done['completed']);

        $this->asUser($user)->getJson("/api/v1/me/lessons/{$quiz['id']}")->assertOk()->assertJsonMissingPath('data.quiz.questions.0.options.0.correct');
        $q = QuizQuestion::first();
        $this->asUser($user)->postJson("/api/v1/me/lessons/{$quiz['id']}/quiz", ['answers' => [$q->id => ['b']]])->assertOk()->assertJsonPath('data.passed', false)->assertJsonPath('data.score_percent', 0);
        $this->asUser($user)->postJson("/api/v1/me/lessons/{$quiz['id']}/quiz", ['answers' => [$q->id => ['a']]])->assertOk()->assertJsonPath('data.passed', true)->assertJsonPath('data.score_percent', 100);

        $registration->refresh();
        $this->assertTrue($registration->course_completed);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'course.completed']);
        // Completing the course issues the certificate by itself.
        $this->assertSame('issued', $registration->certificate_status);
        $this->assertDatabaseHas('certificates', ['registration_id' => $registration->id, 'status' => 'valid']);
        $this->asUser($user)->getJson("/api/v1/me/registrations/{$registration->id}/course")->assertJsonPath('data.summary.certificate.issued', true);
    }

    public function test_media_uploads_are_signed_and_attached_only_to_their_own_lesson(): void
    {
        [$admin, , , , $video] = $this->build();

        $signed = $this->asUser($admin)->postJson("/api/v1/admin/course/lessons/{$video['id']}/upload-url", ['filename' => 'intro.mp4', 'mime' => 'video/mp4', 'size' => 50_000_000])->assertOk()->json('data');
        $this->assertStringStartsWith('/api/v1/uploads/', $signed['url']);
        $this->asUser($admin)->postJson("/api/v1/admin/course/lessons/{$video['id']}/upload-url", ['filename' => 'x.exe', 'mime' => 'application/x-msdownload', 'size' => 100])->assertStatus(422);

        $this->asUser($admin)->postJson("/api/v1/admin/course/lessons/{$video['id']}/file", ['path' => 'course/other/x.mp4', 'name' => 'x', 'mime' => 'video/mp4', 'size' => 1])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/admin/course/lessons/{$video['id']}/file", ['path' => $signed['path'], 'name' => 'intro.mp4', 'mime' => 'video/mp4', 'size' => 50_000_000, 'duration_seconds' => 312])
            ->assertOk()->assertJsonPath('data.duration_seconds', 312)->assertJsonPath('data.has_file', true);

        $this->asUser($admin)->getJson('/api/v1/admin/programs/'.CourseLesson::find($video['id'])->program_id.'/course/analytics')->assertOk()->assertJsonPath('data.summary.learners', 1);
    }

    public function test_a_blueprint_and_a_training_kit_prepare_the_course(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram(['delivery_mode' => 'online']);

        $starters = $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/course/starters")->assertOk()->json('data');
        $this->assertCount(3, $starters['blueprints']);

        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/course/blueprint", ['blueprint' => 'micro', 'watch' => 'strict'])->assertCreated()->assertJsonPath('data.created', 6);
        $course = $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/course")->json('data');
        $this->assertTrue($course['settings']['has_course']);
        $this->assertFalse($course['modules'][0]['lessons'][0]['settings']['allow_seeking']);

        // A kit's video and slides become lessons; its other files are not importable.
        Storage::disk('local')->put('documents/kits/k/files/a.mp4', 'video');
        Storage::disk('local')->put('documents/kits/k/files/b.pdf', 'pdf');
        $kit = TrainingKit::create(['code' => 'K-1', 'title_ar' => 'حقيبة', 'title_en' => 'Kit', 'status' => 'approved', 'owner_id' => $admin->id, 'program_id' => $program->id]);
        $video = $kit->files()->create(['name' => 'Intro', 'original_name' => 'a.mp4', 'kind' => 'video', 'category' => 'media', 'source' => 'upload', 'mime' => 'video/mp4', 'size' => 5, 'storage_path' => 'kits/k/files/a.mp4']);
        $slides = $kit->files()->create(['name' => 'Slides', 'original_name' => 'b.pdf', 'kind' => 'pdf', 'category' => 'presentation', 'source' => 'upload', 'mime' => 'application/pdf', 'size' => 3, 'storage_path' => 'kits/k/files/b.pdf']);
        $doc = $kit->files()->create(['name' => 'Guide', 'original_name' => 'g.docx', 'kind' => 'document', 'category' => 'trainer_guide', 'source' => 'upload', 'mime' => 'x', 'size' => 1, 'storage_path' => 'kits/k/files/g.docx']);

        $kits = $this->asUser($admin)->getJson("/api/v1/admin/programs/{$program->id}/course/starters")->json('data.kits');
        $this->assertTrue($kits[0]['linked']);
        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/course/import-kit", ['kit_id' => $kit->id, 'files' => [$doc->id]])->assertStatus(422);
        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/course/import-kit", ['kit_id' => $kit->id, 'files' => [$video->id, $slides->id, $doc->id]])->assertCreated()->assertJsonPath('data.created', 2);

        $lesson = CourseLesson::where('type', 'video')->where('title_ar', 'Intro')->first();
        $this->assertSame('upload', $lesson->source);
        $this->assertTrue(Storage::disk('local')->exists('materials/'.$lesson->file_path));
        $this->assertTrue(Storage::disk('local')->exists('documents/kits/k/files/a.mp4')); // the kit keeps its file
    }
}
