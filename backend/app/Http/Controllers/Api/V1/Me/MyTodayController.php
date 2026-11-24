<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Services\TodayBoard;
use Illuminate\Http\JsonResponse;

/**
 * «برامج اليوم» for the app: every session of today with its programme, room, trainers, supervisors and attendance, and
 * the day in a few numbers. Somebody who sees programmes gets the whole centre's day; a trainer gets their own sessions;
 * anybody else gets an empty day (TodayBoard::reach), so the route needs no right of its own.
 */
class MyTodayController extends Controller
{
    public function __invoke(TodayBoard $board): JsonResponse
    {
        $user = $this->user();
        $rows = $board->rows($user);

        return response()->json(['data' => [
            'date' => today()->toDateString(),
            'now' => now()->toIso8601String(),
            // Whether this is the whole centre's day or only the person's own sessions.
            'whole_centre' => $board->reach($user) === true,
            'summary' => $board->summary($rows),
            'sessions' => $rows,
        ]]);
    }
}
