<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        if (app()->environment(['local', 'development', 'staging']) || env('TEDC_SEED_DEMO', false)) {
            $this->call([DemoDataSeeder::class, DemoNeedsSurveySeeder::class]);
        }
    }
}
