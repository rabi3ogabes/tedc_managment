<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Services\Duties\MyDuties;
use Illuminate\Http\JsonResponse;

/**
 * «برامجي»: the programme groups the signed-in person supervises or teaches, each with its sessions, the attendance of
 * every session, and the programme's two forms (the trainee survey and the trainer's self-reflection). It asks for no
 * right of its own: it only ever lists the person's own assignments, and what they may do with a session is decided
 * where the session is opened (SessionController).
 */
class MyDutiesController extends Controller
{
    public function index(MyDuties $duties): JsonResponse
    {
        $rows = $duties->rows($this->user());
        $count = fn (string $capacity) => count(array_filter($rows, fn ($r) => in_array($capacity, $r['capacities'], true)));

        return response()->json(['data' => [
            'groups' => $rows,
            'summary' => [
                'total' => count($rows), 'supervising' => $count('supervisor'), 'teaching' => $count('trainer'),
                'running' => count(array_filter($rows, fn ($r) => $r['phase'] === 'running')),
                'upcoming' => count(array_filter($rows, fn ($r) => $r['phase'] === 'upcoming')),
                // Sessions happening now or later today, across every group.
                'today' => array_sum(array_map(fn ($r) => count(array_filter($r['sessions'], fn ($s) => in_array($s['when'], ['now', 'today'], true))), $rows)),
            ],
            'now' => now()->toIso8601String(),
        ]]);
    }
}
