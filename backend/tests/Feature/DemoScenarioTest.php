<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingKit;
use App\Models\User;
use Database\Seeders\DemoScenarioSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Tests\TestCase;

class DemoScenarioTest extends TestCase
{
    private function seedScenario(): void
    {
        ProgramCategory::create(['name_ar' => 'عام', 'name_en' => 'General', 'slug' => 'general']);
        $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => 'teacher@tedc.qa']));
        $this->makeUser(Role::CENTER_ADMIN, ['email' => 'center@tedc.qa']);
        $this->makeUser(Role::SCHOOL_ADMIN, ['email' => 'school@tedc.qa']);
        $this->makeUser(Role::EXECUTIVE, ['email' => 'executive@tedc.qa']);
        $this->makeUser(Role::KIT_DEVELOPER, ['email' => 'kits@tedc.qa']);
        $this->makeUser(Role::QA_REVIEWER, ['email' => 'qa@tedc.qa']);
        $this->seed(DemoTestAccountsSeeder::class);
        $this->seed(DemoScenarioSeeder::class);
    }

    public function test_the_scenario_tells_the_whole_journey_with_every_delivery_type_from_8_to_1(): void
    {
        $this->seedScenario();

        $programs = Program::whereIn('code', DemoScenarioSeeder::CODES)->get()->keyBy('code');
        $this->assertCount(5, $programs);
        $this->assertEqualsCanonicalizing(['in_person', 'online', 'hybrid'], $programs->pluck('delivery_mode')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['draft', 'registration_open', 'in_progress', 'completed'], $programs->pluck('status')->unique()->values()->all());

        foreach ($programs as $program) {
            $this->assertGreaterThan(0, $program->sessions()->count(), $program->code);
            foreach ($program->sessions as $session) {
                $this->assertSame(['08:00', '13:00'], [$session->starts_at->format('H:i'), $session->ends_at->format('H:i')], $program->code);
            }
        }
        $this->assertEqualsCanonicalizing(['in_person', 'online'], $programs['SC-3']->sessions->pluck('mode')->unique()->all(), 'the hybrid program mixes both');

        // Every registration state is on show: waiting for approval, approved, waiting list, rejected, completed.
        $states = Registration::whereIn('program_id', $programs->pluck('id'))->pluck('status')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['pending', 'approved', 'waitlisted', 'rejected', 'completed'], $states);

        // Finished program: one certificate to download, one waiting for the survey, one blocked by absence.
        $this->assertSame(2, Certificate::where('program_id', $programs['SC-4']->id)->count());
        $this->assertDatabaseHas('notifications', ['type' => 'certificate.survey_needed']);
        $this->assertDatabaseHas('notifications', ['type' => 'certificate.available']);

        // Notifications are in Arabic and dated across the story, not all "now".
        $this->assertSame(0, AppNotification::whereNull('title_ar')->count());
        $this->assertGreaterThan(1, AppNotification::all()->map(fn ($n) => $n->created_at->toDateString())->unique()->count());
        $this->assertDatabaseHas('notifications', ['type' => 'registration.new_pending', 'user_id' => User::where('email', 'center@tedc.qa')->value('id')]);
    }

    public function test_running_it_again_rebuilds_instead_of_duplicating(): void
    {
        $this->seedScenario();
        $first = ProgramSession::count();
        $this->seed(DemoScenarioSeeder::class);

        $this->assertSame(5, Program::whereIn('code', DemoScenarioSeeder::CODES)->count());
        $this->assertSame($first, ProgramSession::count());
    }

    public function test_the_page_builds_the_scenario_phase_by_phase_and_each_phase_is_short(): void
    {
        ProgramCategory::create(['name_ar' => 'عام', 'name_en' => 'General', 'slug' => 'general']);
        $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, ['email' => 'teacher@tedc.qa']));
        $this->makeUser(Role::CENTER_ADMIN, ['email' => 'center@tedc.qa']);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        foreach (DemoScenarioSeeder::PHASES as $i => $phase) {
            \DB::enableQueryLog();
            \DB::flushQueryLog();
            $res = $this->asUser($admin)->postJson('/api/v1/admin/test-accounts/scenario', ['phase' => $phase])->assertOk();
            $this->assertLessThan(600, count(\DB::getQueryLog()), "{$phase} stays small enough for a slow database");
            $this->assertSame($i === 5, $res->json('data.done'));
        }
        $this->assertSame(5, Program::whereIn('code', DemoScenarioSeeder::CODES)->count());
        $this->assertSame(3, TrainingKit::where('code', 'like', 'KIT-SC-%')->count());
        $this->asUser($admin)->postJson('/api/v1/admin/test-accounts/scenario', ['phase' => 'nope'])->assertUnprocessable();
    }

    public function test_what_the_mobile_app_reads_is_complete_for_each_kind_of_trainee(): void
    {
        $this->seedScenario();
        $one = User::where('email', 'trainee1@tedc.qa')->first();
        $two = User::where('email', 'trainee2@tedc.qa')->first();

        $this->asUser($one)->getJson('/api/v1/me/home')->assertOk();
        $regs = collect($this->asUser($one)->getJson('/api/v1/me/registrations')->assertOk()->json('data'));
        $this->assertGreaterThanOrEqual(3, $regs->count(), 'in person, online and the finished program');
        $this->assertTrue($regs->contains(fn ($r) => $r['status'] === 'completed'));

        // The session screen works for an in-person, an online and a hybrid session alike.
        foreach (['SC-1', 'SC-2', 'SC-3'] as $code) {
            $session = ProgramSession::whereHas('program', fn ($q) => $q->where('code', $code))->orderBy('starts_at')->first();
            $user = $code === 'SC-3' ? User::where('email', 'teacher@tedc.qa')->first() : $one;
            $res = $this->asUser($user)->getJson("/api/v1/me/sessions/{$session->id}")->assertOk();
            $this->assertSame($session->mode, $res->json('data.mode'));
            $this->assertSame($session->mode === 'online', $res->json('data.online') !== null);
        }

        $calendar = $this->asUser($one)->getJson('/api/v1/me/calendar')->assertOk()->json('data');
        $this->assertNotEmpty($calendar);
        $mine = fn ($user) => collect($this->asUser($user)->getJson('/api/v1/me/certificates')->assertOk()->json('data'));
        $this->assertTrue($mine($one)->contains(fn ($c) => $c['downloadable'] === true), 'trainee 1 can download');
        $this->assertTrue($mine($two)->contains(fn ($c) => $c['survey_required'] === true), 'trainee 2 is asked for the survey first');
        $this->assertNotEmpty($this->asUser($one)->getJson('/api/v1/me/notifications')->assertOk()->json('data'));
    }

    public function test_the_trainer_gets_a_thank_you_certificate_for_the_finished_program(): void
    {
        $this->seedScenario();
        $trainer = Trainer::find(ProgramSession::whereHas('program', fn ($q) => $q->where('code', 'SC-4'))->value('trainer_id'))->user;
        $items = collect($this->asUser($trainer)->getJson('/api/v1/me/trainer-certificates')->assertOk()->json('data'));

        $this->assertTrue($items->isNotEmpty(), 'the trainer sees her certificates');
        $this->assertDatabaseHas('notifications', ['type' => 'certificate.trainer_available', 'user_id' => $trainer->id]);
    }

    public function test_the_scenario_moves_forward_with_the_calendar_without_losing_anything(): void
    {
        $this->seedScenario();
        $today = fn () => ProgramSession::whereHas('program', fn ($q) => $q->where('code', 'SC-1'))->whereDate('starts_at', today())->count();
        $this->assertSame(1, $today());
        $regs = Registration::count();
        $oldest = AppNotification::min('created_at');

        $this->travel(3)->days();
        $this->assertSame(0, $today());
        $this->assertSame(3, DemoScenarioSeeder::advance());

        $this->assertSame(1, $today(), "today's session is today again");
        $this->assertSame($regs, Registration::count());
        $this->assertSame(0, DemoScenarioSeeder::advance(), 'a second run the same day changes nothing');
        $this->assertTrue(AppNotification::min('created_at') > $oldest, 'notification dates moved with the story');
        foreach (ProgramSession::whereHas('program', fn ($q) => $q->where('code', 'like', 'SC-%'))->get() as $s) {
            $this->assertSame(['08:00', '13:00'], [$s->starts_at->format('H:i'), $s->ends_at->format('H:i')]);
        }
        foreach (['school@tedc.qa', 'executive@tedc.qa', 'kits@tedc.qa'] as $email) {
            $this->assertGreaterThan(0, AppNotification::where('user_id', User::where('email', $email)->value('id'))->count(), $email);
        }
    }

    public function test_the_dashboard_tracker_lists_the_eight_steps_with_their_latest_notifications(): void
    {
        $this->seedScenario();
        $steps = $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/process')->assertOk()->json('data.steps');

        $this->assertSame(['needs', 'plan', 'kit', 'register', 'approve', 'deliver', 'evaluate', 'certify'], array_column($steps, 'key'));
        $by = collect($steps)->keyBy('key');
        $this->assertGreaterThan(0, $by['register']['value']);
        $this->assertNotEmpty($by['register']['recent'], 'new registrations are announced');
        $this->assertNotEmpty($by['approve']['recent']);
        $this->assertNotEmpty($by['certify']['recent']);
        $this->assertStringContainsString('بانتظار', (string) $by['register']['alert']);
        $this->assertGreaterThan(0, $by['plan']['split']['hybrid']);
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/process')->assertForbidden();
    }
}
