<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notes how long a share of API requests took, after the response has gone out, so the KPI dashboard can show response time and error rate.
 * Sampling keeps the cost small; the minute job turns the rows into percentiles and removes them after two days.
 */
class RecordRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel builds a fresh middleware object for terminate(), so the start time travels with the request.
        $request->attributes->set('metrics_started', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $started = (float) $request->attributes->get('metrics_started', 0.0);
        $rate = (float) config('tedc.kpi.sample_rate', 0.25);
        if ($rate <= 0 || ($rate < 1 && mt_rand() / mt_getrandmax() > $rate) || $started === 0.0) {
            return;
        }
        // The probe and the metrics themselves are not user traffic.
        $route = $request->route()?->getName() ?? $request->route()?->uri();
        if ($route && str_contains((string) $route, 'public/health')) {
            return;
        }
        try {
            DB::table('request_metrics')->insert(['route' => mb_substr((string) $route, 0, 120), 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'status' => $response->getStatusCode(), 'created_at' => now()]);
        } catch (Throwable) {
            // Measuring must never break a request (the table may not exist yet during a deploy).
        }
    }
}
