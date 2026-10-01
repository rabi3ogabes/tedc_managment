<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProgramSession;
use App\Services\TrainingDaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Training day: the program day hours and the one-session-per-room-per-day rule. */
class TrainingDaySettingsController extends Controller
{
    public function __construct(private readonly TrainingDaySettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'day_start' => ['sometimes', 'date_format:H:i'],
            'day_end' => ['sometimes', 'date_format:H:i', 'after:day_start'],
            'enforce_window' => ['sometimes', 'boolean'],
            'one_session_per_room_per_day' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['day_start']) && ! isset($data['day_end']) && $data['day_start'] >= $this->settings->all()['day_end']) {
            abort(422, __('messages.training_day.order'));
        }
        $this->settings->update($data, $request->user());

        return response()->json(['data' => $this->payload()]);
    }

    /** Existing upcoming sessions that break the rules, so the effect of a change is visible. */
    private function payload(): array
    {
        $s = $this->settings->all();
        $tz = config('app.timezone');
        $upcoming = ProgramSession::where('status', '!=', 'cancelled')->where('starts_at', '>=', now())->get();
        $outside = $s['enforce_window'] ? $upcoming->filter(function (ProgramSession $x) use ($s, $tz) {
            $from = $x->starts_at->copy()->timezone($tz);
            $to = $x->ends_at->copy()->timezone($tz);

            return $from->format('H:i') < $s['day_start'] || $to->format('H:i') > $s['day_end'];
        })->count() : 0;
        $shared = $s['one_session_per_room_per_day'] ? $upcoming->whereNotNull('training_room_id')->groupBy(fn ($x) => $x->training_room_id.'|'.$x->starts_at->copy()->timezone($tz)->toDateString())->filter(fn ($g) => $g->count() > 1)->count() : 0;

        return ['settings' => $s, 'hours' => $this->settings->hours(), 'issues' => ['outside_window' => $outside, 'shared_rooms' => $shared, 'upcoming' => $upcoming->count()]];
    }
}
