<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\NeedsSurvey;
use App\Models\Role;
use App\Models\Skill;
use App\Models\TrainingNeed;
use Database\Seeders\DemoNeedsSurveySeeder;
use Tests\TestCase;

class NeedsSurveyTest extends TestCase
{
    public function test_admin_builds_targets_publishes_and_turns_results_into_needs(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $qatariTeacher = $this->makeEmployee(['school_id' => $schoolA->id, 'nationality' => 'Qatar', 'specialization' => 'رياضيات', 'experience_years' => 2]);
        $otherTeacher = $this->makeEmployee(['school_id' => $schoolB->id, 'nationality' => 'Jordan', 'specialization' => 'رياضيات', 'experience_years' => 12]);
        $outsider = $this->makeEmployee(['school_id' => $schoolB->id, 'nationality' => 'Qatar', 'specialization' => 'علوم']);
        $skill = Skill::where('code', 'classroom_management')->first();

        // Templates are linked to the skills catalogue.
        $templates = $this->asUser($admin)->getJson('/api/v1/admin/needs-surveys/templates')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(5, count($templates));
        $this->assertSame($skill->id, $templates[0]['questions'][1]['rows'][0]['skill_id']);

        // Audience preview: only math teachers.
        $this->asUser($admin)->postJson('/api/v1/admin/needs-surveys/audience/preview', ['audience' => ['specializations' => ['رياضيات']]])
            ->assertOk()->assertJsonPath('data.count', 2);

        $questions = [
            ['id' => 'm', 'type' => 'matrix', 'title' => 'مستوى التمكن', 'required' => true, 'scale' => ['min' => 1, 'max' => 5], 'rows' => [
                ['id' => 'r1', 'label' => 'إدارة الصف', 'skill_id' => $skill->id],
                ['id' => 'r2', 'label' => 'البحث الإجرائي', 'skill_name' => 'البحث الإجرائي'],
            ]],
            ['id' => 'ai', 'type' => 'yes_no', 'title' => 'هل تستخدم الذكاء الاصطناعي؟', 'required' => true],
            ['id' => 'why', 'type' => 'long_text', 'title' => 'لماذا لا؟', 'required' => true, 'show_if' => ['question' => 'ai', 'op' => 'equals', 'value' => 'no']],
        ];
        $id = $this->asUser($admin)->postJson('/api/v1/admin/needs-surveys', [
            'title' => 'استبانة الرياضيات', 'questions' => $questions, 'audience' => ['specializations' => ['رياضيات']],
        ])->assertCreated()->json('data.id');

        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/publish")->assertStatus(422)->assertJsonPath('code', 'instrument_not_approved');
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/submit-approval")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/approve")->assertOk();
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/publish")->assertOk()->assertJsonPath('added', 2);
        $this->assertSame(1, AppNotification::where('user_id', $qatariTeacher->user_id)->where('type', 'needs_survey.invite')->count());
        $this->assertSame(0, AppNotification::where('user_id', $outsider->user_id)->count());
        // Publishing again adds nobody new.
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/publish")->assertOk()->assertJsonPath('added', 0);

        // Employees outside the audience cannot see it.
        $this->asUser($outsider->user)->getJson("/api/v1/me/needs-surveys/$id")->assertForbidden();
        $this->asUser($qatariTeacher->user)->getJson('/api/v1/me/needs-surveys')->assertOk()->assertJsonCount(1, 'data');

        // Conditional logic: "why" becomes required only when answering "no".
        $this->asUser($qatariTeacher->user)->postJson("/api/v1/me/needs-surveys/$id", ['answers' => ['m' => ['r1' => 1, 'r2' => 2], 'ai' => 'no']])
            ->assertUnprocessable()->assertJsonValidationErrors('answers.why');
        $this->asUser($qatariTeacher->user)->postJson("/api/v1/me/needs-surveys/$id", ['answers' => ['m' => ['r1' => 1, 'r2' => 2], 'ai' => 'no', 'why' => 'لا أعرف الأدوات']])->assertOk();
        $this->asUser($otherTeacher->user)->postJson("/api/v1/me/needs-surveys/$id", ['answers' => ['m' => ['r1' => 2, 'r2' => 5], 'ai' => 'yes', 'why' => 'ignored']])->assertOk();

        $report = $this->asUser($admin)->getJson("/api/v1/admin/needs-surveys/$id/report")->assertOk()->json('data');
        $this->assertSame(100.0, (float) $report['summary']['response_rate']);
        $top = $report['needs'][0];
        $this->assertSame($skill->id, $top['skill_id']);
        $this->assertSame(2, $top['in_need']);
        $this->assertSame(88, $top['index']); // (1.0 + 0.75) / 2
        $this->assertSame('critical', $top['priority']);
        $this->assertCount(2, $top['by_school']);

        // Hidden-question answers are dropped.
        $stored = NeedsSurvey::find($id)->responses()->where('user_id', $otherTeacher->user_id)->first()->answers;
        $this->assertArrayNotHasKey('why', $stored);

        // Filtered report: only the experienced teacher.
        $this->asUser($admin)->getJson("/api/v1/admin/needs-surveys/$id/report?experience_band=11-15")->assertOk()->assertJsonPath('data.summary.filtered_responses', 1);

        // Generate one need per school.
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/generate-needs", ['items' => [['key' => $skill->id]], 'split' => 'school'])
            ->assertOk()->assertJsonPath('created', 2);
        $this->assertSame(2, TrainingNeed::where('survey_id', $id)->where('skill_id', $skill->id)->where('status', 'approved')->count());
        // Regenerating updates instead of duplicating; overall needs have no school.
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/generate-needs", ['items' => [['key' => $skill->id]], 'split' => 'school'])->assertOk();
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/generate-needs", ['items' => [['key' => $skill->id]]])->assertOk();
        $this->assertSame(3, TrainingNeed::where('survey_id', $id)->count());
        $this->assertNull(TrainingNeed::where('survey_id', $id)->whereNull('school_id')->first()?->school_id);
        $this->asUser($admin)->getJson('/api/v1/admin/training-needs')->assertOk()->assertJsonPath('total', 3);

        $csv = $this->asUser($admin)->get("/api/v1/admin/needs-surveys/$id/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('إدارة الصف', $csv);

        // Closed surveys refuse answers.
        $this->asUser($admin)->postJson("/api/v1/admin/needs-surveys/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->asUser($qatariTeacher->user)->postJson("/api/v1/me/needs-surveys/$id", ['answers' => ['m' => ['r1' => 3, 'r2' => 3], 'ai' => 'yes']])->assertUnprocessable();
    }

    public function test_surveys_can_start_from_a_template_and_are_admin_only(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $survey = $this->asUser($admin)->postJson('/api/v1/admin/needs-surveys', ['source' => 'template', 'template_key' => 'digital_readiness'])
            ->assertCreated()->json('data');
        $this->assertSame('الجاهزية الرقمية والذكاء الاصطناعي', $survey['title']);
        $this->assertSame('draft', $survey['status']);
        $this->assertSame('uses_ai', $survey['questions'][1]['show_if']['question']);

        $this->asUser($admin)->postJson('/api/v1/admin/needs-surveys', ['title' => 'x', 'questions' => [['type' => 'single', 'title' => 'q', 'options' => ['one']]]])
            ->assertUnprocessable();

        $employee = $this->makeEmployee();
        $this->asUser($employee->user)->getJson('/api/v1/admin/needs-surveys')->assertForbidden();
        $this->asUser($admin)->getJson('/api/v1/admin/needs-surveys')->assertOk()->assertJsonPath('data.0.questions_count', 5);
    }

    public function test_demo_survey_seeds_once_with_responses(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makeEmployee(['experience_years' => $i * 3]);
        }
        $this->seed(DemoNeedsSurveySeeder::class);
        $this->seed(DemoNeedsSurveySeeder::class);

        $this->assertSame(1, NeedsSurvey::count());
        $survey = NeedsSurvey::first();
        $this->assertSame(6, $survey->recipients()->count());
        $this->assertSame($survey->responses()->count(), $survey->recipients()->whereNotNull('responded_at')->count());
    }
}
