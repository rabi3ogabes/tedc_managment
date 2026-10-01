<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ProfileChangeRequest;
use App\Models\Role;
use App\Support\Nationalities;
use Tests\TestCase;

class AccountAndChangeRequestTest extends TestCase
{
    public function test_my_account_shows_everything_read_only_and_flags_missing_data(): void
    {
        $employee = $this->makeEmployee();
        $employee->update(['nationality' => 'Jordan', 'specialization' => null]);
        $user = $employee->user;

        $account = $this->asUser($user)->getJson('/api/v1/me/account')->assertOk()->json('data');
        $keys = collect($account['sections'])->flatMap(fn ($s) => collect($s['fields'])->pluck('key'));
        foreach (['name_ar', 'email', 'phone', 'national_id', 'birth_date', 'school', 'job_title', 'specialization', 'qualification', 'experience_years'] as $k) {
            $this->assertTrue($keys->contains($k), "{$k} is shown");
        }
        $this->assertGreaterThan(0, $account['stats']['missing']);
        $this->assertTrue(collect($account['sections'])->flatMap(fn ($s) => $s['fields'])->firstWhere('key', 'specialization')['missing']);
        $this->assertSame('🇯🇴', $account['identity']['nationality']['flag']);
        $this->assertTrue($account['identity']['is_trainee']);

        // Read-only: the profile endpoint no longer changes personal data (only the language).
        $this->asUser($user)->patchJson('/api/v1/auth/me', ['name' => 'Hacker', 'phone' => '999', 'locale' => 'en'])->assertOk();
        $this->assertNotSame('Hacker', $user->fresh()->name);
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_home_identity_shows_position_flag_and_both_roles(): void
    {
        $employee = $this->makeEmployee();
        $employee->update(['nationality' => 'Qatar']);
        $user = $employee->user;
        $user->roles()->attach(Role::where('slug', Role::TRAINER)->value('id'));

        $identity = $this->asUser($user)->getJson('/api/v1/me/home')->assertOk()->json('data.identity');
        $this->assertTrue($identity['is_trainee']);
        $this->assertTrue($identity['is_trainer'], 'one person can be both a trainee and a trainer');
        $this->assertSame('🇶🇦', $identity['nationality']['flag']);
        $this->assertNotEmpty($identity['position']);

        $this->assertSame('🇪🇬', Nationalities::resolve('مصر')['flag']);
        $this->assertSame('SD', Nationalities::resolve('Sudanese')['code']);
        $this->assertNull(Nationalities::resolve('Atlantis'));
    }

    public function test_change_requests_are_validated_reviewed_applied_and_notified(): void
    {
        $employee = $this->makeEmployee();
        $user = $employee->user;
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'phone', 'requested_value' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('requested_value');
        $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'gender', 'requested_value' => 'robot'])->assertStatus(422);
        $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'password', 'requested_value' => 'x'])->assertStatus(422);

        $phone = $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'phone', 'requested_value' => '+974 5555 1234', 'note' => 'New number'])->assertCreated()->json('data');
        $this->assertSame('pending', $phone['status']);
        $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'phone', 'requested_value' => '+974 5555 9999'])->assertStatus(422);
        $school = $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'school', 'requested_value' => 'مدرسة أخرى', 'kind' => 'wrong'])->assertCreated()->json('data');
        $this->assertSame(2, $this->asUser($user)->getJson('/api/v1/me/account')->json('data.stats.pending'));

        // Only people who manage employees can review.
        $this->asUser($user)->getJson('/api/v1/admin/profile-requests')->assertForbidden();
        $queue = $this->asUser($admin)->getJson('/api/v1/admin/profile-requests')->assertOk()->json();
        $this->assertSame(2, $queue['counts']['pending']);
        $this->assertTrue(collect($queue['data']['data'] ?? $queue['data'])->firstWhere('field', 'phone')['can_apply']);
        $this->assertFalse(collect($queue['data']['data'] ?? $queue['data'])->firstWhere('field', 'school')['can_apply'], 'relations are corrected by HR');

        $this->asUser($admin)->postJson("/api/v1/admin/profile-requests/{$phone['id']}/approve", ['apply' => true, 'note' => 'Updated'])->assertOk()->assertJsonPath('data.applied', true);
        $this->assertSame('+974 5555 1234', $user->fresh()->phone);
        $this->asUser($admin)->postJson("/api/v1/admin/profile-requests/{$school['id']}/reject", ['note' => 'Please contact HR'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->asUser($admin)->postJson("/api/v1/admin/profile-requests/{$school['id']}/reject")->assertStatus(422);

        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'profile.request_approved')->count());
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'profile.request_rejected')->count());
        $mine = $this->asUser($user)->getJson('/api/v1/me/account/requests')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['rejected', 'approved'], collect($mine)->pluck('status')->all());

        // A pending request can be withdrawn.
        $again = $this->asUser($user)->postJson('/api/v1/me/account/requests', ['field' => 'nationality', 'requested_value' => 'Qatar'])->assertCreated()->json('data');
        $this->asUser($user)->deleteJson("/api/v1/me/account/requests/{$again['id']}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, ProfileChangeRequest::where('status', 'pending')->count());
    }
}
