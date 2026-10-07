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
}
