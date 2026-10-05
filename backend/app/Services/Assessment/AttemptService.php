<?php

namespace App\Services\Assessment;

use App\Exceptions\BusinessRuleException;
use App\Models\Assessment;
use App\Models\AssessmentAccessCode;
use App\Models\AssessmentAttempt;
use App\Models\AttemptAnswerGrade;
use App\Models\AuditLog;
use App\Models\CourseLesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\Registration;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CourseService;
use App\Services\FileStorage;
use App\Services\NotificationService;
use Illuminate\Support\Str;

/** The life of an attempt: start (window, attempts, cooldown, access code), autosave, integrity events, submit, grading and results. */
class AttemptService
{
    private const EVENTS = ['visibility_hidden', 'blur', 'multi_tab', 'fullscreen_exit', 'paste', 'copy', 'contextmenu', 'devtools', 'face_absent'];

    public function __construct(private readonly AssessmentService $builder, private readonly KnowledgeService $knowledge, private readonly NotificationService $notifications, private readonly FileStorage $files) {}

    // Start ------------------------------------------------------------------------------------------------------

    public function start(Registration $registration, Assessment $a, array $opts = []): AssessmentAttempt
    {
        abort_unless($a->status === 'published', 404);
        $now = now();
        if (($a->window_opens_at && $a->window_opens_at->gt($now)) || ($a->window_closes_at && $a->window_closes_at->lt($now))) {
            throw new BusinessRuleException(__('messages.assessment.not_open'), 'assessment_not_open');
        }

        $open = AssessmentAttempt::where('assessment_id', $a->id)->where('registration_id', $registration->id)->where('status', 'in_progress')->first();
        if ($open) {
            $this->settle($open);
            if ($open->fresh()->status === 'in_progress') {
                return $open->fresh();
            }
        }

        $taken = AssessmentAttempt::where('assessment_id', $a->id)->where('registration_id', $registration->id)->where('status', '!=', 'voided')->get();
        if ($taken->count() >= $a->max_attempts) {
            throw new BusinessRuleException(__('messages.assessment.attempts_exhausted'), 'attempts_exhausted');
        }
        $last = $taken->sortByDesc('submitted_at')->first();
        if ($a->attempt_cooldown_hours > 0 && $last?->submitted_at && $last->submitted_at->copy()->addHours($a->attempt_cooldown_hours)->gt($now)) {
            throw new BusinessRuleException(__('messages.assessment.cooldown', ['time' => $last->submitted_at->copy()->addHours($a->attempt_cooldown_hours)->toDateTimeString()]), 'cooldown');
        }
        $this->assertRestudied($a, $registration, $last);

        $delivery = 'remote';
        $code = $opts['access_code'] ?? null;
        if ($a->delivery === 'in_center' || ($a->delivery === 'either' && filled($code))) {
            if (! filled($code)) {
                throw new BusinessRuleException(__('messages.assessment.code_required'), 'access_code_required');
            }
            $this->verifyCode($a, (string) $code, $opts['room_id'] ?? null, $registration);
            $delivery = 'in_center';
        }

        $items = $this->freeze($a);
        if ($items === []) {
            throw new BusinessRuleException(__('messages.assessment.not_ready'), 'assessment_not_ready');
        }
        $expires = $a->time_limit_minutes ? $now->copy()->addMinutes($a->time_limit_minutes) : null;
        if ($a->window_closes_at && (! $expires || $a->window_closes_at->lt($expires))) {
            $expires = $a->window_closes_at->copy();
        }

        return AssessmentAttempt::create([
            'assessment_id' => $a->id, 'registration_id' => $registration->id, 'attempt_no' => $taken->count() + 1, 'started_at' => $now, 'expires_at' => $expires, 'ip' => $opts['ip'] ?? null,
            'device' => isset($opts['device']) ? substr((string) $opts['device'], 0, 255) : null, 'delivery' => $delivery, 'questions' => $items, 'answers' => [], 'max_score' => array_sum(array_column($items, 'points')), 'status' => 'in_progress',
        ]);
    }

    /** @return list<array<string, mixed>> the questions drawn for this attempt, frozen with the version the trainee sees */
    private function freeze(Assessment $a): array
    {
        $items = [];
        $seen = [];
        foreach ($a->sections()->orderBy('sort_order')->get() as $i => $s) {
            foreach ($this->builder->draw($s) as $q) {
                if (isset($seen[$q->id])) {
                    continue;
                }
                $seen[$q->id] = true;
                $type = QuestionTypes::get($q->type);
                $q->payload = $type->validate($q->payload);
                $items[] = ['id' => $q->id, 'question_id' => $q->id, 'version' => $q->version, 'type' => $q->type, 'stem_ar' => $q->stem_ar, 'stem_en' => $q->stem_en, 'media' => $q->media, 'points' => (float) ($s->points_per_question ?? $q->points),
                    'payload' => $q->payload, 'public' => $type->publicPayload($q->payload, $a->shuffle_options), 'explanation_ar' => $q->explanation_ar, 'explanation_en' => $q->explanation_en, 'skill_ids' => $q->skill_ids, 'section' => $i];
            }
        }
        if ($a->shuffle_questions) {
            shuffle($items);
        }

        return $items;
    }

    /** After a failed try the trainee must reopen the chapter's lessons before trying again, when the assessment asks for it. */
    private function assertRestudied(Assessment $a, Registration $r, ?AssessmentAttempt $last): void
    {
        if (! $a->require_restudy_on_fail || ! $a->lesson_id || ! $last || $last->passed !== false) {
            return;
        }
        $moduleId = CourseLesson::whereKey($a->lesson_id)->value('module_id');
        $lessons = CourseLesson::where('module_id', $moduleId)->where('id', '!=', $a->lesson_id)->where('is_required', true)->where('status', 'published')->pluck('id');
        $reopened = LessonProgress::where('registration_id', $r->id)->whereIn('lesson_id', $lessons)->where('last_activity_at', '>', $last->submitted_at)->count();
        if ($lessons->isNotEmpty() && $reopened < $lessons->count()) {
            throw new BusinessRuleException(__('messages.assessment.restudy'), 'restudy_required', ['lesson_ids' => $lessons->all()]);
        }
    }

    // Access codes -----------------------------------------------------------------------------------------------

    /** @return array{code: ?string, rotating: bool, row: AssessmentAccessCode} the code is shown once; only its hash is kept */
    public function createCode(Assessment $a, array $data, User $by): array
    {
        $rotating = $a->access_code_mode === 'rotating';
        $plain = $rotating ? null : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $row = AssessmentAccessCode::create([
            'assessment_id' => $a->id, 'group_id' => $data['group_id'] ?? null, 'room_id' => $data['room_id'] ?? null, 'valid_from' => $data['valid_from'] ?? now(), 'valid_to' => $data['valid_to'] ?? now()->addHours(12), 'created_by' => $by->id,
            'code_hash' => $plain ? $this->hashCode($plain) : null, 'secret' => $rotating ? Str::random(40) : null,
        ]);

        return ['code' => $plain, 'rotating' => $rotating, 'row' => $row];
    }

    /** The code the invigilator shows now (rotating) with the seconds it stays valid. @return array{code: string, expires_in: int}|null */
    public function rotatingCode(Assessment $a): ?array
    {
        $row = AssessmentAccessCode::where('assessment_id', $a->id)->whereNotNull('secret')->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', now()))->latest()->first();
        if (! $row) {
            return null;
        }
        $step = intdiv(now()->timestamp, 60);

        return ['code' => $this->totp($row->secret, $step), 'expires_in' => 60 - (now()->timestamp % 60)];
    }

    private function verifyCode(Assessment $a, string $code, ?string $roomId, Registration $r): void
    {
        $rows = AssessmentAccessCode::where('assessment_id', $a->id)->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))->get();
        foreach ($rows as $row) {
            $ok = $row->secret ? in_array($code, [$this->totp($row->secret, intdiv(now()->timestamp, 60)), $this->totp($row->secret, intdiv(now()->timestamp, 60) - 1)], true) : ($row->code_hash && hash_equals($row->code_hash, $this->hashCode($code)));
            if ($ok && (! $row->room_id || $row->room_id === $roomId) && (! $row->group_id || $row->group_id === $r->training_group_id)) {
                return;
            }
        }

        throw new BusinessRuleException(__('messages.assessment.code_invalid'), 'access_code_invalid');
    }

    private function hashCode(string $code): string
    {
        return hash('sha256', $code.'|'.config('app.key'));
    }

    private function totp(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), $secret, true);
        $o = ord($h[19]) & 0xF;

        return str_pad((string) ((unpack('N', substr($h, $o, 4))[1] & 0x7FFFFFFF) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    // During ------------------------------------------------------------------------------------------------------

    public function remaining(AssessmentAttempt $at): ?int
    {
        return $at->expires_at ? max(0, (int) now()->diffInSeconds($at->expires_at->copy()->addMinutes($at->extra_minutes), false)) : null;
    }

    /** Submits an attempt whose time ran out, with whatever was saved. */
    public function settle(AssessmentAttempt $at): AssessmentAttempt
    {
        if ($at->status === 'in_progress' && $at->expires_at && $at->expires_at->copy()->addMinutes($at->extra_minutes)->lte(now())) {
            return $this->submit($at, true);
        }

        return $at;
    }

    /** @param  array<string, mixed>  $answers */
    public function autosave(AssessmentAttempt $at, array $answers): AssessmentAttempt
    {
        $timedOut = $at->status === 'in_progress' && $at->expires_at && $at->expires_at->copy()->addMinutes($at->extra_minutes)->lte(now());
        $at = $this->settle($at);
        if ($at->status !== 'in_progress') {
            throw new BusinessRuleException(__('messages.assessment.expired'), $timedOut ? 'attempt_expired' : 'attempt_closed');
        }
        $ids = array_column($at->questions, 'id');
        $merged = $at->answers ?? [];
        foreach ($answers as $id => $value) {
            if (in_array($id, $ids, true)) {
                $merged[$id] = $value;
            }
        }
        $at->update(['answers' => $merged]);

        return $at;
    }

    /** @return array{ignored: bool, warnings: int, submitted: bool, flags: list<string>} */
    public function event(AssessmentAttempt $at, string $type): array
    {
        $cfg = $at->assessment->proctoring ?? [];
        if (empty($cfg['enabled']) || ! in_array($type, self::EVENTS, true) || $at->status !== 'in_progress') {
            return ['ignored' => true, 'warnings' => 0, 'submitted' => $at->status !== 'in_progress', 'flags' => []];
        }
        $i = $at->integrity ?? ['events' => [], 'flags' => [], 'log' => []];
        $i['events'][$type] = ($i['events'][$type] ?? 0) + 1;
        $i['log'][] = ['type' => $type, 'at' => now()->toIso8601String()];
        $i['log'] = array_slice($i['log'], -60);
        $tabSwitches = ($i['events']['visibility_hidden'] ?? 0) + ($i['events']['blur'] ?? 0) + ($i['events']['multi_tab'] ?? 0);
        $limits = ['tab_switches' => [$tabSwitches, $cfg['tab_switch_limit'] ?? null], 'fullscreen_exits' => [$i['events']['fullscreen_exit'] ?? 0, $cfg['fullscreen_exit_limit'] ?? null], 'paste_attempts' => [($i['events']['paste'] ?? 0) + ($i['events']['copy'] ?? 0), $cfg['paste_limit'] ?? null]];
        $newFlag = false;
        foreach ($limits as $flag => [$count, $limit]) {
            if ($limit !== null && $count > $limit && ! in_array($flag, $i['flags'], true)) {
                $i['flags'][] = $flag;
                $newFlag = true;
            }
        }
        $at->update(['integrity' => $i]);
        $submitted = false;
        if ($newFlag && ($cfg['action'] ?? 'flag') === 'auto_submit') {
            $this->submit($at->fresh(), true);
            $submitted = true;
        }

        return ['ignored' => false, 'warnings' => $tabSwitches, 'submitted' => $submitted, 'flags' => $i['flags']];
    }

    public function snapshot(AssessmentAttempt $at, string $dataUrl): void
    {
        $cfg = $at->assessment->proctoring ?? [];
        if (empty($cfg['enabled']) || empty($cfg['snapshots']) || $at->status !== 'in_progress' || ! preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || strlen($m[1]) > 400_000) {
            return;
        }
        $i = $at->integrity ?? ['events' => [], 'flags' => [], 'log' => []];
        $snaps = $i['snapshots'] ?? [];
        if (count($snaps) >= 40) {
            return;
        }
        $snaps[] = $this->files->put('submissions', 'proctoring/'.$at->id.'/'.count($snaps).'-'.Str::random(6).'.jpg', (string) base64_decode($m[1], true), 'image/jpeg');
        $i['snapshots'] = $snaps;
        $at->update(['integrity' => $i]);
    }

    // Submit & grade ---------------------------------------------------------------------------------------------

    public function submit(AssessmentAttempt $at, bool $auto = false): AssessmentAttempt
    {
        if ($at->status !== 'in_progress') {
            return $at;
        }
        [$earned, $manual] = $this->autoGrade($at);
        $at->fill(['auto_score' => $earned, 'submitted_at' => now(), 'status' => $manual ? 'grading' : 'graded']);
        $at->save();
        if (! $manual) {
            $this->finalize($at);
        }

        return $at->fresh();
    }

    /** @return array{0: float, 1: bool} points earned automatically, and whether anything is waiting for a person */
    private function autoGrade(AssessmentAttempt $at): array
    {
        $earned = 0.0;
        $manual = false;
        foreach ($at->questions as $item) {
            $r = QuestionTypes::get($item['type'])->grade($item['payload'], ($at->answers ?? [])[$item['id']] ?? null);
            if ($r['manual']) {
                $manual = $manual || ! AttemptAnswerGrade::where('attempt_id', $at->id)->where('question_id', $item['id'])->exists();

                continue;
            }
            $earned += $r['ratio'] * $item['points'];
        }

        return [round($earned, 2), $manual];
    }

    /** Final score and pass / fail once nothing is waiting for a grader. */
    public function finalize(AssessmentAttempt $at): void
    {
        $at->loadMissing('assessment');
        $manual = (float) AttemptAnswerGrade::where('attempt_id', $at->id)->sum('points_awarded');
        $max = (float) array_sum(array_column($at->questions, 'points'));
        $percent = $max > 0 ? round(((float) $at->auto_score + $manual) / $max * 100, 2) : 0.0;
        $at->update(['manual_score' => $manual, 'max_score' => $max, 'score_percent' => $percent, 'passed' => $percent >= (float) $at->assessment->pass_percent, 'status' => 'graded', 'graded_at' => now()]);
        $this->knowledge->afterGraded($at->fresh());
        $this->syncLesson($at->fresh());
        $this->afterGraded($at->fresh());
    }

    /** A graded attempt can change who passes — and passing the test-out assessment passes the program. */
    private function afterGraded(AssessmentAttempt $at): void
    {
        $registration = $at->registration()->with('program', 'employee')->first();
        if (! $registration) {
            return;
        }
        $certificates = app(CertificateService::class);
        $certificates->refreshStatus($registration);
        if ($registration->refresh()->passed_via === 'test_out') {
            $certificates->issueDue($registration);
        }
    }

    /** A graded assessment that sits inside a course lesson counts as that lesson's quiz. */
    private function syncLesson(AssessmentAttempt $at): void
    {
        $a = $at->assessment;
        if (! $a->lesson_id) {
            return;
        }
        $r = $at->registration;
        $p = LessonProgress::firstOrNew(['lesson_id' => $a->lesson_id, 'registration_id' => $r->id]);
        $p->fill(['employee_id' => $r->employee_id, 'first_opened_at' => $p->first_opened_at ?? now()]);
        $p->attempts = AssessmentAttempt::where('assessment_id', $a->id)->where('registration_id', $r->id)->where('status', 'graded')->count();
        $p->fill(['best_score' => max((float) $p->best_score, (float) $at->score_percent), 'percent' => max((float) $p->percent, (float) $at->score_percent), 'last_activity_at' => now()]);
        if ($at->passed && $p->status !== 'completed') {
            $p->fill(['status' => 'completed', 'completed_at' => now(), 'percent' => 100]);
        }
        $p->save();
        app(CourseService::class)->recompute($r);
    }

    /** @param  list<array{question_id: string, points: float|int|string, comment?: ?string}>  $grades */
    public function grade(AssessmentAttempt $at, array $grades, ?string $feedback, User $by): AssessmentAttempt
    {
        if (! in_array($at->status, ['grading', 'graded'], true)) {
            throw new BusinessRuleException(__('messages.assessment.not_submitted'), 'attempt_not_submitted');
        }
        $byId = collect($at->questions)->keyBy('id');
        foreach ($grades as $g) {
            $item = $byId->get($g['question_id']);
            if (! $item || QuestionTypes::get($item['type'])->grade($item['payload'], null)['manual'] !== true || (float) $g['points'] < 0 || (float) $g['points'] > $item['points']) {
                throw new BusinessRuleException(__('messages.assessment.bad_grade'), 'bad_grade');
            }
            AttemptAnswerGrade::updateOrCreate(['attempt_id' => $at->id, 'question_id' => $item['id']], ['points_awarded' => (float) $g['points'], 'max_points' => $item['points'], 'grader_id' => $by->id, 'comment' => $g['comment'] ?? null]);
        }
        $at->update(['graded_by' => $by->id, 'feedback' => $feedback ?? $at->feedback]);
        $pending = collect($at->questions)->filter(fn ($i) => QuestionTypes::get($i['type'])->grade($i['payload'], null)['manual'])->pluck('id')->diff(AttemptAnswerGrade::where('attempt_id', $at->id)->pluck('question_id'));
        if ($pending->isEmpty()) {
            $this->finalize($at->fresh());
            $this->notifyGraded($at->fresh());
        }

        return $at->fresh();
    }

    private function notifyGraded(AssessmentAttempt $at): void
    {
        $a = $at->assessment;
        $uid = $at->registration->employee->user_id;
        $this->notifications->send($uid, 'assessment.graded', ['ar' => 'صُحّح اختبارك', 'en' => 'Your assessment was graded'], ['ar' => "نتيجة «{$a->title_ar}» جاهزة.", 'en' => "The result of \"{$a->title_en}\" is ready."],
            ['detail_ar' => "نتيجة «{$a->title_ar}» جاهزة", 'detail_en' => "The result of \"{$a->title_en}\" is ready", 'attempt_id' => $at->id, 'program_id' => $a->program_id]);
    }

    /** Question corrected: swap versions inside the attempts already taken and recompute every score. @param  array<string, string>  $replace old question id => new question id */
    public function regrade(Assessment $a, array $replace, User $by): int
    {
        $n = 0;
        foreach (AssessmentAttempt::where('assessment_id', $a->id)->whereIn('status', ['grading', 'graded'])->get() as $at) {
            $items = $at->questions;
            foreach ($items as $k => $item) {
                $new = isset($replace[$item['question_id']]) ? Question::find($replace[$item['question_id']]) : Question::find($item['question_id']);
                if ($new && ($new->id !== $item['question_id'] || $new->version !== $item['version'])) {
                    $items[$k] = array_merge($item, ['question_id' => $new->id, 'version' => $new->version, 'type' => $new->type, 'stem_ar' => $new->stem_ar, 'stem_en' => $new->stem_en, 'payload' => QuestionTypes::get($new->type)->validate($new->payload), 'public' => QuestionTypes::get($new->type)->publicPayload(QuestionTypes::get($new->type)->validate($new->payload), false), 'explanation_ar' => $new->explanation_ar, 'explanation_en' => $new->explanation_en]);
                }
            }
            $at->questions = $items;
            $at->save();
            [$earned, $manual] = $this->autoGrade($at);
            $at->update(['auto_score' => $earned]);
            if ($at->status === 'graded' || ! $manual) {
                $this->finalize($at->fresh());
            }
            $n++;
        }
        AuditLog::create(['user_id' => $by->id, 'action' => 'assessment_regraded', 'auditable_type' => Assessment::class, 'auditable_id' => $a->id, 'new_values' => ['replace' => $replace, 'attempts' => $n]]);

        return $n;
    }

    public function void(AssessmentAttempt $at, string $reason): AssessmentAttempt
    {
        $at->update(['status' => 'voided', 'void_reason' => $reason]);

        return $at;
    }

    public function extend(AssessmentAttempt $at, int $minutes): AssessmentAttempt
    {
        $at->update(['extra_minutes' => $at->extra_minutes + $minutes]);

        return $at;
    }

    // Views -----------------------------------------------------------------------------------------------------

    /** What the trainee sees while the attempt is open. */
    public function state(AssessmentAttempt $at): array
    {
        return ['id' => $at->id, 'assessment_id' => $at->assessment_id, 'status' => $at->status, 'attempt_no' => $at->attempt_no, 'delivery' => $at->delivery, 'expires_at' => $at->expires_at?->copy()->addMinutes($at->extra_minutes)->toIso8601String(), 'remaining_seconds' => $this->remaining($at), 'answers' => $at->answers ?? [],
            'questions' => array_map(fn ($q) => ['id' => $q['id'], 'type' => $q['type'], 'stem_ar' => $q['stem_ar'], 'stem_en' => $q['stem_en'], 'media' => $q['media'], 'points' => $q['points'], 'payload' => $q['public']], $at->questions),
            'proctoring' => ['enabled' => (bool) ($at->assessment->proctoring['enabled'] ?? false), 'fullscreen' => (bool) ($at->assessment->proctoring['fullscreen'] ?? false), 'snapshots' => (bool) ($at->assessment->proctoring['snapshots'] ?? false)]];
    }

    /** The result as the feedback rules allow the trainee to see it. */
    public function result(AssessmentAttempt $at): array
    {
        $a = $at->assessment;
        $released = $a->released_at !== null || ($a->window_closes_at && $a->window_closes_at->isPast());
        $graded = $at->status === 'graded';
        $visible = $graded && match ($a->feedback_mode) {
            'immediate', 'after_submit' => true, 'after_close' => $released, default => false
        };
        $out = ['id' => $at->id, 'status' => $at->status, 'submitted_at' => $at->submitted_at?->toIso8601String(), 'passed' => $graded && ($visible || $a->feedback_mode === 'never' || $a->show_score) ? $at->passed : null,
            'score_percent' => $visible && $a->show_score ? $at->score_percent : null, 'points' => $visible && $a->show_score ? round((float) $at->auto_score + (float) $at->manual_score, 2) : null, 'max_points' => $visible && $a->show_score ? (float) $at->max_score : null,
            'feedback' => $visible ? $at->feedback : null, 'review' => []];
        if ($at->status === 'in_progress') {
            return $out + $this->state($at);
        }
        if ($visible && $a->show_correct_answers) {
            $grades = AttemptAnswerGrade::where('attempt_id', $at->id)->get()->keyBy('question_id');
            $out['review'] = array_map(function ($q) use ($at, $grades) {
                $type = QuestionTypes::get($q['type']);
                $answer = ($at->answers ?? [])[$q['id']] ?? null;
                $r = $type->grade($q['payload'], $answer);

                return ['id' => $q['id'], 'type' => $q['type'], 'stem_ar' => $q['stem_ar'], 'stem_en' => $q['stem_en'], 'your_answer' => $answer, 'correct' => $r['manual'] ? null : $r['ratio'] >= 1, 'points_awarded' => $r['manual'] ? ($grades[$q['id']]->points_awarded ?? null) : round($r['ratio'] * $q['points'], 2),
                    'max_points' => $q['points'], 'correct_answer' => $type->correctAnswer($q['payload']), 'explanation_ar' => $q['explanation_ar'], 'explanation_en' => $q['explanation_en'], 'comment' => $grades[$q['id']]->comment ?? null];
            }, $at->questions);
        }

        return $out;
    }
}
