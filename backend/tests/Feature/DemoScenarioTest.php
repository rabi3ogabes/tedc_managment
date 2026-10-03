<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
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
