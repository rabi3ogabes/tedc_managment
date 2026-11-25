<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function dashboard(): JsonResponse
    {
        return response()->json(['data' => $this->analytics->dashboard($this->scope())]);
    }

    public function executive(): JsonResponse
    {
        return response()->json(['data' => $this->analytics->executive()]);
    }

    public function geographic(): JsonResponse
    {
        return response()->json(['data' => $this->analytics->geographic()]);
    }
}
