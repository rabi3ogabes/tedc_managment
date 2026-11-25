<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\Wayfinding;
use Illuminate\Http\JsonResponse;

/** The centre's map for visitors (the website's /map and the mobile app): plans, corridors, places, rooms, entrances and signs. */
class WayfindingController extends Controller
{
    public function __invoke(Wayfinding $wayfinding): JsonResponse
    {
        return response()->json(['data' => $wayfinding->payload()]);
    }
}
