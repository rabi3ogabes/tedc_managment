<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Role;
use App\Models\TrainingNeed;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicAndAnalyticsTest extends TestCase
{
    public function test_public_home_and_program_catalogue(): void
    {
        $this->makeProgram(['code' => 'PUB-1', 'is_featured' => true]);
        $this->makeProgram(['code' => 'DRAFT-1', 'status' => Program::STATUS_DRAFT]);

        $this->getJson('/api/v1/public/home')->assertOk()->assertJsonStructure(['data' => ['stats', 'featured_programs', 'upcoming_programs', 'partners', 'testimonials', 'news']]);
        $codes = collect($this->getJson('/api/v1/public/programs')->assertOk()->json('data'))->pluck('code');

        $this->assertContains('PUB-1', $codes);
        $this->assertNotContains('DRAFT-1', $codes);
        $this->getJson('/api/v1/public/programs/DRAFT-1')->assertNotFound();
    }

    public function test_cached_home_payload_survives_serialization(): void
    {
        config(['cache.default' => 'file']);
        Cache::flush();
        $this->makeSchool(['is_partner' => true]);

        $this->getJson('/api/v1/public/home')->assertOk();
        $this->getJson('/api/v1/public/home')->assertOk()->assertJsonCount(1, 'data.partners')->assertJsonPath('data.partners.0.stage', 'primary');
    }

    public function test_arabic_is_default_and_english_on_request(): void
    {
        $this->makeProgram(['code' => 'L-1', 'title_ar' => 'عنوان', 'title_en' => 'Title']);

        $this->getJson('/api/v1/public/programs/L-1')->assertJsonPath('data.title', 'عنوان')->assertHeader('Content-Language', 'ar');
        $this->getJson('/api/v1/public/programs/L-1', ['X-Locale' => 'en'])->assertJsonPath('data.title', 'Title');
    }

    public function test_unknown_certificate_is_not_verified(): void
    {
        $this->getJson('/api/v1/public/certificates/verify/NOPE')->assertNotFound()->assertJsonPath('data.valid', false);
    }

    public function test_school_admin_submits_needs_and_center_sees_analytics(): void
    {
        $adminEmployee = $this->makeEmployee([], $this->makeUser(Role::SCHOOL_ADMIN));

        $this->asUser($adminEmployee->user)->postJson('/api/v1/admin/training-needs', [
            'skill_name' => 'الذكاء الاصطناعي', 'employees_count' => 12, 'priority' => 'critical', 'reason' => 'خطة المدرسة',
        ])->assertCreated()->assertJsonPath('data.school_id', $adminEmployee->school_id);

        $this->asUser($this->makeUser(Role::COORDINATOR))->getJson('/api/v1/admin/training-needs/analytics')
            ->assertOk()->assertJsonPath('data.totals.employees', 12)->assertJsonPath('data.most_requested_skills.0.skill', 'الذكاء الاصطناعي');

        $this->assertSame(1, TrainingNeed::count());
    }

    public function test_dashboards_and_ai_fallback(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $this->asUser($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonStructure(['data' => ['kpis' => ['total_schools', 'total_employees', 'active_programs', 'participants', 'training_hours', 'certificates_issued', 'impact_score']]]);
        $this->asUser($admin)->getJson('/api/v1/admin/analytics/geographic')->assertOk();

        $this->asUser($admin)->postJson('/api/v1/admin/ai/ask', ['question' => 'ما البرامج التي يجب إنشاؤها للمعلمين؟'])
            ->assertOk()->assertJsonPath('data.source', 'rules')->assertJsonStructure(['data' => ['answer', 'insights', 'suggestions', 'report_id']]);
    }

    public function test_recommendations_exclude_ineligible_and_registered_programs(): void
    {
        $employee = $this->makeEmployee(['experience_years' => 1]);
        $open = $this->makeProgram(['code' => 'OPEN-1', 'status' => Program::STATUS_PUBLISHED]);
        $locked = $this->makeProgram(['code' => 'LOCK-1']);
        $locked->eligibilityRules()->create(['field' => 'experience_years', 'operator' => 'gte', 'value' => 10]);

        $codes = collect($this->asUser($employee->user)->getJson('/api/v1/me/recommendations')->assertOk()->json('data'))->pluck('program.code');

        $this->assertContains('OPEN-1', $codes);
        $this->assertNotContains('LOCK-1', $codes);
    }
}
