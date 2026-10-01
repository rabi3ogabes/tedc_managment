<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\ProgramResource;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\ImpactSurvey;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TaskSubmission;
use App\Services\PassportService;
use App\Services\RecommendationEngine;
use App\Support\Nationalities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Employee home, training passport, recommendations and notifications.
 */
class MeController extends Controller
{
    protected function employee(): Employee
    {
        $employee = $this->user()->employee;
        abort_unless($employee, 404, __('eligibility.no_employee_profile'));

        return $employee;
    }

    public function home(RecommendationEngine $engine): JsonResponse
    {
        $employee = $this->employee();
        $active = Registration::where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING]);

        $nextSession = ProgramSession::with('program:id,title_ar,title_en')
            ->whereIn('program_id', (clone $active)->where('status', Registration::STATUS_APPROVED)->select('program_id'))
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        $openTasks = TaskSubmission::query()->whereIn('registration_id', (clone $active)->select('id'))->where('status', TaskSubmission::STATUS_CHANGES)->count();

        return response()->json(['data' => [
            'greeting_name' => $this->user()->displayName(),
            'identity' => Nationalities::identity($this->user()),
            'stats' => [
                'active_programs' => (clone $active)->count(),
                'completed_programs' => Registration::where('employee_id', $employee->id)->where('status', Registration::STATUS_COMPLETED)->count(),
                'certificates' => $employee->certificates()->where('status', 'valid')->count(),
                'training_hours' => (float) $employee->certificates()->where('status', 'valid')->sum('hours'),
                'pending_surveys' => ImpactSurvey::where('employee_id', $employee->id)->where('status', 'sent')->count(),
                'tasks_needing_changes' => $openTasks,
                'unread_notifications' => $this->user()->appNotifications()->whereNull('read_at')->count(),
            ],
            'next_session' => $nextSession ? [
                'id' => $nextSession->id,
                'title' => $nextSession->translate('title'),
                'program' => $nextSession->program->translate('title'),
                'starts_at' => $nextSession->starts_at->toIso8601String(),
                'ends_at' => $nextSession->ends_at->toIso8601String(),
                'location' => $nextSession->location_text,
            ] : null,
            'recommended' => $this->recommendationPayload($engine->forEmployee($employee, 4)),
        ]]);
    }

    public function recommendations(RecommendationEngine $engine): JsonResponse
    {
        return response()->json(['data' => $this->recommendationPayload($engine->forEmployee($this->employee(), 10))]);
    }

    public function passport(PassportService $passport): JsonResponse
    {
        return response()->json(['data' => $passport->build($this->employee())]);
    }

    public function updateSkills(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skills' => ['required', 'array', 'max:50'],
            'skills.*.id' => ['required', 'uuid', 'exists:skills,id'],
            'skills.*.level' => ['required', 'integer', 'between:1,5'],
        ]);

        $employee = $this->employee();
        $existing = $employee->skills()->get()->keyBy('id');

        foreach ($data['skills'] as $skill) {
            // Self-assessment never overrides levels verified by training or a supervisor.
            if (in_array($existing->get($skill['id'])?->pivot->source, ['training', 'supervisor'], true)) {
                continue;
            }
            $employee->skills()->syncWithoutDetaching([$skill['id'] => ['level' => $skill['level'], 'source' => 'self']]);
        }

        return $this->passport(app(PassportService::class));
    }

    public function notifications(Request $request): AnonymousResourceCollection
    {
        return NotificationResource::collection($this->user()->appNotifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest()->paginate($this->perPage($request, 30)));
    }

    public function readNotification(AppNotification $notification): NotificationResource
    {
        abort_unless($notification->user_id === $this->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now(), 'seen_at' => $notification->seen_at ?? now()]);

        return new NotificationResource($notification);
    }

    /** The list was displayed: records the "seen" time that administrators track (before a notification is opened). */
    public function seenNotifications(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['uuid']])['ids'];
        $count = $this->user()->appNotifications()->whereIn('id', $ids)->whereNull('seen_at')->update(['seen_at' => now()]);

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function readAllNotifications(): JsonResponse
    {
        $count = $this->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now(), 'seen_at' => now()]);

        return response()->json(['data' => ['updated' => $count]]);
    }

    private function recommendationPayload($recommendations): array
    {
        return $recommendations->map(fn ($r) => [
            'program' => (new ProgramResource($r['program']))->resolve(),
            'score' => $r['score'],
            'reasons' => $r['reasons'],
        ])->all();
    }
}
