<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProgramSession;
use App\Services\LiveTrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Live training board (the 3D view of the classrooms) and the training card of one classroom. */
class LiveTrainingController extends Controller
{
    public function __construct(private readonly LiveTrainingService $live) {}

    /** Every classroom of the chosen day (today when none is given). */
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->live->board($data['date'] ?? null)]);
    }

    /** The full training card of one session. */
    public function card(ProgramSession $session): JsonResponse
    {
        return response()->json(['data' => $this->live->card($session)]);
    }
}
