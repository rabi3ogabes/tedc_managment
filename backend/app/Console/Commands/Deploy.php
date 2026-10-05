<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use App\Support\DemoGuard;
use Database\Seeders\DemoNeedsSurveySeeder;
use Database\Seeders\DemoOnlineCoursesSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Database\Seeders\DemoTrainerTraineeSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('tedc:deploy {--no-cache : Skip config/route/view caching}')]
#[Description('Prepare a release: run migrations, seed an empty database and warm the caches')]
class Deploy extends Command
{
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        // First deploy only: roles, permissions and reference data (plus demo data when TEDC_SEED_DEMO=true).
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            $this->info('Empty database — seeding.');
            $this->call('db:seed', ['--force' => true]);
        } elseif (filter_var(env('TEDC_SEED_DEMO', false), FILTER_VALIDATE_BOOL) && DemoGuard::allowed()) {
            // Demo data added by later releases to an existing demo database (each seeder is idempotent).
            $this->call('db:seed', ['--class' => DemoNeedsSurveySeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => DemoTrainerTraineeSeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => DemoTestAccountsSeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => DemoOnlineCoursesSeeder::class, '--force' => true]);
        }

        // The national school list (government, private, specialised) with map positions, from the copy shipped with the code.
        if (Schema::hasColumn('schools', 'source') && School::where('source', 'like', 'moe_%')->count() < 700) {
            $this->call('tedc:schools-sync');
        }

        // Read-only file systems (serverless) cannot hold the public/storage symlink.
        if (is_writable(public_path())) {
            $this->call('storage:link', ['--force' => true]);
        }

        if (! $this->option('no-cache')) {
            $this->call('optimize');
        }

        return self::SUCCESS;
    }
}
