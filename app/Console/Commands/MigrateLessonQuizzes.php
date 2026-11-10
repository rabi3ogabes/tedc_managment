<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Models\CourseLesson;
use App\Models\Question;
use App\Models\QuestionBank;
use Illuminate\Console\Command;

class MigrateLessonQuizzes extends Command
{
    protected $signature = 'tedc:migrate-lesson-quizzes {--dry-run : Only report what would be created}';

    protected $description = 'Copy the questions of lesson quizzes into question banks and create a fixed-selection assessment for each (lesson quizzes keep working and keep their attempts)';

    public function handle(): int
    {
        $made = 0;
        foreach (CourseLesson::where('type', 'quiz')->with('questions')->get() as $lesson) {
            if ($lesson->questions->isEmpty() || Assessment::where('lesson_id', $lesson->id)->exists()) {
                continue;
            }
            $made++;
            if ($this->option('dry-run')) {
                continue;
            }
            $bank = QuestionBank::firstOrCreate(['program_id' => $lesson->program_id, 'title_en' => 'Course quizzes'], ['title_ar' => 'اختبارات الدورة', 'visibility' => 'program']);
            $ids = [];
            foreach ($lesson->questions as $q) {
                $correct = collect($q->options)->where('correct', true)->count();
                $options = array_map(fn ($o) => ['id' => $o['id'], 'text_ar' => $o['text_ar'] ?? '', 'text_en' => $o['text_en'] ?? null, 'correct' => (bool) ($o['correct'] ?? false)], $q->options);
                $type = $q->type === 'multiple' || $correct > 1 ? 'multiple_select' : 'single_choice';
                $ids[] = Question::create(['bank_id' => $bank->id, 'type' => $type, 'stem_ar' => $q->text_ar, 'stem_en' => $q->text_en, 'payload' => ['options' => $options], 'points' => $q->points, 'explanation_ar' => $q->explanation_ar, 'explanation_en' => $q->explanation_en, 'status' => 'active', 'version' => 1, 'difficulty' => 'medium'])->id;
            }
            $a = Assessment::create(['program_id' => $lesson->program_id, 'lesson_id' => $lesson->id, 'kind' => 'quiz', 'title_ar' => $lesson->title_ar, 'title_en' => $lesson->title_en, 'max_attempts' => (int) ($lesson->setting('max_attempts') ?? 3),
                'pass_percent' => (float) $lesson->setting('pass_percent', 70), 'time_limit_minutes' => $lesson->setting('time_limit_minutes'), 'shuffle_questions' => (bool) $lesson->setting('shuffle_questions', false), 'shuffle_options' => (bool) $lesson->setting('shuffle_options', false), 'status' => 'draft']);
            $a->sections()->create(['selection' => 'fixed', 'question_ids' => $ids, 'sort_order' => 0]);
        }
        $this->info(($this->option('dry-run') ? 'Would create ' : 'Created ').$made.' assessment(s).');

        return self::SUCCESS;
    }
}
