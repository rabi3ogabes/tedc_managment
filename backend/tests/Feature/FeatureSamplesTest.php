<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Order;
use App\Models\Post;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Space;
use App\Models\User;
use App\Support\DemoGuard;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoFeatureSamplesSeeder;
use Database\Seeders\DemoOnlineCoursesSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Database\Seeders\HelpArticlesSeeder;
use Database\Seeders\Samples\SampleContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeatureSamplesTest extends TestCase
{
    private function demo(): DemoFeatureSamplesSeeder
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoTestAccountsSeeder::class);
        $this->seed(DemoOnlineCoursesSeeder::class);
        $this->seed(HelpArticlesSeeder::class);
        $seeder = new DemoFeatureSamplesSeeder;
        $seeder->setContainer(app());
        $seeder->run();

        return $seeder;
    }

    public function test_every_feature_gets_sample_data_and_a_second_run_adds_nothing(): void
    {
        $seeder = $this->demo();
        $this->assertSame([], $seeder->problems);

        // The newer roles each have an account that can sign in.
        foreach (array_keys(SampleContext::ROLE_ACCOUNTS) as $email) {
            $this->assertNotNull(User::where('email', $email)->first(), $email);
        }
        $filled = [
            'spaces', 'posts', 'comments', 'polls', 'space_events', 'abuse_reports', 'challenges', 'rewards', 'user_badges', 'point_ledger', 'orders', 'payments', 'refunds', 'seat_vouchers', 'discount_codes', 'price_lists', 'entity_accounts',
            'assistant_conversations', 'assistant_messages', 'risk_flags', 'forecasts', 'training_plans', 'training_plan_items', 'needs_cycles', 'program_proposals', 'individual_needs', 'nominations', 'withdrawal_requests',
            'training_places', 'buildings', 'room_bookings', 'attendance_leaves', 'absence_excuses', 'absence_alerts', 'group_trainers', 'passing_policies', 'pass_exceptions', 'evaluation_assignments', 'evaluation_responses',
            'career_paths', 'professional_licences', 'pd_activities', 'knowledge_transfers', 'library_items', 'announcements', 'notification_rules', 'report_schedules', 'support_tickets', 'data_subject_requests',
            'integration_logs', 'help_feedback', 'learner_mastery', 'assessment_attempts', 'notification_deliveries',
        ];
        foreach ($filled as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} has no sample");
        }
        $this->assertTrue(Program::where('code', 'EKIT-PORTAL')->exists());
        $this->assertTrue(Registration::whereHas('program', fn ($q) => $q->where('code', 'EKIT-PORTAL'))->where('course_completed', true)->exists(), 'one learner finished the e-kit');

        $before = [Space::count(), Post::count(), Order::count(), Assessment::count(), DB::table('point_ledger')->count(), DB::table('library_items')->count(), DB::table('announcements')->count()];
        $again = new DemoFeatureSamplesSeeder;
        $again->setContainer(app());
        $again->run();
        $this->assertSame([], $again->problems);
        $this->assertSame($before, [Space::count(), Post::count(), Order::count(), Assessment::count(), DB::table('point_ledger')->count(), DB::table('library_items')->count(), DB::table('announcements')->count()]);
    }

    public function test_nothing_is_added_in_production_without_the_demonstration_switch(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoTestAccountsSeeder::class);
        config(['app.env' => 'production']);
        $this->app['env'] = 'production';
        putenv('TEDC_DEMO_MODE');
        $seeder = new DemoFeatureSamplesSeeder;
        $seeder->setContainer(app());
        $seeder->run();
        if (DemoGuard::allowed()) {
            $this->markTestSkipped('This environment allows demonstration data.');
        }
        $this->assertSame(0, DB::table('spaces')->count());
    }

    /** With sample data in every table, no list or summary screen may fail on the server: every GET endpoint without parameters, as an administrator and as a trainee. */
    public function test_no_listing_endpoint_fails_with_sample_data_present(): void
    {
        $this->demo();
        $admin = User::where('email', 'admin@tedc.qa')->firstOrFail();
        foreach (['payments', 'gamification', 'plc', 'forums'] as $flag) {
            $this->asUser($admin)->putJson("/api/v1/admin/features/{$flag}", ['enabled' => true, 'reason' => 'sample test'])->assertOk();
        }
        $skip = ['system/', 'public/health', 'auth/sso', 'realtime/stream', 'me/privacy/export', 'integrations/', 'ministry/', 'xapi', 'lti/', 'content/', 'files/', 'payments/return', 'payments/fake'];
        $uris = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'api/v1/') && ! str_contains($r->uri(), '{'))
            ->map(fn ($r) => $r->uri())->reject(fn ($u) => collect($skip)->contains(fn ($x) => str_contains($u, $x)))->unique()->values();
        $this->assertGreaterThan(150, $uris->count());
        $failed = [];
        foreach (['admin@tedc.qa', 'trainee1@tedc.qa', 'trainer@tedc.qa'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            foreach ($uris as $uri) {
                $code = $this->asUser($user)->get('/'.$uri, ['Accept' => 'application/json'])->getStatusCode();
                if ($code >= 500) {
                    $failed[] = "{$email} {$uri} → {$code}";
                }
            }
        }
        $this->assertSame([], $failed);
    }
}
