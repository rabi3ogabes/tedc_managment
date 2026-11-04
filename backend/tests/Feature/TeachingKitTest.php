<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\User;
use Tests\TestCase;

class TeachingKitTest extends TestCase
{
    private function course(): Program
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true]);
        $module = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/course/modules", ['title_ar' => 'الوحدة 1', 'title_en' => 'Unit 1'])->assertCreated()->json('data');
        $make = fn (array $lesson) => $this->asUser($admin)->postJson("/api/v1/admin/course/modules/{$module['id']}/lessons", $lesson + ['status' => 'published'])->assertCreated()->json('data');

        $make(['type' => 'video', 'title_ar' => 'فيديو', 'title_en' => 'Video', 'duration_seconds' => 600]);
        $quiz = $make(['type' => 'quiz', 'title_ar' => 'اختبار', 'title_en' => 'Quiz']);
        $this->asUser($admin)->putJson("/api/v1/admin/course/lessons/{$quiz['id']}/questions", ['questions' => [[
            'type' => 'single', 'text_ar' => 'سؤال', 'points' => 2,
            'options' => [['id' => 'a', 'text_ar' => 'صح', 'correct' => true], ['id' => 'b', 'text_ar' => 'خطأ', 'correct' => false]],
        ]]])->assertOk();
        $make(['type' => 'article', 'title_ar' => 'مقال', 'title_en' => 'Article', 'body_ar' => 'نص', 'duration_seconds' => 300]);
        $make(['type' => 'article', 'title_ar' => 'مسودة', 'title_en' => 'Draft', 'status' => 'draft']);

        return $program;
    }

    private function trainerOf(Program $program): User
    {
        $user = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $user->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'status' => 'active']);
        $program->trainers()->attach($trainer->id, ['role' => 'lead']);

        return $user;
    }

    public function test_the_trainer_of_an_online_programme_sees_its_whole_kit_without_the_drafts(): void
    {
        $program = $this->course();

        $res = $this->asUser($this->trainerOf($program))->getJson("/api/v1/me/programs/{$program->id}/kit")->assertOk()
            ->assertJsonPath('data.program.title', 'برنامج')->assertJsonPath('data.totals.lessons', 3)->assertJsonPath('data.totals.modules', 1)
            ->assertJsonPath('data.totals.minutes', 15)->assertJsonPath('data.totals.by_type.video', 1)->assertJsonPath('data.totals.by_type.quiz', 1)->assertJsonPath('data.totals.by_type.article', 1);

        $lessons = collect($res->json('data.modules.0.lessons'))->keyBy('type');
        $this->assertSame(['video', 'quiz', 'article'], $lessons->keys()->all());
        $this->assertSame(1, $lessons['quiz']['questions']);
        $this->assertSame(70, $lessons['quiz']['pass_percent']);
        $this->assertNull($lessons['video']['questions']);
        // What a learner would answer is never part of it.
        $this->assertStringNotContainsString('correct', $res->getContent());
    }

    public function test_a_trainer_who_only_teaches_a_session_and_a_manager_may_open_it_others_may_not(): void
    {
        $program = $this->course();

        $viaSession = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $viaSession->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer 2', 'status' => 'active']);
        $this->makeSession($program, now()->addDay())->update(['trainer_id' => $trainer->id]);
        $this->asUser($viaSession)->getJson("/api/v1/me/programs/{$program->id}/kit")->assertOk();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson("/api/v1/me/programs/{$program->id}/kit")->assertOk();

        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/me/programs/{$program->id}/kit")->assertForbidden();
        $stranger = $this->makeUser(Role::TRAINER);
        Trainer::create(['user_id' => $stranger->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer 3', 'status' => 'active']);
        $this->asUser($stranger)->getJson("/api/v1/me/programs/{$program->id}/kit")->assertForbidden();
    }

    public function test_a_programme_without_a_course_has_no_kit(): void
    {
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => false]);

        $this->asUser($this->trainerOf($program))->getJson("/api/v1/me/programs/{$program->id}/kit")->assertNotFound();
    }

    public function test_the_trainers_day_names_the_programme_so_the_app_can_open_its_kit(): void
    {
        $program = $this->course();
        $user = $this->trainerOf($program);
        $session = $this->makeSession($program, now()->setTime(11, 0));
        $session->update(['trainer_id' => $user->trainer->id, 'mode' => 'online']);

        $today = $this->asUser($user)->getJson('/api/v1/me/app-home')->assertOk()->json('data.today');
        $this->assertSame($program->id, $today[0]['program_id']);
        $this->assertSame('online', $today[0]['mode']);
        $this->assertTrue($today[0]['has_course']);   // the app shows the kit button only when there is a kit
    }
}
