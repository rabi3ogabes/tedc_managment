<?php

namespace App\Services\Assessment;

use App\Models\CourseLesson;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\User;
use App\Models\VideoInteraction;
use App\Models\VideoInteractionResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Questions, reflections and checkpoints placed on a video's timeline, and what they do to the trainee's progress. */
class InteractionService
{
    /** @param  list<array<string, mixed>>  $items */
    public function replace(CourseLesson $lesson, array $items, User $by): Collection
    {
        DB::transaction(function () use ($lesson, $items, $by) {
            VideoInteraction::where('lesson_id', $lesson->id)->delete();
            foreach (array_values($items) as $i => $it) {
                $questionId = $it['question_id'] ?? null;
                if (! $questionId && ! empty($it['question'])) {
                    $bank = QuestionBank::firstOrCreate(['program_id' => $lesson->program_id, 'title_en' => 'Interactive video'], ['title_ar' => 'الفيديو التفاعلي', 'visibility' => 'program', 'owner_id' => $by->id]);
                    $questionId = app(QuestionBankService::class)->create($bank, $it['question'], $by)['question']->id;
                }
                VideoInteraction::create(['lesson_id' => $lesson->id, 'at_seconds' => $it['at_seconds'], 'type' => $it['type'] ?? 'question', 'question_id' => $questionId, 'prompt_ar' => $it['prompt_ar'] ?? null, 'prompt_en' => $it['prompt_en'] ?? null,
                    'required' => (bool) ($it['required'] ?? true), 'blocks_progress' => (bool) ($it['blocks_progress'] ?? true), 'require_correct' => (bool) ($it['require_correct'] ?? false), 'allow_skip' => (bool) ($it['allow_skip'] ?? false), 'sort_order' => $i]);
            }
        });

        return VideoInteraction::where('lesson_id', $lesson->id)->orderBy('at_seconds')->get();
    }

    /**
     * The first interaction still in the way: required, blocking, and not answered (or not answered right).
     * With `$any` every unsatisfied required interaction counts, not only the blocking ones (completion needs them all).
     */
    public function blockerFor(CourseLesson $lesson, Registration $registration, bool $any = false): ?VideoInteraction
    {
        $answered = VideoInteractionResponse::where('registration_id', $registration->id)->get()->keyBy('interaction_id');

        return VideoInteraction::where('lesson_id', $lesson->id)->where('required', true)->when(! $any, fn ($q) => $q->where('blocks_progress', true))->orderBy('at_seconds')->get()
            ->first(function (VideoInteraction $i) use ($answered) {
                $r = $answered->get($i->id);

                return ! $r || ($i->require_correct && $r->correct !== true);
            });
    }

    /** What the player shows: the interactions with their questions (no answers) and whether this trainee answered them. @return list<array<string, mixed>> */
    public function forTrainee(CourseLesson $lesson, Registration $registration): array
    {
        $answered = VideoInteractionResponse::where('registration_id', $registration->id)->get()->keyBy('interaction_id');
        $questions = Question::whereIn('id', VideoInteraction::where('lesson_id', $lesson->id)->pluck('question_id')->filter())->get()->keyBy('id');

        return VideoInteraction::where('lesson_id', $lesson->id)->orderBy('at_seconds')->get()->map(function (VideoInteraction $i) use ($answered, $questions) {
            $q = $i->question_id ? $questions->get($i->question_id) : null;
            $type = $q ? QuestionTypes::get($q->type) : null;
            $r = $answered->get($i->id);

            return ['id' => $i->id, 'at_seconds' => (float) $i->at_seconds, 'type' => $i->type, 'prompt_ar' => $i->prompt_ar, 'prompt_en' => $i->prompt_en, 'required' => $i->required, 'blocks_progress' => $i->blocks_progress, 'require_correct' => $i->require_correct, 'allow_skip' => $i->allow_skip,
                'question' => $q ? ['type' => $q->type, 'stem_ar' => $q->stem_ar, 'stem_en' => $q->stem_en, 'media' => $q->media, 'payload' => $type->publicPayload($type->validate($q->payload), false)] : null,
                'answered' => $r !== null, 'correct' => $r?->correct];
        })->all();
    }

    /** @return array{correct: ?bool, explanation_ar: ?string, explanation_en: ?string, unlocked: bool} */
    public function answer(VideoInteraction $i, Registration $registration, mixed $answer): array
    {
        $q = $i->question_id ? Question::find($i->question_id) : null;
        $correct = null;
        if ($q) {
            $r = QuestionTypes::get($q->type)->grade(QuestionTypes::get($q->type)->validate($q->payload), $answer);
            $correct = $r['manual'] ? null : $r['ratio'] >= 1;
        }
        VideoInteractionResponse::updateOrCreate(['interaction_id' => $i->id, 'registration_id' => $registration->id], ['answer' => is_array($answer) ? $answer : ['value' => $answer], 'correct' => $correct, 'answered_at' => now()]);

        return ['correct' => $correct, 'explanation_ar' => $q?->explanation_ar, 'explanation_en' => $q?->explanation_en, 'unlocked' => ! $i->require_correct || $correct !== false];
    }
}
