<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrainingRoom;
use App\Services\Wayfinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «خريطة المركز» (docs/wayfinding.md): which room is where on the plans, the entrances, the "you are here" signs. Right: rooms.manage. */
class WayfindingController extends Controller
{
    public function show(Wayfinding $wayfinding): JsonResponse
    {
        return response()->json(['data' => $wayfinding->build(true)]);
    }

    public function update(Request $request, Wayfinding $wayfinding): JsonResponse
    {
        $floors = collect($wayfinding->map()['floors'])->pluck('id')->all();
        $point = ['required', 'array', 'size:2'];
        $data = $request->validate([
            'entrances' => ['sometimes', 'nullable', 'array', 'max:20'],
            'entrances.*.id' => ['nullable', 'string', 'max:40'], 'entrances.*.floor' => ['required', Rule::in($floors)],
            'entrances.*.ar' => ['required', 'string', 'max:120'], 'entrances.*.en' => ['nullable', 'string', 'max:120'],
            'entrances.*.at' => $point, 'entrances.*.at.*' => ['numeric'],
            'signs' => ['sometimes', 'array', 'max:60'],
            'signs.*.id' => ['nullable', 'string', 'max:40'], 'signs.*.floor' => ['required', Rule::in($floors)],
            'signs.*.ar' => ['required', 'string', 'max:120'], 'signs.*.en' => ['nullable', 'string', 'max:120'],
            'signs.*.at' => $point, 'signs.*.at.*' => ['numeric'],
            'centre' => ['sometimes', 'nullable', 'array'], 'centre.lat' => ['required_with:centre', 'numeric', 'between:-90,90'], 'centre.lng' => ['required_with:centre', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(['data' => $wayfinding->update($data, $this->user())]);
    }

    public function room(Request $request, TrainingRoom $room, Wayfinding $wayfinding): JsonResponse
    {
        $places = collect($wayfinding->map()['places'])->pluck('id')->all();
        $data = $request->validate([
            'place' => ['sometimes', 'nullable', 'string', Rule::in($places)],
            'door' => ['sometimes', 'nullable', 'array', 'size:2'], 'door.*' => ['numeric'],
            'hidden' => ['sometimes', 'boolean'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $wayfinding->placeRoom($room, $data, $this->user())]);
    }
}
