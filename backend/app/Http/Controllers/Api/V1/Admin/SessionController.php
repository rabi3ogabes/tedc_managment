<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Program $program): AnonymousResourceCollection
    {
        return SessionResource::collection($program->sessions()->with(['trainer', 'room'])->withCount('attendance')->get());
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $session = $program->sessions()->create($this->validated($request));

        return (new SessionResource($session->load(['trainer', 'room'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, ProgramSession $session): SessionResource
    {
        $session->update($this->validated($request, true));

        return new SessionResource($session->load(['trainer', 'room']));
    }

    public function destroy(ProgramSession $session): JsonResponse
    {
        $session->delete();

        return response()->json(null, 204);
    }

    /**
     * Current rotating QR payload for the trainer's attendance screen.
     */
    public function qr(ProgramSession $session): JsonResponse
    {
        $this->authorizeTrainer($session);

        return response()->json(['data' => $this->attendance->currentQr($session) + [
            'session' => ['id' => $session->id, 'title' => $session->translate('title'), 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String()],
        ]]);
    }

    public function attendance(ProgramSession $session): JsonResponse
    {
        $this->authorizeTrainer($session);

        return response()->json(['data' => $this->attendance->sessionReport($session)]);
    }

    public function mark(Request $request, ProgramSession $session): JsonResponse
    {
        $this->authorizeTrainer($session);

        $data = $request->validate([
            'registration_id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['present', 'late', 'absent', 'excused'])],
            'minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        $registration = Registration::where('program_id', $session->program_id)->findOrFail($data['registration_id']);

        return response()->json(['data' => $this->attendance->mark($session, $registration, $data['status'], $this->user(), $data['minutes'] ?? null)]);
    }

    private function authorizeTrainer(ProgramSession $session): void
    {
        $user = $this->user();
        if ($this->isCenterStaff($user)) {
            return;
        }

        $trainerId = $user->trainer?->id;
        $delivers = $trainerId && ($session->trainer_id === $trainerId || $session->program->trainers()->where('trainers.id', $trainerId)->exists());
        abort_unless($delivers, 403, __('auth.forbidden'));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sequence' => ['sometimes', 'integer', 'min:1'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'trainer_id' => ['nullable', 'uuid', 'exists:trainers,id'],
            'training_room_id' => ['nullable', 'uuid', 'exists:training_rooms,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'online_url' => ['nullable', 'url', 'max:255'],
            'activities' => ['nullable', 'array'],
            'activities.*' => ['string', 'max:255'],
            'status' => ['sometimes', Rule::in(['scheduled', 'live', 'completed', 'cancelled'])],
        ]);
    }
}
