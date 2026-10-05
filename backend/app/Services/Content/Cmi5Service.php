<?php

namespace App\Services\Content;

use App\Exceptions\BusinessRuleException;
use App\Models\Cmi5Session;
use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\Registration;
use App\Services\CourseService;
use Illuminate\Support\Str;

/** cmi5: launches an assignable unit with a fetch URL, hands out the auth token once, and lets the cmi5 statements drive completion by the unit's moveOn rule. */
class Cmi5Service
{
    public const VERBS = ['launched', 'initialized', 'completed', 'passed', 'failed', 'abandoned', 'waived', 'terminated', 'satisfied'];

    public function __construct(private readonly CourseService $course, private readonly XapiService $xapi) {}

    /** @return array{session_id: string, launch_url: string, au: array<string, mixed>} */
    public function launch(CourseLesson $lesson, Registration $registration, ?string $auId = null): array
    {
        if ($lesson->type !== CourseLesson::PACKAGE || ! $lesson->package_id) {
            throw new BusinessRuleException(__('messages.package.no_package'), 'no_package');
        }
        $package = ContentPackage::where('status', 'ready')->where('standard', 'cmi5')->findOrFail($lesson->package_id);
        $this->course->assertOpen($lesson, $registration);
        $this->course->open($lesson, $registration);
        $au = collect($package->entry_points)->firstWhere('id', $auId ?? $lesson->package_item_id) ?? collect($package->entry_points)->first();
        abort_unless($au, 422);

        $session = Cmi5Session::create(['registration_id' => $registration->id, 'lesson_id' => $lesson->id, 'au_id' => $au['id'], 'token_hash' => hash('sha256', Str::random(40)), 'state' => ['launched' => true], 'expires_at' => now()->addHours(8)]);
        $registration->loadMissing('employee.user');
        $user = $registration->employee->user;
        $home = rtrim((string) config('tedc.web_url'), '/') ?: 'https://tedc.local';
        $query = http_build_query([
            'endpoint' => url('/api/v1/xapi').'/', 'fetch' => url("/api/v1/xapi/cmi5/fetch/{$session->id}"), 'registration' => $registration->id, 'activityId' => $au['id'],
            'actor' => json_encode(['objectType' => 'Agent', 'name' => $user?->name, 'account' => ['homePage' => $home, 'name' => (string) $user?->id]], JSON_UNESCAPED_UNICODE),
        ]);
        $this->xapi->native($registration, $lesson, 'http://adlnet.gov/expapi/verbs/launched', 'launched');

        return ['session_id' => $session->id, 'launch_url' => PackageToken::url($package->id, $au['href'], $user?->id).(str_contains($au['href'], '?') ? '&' : '?').$query, 'au' => $au];
    }

    /** The fetch URL gives the auth token once: "Basic base64(sessionId:token)". */
    public function fetchToken(Cmi5Session $session): ?string
    {
        if (($session->state['fetched'] ?? false) || $session->expires_at->isPast()) {
            return null;
        }
        $token = Str::random(40);
        $session->update(['token_hash' => hash('sha256', $token), 'state' => array_merge($session->state ?? [], ['fetched' => true])]);

        return base64_encode($session->id.':'.$token);
    }

    public function authenticate(string $sessionId, string $token): ?Cmi5Session
    {
        $s = Cmi5Session::find($sessionId);

        return $s && ! $s->expires_at->isPast() && hash_equals($s->token_hash, hash('sha256', $token)) ? $s : null;
    }

    /** A statement from the unit: cmi5 verbs update the session and may complete the lesson. */
    public function onStatement(Cmi5Session $session, array $statement): void
    {
        $verb = Str::afterLast($statement['verb']['id'] ?? '', '/');
        if (! in_array($verb, self::VERBS, true)) {
            return;
        }
        $state = $session->state ?? [];
        $state[$verb] = true;
        $session->update(['state' => $state]);

        $package = ContentPackage::find(CourseLesson::find($session->lesson_id)?->package_id);
        $au = collect($package?->entry_points)->firstWhere('id', $session->au_id) ?? [];
        $moveOn = $au['move_on'] ?? 'NotApplicable';
        $completed = ! empty($state['completed']) || ! empty($state['waived']);
        $passed = ! empty($state['passed']) || ! empty($state['waived']);
        $satisfied = match ($moveOn) {
            'Completed' => $completed, 'Passed' => $passed, 'CompletedAndPassed' => $completed && $passed, 'CompletedOrPassed' => $completed || $passed, default => $completed || $passed,
        } && empty($state['failed']);
        $score = $statement['result']['score']['scaled'] ?? null;
        if ($satisfied || isset($statement['result']['score'])) {
            $this->course->markFromPackage(CourseLesson::findOrFail($session->lesson_id), Registration::findOrFail($session->registration_id), $satisfied, null, is_numeric($score) ? (float) $score * 100 : null);
        }
    }
}
