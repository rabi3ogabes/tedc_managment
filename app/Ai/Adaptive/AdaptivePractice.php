<?php

namespace App\Ai\Adaptive;

use App\Ai\AiPolicy;
use App\Exceptions\BusinessRuleException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\LearnerMastery;
use App\Models\Question;
use App\Models\Registration;
use App\Models\Skill;
use App\Services\Assessment\AssessmentService;
use App\Services\Assessment\QuestionTypes;

/**
 * Adaptive practice (AI-03). An adaptive assessment asks one question at a time. Every competency in its questions keeps
 * its own level: it starts from the learner's mastery (MasteryService) — easy below 40 %, hard above 75 %, medium in
 * between or without evidence (Settings → AI) — then follows a staircase: one level up after two correct answers in a
 * row, one level down after a wrong one. Competencies take turns, so a practice with several stays balanced. Each
 * question carries the reason it was chosen, in plain words, and the whole trail is kept for the teacher's review.
 *
 * The trail lives in the attempt's adaptive_log: the questions still available per competency and level, each
 * competency's staircase, and one step per question asked (level, reason, correct or not).
 */
class AdaptivePractice
{
    public const LEVELS = ['easy', 'medium', 'hard'];

    /** The track of questions without a competency. */
    private const GENERAL = '_';

    /** Candidates kept per competency and level, so the trail stays small with very large banks. */
    private const POOL_PER_LEVEL = 150;

    public function __construct(private readonly AssessmentService $builder, private readonly AiPolicy $policy) {}

    /**
     * The trail of a new adaptive attempt and its first question.
     *
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>} the frozen questions (one) and the trail
     */
    public function begin(Assessment $a, Registration $r): array
    {
        [$pool, $points, $sections, $total] = $this->pool($a);
        if ($total === 0) {
            return [[], null];
        }
        $cfg = $this->policy->adaptive();
        $skills = array_values(array_filter(array_keys($pool), fn ($t) => $t !== self::GENERAL));
        $mastery = $skills ? LearnerMastery::where('registration_id', $r->id)->whereIn('skill_id', $skills)->where('evidence_count', '>', 0)->pluck('mastery', 'skill_id')->all() : [];
        $tracks = [];
        foreach (array_keys($pool) as $track) {
            $m = isset($mastery[$track]) ? (float) $mastery[$track] : null;
            [$level, $why] = $this->startLevel($track, $m, $cfg);
            $tracks[$track] = ['level' => $level, 'start' => $level, 'mastery' => $m === null ? null : round($m, 4), 'streak' => 0, 'misses' => 0, 'asked' => 0, 'why' => $why];
        }
        $log = [
            'v' => 1, 'total' => $total, 'reveal' => $this->reveals($a),
            'rules' => ['up_after' => $cfg['up_after'], 'down_after' => $cfg['down_after'], 'easy_below' => $cfg['easy_below'], 'hard_above' => $cfg['hard_above']],
            'tracks' => $tracks, 'pool' => $pool, 'points' => $points, 'sections' => $sections, 'steps' => [],
        ];
        $first = $this->next($a, $log);

        return [$first ? [$first] : [], $log];
    }

    /**
     * Records the answer to the question on screen, moves its competency's staircase and draws the next question.
     * The attempt is filled but not saved; the caller saves it inside its lock.
     *
     * @return array<string, mixed>
     */
    public function answer(AssessmentAttempt $at, string $questionId, mixed $answer): array
    {
        $log = $at->adaptive_log;
        if (! $log) {
            throw new BusinessRuleException(__('messages.adaptive.not_adaptive'), 'not_adaptive');
        }
        $index = null;
        foreach ($log['steps'] as $k => $step) {
            if ($step['question_id'] === $questionId) {
                $index = $k;
            }
        }
        if ($index === null) {
            throw new BusinessRuleException(__('messages.adaptive.not_current'), 'adaptive_not_current');
        }
        if ($log['steps'][$index]['answered_at'] !== null) {
            throw new BusinessRuleException(__('messages.adaptive.answered'), 'adaptive_already_answered');
        }
        $item = collect($at->questions)->firstWhere('id', $questionId);
        $correct = $this->grade($item, $answer);
        $answers = $at->answers ?? [];
        $answers[$questionId] = $answer;
        $log['steps'][$index]['correct'] = $correct;
        $log['steps'][$index]['answered_at'] = now()->toIso8601String();
        $this->move($log, $log['steps'][$index]['track'], $correct);

        $a = $at->assessment;
        $next = $this->next($a, $log);
        $questions = $at->questions;
        if ($next) {
            $questions[] = $next;
        }
        $at->fill(['answers' => $answers, 'questions' => $questions, 'adaptive_log' => $log, 'max_score' => array_sum(array_column($questions, 'points'))]);
        $reveal = (bool) $log['reveal'];
        $names = $this->names($log);
        $step = $next ? end($log['steps']) : null;

        return [
            'correct' => $reveal ? $correct : null, 'graded' => $correct !== null,
            'explanation' => $reveal && $a->show_correct_answers && ($item['explanation_ar'] || $item['explanation_en']) ? ['ar' => $item['explanation_ar'], 'en' => $item['explanation_en']] : null,
            'next' => $next ? AssessmentService::publicItem($next) : null, 'level' => $step['level'] ?? null, 'skill' => $step ? $this->skillOf($step['track'], $names) : null,
            'why' => $step ? $this->why($step, $names, $reveal) : null, 'done' => $next === null,
            'answered' => $this->answered($log), 'total' => (int) $log['total'],
        ];
    }

    /**
     * On submit: the question on screen counts with whatever answer was saved for it, and the questions not asked are
     * added unanswered, so stopping early scores out of the planned length. The attempt is filled but not saved.
     */
    public function fill(AssessmentAttempt $at): void
    {
        $log = $at->adaptive_log;
        if (! $log) {
            return;
        }
        $questions = $at->questions;
        $last = array_key_last($log['steps']);
        if ($last !== null && $log['steps'][$last]['answered_at'] === null) {
            if (array_key_exists($log['steps'][$last]['question_id'], $at->answers ?? [])) {
                $item = collect($questions)->firstWhere('id', $log['steps'][$last]['question_id']);
                $log['steps'][$last]['correct'] = $this->grade($item, ($at->answers ?? [])[$item['id']]);
                $log['steps'][$last]['answered_at'] = now()->toIso8601String();
            } else {
                $log['steps'][$last]['unanswered'] = true;   // on screen, left without an answer
            }
        }
        while (count($log['steps']) < $log['total'] && ($item = $this->next($at->assessment, $log, true))) {
            $questions[] = $item;
        }
        $at->fill(['questions' => $questions, 'adaptive_log' => $log, 'max_score' => array_sum(array_column($questions, 'points'))]);
    }

    /** The question on screen, still waiting for its answer. */
    public function currentId(AssessmentAttempt $at): ?string
    {
        $steps = $at->adaptive_log['steps'] ?? [];
        $current = $steps ? end($steps) : null;

        return $current && $current['answered_at'] === null && empty($current['skipped']) ? $current['question_id'] : null;
    }

    /** @return array<string, mixed>|null the learner's view: progress, the current level and why this question */
    public function view(AssessmentAttempt $at): ?array
    {
        $log = $at->adaptive_log;
        if (! $log) {
            return null;
        }
        $names = $this->names($log);
        $reveal = (bool) $log['reveal'];
        $current = $log['steps'] ? end($log['steps']) : null;
        $open = $current && $current['answered_at'] === null && empty($current['skipped']);

        return [
            'total' => (int) $log['total'], 'answered' => $this->answered($log), 'reveal' => $reveal,
            'current_id' => $open ? $current['question_id'] : null, 'level' => $open ? $current['level'] : null,
            'skill' => $open ? $this->skillOf($current['track'], $names) : null, 'why' => $open ? $this->why($current, $names, $reveal) : null,
            'steps' => array_map(fn ($s) => ['level' => $s['level'], 'answered' => $s['answered_at'] !== null, 'correct' => $reveal ? $s['correct'] : null], array_values(array_filter($log['steps'], fn ($s) => empty($s['skipped'])))),
        ];
    }

    /** @return array<string, mixed>|null the teacher's view: every step with its level, result and full reason */
    public function trail(AssessmentAttempt $at): ?array
    {
        $log = $at->adaptive_log;
        if (! $log) {
            return null;
        }
        $names = $this->names($log);

        return [
            'total' => (int) $log['total'], 'answered' => $this->answered($log), 'rules' => $log['rules'],
            'competencies' => array_values(array_map(fn ($track, $t) => ['skill' => $this->skillOf($track, $names), 'start_mastery' => $t['mastery'] === null ? null : (int) round($t['mastery'] * 100), 'start_level' => $t['start'], 'level' => $t['level'], 'asked' => $t['asked']],
                array_keys($log['tracks']), $log['tracks'])),
            'steps' => array_map(fn ($s) => ['question_id' => $s['question_id'], 'level' => $s['level'], 'skill' => $this->skillOf($s['track'], $names), 'correct' => $s['correct'], 'skipped' => ! empty($s['skipped']), 'unanswered' => ! empty($s['unanswered']),
                'answered_at' => $s['answered_at'], 'why' => empty($s['skipped']) ? $this->why($s, $names, true) : $this->text('skipped', $s, $names)], $log['steps']),
        ];
    }

    /** Whether the learner hears right or wrong after each answer: always in practice, otherwise only with immediate feedback. */
    public function reveals(Assessment $a): bool
    {
        return $a->kind === 'practice' || $a->feedback_mode === 'immediate';
    }

    // Drawing ----------------------------------------------------------------------------------------------------

    /**
     * The candidates of every section, by competency and level, with their points; and how many questions the
     * practice asks (what the sections ask for, as far as the bank allows).
     *
     * @return array{0: array<string, array<string, list<string>>>, 1: array<string, float>, 2: array<string, int>, 3: int}
     */
    private function pool(Assessment $a): array
    {
        $pool = [];
        $points = [];
        $sections = [];
        $total = 0;
        foreach ($a->sections()->orderBy('sort_order')->get() as $i => $s) {
            if ($s->selection === 'fixed') {
                $questions = Question::whereIn('id', $s->question_ids ?? [])->get(['id', 'difficulty', 'skill_ids', 'points']);
                $want = count($s->question_ids ?? []);
            } else {
                $questions = Question::where('bank_id', $s->bank_id)->where('status', 'active')->when($s->category_ids, fn ($q, $c) => $q->whereIn('category_id', $c))->get(['id', 'difficulty', 'skill_ids', 'points']);
                $mix = array_sum(array_map('intval', (array) ($s->difficulty_mix ?: [])));
                $want = $mix > 0 ? $mix : (int) ($s->count ?? 0);
            }
            $added = 0;
            foreach ($questions->shuffle() as $q) {
                $track = (string) (collect((array) $q->skill_ids)->filter()->first() ?? self::GENERAL);
                $level = in_array($q->difficulty, self::LEVELS, true) ? $q->difficulty : 'medium';
                if (isset($points[$q->id]) || count($pool[$track][$level] ?? []) >= self::POOL_PER_LEVEL) {
                    continue;
                }
                $pool[$track] ??= array_fill_keys(self::LEVELS, []);
                $pool[$track][$level][] = $q->id;
                $points[$q->id] = (float) ($s->points_per_question ?? $q->points);
                $sections[$q->id] = $i;
                $added++;
            }
            $total += min($want, $added);
        }

        return [$pool, $points, $sections, $total];
    }

    /** Draws the next question into the trail. @param  array<string, mixed>  $log @return array<string, mixed>|null */
    private function next(Assessment $a, array &$log, bool $skipped = false): ?array
    {
        while (count($log['steps']) < $log['total']) {
            $track = $this->turn($log);
            if ($track === null) {
                $log['total'] = count($log['steps']);   // the bank ran out

                return null;
            }
            $t = $log['tracks'][$track];
            $level = $this->available($log['pool'][$track], $t['level'], $t['streak'] > 0);
            $id = array_shift($log['pool'][$track][$level]);
            $q = Question::find($id);
            if (! $q) {
                continue;   // removed from the bank since the attempt started
            }
            $log['tracks'][$track]['asked']++;
            $log['steps'][] = ['question_id' => $id, 'track' => $track, 'level' => $level, 'why' => $t['why'] + ($level !== $t['level'] ? ['fallback' => $t['level']] : []),
                'correct' => null, 'answered_at' => null] + ($skipped ? ['skipped' => true] : []);

            return $this->builder->freezeItem($q, (float) $log['points'][$id], (int) ($log['sections'][$id] ?? 0), (bool) $a->shuffle_options);
        }

        return null;
    }

    /** Competencies take turns: the one asked least so far goes next (then the one with most questions left). @param  array<string, mixed>  $log */
    private function turn(array $log): ?string
    {
        $best = null;
        $bestKey = null;
        foreach ($log['tracks'] as $track => $t) {
            $left = array_sum(array_map('count', $log['pool'][$track]));
            if ($left === 0) {
                continue;
            }
            $key = [$t['asked'], -$left];
            if ($best === null || $key < $bestKey) {
                [$best, $bestKey] = [(string) $track, $key];
            }
        }

        return $best;
    }

    /** The wanted level, or the nearest with questions left (towards harder after a correct answer). @param  array<string, list<string>>  $levels */
    private function available(array $levels, string $want, bool $rising): string
    {
        $order = match ($want) {
            'easy' => ['easy', 'medium', 'hard'],
            'hard' => ['hard', 'medium', 'easy'],
            default => $rising ? ['medium', 'hard', 'easy'] : ['medium', 'easy', 'hard'],
        };
        foreach ($order as $level) {
            if (! empty($levels[$level])) {
                return $level;
            }
        }

        return $want;
    }

    // The staircase ----------------------------------------------------------------------------------------------

    /** @param  array<string, int>  $cfg @return array{0: string, 1: array<string, mixed>} the starting level and why */
    private function startLevel(string $track, ?float $mastery, array $cfg): array
    {
        if ($track === self::GENERAL) {
            return ['medium', ['code' => 'start_general']];
        }
        if ($mastery === null) {
            return ['medium', ['code' => 'start_none']];
        }
        $p = (int) round($mastery * 100);
        if ($mastery * 100 < $cfg['easy_below']) {
            return ['easy', ['code' => 'start_low', 'mastery' => $p, 'threshold' => $cfg['easy_below']]];
        }
        if ($mastery * 100 > $cfg['hard_above']) {
            return ['hard', ['code' => 'start_high', 'mastery' => $p, 'threshold' => $cfg['hard_above']]];
        }

        return ['medium', ['code' => 'start_mid', 'mastery' => $p]];
    }

    /** One step of the staircase for the competency just answered. @param  array<string, mixed>  $log */
    private function move(array &$log, string $track, ?bool $correct): void
    {
        $t = $log['tracks'][$track];
        $i = (int) array_search($t['level'], self::LEVELS, true);
        $up = max(1, (int) $log['rules']['up_after']);
        $down = max(1, (int) $log['rules']['down_after']);
        if ($correct === null) {
            $t['why'] = ['code' => 'pending'];   // waits for the trainer: the level stays
        } elseif ($correct) {
            $t['misses'] = 0;
            $t['streak']++;
            if ($i === count(self::LEVELS) - 1) {
                $t['streak'] = 0;
                $t['why'] = ['code' => 'top'];
            } elseif ($t['streak'] >= $up) {
                $t['streak'] = 0;
                $t['level'] = self::LEVELS[$i + 1];
                $t['why'] = ['code' => 'up', 'n' => $up];
            } else {
                $t['why'] = ['code' => 'streak', 'n' => $up - $t['streak']];
            }
        } else {
            $t['streak'] = 0;
            $t['misses']++;
            if ($t['misses'] >= $down) {
                $t['misses'] = 0;
                if ($i > 0) {
                    $t['level'] = self::LEVELS[$i - 1];
                    $t['why'] = ['code' => 'down', 'n' => $down];
                } else {
                    $t['why'] = ['code' => 'bottom'];
                }
            } else {
                $t['why'] = ['code' => 'miss', 'n' => $down - $t['misses']];
            }
        }
        $log['tracks'][$track] = $t;
    }

    /** @param  array<string, mixed>  $item */
    private function grade(array $item, mixed $answer): ?bool
    {
        $r = QuestionTypes::get($item['type'])->grade($item['payload'], $answer);

        return $r['manual'] ? null : $r['ratio'] >= 1;
    }

    /** @param  array<string, mixed>  $log */
    private function answered(array $log): int
    {
        return count(array_filter($log['steps'], fn ($s) => $s['answered_at'] !== null && empty($s['skipped'])));
    }

    // Why this question ------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $log @return array<string, array{ar: string, en: string}> */
    private function names(array $log): array
    {
        $ids = array_values(array_filter(array_keys($log['tracks']), fn ($t) => $t !== self::GENERAL));

        return Skill::whereIn('id', $ids)->get(['id', 'name_ar', 'name_en'])->mapWithKeys(fn ($s) => [$s->id => ['ar' => (string) $s->name_ar, 'en' => (string) ($s->name_en ?: $s->name_ar)]])->all();
    }

    /** @param  array<string, array{ar: string, en: string}>  $names @return array{ar: string, en: string}|null */
    private function skillOf(string $track, array $names): ?array
    {
        return $track === self::GENERAL ? null : ($names[$track] ?? null);
    }

    /**
     * Why this question, in plain words. Where answers are not revealed during the attempt (an adaptive quiz without
     * immediate feedback) the reason never says whether the last answer was right.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, array{ar: string, en: string}>  $names
     * @return array{ar: string, en: string}
     */
    private function why(array $step, array $names, bool $reveal): array
    {
        $code = $step['why']['code'];
        if (! $reveal && ! str_starts_with($code, 'start_')) {
            return $this->text('neutral', $step, $names);
        }

        return $this->text(isset($step['why']['fallback']) ? 'fallback' : $code, $step, $names);
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, array{ar: string, en: string}>  $names
     * @return array{ar: string, en: string}
     */
    private function text(string $code, array $step, array $names): array
    {
        $skill = $this->skillOf($step['track'], $names);
        $on = $skill ? ['ar' => "في «{$skill['ar']}»", 'en' => "on “{$skill['en']}”"] : ['ar' => 'هنا', 'en' => 'here'];
        $of = $skill ? ['ar' => "لـ«{$skill['ar']}»", 'en' => "of “{$skill['en']}”"] : ['ar' => 'لهذه الأسئلة', 'en' => 'of these questions'];
        $w = $step['why'];
        $n = (int) ($w['n'] ?? 1);
        $m = $w['mastery'] ?? null;
        $t = $w['threshold'] ?? null;
        $levelAr = ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب'];
        $wantedAr = ['easy' => 'سهلة', 'medium' => 'متوسطة', 'hard' => 'صعبة'];
        $wantedEn = ['easy' => 'easier', 'medium' => 'middle-level', 'hard' => 'harder'];
        $levelEn = ['easy' => 'easy', 'medium' => 'medium', 'hard' => 'hard'];
        $wordsEn = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'];
        $nEn = $wordsEn[$n] ?? (string) $n;

        return match ($code) {
            'start_low' => ['ar' => "إتقانك {$of['ar']} {$m}%، أقل من {$t}%، لذلك تبدأ بأسئلة أسهل.", 'en' => "Your mastery {$of['en']} is {$m}%, below {$t}%, so you start with easier questions."],
            'start_high' => ['ar' => "إتقانك {$of['ar']} {$m}%، أعلى من {$t}%، لذلك تبدأ بأسئلة أصعب.", 'en' => "Your mastery {$of['en']} is {$m}%, above {$t}%, so you start with harder questions."],
            'start_mid' => ['ar' => "إتقانك {$of['ar']} {$m}%، لذلك تبدأ بالمستوى المتوسط.", 'en' => "Your mastery {$of['en']} is {$m}%, so you start at the middle level."],
            'start_none' => ['ar' => "لا توجد لدينا إجابات سابقة لك {$on['ar']} بعد، لذلك تبدأ بالمستوى المتوسط.", 'en' => "We have no answers from you {$on['en']} yet, so you start at the middle level."],
            'start_general' => ['ar' => 'هذه الأسئلة غير مرتبطة بكفاية محددة، لذلك تبدأ بالمستوى المتوسط ويتبع المستوى إجاباتك.', 'en' => 'These questions are not linked to a competency, so you start at the middle level and the level follows your answers.'],
            'up' => $n === 1
                ? ['ar' => "أجبت عن السؤال الأخير {$on['ar']} إجابة صحيحة، لذلك هذا السؤال أصعب.", 'en' => "You answered the last question {$on['en']} correctly, so this one is harder."]
                : ['ar' => ($n === 2 ? 'أجبت عن سؤالين متتاليين' : "أجبت عن {$n} أسئلة متتالية")." {$on['ar']} إجابة صحيحة، لذلك هذا السؤال أصعب.", 'en' => "You answered {$nEn} questions {$on['en']} correctly in a row, so this one is harder."],
            'streak' => $n === 1
                ? ['ar' => "إجابة صحيحة. إجابة صحيحة أخرى {$on['ar']} تنقلك إلى أسئلة أصعب.", 'en' => "Correct. One more correct answer {$on['en']} moves you to harder questions."]
                : ['ar' => 'إجابة صحيحة. '.($n === 2 ? 'إجابتان صحيحتان أخريان' : "{$n} إجابات صحيحة أخرى")." متتالية {$on['ar']} تنقلك إلى أسئلة أصعب.", 'en' => "Correct. {$this->capital($nEn)} more correct answers in a row {$on['en']} move you to harder questions."],
            'top' => ['ar' => "إجابة صحيحة، وأنت في أصعب مستوى {$on['ar']}، لذلك يبقى السؤال صعباً.", 'en' => "Correct, and you are at the hardest level {$on['en']}, so this question stays hard."],
            'down' => $n === 1
                ? ['ar' => "إجابتك الأخيرة {$on['ar']} لم تكن صحيحة، لذلك هذا السؤال أسهل قليلاً.", 'en' => "Your last answer {$on['en']} was not right, so this one is a little easier."]
                : ['ar' => ($n === 2 ? 'آخر إجابتين لك' : "آخر {$n} إجابات لك")." {$on['ar']} لم تكن صحيحة، لذلك هذا السؤال أسهل قليلاً.", 'en' => "Your last {$nEn} answers {$on['en']} were not right, so this one is a little easier."],
            'miss' => ['ar' => "إجابتك الأخيرة {$on['ar']} لم تكن صحيحة، ويبقى المستوى كما هو الآن.", 'en' => "Your last answer {$on['en']} was not right; the level stays the same for now."],
            'bottom' => ['ar' => "يبقى المستوى أسهل {$on['ar']} حتى تبني ثقتك خطوة خطوة.", 'en' => "The level stays easier {$on['en']} while you build up step by step."],
            'pending' => ['ar' => 'يصحّح مدربك إجابتك الأخيرة، لذلك يبقى المستوى كما هو.', 'en' => 'Your trainer grades your last answer, so the level stays the same.'],
            'fallback' => ['ar' => "لم تتبقَّ أسئلة {$wantedAr[$w['fallback']]} {$on['ar']}، لذلك هذا السؤال {$levelAr[$step['level']]}.", 'en' => "No more {$wantedEn[$w['fallback']]} questions are left {$on['en']}, so this one is {$levelEn[$step['level']]}."],
            'neutral' => ['ar' => "اخترناه لك بناءً على إجاباتك حتى الآن وإتقانك {$of['ar']}.", 'en' => "Chosen for you from your answers so far and your mastery {$of['en']}."],
            'skipped' => ['ar' => 'لم يُعرض: انتهى التدريب قبل الوصول إليه، ويُحتسب دون إجابة.', 'en' => 'Not asked: the practice ended before it, and it counts as unanswered.'],
            default => ['ar' => 'اخترناه لك بناءً على مستواك.', 'en' => 'Chosen for you from your level.'],
        };
    }

    private function capital(string $word): string
    {
        return ucfirst($word);
    }
}
