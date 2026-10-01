<?php

namespace App\Services;

use App\Models\CourseLesson;
use App\Models\Program;
use App\Models\TrainingKit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ready-made course structures and the import of a training kit's files, so the content of an online program
 * starts from a professional skeleton instead of an empty page. Everything is created as draft for the admin to fill.
 */
class CourseBlueprints
{
    /** How strictly videos are watched. */
    public const WATCH = [
        'strict' => ['allow_seeking' => false, 'min_watch_percent' => 90, 'max_speed' => 1.5, 'pause_when_hidden' => true],
        'standard' => ['allow_seeking' => true, 'min_watch_percent' => 80, 'max_speed' => 2, 'pause_when_hidden' => true],
        'free' => ['allow_seeking' => true, 'min_watch_percent' => 50, 'max_speed' => 3, 'pause_when_hidden' => false],
    ];

    public function __construct(private readonly FileStorage $storage) {}

    /** @return array<string, array{ar: string, en: string, ar_text: string, en_text: string, modules: list<array{ar: string, en: string, lessons: list<array{0: string, 1: string, 2: string}>}>}> */
    public function all(): array
    {
        // lesson = [type, title_ar, title_en]
        return [
            'classic' => [
                'ar' => 'دورة متكاملة', 'en' => 'Complete course', 'ar_text' => 'مقدمة، محتوى أساسي بالفيديو والعروض، اختبار ختامي واستبيان.', 'en_text' => 'Introduction, core content in video and slides, a final quiz and a survey.',
                'modules' => [
                    ['ar' => 'مقدمة البرنامج', 'en' => 'Introduction', 'lessons' => [['video', 'كلمة الترحيب', 'Welcome'], ['presentation', 'أهداف البرنامج', 'Program objectives']]],
                    ['ar' => 'المحتوى الأساسي', 'en' => 'Core content', 'lessons' => [['video', 'الدرس الأول', 'Lesson one'], ['presentation', 'عرض الدرس الأول', 'Lesson one slides'], ['article', 'قراءة داعمة', 'Supporting reading'], ['quiz', 'اختبار قصير', 'Short quiz']]],
                    ['ar' => 'الختام والتقييم', 'en' => 'Wrap-up', 'lessons' => [['quiz', 'الاختبار الختامي', 'Final quiz'], ['survey', 'استبيان رضا المتدرب', 'Learner feedback']]],
                ],
            ],
            'micro' => [
                'ar' => 'تعلّم مصغّر', 'en' => 'Micro-learning', 'ar_text' => 'ثلاثة فيديوهات قصيرة يتبع كلًّا منها سؤال سريع.', 'en_text' => 'Three short videos, each followed by a quick check.',
                'modules' => [['ar' => 'الدروس', 'en' => 'Lessons', 'lessons' => [['video', 'الفيديو الأول', 'Video one'], ['quiz', 'تحقق سريع 1', 'Quick check 1'], ['video', 'الفيديو الثاني', 'Video two'], ['quiz', 'تحقق سريع 2', 'Quick check 2'], ['video', 'الفيديو الثالث', 'Video three'], ['quiz', 'تحقق سريع 3', 'Quick check 3']]]],
            ],
            'workshop' => [
                'ar' => 'ورشة بتقييم', 'en' => 'Workshop with assessment', 'ar_text' => 'عروض تقديمية ومقال تطبيقي ثم اختبار قبلي وبعدي.', 'en_text' => 'Slides and a practical article with a pre- and post-test.',
                'modules' => [
                    ['ar' => 'قبل الورشة', 'en' => 'Before', 'lessons' => [['quiz', 'الاختبار القبلي', 'Pre-test']]],
                    ['ar' => 'الورشة', 'en' => 'Workshop', 'lessons' => [['presentation', 'العرض الرئيسي', 'Main presentation'], ['article', 'دليل التطبيق', 'Practice guide']]],
                    ['ar' => 'بعد الورشة', 'en' => 'After', 'lessons' => [['quiz', 'الاختبار البعدي', 'Post-test'], ['survey', 'تقييم الورشة', 'Workshop feedback']]],
                ],
            ],
        ];
    }

    /** Creates the modules and draft lessons of a blueprint. @return int lessons created */
    public function apply(Program $program, string $blueprint, string $watch = 'standard'): int
    {
        $plan = $this->all()[$blueprint] ?? abort(422);
        $created = 0;

        DB::transaction(function () use ($program, $plan, $watch, &$created) {
            $order = (int) $program->courseModules()->max('sort_order');
            foreach ($plan['modules'] as $m) {
                $module = $program->courseModules()->create(['title_ar' => $m['ar'], 'title_en' => $m['en'], 'sort_order' => ++$order]);
                foreach ($m['lessons'] as $i => [$type, $ar, $en]) {
                    $module->lessons()->create(['program_id' => $program->id, 'type' => $type, 'title_ar' => $ar, 'title_en' => $en, 'sort_order' => $i + 1, 'settings' => $this->settings($type, $watch)]);
                    $created++;
                }
            }
            $program->update(['has_course' => true]);
        });

        return $created;
    }

    /** Training kits whose files can become lessons: the program's own kit first. @return list<array<string, mixed>> */
    public function kits(?Program $program = null): array
    {
        return TrainingKit::with(['files' => fn ($q) => $q->orderBy('sort_order')])
            ->whereIn('status', [TrainingKit::APPROVED, TrainingKit::PUBLISHED, TrainingKit::IN_DEVELOPMENT, TrainingKit::DRAFT])
            ->orderByRaw('case when program_id = ? then 0 else 1 end', [$program?->id])->orderBy('title_ar')->limit(40)->get()
            ->map(fn (TrainingKit $k) => [
                'id' => $k->id, 'code' => $k->code, 'title' => $k->translate('title'), 'status' => $k->status, 'linked' => $program !== null && $k->program_id === $program->id,
                'files' => $k->files->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'kind' => $f->kind, 'category' => $f->category, 'size' => $f->size, 'lesson_type' => $this->lessonTypeFor($f->kind)])->values(),
            ])->values()->all();
    }

    /** Turns the chosen files of a kit into draft lessons of one module (the files are copied, the kit stays as it is). @return int lessons created */
    public function importKit(Program $program, TrainingKit $kit, array $fileIds, string $watch = 'standard'): int
    {
        $files = $kit->files()->whereIn('id', $fileIds)->orderBy('sort_order')->get()->filter(fn ($f) => $this->lessonTypeFor($f->kind) !== null && $f->storage_path);
        if ($files->isEmpty()) {
            return 0;
        }

        $created = 0;
        DB::transaction(function () use ($program, $kit, $files, $watch, &$created) {
            $module = $program->courseModules()->create(['title_ar' => $kit->title_ar, 'title_en' => $kit->title_en, 'sort_order' => (int) $program->courseModules()->max('sort_order') + 1]);
            foreach ($files as $i => $file) {
                $type = $this->lessonTypeFor($file->kind);
                $lesson = $module->lessons()->create([
                    'program_id' => $program->id, 'type' => $type, 'title_ar' => $file->name, 'title_en' => $file->name, 'sort_order' => $i + 1, 'settings' => $this->settings($type, $watch),
                ]);
                $extension = strtolower(pathinfo($file->original_name ?: $file->storage_path, PATHINFO_EXTENSION)) ?: 'bin';
                $path = "course/{$program->id}/{$lesson->id}/".Str::uuid().'.'.$extension;
                $this->storage->copy('documents', $file->storage_path, 'materials', $path);
                $lesson->update(['source' => 'upload', 'file_path' => $path, 'file_name' => $file->original_name ?: $file->name, 'file_mime' => $file->mime, 'file_size' => (int) $file->size]);
                $created++;
            }
            $program->update(['has_course' => true]);
        });

        return $created;
    }

    private function lessonTypeFor(string $kind): ?string
    {
        return match ($kind) {
            'video' => CourseLesson::VIDEO,
            'presentation', 'pdf' => CourseLesson::PRESENTATION,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function settings(string $type, string $watch): array
    {
        return match ($type) {
            CourseLesson::VIDEO => self::WATCH[$watch] ?? self::WATCH['standard'],
            CourseLesson::PRESENTATION => ['min_view_percent' => 80, 'downloadable' => false],
            CourseLesson::QUIZ => ['pass_percent' => 70, 'max_attempts' => null, 'shuffle_questions' => true, 'shuffle_options' => true, 'show_answers' => 'after_submit'],
            default => [],
        };
    }
}
