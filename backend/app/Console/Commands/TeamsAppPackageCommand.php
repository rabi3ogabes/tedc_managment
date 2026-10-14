<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationManager;
use App\Integrations\Teams\TeamsAppPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:teams-app-package {--out= : Where to write the zip (default storage/app/teams/tedc-teams-app.zip)}')]
#[Description('Build the TEDC Teams app package (manifest, Arabic strings, icons) for the Ministry\'s Teams administrator to upload')]
class TeamsAppPackageCommand extends Command
{
    public function handle(TeamsAppPackage $package, IntegrationManager $hub): int
    {
        if (blank($hub->settings('teams')['client_id'] ?? null)) {
            $this->error('Set up the Teams integration (the application client id) first: the app must name the registration that sends the notifications.');

            return self::FAILURE;
        }
        $path = (string) ($this->option('out') ?: storage_path('app/teams/tedc-teams-app.zip'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $package->build($path);
        $this->info("Teams app package written: {$path}");

        return self::SUCCESS;
    }
}
