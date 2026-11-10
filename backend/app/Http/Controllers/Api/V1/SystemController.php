<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Security\AppKeyRotator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

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
        foreach (['tedc:program-lifecycle', 'tedc:group-lifecycle', 'tedc:plan-deviations', 'tedc:needs-cycles', 'tedc:needs-daily', 'tedc:seats-release', 'tedc:operations-hourly', 'tedc:session-reminders', 'tedc:dispatch-surveys', 'tedc:self-heal', 'tedc:surveys-retention', 'tedc:plc-tick'] as $command) {
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

    /**
     * Moves everything locked with APP_KEY to the key in TEDC_NEW_APP_KEY (see docs/KEY-ROTATION.md). Does not exist while
     * that variable is empty. `mode` is dry (count only), run (write; needs `confirm` = ROTATE-APP-KEY) or verify.
     * Answers with counts only; call it again while `finished` is false.
     */
    public function rotateKey(Request $request): JsonResponse
    {
        $this->authorizeSecret($request);
        $new = (string) config('tedc.rotation.new_app_key');
        abort_if($new === '', 404);
        $data = $request->validate(['mode' => ['required', 'in:dry,run,verify'], 'confirm' => ['nullable', 'string'], 'seconds' => ['nullable', 'integer', 'min:5', 'max:240']]);
        abort_if($data['mode'] === 'run' && ($data['confirm'] ?? null) !== 'ROTATE-APP-KEY', 422, 'Confirm with ROTATE-APP-KEY to write.');
        set_time_limit(0);

        try {
            $report = (new AppKeyRotator($new, $data['mode'], (int) ($data['seconds'] ?? 200)))->handle();
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $report]);
    }

    private function authorizeSecret(Request $request): void
    {
        $secret = (string) config('tedc.cron_secret');

        abort_if($secret === '', 404);
        abort_unless(hash_equals('Bearer '.$secret, (string) $request->header('Authorization')), 401);
    }
}
