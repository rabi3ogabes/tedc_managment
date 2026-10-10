<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Ai\AiPolicy;
use App\Ai\Recommend\HybridRecommender;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\ProgramResource;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\ImpactSurvey;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TaskSubmission;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;
use App\Services\FeatureSettings;
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

        $upcoming = ProgramSession::with(['program:id,title_ar,title_en,require_biometric', 'room'])
            ->whereIn('program_id', (clone $active)->where('status', Registration::STATUS_APPROVED)->select('program_id'))
            ->where('ends_at', '>=', now())->where('status', '!=', 'cancelled')
            ->orderBy('starts_at')
            ->limit(8)
            ->get();
        $nextSession = $upcoming->first();
        // The check-in button is for the session happening now (or about to start): the same window the session screen uses.
        $opens = (int) config('tedc.attendance.check_in_opens_minutes_before');
        $current = $upcoming->first(fn (ProgramSession $x) => $x->mode !== 'online' && now()->between($x->starts_at->copy()->subMinutes($opens), $x->ends_at->copy()->addMinutes(30)));
        $card = fn (ProgramSession $x) => [
            'id' => $x->id, 'title' => $x->translate('title'), 'program' => $x->program->translate('title'), 'starts_at' => $x->starts_at->toIso8601String(), 'ends_at' => $x->ends_at->toIso8601String(),
            'location' => $x->location_text ?? $x->room?->translate('name'), 'mode' => $x->mode, 'biometric_required' => (bool) $x->program->require_biometric,
        ];

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
            'next_session' => $nextSession ? $card($nextSession) : null,
            'current_session' => $current ? $card($current) + ['live' => now()->gte($current->starts_at)] : null,
            'upcoming_programs' => $this->upcomingPrograms($employee),
            'upcoming_sessions' => $upcoming->reject(fn ($x) => $x->id === $current?->id)->take(5)->map($card)->values(),
            'recommended' => $this->recommended($engine, 4),
        ]]);
    }

    /**
     * The programs the person is registered in (approved or waiting) that have not finished, for the home slider:
     * each with its delivery mode (in person, online or hybrid) and its next session.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingPrograms(Employee $employee): array
    {
        $registrations = Registration::with(['program.category'])
            ->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING])
            ->whereHas('program', fn ($q) => $q->whereNotIn('status', [Program::STATUS_COMPLETED, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED])
                ->where(fn ($d) => $d->whereNull('end_date')->orWhere('end_date', '>=', today())))
            ->get();
        $next = ProgramSession::whereIn('program_id', $registrations->pluck('program_id'))->where('ends_at', '>=', now())->where('status', '!=', 'cancelled')
            ->orderBy('starts_at')->get()->groupBy('program_id')->map->first();

        return $registrations->sortBy(fn (Registration $r) => $next[$r->program_id]->starts_at ?? $r->program->start_date ?? now()->addYears(5))->values()
            ->map(fn (Registration $r) => [
                'registration_id' => $r->id, 'registration_status' => $r->status,
                'program' => (new ProgramResource($r->program))->resolve(),
                'mode' => in_array($r->program->delivery_mode, ['in_person', 'online', 'hybrid'], true) ? $r->program->delivery_mode : 'in_person',
                'next_session_at' => ($next[$r->program_id]->starts_at ?? null)?->toIso8601String(),
            ])->take(30)->all();
    }

    /**
     * «Programs for me»: only programs the person is eligible for, in three groups —
     * `mine` (aimed at the person's role, department or school type), `general` (open to everyone) and `recommended`
     * (the recommender's picks). A program aimed at a group the person is not in never appears.
     */
    public function programsForMe(RecommendationEngine $engine, EligibilityEngine $eligibility): JsonResponse
    {
        $employee = $this->employee();
        $context = EmployeeContext::fromEmployee($employee);
        $mine = Registration::where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_COMPLETED])->pluck('status', 'program_id');

        $eligible = Program::visible()->with(['category', 'targetGroups', 'eligibilityRules'])
            ->whereIn('status', [Program::STATUS_PUBLISHED, Program::STATUS_REGISTRATION_OPEN])
            ->where(fn ($d) => $d->whereNull('end_date')->orWhere('end_date', '>=', today()))
            ->orderBy('start_date')->limit(200)->get()
            ->filter(fn (Program $p) => $eligibility->evaluate($p, $employee, $context)->eligible)->keyBy('id');

        $card = fn (Program $p) => (new ProgramResource($p))->resolve() + ['my_registration' => $mine[$p->id] ?? null];
        $recommended = collect($this->recommended($engine, 12))->filter(fn ($r) => $eligible->has($r['program']['id'] ?? ''))
            ->map(fn ($r) => $r + ['program' => $r['program'] + ['my_registration' => $mine[$r['program']['id']] ?? null]])->values()->all();

        return response()->json(['data' => [
            'position' => $employee->jobTitle?->translate('name'),
            'mine' => $eligible->filter(fn (Program $p) => $p->targetGroups->isNotEmpty())->map($card)->values()->take(30)->all(),
            'general' => $eligible->filter(fn (Program $p) => $p->targetGroups->isEmpty())->map($card)->values()->take(30)->all(),
            'recommended' => $recommended,
        ]]);
    }

    public function recommendations(RecommendationEngine $engine): JsonResponse
    {
        return response()->json(['data' => $this->recommended($engine, 10)]);
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

    /** "For you": the hybrid recommender when AI is on for it, the rule engine alone otherwise (and whenever the hybrid has nothing to say). */
    private function recommended(RecommendationEngine $engine, int $limit): array
    {
        if (app(FeatureSettings::class)->enabled('ai') && app(AiPolicy::class)->feature('recommendations')['enabled']) {
            $res = app(HybridRecommender::class)->forUser($this->user(), $limit);
            $programs = Program::whereIn('id', array_column($res['items'], 'id'))->get()->keyBy('id');
            $rows = [];
            foreach ($res['items'] as $i) {
                if (isset($programs[$i['id']])) {
                    $rows[] = ['program' => (new ProgramResource($programs[$i['id']]))->resolve(), 'score' => $i['score'], 'reasons' => $i['reasons'], 'variant' => $res['variant'], 'components' => $i['components']];
                }
            }

            return $rows;
        }

        return $this->recommendationPayload($engine->forEmployee($this->employee(), $limit));
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
