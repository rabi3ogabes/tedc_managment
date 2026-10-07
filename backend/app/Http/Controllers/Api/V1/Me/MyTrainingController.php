<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Resources\RegistrationResource;
use App\Http\Resources\SessionResource;
use App\Models\Material;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingGroup;
use App\Services\AttendanceService;
use App\Services\ConflictService;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\FileStorage;
use App\Services\Notifications\ProgramSurvey;
use App\Services\RegistrationPriorityService;
use App\Services\RegistrationService;
use App\Services\SeatAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Self-registration, my programs, calendar, QR attendance and materials.
 */
class MyTrainingController extends MeController
{
    public function eligibility(Program $program, EligibilityEngine $engine, RegistrationService $service): JsonResponse
    {
        $employee = $this->employee();
        $registration = Registration::where('program_id', $program->id)->where('employee_id', $employee->id)->first();
        $group = $program->primaryGroup();
        $conflicts = app(ConflictService::class);
        $clash = $conflicts->conflicts($employee, $program, $group, [Registration::STATUS_APPROVED]);
        $done = $conflicts->completedEquivalent($employee, $program);
        $manager = ($group?->approval_mode ?? 'manager_then_center') === 'manager_then_center' ? $service->resolveManager($employee) : null;
        $rank = app(RegistrationPriorityService::class)->score($employee, $program, $group);
        $pool = $group ? app(SeatAllocationService::class)->claim($group, $employee) : ['open', null];

        return response()->json(['data' => $engine->evaluate($program, $employee)->jsonSerialize() + [
            'registration_open' => $program->isRegistrationOpen(),
            'self_registration' => $program->allowsMode(Registration::SOURCE_SELF),
            'seats_available' => $program->seatsAvailable(),
            'registration' => $registration ? ['id' => $registration->id, 'status' => $registration->status] : null,
            'admission' => [
                'conflicts' => $clash, 'repeat' => $done ? ['program' => $done->program->translate('title'), 'policy' => $program->repeat_policy] : null,
                'approval_path' => array_values(array_filter([$manager ? 'manager' : null, ($group?->approval_mode ?? 'manager_then_center') === 'auto' ? null : 'center'])),
                'seat_in_my_pool' => $pool !== null, 'priority_score' => $rank['score'], 'priority_explanation' => $rank['explanation'],
            ],
        ]]);
    }

    public function register(Request $request, Program $program, RegistrationService $service): JsonResponse
    {
        $data = $request->validate(['group_id' => ['nullable', 'uuid', 'exists:training_groups,id']]);
        $group = isset($data['group_id']) ? TrainingGroup::findOrFail($data['group_id']) : null;
        $registration = $service->register($program, $this->employee(), Registration::SOURCE_SELF, $this->user(), null, false, $group);

        return (new RegistrationResource($registration->load(['program', 'trainingGroup'])))->response()->setStatusCode(201);
    }

    public function registrations(Request $request): AnonymousResourceCollection
    {
        return RegistrationResource::collection(Registration::with(['program.category', 'certificate', 'trainingGroup'])
            ->where('employee_id', $this->employee()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->latest()
            ->get());
    }

    public function registration(Registration $registration): JsonResponse
    {
        $this->own($registration);
        $registration->load(['program.sessions.trainer', 'program.sessions.room', 'program.category', 'attendance', 'certificate', 'evaluation']);

        return response()->json(['data' => (new RegistrationResource($registration))->resolve() + [
            'sessions' => SessionResource::collection($registration->program->sessions)->resolve(),
            'attendance' => $registration->attendance->map->only(['program_session_id', 'status', 'check_in_at', 'check_out_at', 'minutes_attended']),
            'evaluation_submitted' => $registration->evaluation !== null,
            'survey_open' => $registration->program->surveyIsOpen(),
            'survey_opens_at' => $registration->program->survey_mode === 'auto' && ! $registration->program->survey_opened_at ? app(ProgramSurvey::class)->autoOpensAt($registration->program)?->toIso8601String() : null,
        ]]);
    }

    public function calendar(Request $request): AnonymousResourceCollection
    {
        return SessionResource::collection($this->mySessions($request->date('from'), $request->date('to'))->get());
    }

    /**
     * iCalendar feed of the employee's approved sessions ("Add to calendar").
     */
    public function ics(): Response
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TEDC//Training Platform//AR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];

        foreach ($this->mySessions()->get() as $session) {
            $lines = array_merge($lines, [
                'BEGIN:VEVENT',
                'UID:'.$session->id.'@tedc',
                'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                'DTSTART:'.$session->starts_at->utc()->format('Ymd\THis\Z'),
                'DTEND:'.$session->ends_at->utc()->format('Ymd\THis\Z'),
                'SUMMARY:'.$this->icsEscape($session->program->title_ar.' — '.$session->title_ar),
                'LOCATION:'.$this->icsEscape((string) ($session->location_text ?? $session->room?->name_ar)),
                'BEGIN:VALARM', 'TRIGGER:-PT1H', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'END:VALARM',
                'END:VEVENT',
            ]);
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="tedc-training.ics"',
        ]);
    }

    public function scan(Request $request, AttendanceService $attendance): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'mocked' => ['nullable', 'boolean'],
            'biometric' => ['nullable', 'boolean'],
            'intent' => ['nullable', Rule::in(['check_in', 'check_out'])],
        ]);
        $result = $attendance->scan($this->employee(), $data['payload'], $request->userAgent(), $request->ip(), Arr::only($data, ['latitude', 'longitude', 'accuracy', 'mocked']), (bool) ($data['biometric'] ?? false), $data['intent'] ?? null);

        return response()->json(['data' => [
            'action' => $result['action'],
            'message' => $result['message'],
            'attendance' => $result['attendance']->only(['id', 'status', 'check_in_at', 'check_out_at', 'minutes_attended', 'location_status', 'distance_m']),
            'session' => $result['attendance']->session->only(['id', 'title_ar', 'title_en', 'starts_at', 'ends_at']),
        ]]);
    }

    /** One session as the participant sees it: when and where, how to attend (scan or join) and their attendance so far. */
    public function session(ProgramSession $session, AttendanceService $attendance): JsonResponse
    {
        $session->load(['program', 'trainer', 'room']);
        $registration = $this->sessionRegistration($session);
        $record = $session->attendance()->where('registration_id', $registration->id)->first();
        $now = now();
        $online = $session->mode === 'online';
        $remote = $session->program->remote ?? [];
        $opensAt = $online ? $attendance->joinOpensAt($session) : null;
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';

        return response()->json(['data' => [
            'id' => $session->id,
            'title' => $session->translate('title'),
            'description' => $session->description,
            'starts_at' => $session->starts_at->toIso8601String(),
            'ends_at' => $session->ends_at->toIso8601String(),
            'duration_minutes' => $session->durationMinutes(),
            'status' => $session->status,
            'mode' => $session->mode,
            'location' => $session->location_text ?? $session->room?->translate('name'),
            'trainer' => $session->trainer?->translate('name'),
            'program' => ['id' => $session->program->id, 'code' => $session->program->code, 'title' => $session->program->translate('title')],
            'registration_id' => $registration->id,
            'online' => $online ? [
                'platform' => $session->online_platform,
                'opens_at' => $opensAt->toIso8601String(),
                'can_join' => $session->status !== 'cancelled' && $now->between($opensAt, $session->ends_at),
                'instructions' => $remote['instructions_'.$locale] ?? null,
                'recording_url' => $session->ends_at->isPast() ? $session->recording_url : null,
            ] : null,
            'biometric_required' => (bool) $session->program->require_biometric,
            'can_scan' => ! $online && $session->status !== 'cancelled'
                && $now->between($session->starts_at->copy()->subMinutes((int) config('tedc.attendance.check_in_opens_minutes_before')), $session->ends_at->copy()->addMinutes(30)),
            'attendance' => $record ? $record->only(['status', 'check_in_at', 'check_out_at', 'minutes_attended', 'join_count', 'method']) : null,
        ]]);
    }

    /** Joins an online session; the response carries the meeting link (only available inside the join window). */
    public function joinSession(Request $request, ProgramSession $session, AttendanceService $attendance): JsonResponse
    {
        $result = $attendance->remoteJoin($this->employee(), $session, $request->userAgent(), $request->ip());

        return response()->json(['data' => [
            'message' => $result['message'], 'join_url' => $result['join_url'], 'passcode' => $result['passcode'], 'platform' => $result['platform'],
            'attendance' => $result['attendance']->only(['status', 'check_in_at', 'check_out_at', 'minutes_attended', 'join_count']),
        ]]);
    }

    public function leaveSession(ProgramSession $session, AttendanceService $attendance): JsonResponse
    {
        $this->sessionRegistration($session);

        return response()->json(['data' => $attendance->remoteLeave($this->employee(), $session)->only(['status', 'check_in_at', 'check_out_at', 'minutes_attended'])]);
    }

    private function sessionRegistration(ProgramSession $session): Registration
    {
        return Registration::where('program_id', $session->program_id)->where('employee_id', $this->employee()->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->firstOr(fn () => abort(403, __('messages.attendance.not_registered')));
    }

    public function materials(Registration $registration): JsonResponse
    {
        $this->own($registration);

        return response()->json(['data' => Material::where('program_id', $registration->program_id)
            ->whereIn('visibility', ['participants', 'public'])
            ->latest()->get()
            ->map(fn (Material $m) => [
                'id' => $m->id,
                'title' => $m->translate('title'),
                'type' => $m->type,
                'url' => $m->url,
                'has_file' => (bool) $m->storage_path,
                'mime' => $m->mime,
                'size' => $m->size,
                'session_id' => $m->program_session_id,
            ])]);
    }

    /**
     * File access control: participants receive a short-lived signed URL only for their own programs.
     */
    public function downloadMaterial(Material $material, FileStorage $storage): JsonResponse
    {
        $registered = Registration::where('program_id', $material->program_id)
            ->where('employee_id', $this->employee()->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->exists();

        abort_unless(($registered && $material->visibility !== 'trainers') || $material->visibility === 'public', 403);
        abort_unless($material->storage_path, 404);

        return response()->json(['data' => ['url' => $storage->temporaryUrl('materials', $material->storage_path), 'name' => Str::slug($material->title_en).'.'.pathinfo($material->storage_path, PATHINFO_EXTENSION)]]);
    }

    protected function own(Registration $registration): void
    {
        abort_unless($registration->employee_id === $this->employee()->id, 404);
    }

    private function mySessions($from = null, $to = null)
    {
        return ProgramSession::with(['program', 'trainer', 'room'])
            ->whereIn('program_id', Registration::where('employee_id', $this->employee()->id)
                ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
                ->select('program_id'))
            ->when($from, fn ($q) => $q->where('starts_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('starts_at', '<=', $to))
            ->where('status', '!=', 'cancelled')
            ->orderBy('starts_at');
    }

    private function icsEscape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $text);
    }
}
