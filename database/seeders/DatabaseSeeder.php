<?php

namespace Database\Seeders;

use App\Support\DemoGuard;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            ReferenceDataSeeder::class,
            HelpArticlesSeeder::class,
        ]);

        // Demo accounts and data: outside production, on staging/demo opt-in, and in production only on a demonstration deployment (see DemoGuard).
        if (DemoGuard::allowed() && (app()->environment(['local', 'development', 'staging']) || env('TEDC_SEED_DEMO', false))) {
            $this->call([DemoDataSeeder::class, DemoNeedsSurveySeeder::class, DemoCalendarSeeder::class, DemoKitSeeder::class, DemoTrainerTraineeSeeder::class, DemoTestAccountsSeeder::class, DemoOnlineCoursesSeeder::class, HelpArticlesSeeder::class, DemoFeatureSamplesSeeder::class]);
        }
    }
}
