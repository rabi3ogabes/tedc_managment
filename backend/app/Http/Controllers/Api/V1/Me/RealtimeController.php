<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * How the apps learn about a new notification at once. The driver is a server setting: `supabase` (the client listens to Supabase Realtime), `sse` (this API streams
 * server-sent events — no third party, runs wherever the API runs; use it behind Octane/FrankenPHP at scale), or `polling` (the default; clients ask every minute).
 */
class RealtimeController extends Controller
{
    public function config(): JsonResponse
    {
        $driver = in_array(config('tedc.realtime.driver'), ['supabase', 'sse', 'polling'], true) ? config('tedc.realtime.driver') : 'polling';

        return response()->json(['data' => ['driver' => $driver, 'stream' => $driver === 'sse' ? '/me/realtime/stream' : null]]);
    }

    /** One window of events (about 25 seconds), then the client reconnects with the last time it saw. */
    public function stream(Request $request): StreamedResponse
    {
        abort_unless(config('tedc.realtime.driver') === 'sse', 404);
        $user = $this->user();
        $window = max(1, min(30, (int) $request->query('window', (int) config('tedc.realtime.window', 25))));
        $since = $request->query('since') ? Carbon::parse((string) $request->query('since')) : now();

        return response()->stream(function () use ($user, $window, $since) {
            $end = time() + $window;
            $last = $since->copy();
            echo "retry: 1000\n\n";
            do {
                $rows = AppNotification::where('user_id', $user->id)->where('created_at', '>', $last)->orderBy('created_at')->limit(20)->get();
                foreach ($rows as $n) {
                    echo "event: notification\ndata: ".json_encode(['id' => $n->id, 'type' => $n->type, 'title_ar' => $n->title_ar ?? null, 'title_en' => $n->title_en ?? null, 'created_at' => $n->created_at?->toIso8601String()], JSON_UNESCAPED_UNICODE)."\n\n";
                    $last = $n->created_at;
                }
                echo ": ping\n\n";
                @ob_flush();
                @flush();
                if (time() < $end) {
                    sleep(2);
                }
            } while (time() < $end && ! connection_aborted());
            echo "event: end\ndata: ".json_encode(['since' => $last->toIso8601String()])."\n\n";
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
