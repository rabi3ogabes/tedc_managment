<?php

namespace App\Learning\EKits;

use App\Models\Assessment;
use App\Models\CourseLesson;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Question;
use App\Models\QuestionBank;
use Illuminate\Support\Facades\DB;

/**
 * Publishes an e-kit source as a real e-course: a program with one module per chapter (an article and a knowledge check)
 * and a final assessment that draws from a question bank. Idempotent: a kit that already has modules is left alone
 * unless a rebuild is asked for, so edits made on the dashboard are never lost.
 */
class EKitBuilder
{
    /** @return array{program: Program, created: bool} */
    public function build(array $kit, bool $rebuild = false): array
    {
        return DB::transaction(function () use ($kit, $rebuild) {
            $start = today();
            $program = Program::updateOrCreate(['code' => $kit['code']], [
                'category_id' => ProgramCategory::query()->value('id'),
                'title_ar' => $kit['title_ar'], 'title_en' => $kit['title_en'],
                'summary_ar' => implode('، ', array_slice($kit['objectives_ar'], 0, 3)), 'summary_en' => implode(', ', array_slice($kit['objectives_en'], 0, 3)),
                'description_ar' => $kit['title_ar'].' — '.implode('. ', $kit['objectives_ar']), 'description_en' => $kit['title_en'].' — '.implode('. ', $kit['objectives_en']),
                'objectives' => $kit['objectives_ar'], 'delivery_mode' => 'online', 'level' => 'beginner', 'total_hours' => $kit['hours'] ?? 2, 'capacity' => 5000,
                'min_attendance_percent' => 0, 'requires_tasks' => false, 'requires_evaluation' => false,
                'start_date' => $start->copy()->subDay(), 'end_date' => $start->copy()->addYear(),
                'registration_opens_at' => $start->copy()->subDay(), 'registration_closes_at' => $start->copy()->addYear()->endOfDay(),
                'registration_modes' => Program::MODES, 'status' => Program::STATUS_IN_PROGRESS, 'is_featured' => false,
                'has_course' => true, 'course_sequential' => true, 'course_completion_percent' => 100, 'course_auto_certificate' => true,
            ]);

            if ($program->courseModules()->exists()) {
                if (! $rebuild) {
                    return ['program' => $program, 'created' => false];
                }
                $this->clear($program);
            }
            $this->modules($program, $kit);
            $this->finalAssessment($program, $kit);

            return ['program' => $program->refresh(), 'created' => true];
        });
    }

    private function clear(Program $program): void
    {
        Assessment::where('program_id', $program->id)->each(fn ($a) => $a->delete());
        QuestionBank::where('program_id', $program->id)->each(fn ($b) => $b->delete());
        $program->courseModules()->each(fn ($m) => $m->delete());
    }

    private function modules(Program $program, array $kit): void
    {
        $order = 0;
        foreach ($kit['chapters'] as $i => $chapter) {
            $module = $program->courseModules()->create(['title_ar' => $chapter['title_ar'], 'title_en' => $chapter['title_en'], 'sort_order' => $i + 1]);
            $module->lessons()->create([
                'program_id' => $program->id, 'type' => 'article', 'title_ar' => $chapter['title_ar'], 'title_en' => $chapter['title_en'],
                'body_ar' => EKitSource::plain($chapter['body_ar']), 'body_en' => EKitSource::plain($chapter['body_en']), 'sort_order' => ++$order, 'is_required' => true, 'status' => 'published', 'duration_seconds' => 0, 'settings' => [],
            ]);
            $check = $module->lessons()->create([
                'program_id' => $program->id, 'type' => 'quiz', 'title_ar' => 'اختبر نفسك: '.$chapter['title_ar'], 'title_en' => 'Check yourself: '.$chapter['title_en'],
                'sort_order' => ++$order, 'is_required' => true, 'status' => 'published', 'duration_seconds' => 0, 'settings' => ['pass_percent' => 50, 'max_attempts' => 0, 'show_correct' => true],
            ]);
            $this->quizQuestions($check, $chapter['check']);
        }
        $module = $program->courseModules()->create(['title_ar' => 'التقييم النهائي', 'title_en' => 'Final assessment', 'sort_order' => count($kit['chapters']) + 1]);
        $final = $module->lessons()->create([
            'program_id' => $program->id, 'type' => 'quiz', 'title_ar' => 'التقييم النهائي', 'title_en' => 'Final assessment',
            'sort_order' => ++$order, 'is_required' => true, 'status' => 'published', 'duration_seconds' => 0, 'settings' => ['pass_percent' => 70, 'max_attempts' => 3, 'show_correct' => true],
        ]);
        $this->quizQuestions($final, $kit['final']);
    }

    /** @param  list<array<string, mixed>>  $questions */
    private function quizQuestions(CourseLesson $lesson, array $questions): void
    {
        foreach (array_values($questions) as $i => $q) {
            $lesson->questions()->create([
                'type' => 'single', 'text_ar' => $q['stem_ar'], 'text_en' => $q['stem_en'], 'points' => 1, 'sort_order' => $i + 1,
                'explanation_ar' => $q['explanation_ar'] ?: null, 'explanation_en' => $q['explanation_en'] ?: null,
                'options' => $this->options($q),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function options(array $q): array
    {
        return array_map(fn ($o, $k) => ['id' => chr(97 + $k), 'text_ar' => $o['ar'], 'text_en' => $o['en'], 'correct' => $k === $q['correct']], $q['options'], array_keys($q['options']));
    }

    /** The final assessment draws a fresh set from a question bank each attempt, so a retake is not the same paper. */
    private function finalAssessment(Program $program, array $kit): void
    {
        $bank = QuestionBank::create(['title_ar' => 'بنك أسئلة: '.$kit['title_ar'], 'title_en' => 'Question bank: '.$kit['title_en'], 'program_id' => $program->id, 'visibility' => 'program', 'description' => 'Source: resources/ekits/kits.json']);
        foreach ($kit['final'] as $q) {
            Question::create([
                'bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => $q['stem_ar'], 'stem_en' => $q['stem_en'], 'difficulty' => 'medium', 'points' => 1, 'version' => 1, 'status' => 'active',
                'explanation_ar' => $q['explanation_ar'] ?: null, 'explanation_en' => $q['explanation_en'] ?: null, 'payload' => ['options' => $this->options($q)],
            ]);
        }
        $lesson = $program->courseLessons()->where('title_en', 'Final assessment')->first();
        $draw = max(1, count($kit['final']) - 1);
        $a = Assessment::create([
            'program_id' => $program->id, 'lesson_id' => $lesson?->id, 'kind' => 'quiz', 'title_ar' => 'التقييم النهائي: '.$kit['title_ar'], 'title_en' => 'Final assessment: '.$kit['title_en'],
            'max_attempts' => 3, 'pass_percent' => 70, 'shuffle_questions' => true, 'shuffle_options' => true, 'feedback_mode' => 'after_submit', 'show_score' => true, 'show_correct_answers' => true, 'status' => 'published',
        ]);
        $a->sections()->create(['selection' => 'random', 'bank_id' => $bank->id, 'count' => $draw, 'points_per_question' => 1, 'sort_order' => 0]);
    }
}
