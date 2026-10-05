<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Program;
use App\Models\Role;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    private function types(array $res): array
    {
        return collect($res)->pluck('type')->all();
    }

    public function test_staff_find_programs_people_and_trainers_but_only_inside_their_scope(): void
    {
        $this->makeProgram(['code' => 'LEAD-101', 'title_ar' => 'القيادة التربوية', 'title_en' => 'Educational leadership']);
        $mine = $this->makeSchool();
        $ana = $this->makeEmployee(['school_id' => $mine->id], $this->makeUser(Role::EMPLOYEE, ['name' => 'Leila Hassan', 'name_ar' => 'ليلى حسن']));
        $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['name' => 'Leila Other', 'name_ar' => 'ليلى أخرى']));
        $schoolAdmin = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $mine->id], $schoolAdmin);

        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $res = $this->asUser($admin)->getJson('/api/v1/search?q=lead')->assertOk()->json('data');
        $this->assertContains('programs', $this->types($res));
        $this->assertSame('LEAD-101', collect($res)->firstWhere('type', 'programs')['items'][0]['code']);

        $people = collect($this->asUser($admin)->getJson('/api/v1/search?q=Leila')->json('data'))->firstWhere('type', 'people')['items'];
        $this->assertCount(2, $people);

        $scoped = collect($this->asUser($schoolAdmin)->getJson('/api/v1/search?q=Leila')->json('data'))->firstWhere('type', 'people')['items'];
        $this->assertSame([$ana->id], array_column($scoped, 'id'), 'a school administrator only finds people of their own school');
    }

    public function test_a_trainee_finds_open_programs_and_news_but_never_people_or_drafts(): void
    {
        $this->makeProgram(['code' => 'OPEN-1', 'title_ar' => 'برنامج مفتوح', 'title_en' => 'Open writing program', 'status' => Program::STATUS_REGISTRATION_OPEN]);
        $this->makeProgram(['code' => 'DRAFT-1', 'title_ar' => 'مسودة', 'title_en' => 'Draft writing program', 'status' => Program::STATUS_DRAFT]);
        Announcement::create(['type' => 'news', 'title_ar' => 'خبر الكتابة', 'title_en' => 'Writing news', 'body_ar' => 'ن', 'body_en' => 'n', 'audience' => 'all', 'is_public' => true, 'published_at' => now()->subDay()]);
        $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['name' => 'Writing Person']));
        $trainee = $this->makeEmployee()->user;

        $res = $this->asUser($trainee)->getJson('/api/v1/search?q=writing')->assertOk()->json('data');
        $types = $this->types($res);

        $this->assertContains('programs', $types);
        $this->assertContains('news', $types);
        $this->assertNotContains('people', $types);
        $this->assertSame(['OPEN-1'], array_column(collect($res)->firstWhere('type', 'programs')['items'], 'code'));
    }

    public function test_short_queries_return_nothing_and_the_endpoint_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/search?q=lead')->assertUnauthorized();
        $this->assertSame([], $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/search?q=a')->assertOk()->json('data'));
    }
}
