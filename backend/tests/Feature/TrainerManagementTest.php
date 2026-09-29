<?php

namespace Tests\Feature;

use App\Models\PartnerOrganization;
use App\Models\Role;
use App\Models\Skill;
use App\Models\Trainer;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TrainerManagementTest extends TestCase
{
    private function partner(string $en = 'Qatar Foundation'): PartnerOrganization
    {
        return PartnerOrganization::create(['name_ar' => 'مؤسسة قطر', 'name_en' => $en, 'type' => 'foundation']);
    }

    private function trainer(array $extra = []): Trainer
    {
        return Trainer::create($extra + ['name_ar' => 'مدرب', 'name_en' => 'Trainer '.uniqid(), 'specializations' => ['active_learning'], 'rating' => 4.5]);
    }

    public function test_every_source_is_validated_and_completed(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $post = fn (array $body) => $this->asUser($admin)->postJson('/api/v1/admin/trainers', $body);
        $name = ['name_ar' => 'مدرب', 'name_en' => 'Trainer'];

        // Center trainers need nothing more and are inside the organization.
        $post($name + ['source' => 'center'])->assertCreated()->assertJsonPath('data.is_external', false)->assertJsonPath('data.source_label', 'مدرب من المركز');

        // School trainers must belong to a school...
        $post($name + ['source' => 'school'])->assertUnprocessable()->assertJsonValidationErrors('school_id');
        $school = $this->makeSchool();
        $post($name + ['source' => 'school', 'school_id' => $school->id])->assertCreated()->assertJsonPath('data.school.id', $school->id);

        // ...or be picked from the staff list, which pre-fills the profile.
        $employee = $this->makeEmployee(['school_id' => $school->id, 'specialization' => 'رياضيات', 'experience_years' => 9], $this->makeUser(Role::EMPLOYEE, ['name' => 'Layla Teacher', 'name_ar' => 'ليلى المعلمة', 'email' => 'layla@test.qa']));
        $this->asUser($admin)->getJson('/api/v1/admin/trainers/candidates?q=Layla')->assertOk()->assertJsonPath('data.0.employee_id', $employee->id)->assertJsonPath('data.0.already_trainer', false);
        $created = $post(['source' => 'school', 'employee_id' => $employee->id])->assertCreated()
            ->assertJsonPath('data.name_ar', 'ليلى المعلمة')->assertJsonPath('data.email', 'layla@test.qa')->assertJsonPath('data.school_id', $school->id)
            ->assertJsonPath('data.specializations.0', 'رياضيات')->assertJsonPath('data.experience_years', 9);
        $this->assertNotNull($created->json('data.id'));
        $post(['source' => 'school', 'employee_id' => $employee->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->asUser($admin)->getJson('/api/v1/admin/trainers/candidates?q=Layla')->assertJsonPath('data.0.already_trainer', true);

        // Ministry defaults to the Ministry of Education.
        $post($name + ['source' => 'ministry'])->assertCreated()->assertJsonPath('data.organization', 'وزارة التربية والتعليم والتعليم العالي');

        // Partner trainers inherit the partner organization and count as outside.
        $post($name + ['source' => 'partner'])->assertUnprocessable()->assertJsonValidationErrors('partner_id');
        $partner = $this->partner();
        $post($name + ['source' => 'partner', 'partner_id' => $partner->id])->assertCreated()
            ->assertJsonPath('data.organization', 'مؤسسة قطر')->assertJsonPath('data.is_external', true)->assertJsonPath('data.partner.id', $partner->id);

        // External consultants are free text.
        $post($name + ['source' => 'external', 'organization' => 'Haddad Consulting', 'hourly_rate' => 500])->assertCreated()->assertJsonPath('data.hourly_rate', 500)->assertJsonPath('data.is_external', true);

        // International trainers must come from another country.
        $post($name + ['source' => 'international'])->assertUnprocessable()->assertJsonValidationErrors('country');
        $post($name + ['source' => 'international', 'country' => 'Qatar'])->assertUnprocessable()->assertJsonValidationErrors('country');
        $post($name + ['source' => 'international', 'country' => 'Jordan', 'languages' => ['en', 'ar']])->assertCreated()->assertJsonPath('data.country', 'Jordan')->assertJsonPath('data.languages.0', 'en');

        $post($name + ['source' => 'martian'])->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    public function test_switching_source_clears_links_and_filters_work(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $partner = $this->partner();
        $trainer = $this->trainer(['source' => 'partner', 'partner_id' => $partner->id, 'organization' => 'مؤسسة قطر', 'languages' => ['ar']]);
        $this->assertTrue($trainer->is_external);
        $this->trainer(['source' => 'center', 'specializations' => ['coaching']]);

        $this->asUser($admin)->getJson('/api/v1/admin/trainers?source=partner')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($admin)->getJson("/api/v1/admin/trainers?partner_id={$partner->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($admin)->getJson('/api/v1/admin/trainers?specialization=coaching')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($admin)->getJson('/api/v1/admin/trainers?language=ar')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($admin)->getJson('/api/v1/admin/trainers?min_rating=4.9')->assertOk()->assertJsonCount(0, 'data');

        $this->asUser($admin)->putJson("/api/v1/admin/trainers/{$trainer->id}", ['source' => 'center'])->assertOk()->assertJsonPath('data.is_external', false)->assertJsonPath('data.partner_id', null);
        $this->assertNull($trainer->fresh()->partner_id);

        $this->asUser($admin)->getJson('/api/v1/admin/trainers/options')->assertOk()->assertJsonCount(6, 'data.sources')->assertJsonPath('data.partners.0.id', $partner->id);
    }

    public function test_internal_details_stay_private_and_used_trainers_are_deactivated(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $trainer = $this->trainer(['email' => 'secret@test.qa', 'hourly_rate' => 900, 'notes' => 'private']);

        $public = $this->getJson('/api/v1/public/trainers')->assertOk();
        $this->assertStringNotContainsString('secret@test.qa', $public->getContent());
        $this->assertStringNotContainsString('900', json_encode($public->json()));
        $this->asUser($this->makeUser(Role::TRAINER))->getJson("/api/v1/admin/trainers/{$trainer->id}")->assertOk()->assertJsonMissingPath('data.hourly_rate')->assertJsonMissingPath('data.email');
        $this->asUser($admin)->getJson("/api/v1/admin/trainers/{$trainer->id}")->assertOk()->assertJsonPath('data.hourly_rate', 900)->assertJsonPath('data.email', 'secret@test.qa');

        $this->makeSession($this->makeProgram(), CarbonImmutable::parse('2027-03-07 09:00'))->update(['trainer_id' => $trainer->id]);
        $this->asUser($admin)->deleteJson("/api/v1/admin/trainers/{$trainer->id}")->assertOk()->assertJsonPath('meta.deactivated', true);
        $this->assertSame('inactive', $trainer->fresh()->status);
        $spare = $this->trainer();
        $this->asUser($admin)->deleteJson("/api/v1/admin/trainers/{$spare->id}")->assertNoContent();
    }

    public function test_partner_organizations_and_sessions_respect_trainer_availability(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/partners', ['name_ar' => 'وزارة الصحة العامة', 'name_en' => 'Ministry of Public Health', 'type' => 'ministry'])->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson('/api/v1/admin/partners', ['name_ar' => 'x', 'name_en' => 'x', 'type' => 'alien'])->assertUnprocessable();
        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson('/api/v1/admin/partners', ['name_ar' => 'x', 'name_en' => 'x', 'type' => 'ngo'])->assertCreated();

        $trainer = $this->trainer(['source' => 'partner', 'partner_id' => $id]);
        $program = $this->makeProgram();
        $url = "/api/v1/admin/programs/{$program->id}/sessions";
        $payload = fn (string $s, string $e, string $t) => ['title_ar' => 'ج', 'title_en' => 'S', 'starts_at' => $s, 'ends_at' => $e, 'trainer_id' => $t];

        $first = $this->asUser($admin)->postJson($url, $payload('2027-03-07 09:00', '2027-03-07 11:00', $trainer->id))->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson($url, $payload('2027-03-07 10:00', '2027-03-07 12:00', $trainer->id))->assertUnprocessable()->assertJsonPath('code', 'trainer_conflict')->assertJsonPath('details.sessions.0.id', $first);
        $this->asUser($admin)->postJson($url, $payload('2027-03-07 11:00', '2027-03-07 12:00', $trainer->id))->assertCreated();
        $this->asUser($admin)->getJson("/api/v1/admin/trainers/{$trainer->id}/schedule?from=2027-03-01&to=2027-03-31")->assertOk()->assertJsonCount(2, 'data');
        // Free-only listing drops the busy trainer.
        $this->asUser($admin)->getJson('/api/v1/admin/trainers?available_from=2027-03-07 09:30&available_to=2027-03-07 10:00')->assertOk()->assertJsonCount(0, 'data');

        $trainer->update(['status' => 'inactive']);
        $this->asUser($admin)->postJson($url, $payload('2027-03-09 09:00', '2027-03-09 10:00', $trainer->id))->assertUnprocessable()->assertJsonPath('code', 'trainer_inactive');

        // A partner that supplied trainers is deactivated, an unused one is deleted.
        $this->asUser($admin)->deleteJson("/api/v1/admin/partners/$id")->assertOk()->assertJsonPath('meta.deactivated', true);
        $unused = $this->partner('Unused');
        $this->asUser($admin)->deleteJson("/api/v1/admin/partners/{$unused->id}")->assertNoContent();
    }

    public function test_suggestions_rank_by_specialization_rating_and_availability(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $skill = Skill::where('code', 'classroom_management')->first();
        $expert = $this->trainer(['name_en' => 'Expert', 'specializations' => ['classroom_management', 'coaching'], 'rating' => 4.9]);
        $busyExpert = $this->trainer(['name_en' => 'Busy', 'specializations' => ['classroom_management'], 'rating' => 5.0]);
        $this->trainer(['name_en' => 'Other', 'specializations' => ['stem']]);
        $this->trainer(['name_en' => 'Away', 'specializations' => ['classroom_management'], 'status' => 'inactive']);
        $this->makeSession($this->makeProgram(), CarbonImmutable::parse('2027-03-07 09:00'))->update(['trainer_id' => $busyExpert->id]);

        $res = $this->asUser($admin)->getJson('/api/v1/admin/trainers/suggest?'.http_build_query(['skill_ids' => [$skill->id], 'starts_at' => '2027-03-07 09:30', 'ends_at' => '2027-03-07 10:30']))->assertOk();
        $ids = collect($res->json('data'))->pluck('trainer.id')->all();
        $this->assertSame([$expert->id, $busyExpert->id], $ids, 'free first; unrelated and inactive trainers are left out');
        $this->assertFalse($res->json('data.1.available'));
        $this->assertSame(['classroom_management'], $res->json('data.0.matched_specializations'));
    }
}
