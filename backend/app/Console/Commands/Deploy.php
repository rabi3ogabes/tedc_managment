<?php

namespace App\Console\Commands;

use App\Models\Role;
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
        }

        $this->call('storage:link', ['--force' => true]);

        if (! $this->option('no-cache')) {
            $this->call('optimize');
        }

        return self::SUCCESS;
    }
}
