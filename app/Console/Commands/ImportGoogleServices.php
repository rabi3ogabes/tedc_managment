<?php

namespace App\Console\Commands;

use App\Services\Push\PushSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:push-import {file : Path to the google-services.json downloaded from the Firebase console} {--package= : Expected Android package name}')]
#[Description('Load the mobile app Firebase client options from google-services.json into the notification settings')]
class ImportGoogleServices extends Command
{
    public const APP_PACKAGE = 'app.tedcmanagment.vercel';

    public function handle(PushSettings $push): int
    {
        $path = $this->argument('file');
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($json) || empty($json['client'])) {
            $this->error('That is not a valid google-services.json file.');

            return self::FAILURE;
        }

        $expected = $this->option('package') ?: self::APP_PACKAGE;
        $client = collect($json['client'])->first(fn ($c) => ($c['client_info']['android_client_info']['package_name'] ?? null) === $expected);
        if (! $client) {
            $found = collect($json['client'])->map(fn ($c) => $c['client_info']['android_client_info']['package_name'] ?? '?')->implode(', ');
            $this->error("No Android app with the package \"{$expected}\" in this file (found: {$found}). Register the app with that package name in Firebase and download the file again.");

            return self::FAILURE;
        }

        $info = $json['project_info'];
        $push->update(['client' => [
            'api_key' => $client['api_key'][0]['current_key'] ?? null,
            'app_id' => $client['client_info']['mobilesdk_app_id'],
            'messaging_sender_id' => $info['project_number'],
            'project_id' => $info['project_id'],
            'storage_bucket' => $info['storage_bucket'] ?? null,
        ]]);

        $this->info("Imported Firebase project \"{$info['project_id']}\" for {$expected}.");
        $this->line('Next: upload the service-account key (Firebase → Project settings → Service accounts) in Settings → Notifications and switch notifications on.');

        return self::SUCCESS;
    }
}
