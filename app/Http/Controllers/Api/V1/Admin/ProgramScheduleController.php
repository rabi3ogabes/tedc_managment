<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Programs\ProgramSchedulePlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The session plan of a new programme: generated from the filled form, or re-checked after the administrator edited it. */
class ProgramScheduleController extends Controller
{
    public function __construct(private readonly ProgramSchedulePlanner $planner) {}

    public function plan(Request $request): JsonResponse
    {
        $in = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'total_hours' => ['required', 'numeric', 'min:0.25', 'max:1000'], 'capacity' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'delivery_mode' => ['nullable', Rule::in(['in_person', 'online', 'hybrid'])], 'pattern' => ['nullable', Rule::in(ProgramSchedulePlanner::PATTERNS)],
            'weekday' => ['nullable', 'integer', 'between:0,6'], 'every_weeks' => ['nullable', 'integer', 'between:1,8'],
            'dates' => ['nullable', 'array', 'max:'.ProgramSchedulePlanner::MAX_SESSIONS], 'dates.*' => ['date_format:Y-m-d'],
            'sessions_count' => ['nullable', 'integer', 'between:1,'.ProgramSchedulePlanner::MAX_SESSIONS], 'start_time' => ['nullable', 'date_format:H:i'],
            'room_mode' => ['nullable', Rule::in(ProgramSchedulePlanner::ROOM_MODES)], 'room_id' => ['nullable', 'uuid'], 'trainer_id' => ['nullable', 'uuid'],
            'sessions' => ['sometimes', 'array', 'max:'.ProgramSchedulePlanner::MAX_SESSIONS], 'sessions.*.starts_at' => ['required', 'date'], 'sessions.*.ends_at' => ['required', 'date'],
            'sessions.*.training_room_id' => ['nullable', 'uuid'], 'sessions.*.trainer_id' => ['nullable', 'uuid'], 'sessions.*.title_ar' => ['nullable', 'string', 'max:255'], 'sessions.*.title_en' => ['nullable', 'string', 'max:255'], 'sessions.*.mode' => ['nullable', Rule::in(['in_person', 'online'])],
        ]);
        $in['_online'] = ($in['delivery_mode'] ?? '') === 'online';

        return response()->json(['data' => isset($in['sessions']) ? $this->planner->check($in) : $this->planner->plan($in)]);
    }
}
