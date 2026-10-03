<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProcessTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The training journey (need → plan → kit → register → approve → deliver → evaluate → certify) for the main dashboard. */
class ProcessController extends Controller
{
    public function __invoke(Request $request, ProcessTracker $tracker): JsonResponse
    {
        return response()->json(['data' => $tracker->build($request->user()->locale === 'en' ? 'en' : 'ar')]);
    }
}
