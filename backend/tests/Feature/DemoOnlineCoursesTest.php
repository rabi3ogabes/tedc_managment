<?php

namespace Tests\Feature;

use App\Models\CourseLesson;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\QuizQuestion;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\CourseService;
use Database\Seeders\DemoOnlineCoursesSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Tests\TestCase;

class DemoOnlineCoursesTest extends TestCase
{
    private function seedDemo(): void
    {
        ProgramCategory::create(['name_ar' => 'عام', 'name_en' => 'General', 'slug' => 'general']);
        $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => 'teacher@tedc.qa']));
        $this->seed(DemoTestAccountsSeeder::class);
        $this->seed(DemoOnlineCoursesSeeder::class);
    }

    public function test_five_courses_are_created_once_and_enrol_the_test_trainees(): void
    {
        $this->seedDemo();
        $this->seed(DemoOnlineCoursesSeeder::class); // idempotent

        $courses = Program::where('code', 'like', 'TEST-OL%')->orderBy('code')->get();
        $this->assertCount(5, $courses);
        foreach ($courses as $course) {
            $this->assertTrue($course->has_course);
            $this->assertGreaterThan(2, $course->courseLessons()->count());
            $this->assertSame($course->courseModules()->count(), $course->courseModules()->distinct('id')->count());
        }
        $this->assertSame($courses->first()->courseLessons()->count(), Program::where('code', 'TEST-OL1')->first()->courseLessons()->count());

        // Every lesson type and every quiz / survey question kind is represented across the five.
        $this->assertEqualsCanonicalizing([CourseLesson::VIDEO, CourseLesson::PRESENTATION, CourseLesson::QUIZ, CourseLesson::SURVEY, CourseLesson::ARTICLE], CourseLesson::query()->distinct()->pluck('type')->all());
        $this->assertEqualsCanonicalizing(['single', 'multiple', 'true_false'], QuizQuestion::query()->distinct()->pluck('type')->all());
        $this->assertTrue(CourseLesson::where('status', 'draft')->exists());
        $this->assertTrue(CourseLesson::where('is_required', false)->exists());

        $enrolled = fn (string $email, string $code) => Registration::whereHas('employee.user', fn ($q) => $q->where('email', $email))
            ->whereHas('program', fn ($q) => $q->where('code', $code))->exists();
        $this->assertTrue($enrolled('trainee1@tedc.qa', 'TEST-OL1'));
        $this->assertFalse($enrolled('trainee1@tedc.qa', 'TEST-OL3'));
        $this->assertFalse($enrolled('trainee4@tedc.qa', 'TEST-OL5'), 'course 5 stays open for self-registration');
    }

    public function test_a_trainee_can_finish_a_seeded_course_and_get_the_certificate(): void
    {
        $this->seedDemo();
        $user = User::where('email', 'trainee1@tedc.qa')->first();
        $program = Program::where('code', 'TEST-OL2')->first();
        $registration = Registration::where('program_id', $program->id)->where('employee_id', $user->employee->id)->first();

        $this->asUser($user)->getJson("/api/v1/me/registrations/{$registration->id}/course")->assertOk()->assertJsonPath('data.modules.0.lessons.1.locked', false);

        // Complete every lesson the honest way: the quizzes with their correct answers, the rest as finished.
        foreach ($program->courseLessons()->where('status', 'published')->orderBy('sort_order')->get() as $lesson) {
            if ($lesson->type === 'quiz') {
                $answers = $lesson->questions->mapWithKeys(fn ($q) => [$q->id => collect($q->options)->where('correct', true)->pluck('id')->all()])->all();
                $this->asUser($user)->postJson("/api/v1/me/lessons/{$lesson->id}/quiz", ['answers' => $answers])->assertOk()->assertJsonPath('data.passed', true);
            } else {
                $service = app(CourseService::class);
                $position = 0.0;
                for ($i = 0; $i < 8 && ! ($done['completed'] ?? false); $i++) {
                    $this->travel(20)->seconds();
                    $done = $service->heartbeat($lesson, $registration->fresh(), $position, $position + 20);
                    $position = $done['position'];
                }
                unset($done);
            }
        }

        $registration->refresh();
        $this->assertTrue($registration->course_completed);
        $this->assertSame('issued', $registration->certificate_status);
    }
}
