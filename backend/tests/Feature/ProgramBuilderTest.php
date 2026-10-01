<?php

namespace Tests\Feature;

use App\Models\CalendarDay;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Trainer;
use App\Models\TrainingNeed;
use App\Models\TrainingRoom;
use App\Services\Eligibility\EligibilityEngine;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ProgramBuilderTest extends TestCase
{
    private function need(string $skillCode, int $count, string $priority, $school = null, array $extra = []): TrainingNeed
    {
        $skill = Skill::where('code', $skillCode)->first();
        $school ??= $this->makeSchool();

        return TrainingNeed::create($extra + [
            'school_id' => $school->id, 'skill_id' => $skill->id, 'skill_name' => $skill->name_ar, 'employees_count' => $count,
            'priority' => $priority, 'reason' => 'سبب '.$skillCode, 'status' => 'submitted',
        ]);
    }

    private function teacher(array $attributes): Employee
    {
        return $this->makeEmployee($attributes);
    }

    public function test_needs_pool_groups_topics_by_urgency_and_shows_existing_programs(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->need('classroom_management', 10, 'high');
        $this->need('classroom_management', 8, 'critical');
        $this->need('ai_in_education', 40, 'medium');
        $this->need('stem_pedagogy', 5, 'low', null, ['status' => 'planned']);   // already planned: not offered
        $covering = $this->makeProgram(['code' => 'CLM-1', 'status' => Program::STATUS_PUBLISHED]);
        $covering->skills()->attach(Skill::where('code', 'classroom_management')->first()->id, ['target_level' => 3]);

        $res = $this->asUser($admin)->getJson('/api/v1/admin/program-builder/options')->assertOk();
        $pool = collect($res->json('data.needs'));
        $this->assertCount(2, $pool);
        $this->assertSame('ai_in_education', $pool[0]['skill_code'], 'largest weighted demand first');
        $cm = $pool->firstWhere('skill_code', 'classroom_management');
        $this->assertSame(18, $cm['employees']);
        $this->assertSame('critical', $cm['priority']);
        $this->assertSame('CLM-1', $cm['existing_programs'][0]['code']);
        $res->assertJsonPath('data.totals.topics', 2)->assertJsonPath('data.totals.critical', 1);
        $this->assertContains('specializations', array_keys($res->json('data.filters')));
        $this->assertSame(['<30', '30-39', '40-49', '50+'], $res->json('data.filters.age_bands'));
    }

    public function test_draft_from_needs_fills_everything_and_skips_closed_days(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $school = $this->makeSchool();
        $a = $this->need('ai_in_education', 22, 'critical', $school, ['target_job_title_id' => JobTitle::where('code', 'TEACHER')->value('id')]);
        $b = $this->need('ai_in_education', 9, 'high');
        TrainingRoom::query()->delete();
        $room = TrainingRoom::create(['name_ar' => 'قاعة', 'name_en' => 'Hall', 'capacity' => 40, 'layouts' => ['classroom' => 40]]);
        Trainer::create(['name_ar' => 'خبير', 'name_en' => 'Expert', 'specializations' => ['ai_in_education'], 'rating' => 4.8]);
        Trainer::create(['name_ar' => 'آخر', 'name_en' => 'Other', 'specializations' => ['stem_pedagogy']]);

        $from = now()->addDays(14)->toDateString();
        $res = $this->asUser($admin)->postJson('/api/v1/admin/program-builder/draft', ['need_ids' => [$a->id, $b->id], 'from' => $from])->assertOk();
        $d = $res->json('data');

        $this->assertSame('برنامج الذكاء الاصطناعي في التعليم', $d['title_ar']);
        $this->assertSame(31, $d['demand']);
        $this->assertSame(2, $d['cohorts'], '31 people are split into two cohorts of at most 30');
        $this->assertSame(20, $d['capacity']);
        $this->assertEquals(15, $d['total_hours'], 'urgent needs get the longer format');
        $this->assertCount(3, $d['sessions'], '15 hours in program days of 5 hours (08:00–13:00)');
        $this->assertStringContainsString('08:00', $d['sessions'][0]['starts_at']);
        $this->assertSame($room->id, $d['sessions'][0]['training_room_id']);
        $this->assertSame([$school->id], array_slice($d['audience']['school_ids'], 0, 1));
        $this->assertSame('خبير', $d['trainers'][0]['name']);
        $this->assertCount(1, $d['trainers'], 'only trainers who match the topic are suggested');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $d['need_ids']);
        $this->assertNotEmpty($d['objectives']);

        // Weekends and marked days are never planned.
        foreach ($d['sessions'] as $session) {
            $this->assertNotContains(date('w', strtotime($session['starts_at'])), [5, 6]);
        }
        $blocked = date('Y-m-d', strtotime($d['sessions'][1]['starts_at']));
        CalendarDay::create(['date' => $blocked, 'type' => 'exam', 'title_ar' => 'ا', 'title_en' => 'E']);
        $again = $this->asUser($admin)->postJson('/api/v1/admin/program-builder/draft', ['need_ids' => [$a->id, $b->id], 'from' => $from])->json('data.sessions');
        $this->assertNotContains($blocked, array_map(fn ($s) => substr($s['starts_at'], 0, 10), $again));

        $this->asUser($admin)->postJson('/api/v1/admin/program-builder/schedule', ['total_hours' => 6, 'capacity' => 20, 'from' => $from, 'session_hours' => 2])
            ->assertOk()->assertJsonCount(3, 'data.sessions');
    }

    public function test_creating_from_needs_links_them_and_enforces_the_audience(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $need = $this->need('classroom_management', 12, 'high');
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Coach', 'specializations' => ['classroom_management']]);
        $skill = Skill::where('code', 'classroom_management')->first();

        $young = $this->teacher(['specialization' => 'رياضيات', 'experience_years' => 6, 'birth_date' => now()->subYears(31)]);
        $senior = $this->teacher(['specialization' => 'رياضيات', 'experience_years' => 15, 'birth_date' => now()->subYears(45)]);
        $science = $this->teacher(['specialization' => 'علوم', 'experience_years' => 7, 'birth_date' => now()->subYears(34)]);
        $unknownAge = $this->teacher(['specialization' => 'رياضيات', 'experience_years' => 8]);

        $day = now()->addDays(20);
        while (in_array($day->dayOfWeek, [5, 6], true)) {
            $day = $day->addDay();
        }
        $res = $this->asUser($admin)->postJson('/api/v1/admin/program-builder', [
            'title_ar' => 'إدارة الصف', 'title_en' => 'Classroom Management', 'total_hours' => 6, 'capacity' => 20, 'status' => 'registration_open',
            'skills' => [['id' => $skill->id, 'target_level' => 4]], 'trainers' => [['id' => $trainer->id, 'role' => 'lead']],
            'need_ids' => [$need->id],
            'audience' => ['specializations' => ['رياضيات'], 'experience_min' => 5, 'experience_max' => 10, 'age_min' => 25, 'age_max' => 40],
            'sessions' => [['starts_at' => $day->format('Y-m-d 09:00:00'), 'ends_at' => $day->format('Y-m-d 12:00:00')]],
            'registration_opens_at' => now()->subDay()->toDateTimeString(),
        ])->assertCreated();

        $program = Program::with(['eligibilityRules', 'sessions'])->find($res->json('data.id'));
        $this->assertSame('needs', $program->source_type);
        $this->assertNotEmpty($program->code);
        $this->assertSame('planned', $need->fresh()->status);
        $this->assertSame($program->id, $need->fresh()->program_id);
        $this->assertCount(1, $program->sessions);
        $this->assertSame($trainer->id, $program->sessions[0]->trainer_id, 'the lead trainer teaches the sessions');
        $this->assertSame(['age', 'age', 'experience_years', 'experience_years', 'specialization'], $program->eligibilityRules->pluck('field')->sort()->values()->all());
        $this->assertTrue($program->eligibilityRules->every->is_generated);

        // The generated rules are what registration enforces.
        $engine = app(EligibilityEngine::class);
        $this->assertTrue($engine->evaluate($program, $young)->eligible);
        $this->assertFalse($engine->evaluate($program, $senior)->eligible, 'too old and too experienced');
        $this->assertFalse($engine->evaluate($program, $science)->eligible, 'wrong specialization');
        $this->assertFalse($engine->evaluate($program, $unknownAge)->eligible, 'age unknown');

        // The preview counts the same people.
        $body = ['audience' => $program->audience];
        $this->asUser($admin)->postJson('/api/v1/admin/program-builder/audience/preview', $body)->assertOk()
            ->assertJsonPath('data.count', 1)->assertJsonPath('data.sample.0.id', $young->id)->assertJsonPath('data.sample.0.age', 31)
            ->assertJsonPath('data.by_age.1.label', '30-39')->assertJsonPath('data.by_age.1.value', 1);

        // Nominating the audience registers exactly those people, once.
        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/audience/nominate")->assertOk()->assertJsonPath('data.nominated', 1);
        $this->assertSame(1, Registration::where('program_id', $program->id)->count());
        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/audience/nominate")->assertOk()->assertJsonPath('data.nominated', 0)->assertJsonPath('data.already', 1);

        // Changing the audience replaces the generated rules but keeps manual ones.
        $program->eligibilityRules()->create(['field' => 'qualification', 'operator' => 'in', 'value' => ['master'], 'is_mandatory' => false, 'is_generated' => false, 'sort_order' => 99]);
        $this->asUser($admin)->putJson("/api/v1/admin/programs/{$program->id}/audience", ['audience' => ['genders' => ['female']]])->assertOk()->assertJsonPath('data.summary', 'جميع الموظفين');
        $fields = $program->refresh()->eligibilityRules->pluck('field')->sort()->values()->all();
        $this->assertSame(['gender', 'qualification'], $fields);
    }

    public function test_creation_is_blocked_by_calendar_room_and_trainer_and_rolls_back(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $need = $this->need('classroom_management', 12, 'high');
        $day = now()->addDays(20);
        while (in_array($day->dayOfWeek, [5, 6], true)) {
            $day = $day->addDay();
        }
        $base = fn (array $session) => [
            'title_ar' => 'ب', 'title_en' => 'P', 'total_hours' => 3, 'capacity' => 10, 'need_ids' => [$need->id], 'sessions' => [$session],
        ];
        $slot = ['starts_at' => $day->format('Y-m-d 09:00:00'), 'ends_at' => $day->format('Y-m-d 12:00:00')];

        CalendarDay::create(['date' => $day->toDateString(), 'type' => 'vacation', 'title_ar' => 'إ', 'title_en' => 'V']);
        $this->asUser($admin)->postJson('/api/v1/admin/program-builder', $base($slot))->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');
        $this->assertSame(0, Program::count());
        $this->assertSame('submitted', $need->fresh()->status, 'a failed creation leaves the need untouched');

        // An approver can approve the day in the same request.
        $this->asUser($admin)->postJson('/api/v1/admin/program-builder', $base($slot) + ['calendar_approval_reason' => 'Ministry request'])->assertCreated();

        $room = TrainingRoom::create(['name_ar' => 'ق', 'name_en' => 'R', 'capacity' => 30, 'layouts' => ['classroom' => 30]]);
        $this->makeSession(Program::first(), CarbonImmutable::parse($day->format('Y-m-d 10:00')))->update(['training_room_id' => $room->id]);
        $this->asUser($admin)->postJson('/api/v1/admin/program-builder', ['title_en' => 'Q', 'title_ar' => 'س', 'code' => 'Q-1'] + $base($slot + ['training_room_id' => $room->id]))->assertUnprocessable()->assertJsonPath('code', 'room_conflict');
        $this->assertNull(Program::where('code', 'Q-1')->first());
    }

    public function test_only_program_managers_can_use_the_builder(): void
    {
        $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/admin/program-builder/options')->assertForbidden();
        $this->asUser($this->makeUser(Role::SCHOOL_ADMIN))->postJson('/api/v1/admin/program-builder/audience/preview', ['audience' => []])->assertForbidden();
        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson('/api/v1/admin/program-builder/audience/preview', ['audience' => ['age_min' => 30]])->assertOk();
    }
}
