<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomResource;
use App\Models\ProgramSession;
use App\Models\TrainingRoom;
use App\Services\RoomScreenService;
use App\Services\RoomService;
use App\Support\RoomCatalog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Training rooms: location, seating layouts, equipment, availability and best-fit search.
 */
class RoomController extends Controller
{
    public function __construct(private readonly RoomService $rooms) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $rooms = TrainingRoom::query()
            ->withCount(['sessions', 'sessions as upcoming_sessions_count' => fn ($q) => $q->where('starts_at', '>=', now())->where('status', '!=', 'cancelled')])
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name_ar', 'like', "%{$t}%")->orWhere('name_en', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")))
            ->when($request->query('office'), fn ($q, $v) => $q->where('office', $v))
            ->when($request->query('floor'), fn ($q, $v) => $q->where('floor', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('min_capacity'), fn ($q, $v) => $q->where('capacity', '>=', (int) $v))
            ->when($request->query('layout'), fn ($q, $v) => $q->whereNotNull("layouts->{$v}"))
            ->orderBy('office')->orderBy('floor')->orderBy('name_ar')
            ->paginate($this->perPage($request, 50));

        return RoomResource::collection($rooms);
    }

    public function show(TrainingRoom $room): RoomResource
    {
        return new RoomResource($room->loadCount('sessions'));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => RoomCatalog::options() + [
            'offices' => TrainingRoom::whereNotNull('office')->distinct()->orderBy('office')->pluck('office'),
            'floors' => TrainingRoom::whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        return (new RoomResource(TrainingRoom::create($this->validated($request))))->response()->setStatusCode(201);
    }

    public function update(Request $request, TrainingRoom $room): RoomResource
    {
        $room->update($this->validated($request, $room));

        return new RoomResource($room->refresh());
    }

    /** A room with sessions is deactivated instead of deleted so history stays intact. */
    public function destroy(TrainingRoom $room): JsonResponse
    {
        if ($room->sessions()->exists()) {
            $room->update(['status' => 'inactive']);

            return response()->json(['data' => new RoomResource($room), 'meta' => ['deactivated' => true]]);
        }

        $room->delete();

        return response()->json(null, 204);
    }

    /** Bookings of one room between two dates (room calendar). */
    public function schedule(Request $request, TrainingRoom $room): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        $sessions = ProgramSession::with('program:id,code,title_ar,title_en')
            ->where('training_room_id', $room->id)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', Carbon::parse($data['to'])->endOfDay())->where('ends_at', '>=', Carbon::parse($data['from'])->startOfDay())
            ->orderBy('starts_at')->get()
            ->map(fn ($s) => ['id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'), 'program_id' => $s->program_id, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()]);

        return response()->json(['data' => $sessions]);
    }

    /** Ranked rooms for a slot, capacity, layout and equipment. */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'layout' => ['nullable', Rule::in(RoomCatalog::layoutKeys())],
            'equipment' => ['nullable', 'array'],
            'equipment.*' => [Rule::in(RoomCatalog::equipmentKeys())],
            'office' => ['nullable', 'string', 'max:255'],
            'accessible' => ['nullable', 'boolean'],
            'except_session' => ['nullable', 'uuid'],
        ]);

        $ranked = $this->rooms->suggest(Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $data, $data['except_session'] ?? null);

        return response()->json(['data' => $ranked->map(fn ($r) => ['room' => new RoomResource($r['room'])] + collect($r)->except('room')->all())->values()]);
    }

    private function validated(Request $request, ?TrainingRoom $room = null): array
    {
        $required = $room ? 'sometimes' : 'required';

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32', Rule::unique('training_rooms', 'code')->ignore($room?->id)],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:32'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'area_m2' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'layout' => ['sometimes', Rule::in(RoomCatalog::layoutKeys())],
            'layouts' => ['nullable', 'array'],
            'layouts.*' => ['integer', 'min:1', 'max:5000'],
            'facilities' => ['nullable', 'array'],
            'facilities.*.key' => ['required', Rule::in(RoomCatalog::equipmentKeys())],
            'facilities.*.qty' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_accessible' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(RoomCatalog::ROOM_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        foreach (array_keys($data['layouts'] ?? []) as $key) {
            abort_unless(in_array($key, RoomCatalog::layoutKeys(), true), 422, "Unknown layout: {$key}");
        }

        if (array_key_exists('facilities', $data)) {
            // One line per equipment key, quantities default to 1.
            $data['facilities'] = collect($data['facilities'] ?? [])->groupBy('key')->map(fn ($rows, $key) => ['key' => $key, 'qty' => (int) $rows->sum(fn ($r) => $r['qty'] ?? 1)])->values()->all();
        }

        // Capacity is the largest layout unless it is given explicitly; the default layout always has an entry.
        if (! empty($data['layouts'])) {
            $data['capacity'] ??= max($data['layouts']);
            $layout = $data['layout'] ?? $room?->layout ?? 'classroom';
            $data['layouts'][$layout] ??= $data['capacity'];
        }
        if (! $room) {
            $data['capacity'] ??= 30;
            $data['layout'] ??= 'classroom';
            $data['layouts'] ??= [$data['layout'] => $data['capacity']];
        }

        return $data;
    }

    /** The room screen for one day (signed-in staff). */
    public function screen(Request $request, TrainingRoom $room, RoomScreenService $screen): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $screen->day($room, $data['date'] ?? null)]);
    }

    /** The secret address of the TV at the room's door; `regenerate` invalidates the old one. */
    public function screenLink(Request $request, TrainingRoom $room, RoomScreenService $screen): JsonResponse
    {
        $token = $screen->token($room, $request->boolean('regenerate'));

        return response()->json(['data' => ['token' => $token, 'url' => rtrim(config('tedc.web_url'), '/').'/room-screen/'.$token]]);
    }
}
