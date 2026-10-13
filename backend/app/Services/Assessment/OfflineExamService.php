<?php

namespace App\Services\Assessment;

use App\Exceptions\BusinessRuleException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\OfflineExamToken;
use App\Models\Registration;
use App\Models\User;
use App\Services\Content\StandardsSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assessments taken in the app without a connection (TYP-12). The server hands out a single-use package bound to one
 * device — questions without their answers, the time limit, an expiry and a signature — and grades the answers later
 * exactly as online. The phone's clock is never trusted alone: the app reports the time it measured with a monotonic
 * clock, too long is refused, implausibly short is flagged for the invigilators.
 */
class OfflineExamService
{
    public function __construct(private readonly AttemptService $attempts, private readonly StandardsSettings $settings) {}

    /** @return array<string, mixed> the package the app keeps */
    public function issue(Registration $r, Assessment $a, string $deviceId): array
    {
        abort_unless($a->status === 'published' && $a->program_id === $r->program_id, 404);
        if (! $a->allow_offline || $a->delivery === 'in_center') {
            throw new BusinessRuleException(__('messages.offline.not_allowed'), 'offline_not_allowed');
        }
        $now = now();
        if (($a->window_opens_at && $a->window_opens_at->gt($now)) || ($a->window_closes_at && $a->window_closes_at->lt($now))) {
            throw new BusinessRuleException(__('messages.assessment.not_open'), 'assessment_not_open');
        }
        $this->attempts->assertCanStart($r, $a, $this->attempts->outstandingOfflinePackages($r, $a));
        $items = $this->attempts->freeze($a);
        if ($items === []) {
            throw new BusinessRuleException(__('messages.assessment.not_ready'), 'assessment_not_ready');
        }
        $expires = $now->copy()->addHours(max(1, (int) $a->offline_window_hours));
        if ($a->window_closes_at && $a->window_closes_at->lt($expires)) {
            $expires = $a->window_closes_at->copy();
        }
        $token = OfflineExamToken::create([
            'assessment_id' => $a->id, 'registration_id' => $r->id, 'device_id' => $deviceId, 'issued_at' => $now, 'expires_at' => $expires,
            'nonce' => bin2hex(random_bytes(32)), 'question_ids' => array_column($items, 'question_id'), 'questions' => $items, 'seed' => random_int(1, 2_000_000_000),
        ]);

        return [
            'token_id' => $token->id, 'nonce' => $token->nonce, 'signature' => $this->sign($token), 'device_id' => $deviceId,
            'assessment_id' => $a->id, 'title_ar' => $a->title_ar, 'title_en' => $a->title_en, 'instructions_ar' => $a->instructions_ar, 'instructions_en' => $a->instructions_en,
            'time_limit_minutes' => $a->time_limit_minutes, 'issued_at' => $token->issued_at->toIso8601String(), 'expires_at' => $token->expires_at->toIso8601String(), 'seed' => $token->seed,
            // The same view of each question as online: the public payload has no answers and no explanations.
            'questions' => array_map(fn ($q) => ['id' => $q['id'], 'type' => $q['type'], 'stem_ar' => $q['stem_ar'], 'stem_en' => $q['stem_en'], 'media' => $q['media'], 'points' => $q['points'], 'payload' => $q['public']], $items),
        ];
    }

    /**
     * Grades what the app hands in for a package.
     *
     * @param  array{token_id: string, nonce: string, signature: string, device_id: string, answers: array<string, mixed>, device_elapsed_seconds: int, client_started_at: ?string, client_submitted_at: ?string}  $d
     */
    public function submit(User $user, array $d): AssessmentAttempt
    {
        $token = OfflineExamToken::with('assessment', 'registration')->find($d['token_id']);
        if (! $token || ! hash_equals($token->nonce, (string) $d['nonce']) || ! hash_equals($this->sign($token), (string) $d['signature']) || $token->device_id !== $d['device_id']) {
            throw new BusinessRuleException(__('messages.offline.invalid'), 'offline_token_invalid');
        }
        abort_unless($token->registration->employee_id === $user->employee?->id, 404);
        if ($token->used_at) {
            throw new BusinessRuleException(__('messages.offline.used'), 'offline_token_used');
        }
        $cfg = $this->settings->all()['offline'];
        if (now()->gt($token->expires_at->copy()->addHours((int) $cfg['sync_grace_hours']))) {
            throw new BusinessRuleException(__('messages.offline.expired'), 'offline_token_expired');
        }
        $a = $token->assessment;
        $elapsed = max(0, (int) $d['device_elapsed_seconds']);
        if ($a->time_limit_minutes && $elapsed > $a->time_limit_minutes * 60 + (int) $cfg['elapsed_grace_seconds']) {
            throw new BusinessRuleException(__('messages.offline.too_long'), 'offline_time_exceeded');
        }

        $flags = [];
        if ($elapsed < (int) $cfg['min_seconds_per_question'] * count($token->questions)) {
            $flags[] = 'offline_too_fast';
        }
        $started = $this->time($d['client_started_at'] ?? null);
        $submitted = $this->time($d['client_submitted_at'] ?? null);
        if ($started && $started->gt($token->expires_at)) {
            $flags[] = 'offline_started_after_expiry';
        }
        if ($submitted && $submitted->gt($token->expires_at)) {
            $flags[] = 'offline_finished_after_expiry';
        }
        if ($started && $started->lt($token->issued_at->copy()->subMinutes(5))) {
            $flags[] = 'offline_clock_before_issue';   // the phone's clock says it started before the package existed
        }

        return DB::transaction(function () use ($token, $a, $d, $elapsed, $flags, $started) {
            $token = OfflineExamToken::whereKey($token->id)->lockForUpdate()->first();
            if ($token->used_at) {
                throw new BusinessRuleException(__('messages.offline.used'), 'offline_token_used');
            }
            $taken = AssessmentAttempt::where('assessment_id', $a->id)->where('registration_id', $token->registration_id)->where('status', '!=', 'voided')->count();
            if ($taken >= $a->max_attempts) {
                throw new BusinessRuleException(__('messages.assessment.attempts_exhausted'), 'attempts_exhausted');
            }
            $ids = array_column($token->questions, 'id');
            $answers = array_intersect_key((array) $d['answers'], array_flip($ids));
            // When the attempt started, as far as the server can tell: never before the package, never in the future.
            $startedAt = $started ? $started->copy()->max($token->issued_at)->min(now()) : $token->issued_at;
            $attempt = AssessmentAttempt::create([
                'assessment_id' => $a->id, 'registration_id' => $token->registration_id, 'attempt_no' => $taken + 1, 'started_at' => $startedAt, 'expires_at' => null,
                'device' => 'offline:'.mb_substr($token->device_id, 0, 60), 'delivery' => 'offline', 'questions' => $token->questions, 'answers' => $answers,
                'max_score' => array_sum(array_column($token->questions, 'points')), 'status' => 'in_progress', 'device_elapsed_seconds' => $elapsed, 'client_started_at' => $started,
                'integrity' => ['events' => [], 'flags' => $flags, 'log' => [['at' => now()->toIso8601String(), 'type' => 'offline_submitted', 'elapsed' => $elapsed, 'token' => $token->id]]],
            ]);
            $attempt = $this->attempts->submit($attempt);
            $token->update(['used_at' => now(), 'attempt_id' => $attempt->id]);

            return $attempt;
        });
    }

    /** Ties the package to its device, expiry and trainee; the app cannot change any of them unnoticed. */
    private function sign(OfflineExamToken $t): string
    {
        return hash_hmac('sha256', implode('|', [$t->id, $t->nonce, $t->device_id, $t->expires_at->timestamp, $t->assessment_id, $t->registration_id]), (string) config('app.key'));
    }

    private function time(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
