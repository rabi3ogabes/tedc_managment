<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use App\Needs\LegacyNeedsMigrator;
use App\Support\DemoGuard;
use Database\Seeders\DemoFeatureSamplesSeeder;
use Database\Seeders\DemoNeedsSurveySeeder;
use Database\Seeders\DemoOnlineCoursesSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Database\Seeders\DemoTrainerTraineeSeeder;
use Database\Seeders\HelpArticlesSeeder;
use Database\Seeders\RolePermissionSeeder;
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

        // New roles and permissions of this release reach an existing database without touching hand-made changes.
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'scope_levels')) {
            (new RolePermissionSeeder)->additive();
        }

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
            $this->call('db:seed', ['--class' => DemoFeatureSamplesSeeder::class, '--force' => true]);
        }

        // Starter help articles of this release (only the missing ones are created, so edited articles stay as they are).
        if (Schema::hasTable('help_articles')) {
            (new HelpArticlesSeeder)->run();
        }

        // Needs raised on the old school-request screen join the needs-cycle requests (each is copied once).
        if (Schema::hasTable('training_needs') && Schema::hasColumn('institutional_requests', 'legacy_need_id')) {
            $moved = app(LegacyNeedsMigrator::class)->run();
            $moved['created'] > 0 && $this->info("Moved {$moved['created']} older needs into the needs requests.");
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
