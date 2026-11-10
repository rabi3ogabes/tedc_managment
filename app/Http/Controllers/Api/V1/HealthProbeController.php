<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Ops\HealthProbes;
use Illuminate\Http\JsonResponse;

/** /public/health/live and /public/health/ready — for load balancers, Container Apps probes and Kubernetes. */
class HealthProbeController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
    }

    public function ready(HealthProbes $probes): JsonResponse
    {
        $r = $probes->ready();

        return response()->json($r, $r['status'] === 'fail' ? 503 : 200);
    }
}
