<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Operations for serverless hosting (Vercel), where there is no shell, no long-running scheduler and no
 * deploy hook. Both endpoints require `Authorization: Bearer <CRON_SECRET>` and do not exist without it.
 */
class SystemController extends Controller
{
    /** Scheduled work — called by Vercel Cron (which sends the CRON_SECRET bearer token automatically). */
    public function cron(Request $request): JsonResponse
    {
        $this->authorizeSecret($request);
        set_time_limit(0);

        $results = [];
        foreach (['tedc:program-lifecycle', 'tedc:group-lifecycle', 'tedc:plan-deviations', 'tedc:session-reminders', 'tedc:dispatch-surveys', 'tedc:self-heal'] as $command) {
            $results[$command] = Artisan::call($command) === 0 ? 'ok' : 'failed';
        }

        return response()->json(['data' => $results]);
    }

    /**
     * Migrations and first-time seeding (the equivalent of `php artisan tedc:deploy`). With a
     * `sync_users_password`, also creates / links every platform user in Supabase Auth with that password
     * (`tedc:supabase-sync-users --reset-password`).
     */
    public function setup(Request $request): JsonResponse
    {
        $this->authorizeSecret($request);
        $request->validate(['sync_users_password' => ['nullable', 'string', 'min:8', 'max:72']]);
        set_time_limit(0);

        $status = Artisan::call('tedc:deploy', ['--no-cache' => true]);
        $output = Artisan::output();

        if ($status === 0 && $request->filled('sync_users_password')) {
            $status = Artisan::call('tedc:supabase-sync-users', ['--password' => $request->input('sync_users_password'), '--reset-password' => true]);
            $output .= Artisan::output();
        }

        return response()->json(['data' => ['status' => $status === 0 ? 'ok' : 'failed', 'output' => $output]], $status === 0 ? 200 : 500);
    }

    private function authorizeSecret(Request $request): void
    {
        $secret = (string) config('tedc.cron_secret');

        abort_if($secret === '', 404);
        abort_unless(hash_equals('Bearer '.$secret, (string) $request->header('Authorization')), 401);
    }
}
