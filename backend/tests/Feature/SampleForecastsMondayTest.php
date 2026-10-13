<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoFeatureSamplesSeeder;
use Database\Seeders\DemoOnlineCoursesSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Database\Seeders\HelpArticlesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The nightly AI job rebuilds forecasts on Mondays; run from the sample seeder it wiped the sample forecasts, so the
 * samples (and CI) failed every Monday. The seeder now runs the job without forecasts.
 */
class SampleForecastsMondayTest extends TestCase
{
    public function test_sample_forecasts_survive_a_monday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 09:00'));   // a Monday
        $this->assertSame(1, now()->dayOfWeek);

        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoTestAccountsSeeder::class);
        $this->seed(DemoOnlineCoursesSeeder::class);
        $this->seed(HelpArticlesSeeder::class);
        $seeder = new DemoFeatureSamplesSeeder;
        $seeder->setContainer(app());
        $seeder->run();

        $this->assertGreaterThan(0, DB::table('forecasts')->count(), 'the sample forecasts are still there on a Monday');
    }
}
