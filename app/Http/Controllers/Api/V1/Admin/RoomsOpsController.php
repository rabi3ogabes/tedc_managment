<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\LogisticsRequest;
use App\Models\ProgramSession;
use App\Models\RoomBooking;
use App\Models\TrainingPlace;
use App\Models\TrainingRoom;
use App\Services\LogisticsService;
use App\Services\RoomService;
use App\Services\SeatingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Places and buildings, non-training room bookings, the occupancy calendar, seating plans and logistics requests. */
class RoomsOpsController extends Controller
{
    public function __construct(private readonly RoomService $rooms, private readonly SeatingService $seating, private readonly LogisticsService $logistics) {}

    // Places & buildings ----------------------------------------------------------------------------------------

    public function places(): JsonResponse
    {
        return response()->json(['data' => TrainingPlace::with('buildings')->withCount('buildings')->orderBy('name_ar')->get()->all()]);
    }

    public function savePlace(Request $request, ?TrainingPlace $place = null): JsonResponse
    {
        $r = $place ? 'sometimes' : 'required';
        $d = $request->validate(['name_ar' => [$r, 'string', 'max:200'], 'name_en' => [$r, 'string', 'max:200'], 'address' => ['nullable', 'string', 'max:300'], 'map_url' => ['nullable', 'url', 'max:500'], 'website' => ['nullable', 'url', 'max:300'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'capacity_limit' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $place = $place ? tap($place)->update($d) : TrainingPlace::create($d);

        return response()->json(['data' => $place->fresh()], $place->wasRecentlyCreated ? 201 : 200);
    }

    public function saveBuilding(Request $request, ?Building $building = null): JsonResponse
    {
        $r = $building ? 'sometimes' : 'required';
        $d = $request->validate(['place_id' => [$r, 'uuid', 'exists:training_places,id'], 'name_ar' => [$r, 'string', 'max:200'], 'name_en' => [$r, 'string', 'max:200'], 'capacity_limit' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $building = $building ? tap($building)->update($d) : Building::create($d);

        return response()->json(['data' => $building->fresh()], $building->wasRecentlyCreated ? 201 : 200);
    }

    // Bookings --------------------------------------------------------------------------------------------------

    public function bookings(Request $request): JsonResponse
    {
        $rows = RoomBooking::with(['room:id,name_ar,name_en,code', 'booker:id,name,name_ar'])->where('status', 'confirmed')
            ->when($request->query('room_id'), fn ($q, $v) => $q->where('room_id', $v))->when($request->query('from'), fn ($q, $v) => $q->where('ends_at', '>=', $v))->when($request->query('to'), fn ($q, $v) => $q->where('starts_at', '<=', $v))->orderBy('starts_at')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($b) => $this->presentBooking($b))->all()]);
    }

    public function storeBooking(Request $request): JsonResponse
    {
        $d = $request->validate($this->bookingRules(true));
        $this->rooms->assertBookable($d['room_id'], Carbon::parse($d['starts_at']), Carbon::parse($d['ends_at']), null, $d['attendees'] ?? null);

        return response()->json(['data' => $this->presentBooking(RoomBooking::create($d + ['booked_by' => $this->user()->id, 'status' => 'confirmed'])->load(['room', 'booker']))], 201);
    }

    public function updateBooking(Request $request, RoomBooking $booking): JsonResponse
    {
        $this->ownBooking($booking);
        $d = $request->validate($this->bookingRules(false));
        $this->rooms->assertBookable($d['room_id'] ?? $booking->room_id, Carbon::parse($d['starts_at'] ?? $booking->starts_at), Carbon::parse($d['ends_at'] ?? $booking->ends_at), null, $d['attendees'] ?? $booking->attendees, $booking->id);
        $booking->update($d);

        return response()->json(['data' => $this->presentBooking($booking->fresh(['room', 'booker']))]);
    }

    public function cancelBooking(RoomBooking $booking): JsonResponse
    {
        $this->ownBooking($booking);
        $booking->update(['status' => 'cancelled']);

        return response()->json(['data' => ['cancelled' => true]]);
    }

    /** Sessions and bookings together, per room, for a date range (day, week or month view). */
    public function calendar(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'room_id' => ['nullable', 'uuid']]);
        $from = Carbon::parse($d['from'])->startOfDay();
        $to = Carbon::parse($d['to'])->endOfDay();
        $sessions = ProgramSession::with('program:id,code,title_ar,title_en')->whereNotNull('training_room_id')->where('status', '!=', 'cancelled')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->when($d['room_id'] ?? null, fn ($q, $v) => $q->where('training_room_id', $v))->get();
        $bookings = RoomBooking::with('booker:id,name,name_ar')->where('status', 'confirmed')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->when($d['room_id'] ?? null, fn ($q, $v) => $q->where('room_id', $v))->get();
        $events = $sessions->map(fn ($s) => ['type' => 'session', 'id' => $s->id, 'room_id' => $s->training_room_id, 'title' => $s->program?->translate('title'), 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()])
            ->merge($bookings->map(fn ($b) => ['type' => 'booking', 'id' => $b->id, 'room_id' => $b->room_id, 'title' => $b->title, 'purpose' => $b->purpose, 'starts_at' => $b->starts_at->toIso8601String(), 'ends_at' => $b->ends_at->toIso8601String()]))->sortBy('starts_at')->values();
        $hours = $events->sum(fn ($e) => max(0, Carbon::parse($e['starts_at'])->diffInMinutes(Carbon::parse($e['ends_at']))) / 60);
        $roomCount = TrainingRoom::where('status', 'active')->count();
        $days = max(1, $from->diffInDays($to) + 1);

        return response()->json(['data' => ['events' => $events->all(), 'occupancy' => ['booked_hours' => round($hours, 1), 'rooms' => $roomCount, 'available_hours' => round($roomCount * $days * 8, 1)]]]);
    }

    // Seating ---------------------------------------------------------------------------------------------------

    public function seat(Request $request, TrainingRoom $room): JsonResponse
    {
        return response()->json(['data' => $this->seating->present($room, $this->seating->plan($room, $request->query('session'), $request->query('group')))]);
    }

    public function saveSeating(Request $request, TrainingRoom $room): JsonResponse
    {
        $d = $request->validate(['session_id' => ['nullable', 'uuid'], 'group_id' => ['nullable', 'uuid'], 'layout' => ['required', 'array'], 'layout.rows' => ['required', 'integer', 'min:1', 'max:40'], 'layout.cols' => ['required', 'integer', 'min:1', 'max:40'], 'layout.blocked' => ['sometimes', 'array', 'max:1600'], 'layout.blocked.*' => ['string', 'regex:/^\d+,\d+$/'], 'assignments' => ['sometimes', 'array']]);
        $plan = $this->seating->saveLayout($room, $d['session_id'] ?? null, $d['group_id'] ?? null, $d['layout'], $d['assignments'] ?? null);

        return response()->json(['data' => $this->seating->present($room, $plan)]);
    }

    public function autoSeat(Request $request, TrainingRoom $room): JsonResponse
    {
        $d = $request->validate(['session_id' => ['nullable', 'uuid'], 'group_id' => ['nullable', 'uuid'], 'mode' => ['required', Rule::in(['alphabetical', 'school', 'random'])]]);

        return response()->json(['data' => $this->seating->present($room, $this->seating->auto($room, $d['session_id'] ?? null, $d['group_id'] ?? null, $d['mode']))]);
    }

    // Logistics -------------------------------------------------------------------------------------------------

    public function logisticsIndex(Request $request): JsonResponse
    {
        $user = $this->user();
        $rows = LogisticsRequest::with(['session:id,title_ar,title_en,starts_at', 'requester:id,name,name_ar', 'assignee:id,name,name_ar'])->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when(! $user->hasPermission('logistics.manage'), fn ($q) => $q->where('requested_by', $user->id))->orderBy('needed_by')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->presentLogistics($r))->all()]);
    }

    public function logisticsStore(Request $request): JsonResponse
    {
        $d = $request->validate(['session_id' => ['nullable', 'uuid', 'exists:program_sessions,id'], 'group_id' => ['nullable', 'uuid', 'exists:training_groups,id'], 'booking_id' => ['nullable', 'uuid', 'exists:room_bookings,id'], 'items' => ['required', 'array', 'min:1', 'max:30'], 'items.*.type' => ['required', Rule::in(LogisticsService::ITEMS)], 'items.*.qty' => ['nullable', 'integer', 'min:1', 'max:100000'], 'items.*.note' => ['nullable', 'string', 'max:300'], 'notes' => ['nullable', 'string', 'max:2000'], 'needed_by' => ['nullable', 'date']]);

        return response()->json(['data' => $this->presentLogistics($this->logistics->create($this->user(), $d)->load(['session', 'requester', 'assignee']))], 201);
    }

    public function logisticsUpdate(Request $request, LogisticsRequest $logisticsRequest): JsonResponse
    {
        abort_unless($this->user()->hasPermission('logistics.manage'), 403);
        $d = $request->validate(['status' => ['sometimes', Rule::in(['new', 'in_progress', 'done', 'rejected'])], 'assignee_id' => ['nullable', 'uuid', 'exists:users,id'], 'comment' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->presentLogistics($this->logistics->update($logisticsRequest, $this->user(), $d)->load(['session', 'requester', 'assignee']))]);
    }

    private function ownBooking(RoomBooking $b): void
    {
        abort_unless($b->booked_by === $this->user()->id || $this->user()->hasPermission('logistics.manage') || $this->user()->hasPermission('rooms.manage'), 403);
    }

    private function bookingRules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return ['room_id' => [$r, 'uuid', 'exists:training_rooms,id'], 'purpose' => [$r, Rule::in(['training', 'meeting', 'exam', 'event', 'maintenance', 'other'])], 'title' => [$r, 'string', 'max:200'], 'starts_at' => [$r, 'date'], 'ends_at' => [$r, 'date', 'after:starts_at'], 'attendees' => ['nullable', 'integer', 'min:1', 'max:5000'], 'notes' => ['nullable', 'string', 'max:1000'], 'session_id' => ['nullable', 'uuid', 'exists:program_sessions,id']];
    }

    private function presentBooking(RoomBooking $b): array
    {
        return ['id' => $b->id, 'room_id' => $b->room_id, 'room' => $b->room?->translate('name'), 'purpose' => $b->purpose, 'title' => $b->title, 'starts_at' => $b->starts_at->toIso8601String(), 'ends_at' => $b->ends_at->toIso8601String(), 'attendees' => $b->attendees, 'status' => $b->status, 'booked_by' => $b->booker?->displayName()];
    }

    private function presentLogistics(LogisticsRequest $r): array
    {
        return ['id' => $r->id, 'session_id' => $r->session_id, 'session' => $r->session?->translate('title'), 'items' => $r->items, 'notes' => $r->notes, 'needed_by' => $r->needed_by?->toIso8601String(), 'status' => $r->status, 'requester' => $r->requester?->displayName(), 'assignee_id' => $r->assignee_id, 'assignee' => $r->assignee?->displayName(), 'comment' => $r->comment, 'completed_at' => $r->completed_at?->toIso8601String(), 'overdue' => $r->needed_by && $r->needed_by->isPast() && in_array($r->status, ['new', 'in_progress'], true)];
    }
}
