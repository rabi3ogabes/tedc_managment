<?php

namespace App\Ai\Feedback;

use App\Ai\AiGuard;
use App\Ai\Rag\LocalEmbedder;
use App\Ai\Rag\Retriever;
use App\Exceptions\BusinessRuleException;
use App\Models\AiFeedbackDraft;
use App\Models\AssessmentAttempt;
use App\Models\Skill;
use App\Models\User;
use App\Services\Assessment\AttemptService;
use App\Services\Assessment\QuestionTypes;

/**
 * Feedback after an assessment. Objective questions: what the person chose, what was right, why, how many others made the same mistake (distractor analysis),
 * which competency it belongs to, and where in the course to look. Essays: a draft for the grader — rubric criterion by criterion with a suggested score — that a person accepts,
 * edits or rejects. A draft never becomes a grade by itself.
 */
class SmartFeedback
{
    public function __construct(private readonly AiGuard $guard, private readonly AttemptService $attempts, private readonly Retriever $retriever) {}

    /** @param  array<string, mixed>  $result  AttemptService::result(): already limited to what the feedback rules let this person see @return array<string, mixed> */
    public function forLearner(AssessmentAttempt $at, User $user, array $result): array
    {
        $review = $result['review'] ?? [];
        if (! $review) {
            return ['available' => false, 'questions' => [], 'review' => []];
        }
        $items = collect($at->questions)->keyBy('id');
        $out = [];
        $weak = [];
        foreach ($review as $r) {
            $item = $items->get($r['id']);
            if (! $item || $r['correct'] === null) {
                continue;   // essays are graded by a person
            }
            $row = ['question_id' => $r['id'], 'correct' => (bool) $r['correct'], 'skills' => $this->skillNames($item['skill_ids'] ?? [])];
            if (! $r['correct']) {
                $row += $this->distractor($item, $r['your_answer'], $at);
                $weak[] = $item;
            }
            $out[] = $row;
        }

        return ['available' => true, 'questions' => $out, 'review' => $this->whatToReview($weak, $at, $user), 'source' => 'rules'];
    }

    /** What was chosen, the right answer in words, and how common the mistake is. @return array<string, mixed> */
    private function distractor(array $item, mixed $answer, AssessmentAttempt $at): array
    {
        $payload = $item['payload'];
        $opts = collect($payload['options'] ?? []);
        $text = fn ($o) => $o ? ['ar' => $o['text_ar'] ?? null, 'en' => $o['text_en'] ?? null] : null;
        $out = ['explanation_ar' => $item['explanation_ar'] ?? null, 'explanation_en' => $item['explanation_en'] ?? null];
        if (is_string($answer) && $opts->isNotEmpty()) {
            $out['your_choice'] = $text($opts->firstWhere('id', $answer));
            $out['right_choice'] = $text($opts->firstWhere('correct', true));
            $shares = $this->shares($item['question_id'], $at);
            if (($shares['total'] ?? 0) >= 5 && isset($shares['options'][$answer])) {
                $out['same_mistake_percent'] = (int) round($shares['options'][$answer] / $shares['total'] * 100);
            }
        }

        return $out;
    }

    /** How the people who answered this question chose, from every graded attempt. @return array{total: int, options: array<string, int>} */
    private function shares(string $questionId, AssessmentAttempt $except): array
    {
        $options = [];
        $total = 0;
        AssessmentAttempt::where('assessment_id', $except->assessment_id)->where('status', 'graded')->where('id', '!=', $except->id)->select(['questions', 'answers'])->limit(500)->get()->each(function ($a) use ($questionId, &$options, &$total) {
            $ans = ($a->answers ?? [])[$questionId] ?? null;
            if (is_string($ans)) {
                $options[$ans] = ($options[$ans] ?? 0) + 1;
                $total++;
            }
        });

        return ['total' => $total, 'options' => $options];
    }

    /** @param  list<string>  $ids @return list<array<string, string>> */
    private function skillNames(array $ids): array
    {
        return $ids ? Skill::whereIn('id', $ids)->get(['name_ar', 'name_en'])->map(fn ($s) => ['ar' => $s->name_ar, 'en' => $s->name_en])->all() : [];
    }

    /** Course content that talks about what was missed. @param  list<array<string, mixed>>  $weak @return list<array<string, string>> */
    private function whatToReview(array $weak, AssessmentAttempt $at, User $user): array
    {
        $out = [];
        foreach ($weak as $item) {
            $stem = trim(($item['stem_ar'] ?? '').' '.($item['stem_en'] ?? ''));
            foreach ($this->retriever->search($user, $stem, 2, 0.15) as $hit) {
                if ($hit['source_type'] === 'lesson' && ! isset($out[$hit['source_id']])) {
                    $out[$hit['source_id']] = ['title' => $hit['title'], 'route' => $hit['route'], 'lesson_id' => $hit['source_id']];
                }
            }
        }

        return array_values($out);
    }

    // ---- essay drafts for the grader ----------------------------------------------------------------

    /** Drafts for every essay of a submitted attempt that has none yet. @return list<AiFeedbackDraft> */
    public function draftEssays(AssessmentAttempt $at): array
    {
        $out = [];
        foreach ($at->questions as $item) {
            if ($item['type'] !== 'essay') {
                continue;
            }
            $answer = trim((string) (($at->answers ?? [])[$item['id']] ?? ''));
            if ($answer === '' || AiFeedbackDraft::where(['attempt_id' => $at->id, 'question_id' => $item['id']])->exists()) {
                continue;
            }
            $out[] = $this->draftOne($at, $item, $answer);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $item */
    private function draftOne(AssessmentAttempt $at, array $item, string $answer): AiFeedbackDraft
    {
        $rubric = $item['payload']['rubric'] ?? [];
        $max = (float) $item['points'];
        $ai = $this->aiDraft($item, $answer, $rubric, $max);
        [$text, $score, $criteria, $source] = $ai ?? $this->ruleDraft($item, $answer, $rubric, $max);

        return AiFeedbackDraft::create(['attempt_id' => $at->id, 'question_id' => $item['id'], 'draft_text' => $text, 'suggested_score' => $score, 'max_points' => $max, 'rubric' => $criteria, 'source' => $source, 'status' => 'draft']);
    }

    /** @param  list<array<string, mixed>>  $rubric @return array{0: string, 1: float, 2: array, 3: string}|null */
    private function aiDraft(array $item, string $answer, array $rubric, float $max): ?array
    {
        if (! $this->guard->available('feedback', true)) {
            return null;
        }
        $schema = ['type' => 'object', 'required' => ['feedback', 'criteria'], 'properties' => ['feedback' => ['type' => 'string'], 'criteria' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'met' => ['type' => 'number'], 'comment' => ['type' => 'string']]]]]];
        $prompt = "QUESTION:\n".($item['stem_ar'] ?? '')."\n".($item['stem_en'] ?? '')."\n\nRUBRIC (criterion id, text, points):\n".json_encode($rubric, JSON_UNESCAPED_UNICODE)."\n\nSTUDENT ANSWER:\n".mb_substr($answer, 0, 6000);
        $res = $this->guard->json('feedback', 'You are a careful teacher-trainer grading a written answer against a rubric. For each criterion give met between 0 and 1 and a short comment; then write constructive feedback in the same language as the answer (max 120 words). Do not mention any personal data.', $prompt, $schema, null, true);
        if (! $res || ! isset($res['feedback'])) {
            return null;
        }
        $criteria = [];
        $score = 0.0;
        $byId = collect($res['criteria'] ?? [])->keyBy('id');
        foreach ($rubric as $r) {
            $met = max(0.0, min(1.0, (float) ($byId[$r['id']]['met'] ?? 0)));
            $criteria[] = ['id' => $r['id'], 'text' => $r['text'], 'points' => $r['points'], 'met' => $met, 'comment' => (string) ($byId[$r['id']]['comment'] ?? '')];
            $score += $met * (float) $r['points'];
        }
        $score = $rubric ? min($max, round($score, 2)) : min($max, round((float) ($res['score'] ?? 0), 2));

        return [mb_substr((string) $res['feedback'], 0, 2000), $score, $criteria, 'ai'];
    }

    /** Without a model: how many of each criterion's key words appear in the answer. @param  list<array<string, mixed>>  $rubric @return array{0: string, 1: float, 2: array, 3: string} */
    private function ruleDraft(array $item, string $answer, array $rubric, float $max): array
    {
        $words = LocalEmbedder::tokens($answer);
        $set = array_flip($words);
        $criteria = [];
        $score = 0.0;
        $missing = [];
        foreach ($rubric as $r) {
            $keys = array_values(array_unique(LocalEmbedder::tokens((string) $r['text'])));
            $hit = $keys ? count(array_filter($keys, fn ($k) => isset($set[$k]))) / count($keys) : 0.0;
            $met = $hit >= 0.6 ? 1.0 : ($hit >= 0.3 ? 0.5 : 0.0);
            $criteria[] = ['id' => $r['id'], 'text' => $r['text'], 'points' => $r['points'], 'met' => $met, 'comment' => ''];
            $score += $met * (float) $r['points'];
            if ($met < 1) {
                $missing[] = $r['text'];
            }
        }
        $minWords = (int) ($item['payload']['min_words'] ?? 0);
        $count = count(preg_split('/\s+/u', trim($answer)) ?: []);
        $notes = [];
        if ($minWords && $count < $minWords) {
            $notes[] = "The answer has {$count} words; at least {$minWords} were asked for.";
        }
        if ($missing) {
            $notes[] = 'Points to look at: '.implode('؛ ', array_slice($missing, 0, 4)).'.';
        }
        $text = ($rubric ? 'Draft based on keyword coverage of the rubric — please read the answer before accepting. ' : 'No rubric was set for this question, so no score is suggested. ').implode(' ', $notes);

        return [trim($text), $rubric ? min($max, round($score, 2)) : 0.0, $criteria, 'rules'];
    }

    /** The grader accepts the draft as it is, or with changes. The grade is written the normal way, by the grader. */
    public function review(AiFeedbackDraft $d, User $grader, string $action, ?float $score = null, ?string $comment = null): AiFeedbackDraft
    {
        if ($d->status !== 'draft') {
            throw new BusinessRuleException('This draft was already reviewed.', 'already_reviewed');
        }
        if ($action === 'reject') {
            $d->update(['status' => 'rejected', 'reviewed_by' => $grader->id, 'reviewed_at' => now()]);

            return $d;
        }
        $final = $action === 'accept' ? (float) $d->suggested_score : (float) $score;
        $text = $action === 'accept' ? $d->draft_text : (string) $comment;
        if ($final < 0 || $final > (float) $d->max_points) {
            throw new BusinessRuleException('The score must be between 0 and '.$d->max_points.'.', 'bad_score');
        }
        $at = AssessmentAttempt::findOrFail($d->attempt_id);
        $this->attempts->grade($at, [['question_id' => $d->question_id, 'points' => $final, 'comment' => $text]], null, $grader);
        $d->update(['status' => $action === 'accept' ? 'accepted' : 'edited', 'final_score' => $final, 'final_comment' => $text, 'reviewed_by' => $grader->id, 'reviewed_at' => now()]);

        return $d;
    }

    public static function manual(array $item): bool
    {
        return QuestionTypes::get($item['type'])->grade($item['payload'], null)['manual'] === true;
    }
}
