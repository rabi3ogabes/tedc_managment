<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\Admin\ShowcaseAccounts;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShowcaseAccountsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::SUPER_ADMIN);
    }

    public function test_only_the_system_administrator_sees_or_builds_the_showcase(): void
    {
        foreach ([Role::CENTER_ADMIN, Role::TRAINING_HEAD, Role::EMPLOYEE] as $role) {
            $this->asUser($this->makeUser($role))->getJson('/api/v1/admin/showcase-accounts')->assertForbidden();
            $this->asUser($this->makeUser($role))->postJson('/api/v1/admin/showcase-accounts')->assertForbidden();
        }
        $this->asUser($this->admin)->getJson('/api/v1/admin/showcase-accounts')->assertOk()->assertJsonPath('data.exists', false)->assertJsonCount(10, 'data.accounts');
    }

    public function test_building_creates_every_role_with_its_sample_training_and_can_run_again(): void
    {
        $real = $this->makeEmployee();
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk()->assertJsonPath('data.exists', true);
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();   // a second run resets, never duplicates

        $users = User::whereIn('email', ShowcaseAccounts::emails())->with('roles')->get()->keyBy(fn ($u) => strstr($u->email, '@', true));
        $this->assertCount(10, $users);
        $this->assertTrue($users['admin']->isSuperAdmin());
        foreach (['centre-head' => Role::CENTER_ADMIN, 'training-head' => Role::TRAINING_HEAD, 'planning-head' => Role::PLANNING_HEAD, 'supervisor-1' => Role::SUPERVISOR, 'trainer-2' => Role::TRAINER] as $key => $role) {
            $this->assertTrue($users[$key]->hasRole($role), $key);
        }
        // Supervisors work inside the showcase school only, and lead the people they supervise.
        $school = School::where('code', ShowcaseAccounts::SCHOOL)->firstOrFail();
        $this->assertSame(['school', $school->id], [$users['supervisor-1']->roles->firstWhere('slug', Role::SUPERVISOR)->pivot->scope_type, $users['supervisor-1']->roles->firstWhere('slug', Role::SUPERVISOR)->pivot->scope_id]);
        $this->assertSame($users['supervisor-1']->employee->id, $users['trainee-1']->employee->supervisor_id);

        // Four programmes, hidden from the public catalogue: running, finished (evaluated), upcoming (one pending), draft.
        $programs = Program::where('code', 'like', ShowcaseAccounts::PREFIX.'-%')->orderBy('code')->get();
        $this->assertSame([Program::STATUS_IN_PROGRESS, Program::STATUS_COMPLETED, Program::STATUS_REGISTRATION_OPEN, Program::STATUS_DRAFT], $programs->pluck('status')->all());
        $this->assertSame(0, Program::visible()->whereIn('id', $programs->pluck('id'))->count());
        $this->assertTrue($programs[0]->surveyIsOpen());
        $this->assertSame(2, Evaluation::where('program_id', $programs[1]->id)->count());
        $this->assertSame(1, Registration::where('program_id', $programs[2]->id)->where('status', Registration::STATUS_PENDING)->count());
        $this->assertSame(1, $programs[0]->trainers()->count());
        $this->assertSame(10, AppNotification::whereIn('user_id', $users->pluck('id'))->where('type', 'showcase_welcome')->count());

        // Nobody can sign in with a password nobody knows, and nothing touched real people.
        $this->assertFalse(Hash::check('Tedc@2026!', $users['trainee-1']->password));
        $this->assertNull($real->fresh()->supervisor_id);
        $this->assertSame(0, Registration::where('employee_id', $real->id)->count());
    }

    public function test_the_trainee_sees_the_running_programme_and_can_evaluate_it(): void
    {
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();
        $trainee = User::where('email', ShowcaseAccounts::email('trainee-1'))->firstOrFail();
        $running = Registration::where('employee_id', $trainee->employee->id)->whereHas('program', fn ($q) => $q->where('status', Program::STATUS_IN_PROGRESS))->firstOrFail();

        $this->asUser($trainee)->getJson("/api/v1/me/registrations/{$running->id}/evaluation")->assertOk()->assertJsonPath('data.open', true)->assertJsonPath('data.submitted', false);
        $this->asUser(User::where('email', ShowcaseAccounts::email('supervisor-1'))->first())->getJson('/api/v1/me/team')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_opening_an_account_gives_a_one_hour_session_as_that_person_and_is_audited(): void
    {
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();

        $res = $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts/training-head/open')->assertOk()
            ->assertJsonPath('expires_in', 3600)->assertJsonPath('user.email', ShowcaseAccounts::email('training-head'))->assertJsonPath('impersonator.id', $this->admin->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('access_token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', ShowcaseAccounts::email('training-head'));
        $this->assertSame(1, AuditLog::where('action', 'showcase_account_opened')->where('user_id', $this->admin->id)->count());

        // The demo system administrator can be opened too (the real one is already one); a real account never can.
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts/admin/open')->assertOk();
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts/'.$this->makeUser()->id.'/open')->assertNotFound();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->postJson('/api/v1/admin/showcase-accounts/trainee-1/open')->assertForbidden();
    }

    public function test_an_app_password_is_new_each_time_and_signs_in(): void
    {
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();

        $first = $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts/trainer-1/app-password')->assertOk()->json('data.password');
        $second = $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts/trainer-1/app-password')->assertOk()->json('data.password');
        $this->assertNotSame($first, $second);
        $this->assertTrue(Hash::check($second, User::where('email', ShowcaseAccounts::email('trainer-1'))->value('password')));
        $this->postJson('/api/v1/auth/login', ['email' => ShowcaseAccounts::email('trainer-1'), 'password' => $second])->assertOk();
    }

    public function test_the_sign_in_page_offers_the_supervisors_trainers_and_trainees_in_one_tap_never_the_leadership(): void
    {
        $this->getJson('/api/v1/public/showcase-accounts')->assertOk()->assertExactJson(['data' => [], 'leadership' => false]);   // nothing until they exist
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $keys = collect($this->getJson('/api/v1/public/showcase-accounts')->assertOk()->json('data'))->pluck('key')->all();
        $this->assertSame(ShowcaseAccounts::PUBLIC, $keys);

        $res = $this->postJson('/api/v1/public/showcase-accounts/trainee-1/sign-in')->assertOk()->assertJsonPath('user.email', ShowcaseAccounts::email('trainee-1'));
        $this->assertNotEmpty($res->json('refresh_token'));
        $this->withToken($res->json('access_token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', ShowcaseAccounts::email('trainee-1'));
        $this->assertSame(1, AuditLog::where('action', 'showcase_public_sign_in')->count());

        foreach (['admin', 'centre-head', 'training-head', 'planning-head', 'nobody'] as $key) {
            $this->postJson("/api/v1/public/showcase-accounts/{$key}/sign-in")->assertNotFound();
        }

        // Switched off from Settings: the page shows nothing and a tap opens nothing.
        $this->asUser($this->admin)->putJson('/api/v1/admin/showcase-accounts/public-sign-in', ['enabled' => false])->assertOk()->assertJsonPath('data.public_sign_in', false);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/v1/public/showcase-accounts')->assertOk()->assertExactJson(['data' => [], 'leadership' => false]);
        $this->postJson('/api/v1/public/showcase-accounts/trainee-1/sign-in')->assertNotFound();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->putJson('/api/v1/admin/showcase-accounts/public-sign-in', ['enabled' => true])->assertForbidden();
    }

    public function test_the_leadership_accounts_open_from_the_sign_in_page_only_with_the_showcase_code(): void
    {
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        // Nothing is offered until the administrator makes a code and switches it on.
        $this->getJson('/api/v1/public/showcase-accounts')->assertOk()->assertJsonPath('leadership', false);
        $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => 'ANYTHING'])->assertStatus(422);
        $this->postJson('/api/v1/public/showcase-accounts/leadership/admin/sign-in', ['code' => 'ANYTHING'])->assertStatus(422);

        $overview = $this->asUser($this->admin)->putJson('/api/v1/admin/showcase-accounts/leadership', ['enabled' => true])->assertOk()->assertJsonPath('data.leadership.enabled', true)->json('data.leadership');
        $code = $overview['code'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $this->getJson('/api/v1/public/showcase-accounts')->assertOk()->assertJsonPath('leadership', true);
        $this->assertStringNotContainsString($code, $this->getJson('/api/v1/public/showcase-accounts')->getContent());   // the code is never sent out

        // A wrong code opens nothing; the right one (typed any way) lists the four and signs in.
        $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => 'WRONG-CODE'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/public/showcase-accounts/leadership/admin/sign-in', ['code' => 'WRONG-CODE'])->assertStatus(422);
        $list = $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => strtolower(str_replace('-', ' ', $code))])->assertOk()->json('data');
        $this->assertSame(ShowcaseAccounts::LEADERSHIP, array_column($list, 'key'));

        $res = $this->postJson('/api/v1/public/showcase-accounts/leadership/admin/sign-in', ['code' => $code])->assertOk()->assertJsonPath('user.email', ShowcaseAccounts::email('admin'));
        $this->withToken($res->json('access_token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', ShowcaseAccounts::email('admin'));
        $this->assertSame(1, AuditLog::where('action', 'showcase_leadership_sign_in')->count());
        // Only the four: a supervisor's key is not a leadership key, and a real account's address never is.
        $this->postJson('/api/v1/public/showcase-accounts/leadership/supervisor-1/sign-in', ['code' => $code])->assertNotFound();

        // A new code replaces the old one at once; switching off closes the section.
        $new = $this->asUser($this->admin)->putJson('/api/v1/admin/showcase-accounts/leadership', ['regenerate' => true])->assertOk()->json('data.leadership.code');
        $this->assertNotSame($code, $new);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => $code])->assertStatus(422);
        $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => $new])->assertOk();
        $this->asUser($this->admin)->putJson('/api/v1/admin/showcase-accounts/leadership', ['enabled' => false])->assertOk()->assertJsonPath('data.leadership.enabled', false);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => $new])->assertStatus(422);
        $this->getJson('/api/v1/public/showcase-accounts')->assertJsonPath('leadership', false);

        // Only the system administrator sees or changes the code.
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->putJson('/api/v1/admin/showcase-accounts/leadership', ['enabled' => true])->assertForbidden();
    }

    public function test_too_many_wrong_codes_make_the_address_wait(): void
    {
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();
        $code = $this->asUser($this->admin)->putJson('/api/v1/admin/showcase-accounts/leadership', ['enabled' => true])->json('data.leadership.code');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => 'NOPE-NOPE'])->assertStatus(422);
        }
        // Now even the right code waits: the address is held for a quarter of an hour, and the right code does not lift it.
        $this->postJson('/api/v1/public/showcase-accounts/leadership', ['code' => $code])->assertStatus(429);
        $this->postJson('/api/v1/public/showcase-accounts/leadership/admin/sign-in', ['code' => $code])->assertStatus(429);
    }

    public function test_removing_takes_away_exactly_what_was_built(): void
    {
        $real = $this->makeEmployee();
        $realProgram = $this->makeProgram(['code' => 'TEST-KEEP-1']);
        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts')->assertOk();
        $users = User::whereIn('email', ShowcaseAccounts::emails())->pluck('id');

        $this->asUser($this->admin)->deleteJson('/api/v1/admin/showcase-accounts')->assertOk()
            ->assertJsonPath('removed.accounts', 10)->assertJsonPath('removed.programs', 4)->assertJsonPath('data.exists', false);

        $this->assertSame(0, User::whereIn('id', $users)->count());
        $this->assertSame(0, Employee::whereIn('user_id', $users)->count());
        $this->assertSame(0, Program::where('code', 'like', ShowcaseAccounts::PREFIX.'-%')->count());
        $this->assertSame(0, AppNotification::whereIn('user_id', $users)->count());
        $this->assertNull(School::where('code', ShowcaseAccounts::SCHOOL)->first());
        $this->assertNotNull($real->fresh());
        $this->assertNotNull($realProgram->fresh());
        $this->assertNotNull($this->admin->fresh());
        $this->assertSame(1, AuditLog::where('action', 'showcase_accounts_removed')->count());
    }

    public function test_with_supabase_the_app_password_creates_the_sign_in_and_removal_deletes_it(): void
    {
        config(['tedc.auth.driver' => 'supabase', 'tedc.supabase.url' => 'https://project-ref.supabase.co', 'tedc.supabase.service_role_key' => 'service-role']);
        Http::fake(['*/auth/v1/admin/users' => Http::response(['id' => '0b6a3e1c-4d55-4b9e-9a3c-1f2e3d4c5b6a']), '*/auth/v1/admin/users/*' => Http::response([])]);
        app(ShowcaseAccounts::class)->build($this->admin);

        $this->asUser($this->admin)->postJson('/api/v1/admin/showcase-accounts/trainee-2/app-password')->assertOk();
        $this->assertSame('0b6a3e1c-4d55-4b9e-9a3c-1f2e3d4c5b6a', User::where('email', ShowcaseAccounts::email('trainee-2'))->value('auth_id'));

        app(ShowcaseAccounts::class)->remove($this->admin);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/auth/v1/admin/users/0b6a3e1c-4d55-4b9e-9a3c-1f2e3d4c5b6a'));
    }
}
