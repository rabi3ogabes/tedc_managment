<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Registration;
use App\Models\TargetGroup;
use App\Models\Trainer;
use Illuminate\Database\Seeder;

/**
 * Five online courses for trying the whole learning experience (web portal and mobile app) with the test accounts,
 * each showing different features: strict / standard / free watching, uploaded-style links and YouTube, slides, articles,
 * quizzes (single, multiple, true/false, time limit, attempts, pass mark), every survey question type, optional lessons,
 * draft lessons, sequential or open order and automatic or manual certificates.
 *
 * Idempotent: the programs are refreshed on every deploy, but a course that already has lessons is left alone so
 * edits made on the dashboard and learners' progress are never overwritten.
 */
class DemoOnlineCoursesSeeder extends Seeder
{
    private const CLIP = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/';

    private const SLIDES = 'https://mozilla.github.io/pdf.js/web/compressed.tracemonkey-pldi-09.pdf';

    private const YOUTUBE = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';

    /** Test trainee (1-4) => the courses (1-4) they are enrolled in; course 5 stays open for self-registration. */
    private const ENROLLED = [1 => [1, 2], 2 => [1, 2, 3], 3 => [1, 2, 3, 4], 4 => [1, 2, 3, 4]];

    public function run(): void
    {
        $teacher = Employee::whereHas('user', fn ($q) => $q->where('email', 'teacher@tedc.qa'))->first();
        $jobTitle = JobTitle::where('code', 'TEACHER')->value('id');
        if (! $teacher || ! $jobTitle) {
            return; // the demo organisation is not seeded
        }
        $trainer = Trainer::where('email', 'trainer1@tedc.qa')->first();
        $start = today();

        foreach ($this->courses() as $n => $c) {
            $program = Program::updateOrCreate(['code' => "TEST-OL{$n}"], [
                'category_id' => ProgramCategory::query()->value('id'),
                'title_ar' => $c['title_ar'], 'title_en' => $c['title_en'], 'summary_ar' => $c['summary_ar'], 'summary_en' => $c['summary_en'],
                'description_ar' => $c['summary_ar'].' '.$c['features_ar'], 'description_en' => $c['summary_en'].' '.$c['features_en'],
                'objectives' => $c['objectives'], 'delivery_mode' => 'online', 'level' => $c['level'], 'total_hours' => $c['hours'], 'capacity' => 200,
                'min_attendance_percent' => 80, 'requires_tasks' => false, 'requires_evaluation' => false,
                'start_date' => $start->copy()->subDay(), 'end_date' => $start->copy()->addDays(90),
                'registration_opens_at' => $start->copy()->subDays(30), 'registration_closes_at' => $start->copy()->addDays(90)->endOfDay(),
                'registration_modes' => Program::MODES, 'status' => Program::STATUS_IN_PROGRESS, 'is_featured' => $n === 1,
                'has_course' => true, 'course_sequential' => $c['sequential'], 'course_completion_percent' => 100, 'course_auto_certificate' => $c['auto_certificate'],
            ]);
            TargetGroup::firstOrCreate(['program_id' => $program->id, 'description' => 'مجموعة الاختبار'], ['job_title_id' => $jobTitle, 'education_stage' => 'primary']);
            if ($trainer) {
                $program->trainers()->syncWithoutDetaching([$trainer->id => ['role' => 'lead']]);
            }

            if (! $program->courseModules()->exists()) {
                $this->build($program, $c['modules']);
            }
        }

        // Enrol the test trainees; the last course is left open so registering by yourself can be tested too.
        foreach (self::ENROLLED as $t => $list) {
            $employee = Employee::whereHas('user', fn ($q) => $q->where('email', "trainee{$t}@tedc.qa"))->first();
            if (! $employee) {
                continue;
            }
            foreach ($list as $n) {
                $program = Program::where('code', "TEST-OL{$n}")->first();
                $program && Registration::firstOrCreate(['program_id' => $program->id, 'employee_id' => $employee->id], [
                    'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now(),
                ]);
            }
        }
    }

    /** @param list<array<string, mixed>> $modules */
    private function build(Program $program, array $modules): void
    {
        foreach ($modules as $m => $module) {
            $row = $program->courseModules()->create(['title_ar' => $module['ar'], 'title_en' => $module['en'], 'description_ar' => $module['desc_ar'] ?? null, 'description_en' => $module['desc_en'] ?? null, 'sort_order' => $m + 1]);
            foreach ($module['lessons'] as $i => $l) {
                $lesson = $row->lessons()->create([
                    'program_id' => $program->id, 'type' => $l['type'], 'title_ar' => $l['ar'], 'title_en' => $l['en'],
                    'description_ar' => $l['desc_ar'] ?? null, 'description_en' => $l['desc_en'] ?? null, 'body_ar' => $l['body_ar'] ?? null, 'body_en' => $l['body_en'] ?? null,
                    'sort_order' => $i + 1, 'is_required' => $l['required'] ?? true, 'status' => $l['status'] ?? 'published', 'duration_seconds' => $l['duration'] ?? 0,
                    'source' => isset($l['url']) ? 'url' : null, 'external_url' => $l['url'] ?? null, 'slide_count' => $l['slides'] ?? 0, 'settings' => $l['settings'] ?? [],
                ]);
                foreach ($l['questions'] ?? [] as $q => $question) {
                    $lesson->questions()->create($question + ['sort_order' => $q + 1]);
                }
                foreach ($l['survey'] ?? [] as $q => $question) {
                    $lesson->surveyQuestions()->create($question + ['required' => true, 'sort_order' => $q + 1]);
                }
            }
        }
    }

    // Course content ------------------------------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function courses(): array
    {
        $strict = ['allow_seeking' => false, 'min_watch_percent' => 90, 'max_speed' => 1.0, 'pause_when_hidden' => true];
        $standard = ['allow_seeking' => true, 'min_watch_percent' => 80, 'max_speed' => 2, 'pause_when_hidden' => true];
        $free = ['allow_seeking' => true, 'min_watch_percent' => 50, 'max_speed' => 3, 'pause_when_hidden' => false];

        return [
            1 => [
                'title_ar' => 'دورة تجريبية ١: أساسيات التعلّم الرقمي', 'title_en' => 'Test course 1: Digital learning basics', 'level' => 'beginner', 'hours' => 2,
                'summary_ar' => 'الدورة الكاملة: فيديو بمشاهدة صارمة، عرض تقديمي، مقال، اختبارات واستبيان.', 'summary_en' => 'The complete course: strictly watched video, slides, an article, quizzes and a survey.',
                'features_ar' => 'الدروس تُفتح بالترتيب والشهادة تصدر تلقائياً عند الإكمال.', 'features_en' => 'Lessons unlock in order and the certificate is issued automatically on completion.',
                'objectives' => ['فهم أسس التعلم الرقمي', 'استخدام أدوات التعلم عن بعد'], 'sequential' => true, 'auto_certificate' => true,
                'modules' => [
                    ['ar' => 'المقدمة', 'en' => 'Introduction', 'desc_ar' => 'ابدأ من هنا', 'desc_en' => 'Start here', 'lessons' => [
                        ['type' => 'video', 'ar' => 'كلمة الترحيب (15 ثانية)', 'en' => 'Welcome (15 seconds)', 'url' => self::CLIP.'ForBiggerBlazes.mp4', 'duration' => 15, 'settings' => $strict, 'desc_ar' => 'مشاهدة صارمة: لا تقديم ولا تسريع.', 'desc_en' => 'Strict watching: no skipping, no speeding up.'],
                        ['type' => 'presentation', 'ar' => 'أهداف الدورة', 'en' => 'Course objectives', 'url' => self::SLIDES, 'slides' => 14, 'settings' => ['min_view_percent' => 80, 'downloadable' => false]],
                    ]],
                    ['ar' => 'المحتوى', 'en' => 'Content', 'lessons' => [
                        ['type' => 'article', 'ar' => 'قراءة: لماذا التعلم الرقمي؟', 'en' => 'Reading: why digital learning?',
                            'body_ar' => "## لماذا التعلّم الرقمي؟\n\nيتيح التعلّم الرقمي للمتدرب أن يتعلم في الوقت والمكان المناسبين له، مع تتبّع دقيق لتقدمه.\n\n- مرونة في الوقت\n- محتوى قابل للتكرار\n- قياس فوري للنتائج\n\n**نصيحة:** خصّص 20 دقيقة يومياً للتعلم.",
                            'body_en' => "## Why digital learning?\n\nDigital learning lets people learn at the time and place that suit them, with precise progress tracking.\n\n- Flexible timing\n- Repeatable content\n- Instant results\n\n**Tip:** set aside 20 minutes every day."],
                        ['type' => 'quiz', 'ar' => 'اختبار قصير', 'en' => 'Short quiz', 'settings' => ['pass_percent' => 70, 'max_attempts' => null, 'shuffle_questions' => true, 'shuffle_options' => true, 'show_answers' => 'after_submit'],
                            'questions' => $this->quizBasics()],
                    ]],
                    ['ar' => 'الختام', 'en' => 'Wrap-up', 'lessons' => [
                        ['type' => 'survey', 'ar' => 'استبيان رضا المتدرب', 'en' => 'Learner feedback', 'survey' => $this->feedbackSurvey()],
                    ]],
                ],
            ],
            2 => [
                'title_ar' => 'دورة تجريبية ٢: تعلّم مصغّر بالفيديو', 'title_en' => 'Test course 2: Micro-learning with video', 'level' => 'beginner', 'hours' => 1,
                'summary_ar' => 'ثلاثة فيديوهات قصيرة (روابط مباشرة ويوتيوب) يتبع كلًّا منها اختبار سريع.', 'summary_en' => 'Three short videos (direct links and YouTube), each followed by a quick check.',
                'features_ar' => 'الدروس مفتوحة بأي ترتيب ويُسمح بالتقديم والتسريع.', 'features_en' => 'Lessons open in any order; seeking and speed-up are allowed.',
                'objectives' => ['التعلم السريع', 'التحقق الفوري من الفهم'], 'sequential' => false, 'auto_certificate' => true,
                'modules' => [
                    ['ar' => 'الدروس المصغّرة', 'en' => 'Micro lessons', 'lessons' => [
                        ['type' => 'video', 'ar' => 'الفيديو الأول (15 ثانية)', 'en' => 'Video one (15 seconds)', 'url' => self::CLIP.'ForBiggerEscapes.mp4', 'duration' => 15, 'settings' => $free],
                        ['type' => 'quiz', 'ar' => 'تحقق سريع ١', 'en' => 'Quick check 1', 'settings' => ['pass_percent' => 50, 'max_attempts' => null, 'shuffle_questions' => false, 'shuffle_options' => false, 'show_answers' => 'after_submit'], 'questions' => [$this->tf('الفيديو القصير يساعد على التركيز.', 'Short videos help focus.', true)]],
                        ['type' => 'video', 'ar' => 'الفيديو الثاني (15 ثانية)', 'en' => 'Video two (15 seconds)', 'url' => self::CLIP.'ForBiggerJoyrides.mp4', 'duration' => 15, 'settings' => $standard],
                        ['type' => 'quiz', 'ar' => 'تحقق سريع ٢', 'en' => 'Quick check 2', 'settings' => ['pass_percent' => 50, 'shuffle_questions' => false, 'shuffle_options' => false, 'show_answers' => 'after_submit'], 'questions' => [$this->single('كم تستغرق الدروس المصغّرة عادةً؟', 'How long are micro lessons usually?', ['أقل من 10 دقائق', 'ساعتان', 'يوم كامل'], ['Under 10 minutes', 'Two hours', 'A full day'], 0)]],
                        ['type' => 'video', 'ar' => 'فيديو يوتيوب (مدمج)', 'en' => 'YouTube video (embedded)', 'url' => self::YOUTUBE, 'duration' => 60, 'settings' => $free, 'desc_ar' => 'يُعرض داخل مشغّل يوتيوب المدمج.', 'desc_en' => 'Shown inside the embedded YouTube player.'],
                        ['type' => 'quiz', 'ar' => 'تحقق سريع ٣', 'en' => 'Quick check 3', 'settings' => ['pass_percent' => 50, 'shuffle_questions' => false, 'shuffle_options' => false, 'show_answers' => 'after_submit'], 'questions' => [$this->tf('يمكن مشاهدة الدروس المصغّرة من الهاتف.', 'Micro lessons can be watched on a phone.', true)]],
                    ]],
                ],
            ],
            3 => [
                'title_ar' => 'دورة تجريبية ٣: ورشة تطبيقية باختبار قبلي وبعدي', 'title_en' => 'Test course 3: Applied workshop with pre- and post-test', 'level' => 'intermediate', 'hours' => 3,
                'summary_ar' => 'اختبار قبلي، عرض تقديمي ودليل تطبيق، ثم اختبار بعدي بوقت محدد ومحاولتين.', 'summary_en' => 'A pre-test, slides and a practice guide, then a timed post-test with two attempts.',
                'features_ar' => 'نسبة النجاح 80٪، الإجابات الصحيحة لا تظهر، ومحاولتان فقط.', 'features_en' => 'Pass mark 80%, correct answers are hidden and only two attempts are allowed.',
                'objectives' => ['قياس المستوى القبلي', 'تطبيق مهارات الورشة', 'إثبات التحسن'], 'sequential' => true, 'auto_certificate' => true,
                'modules' => [
                    ['ar' => 'قبل الورشة', 'en' => 'Before', 'lessons' => [
                        ['type' => 'quiz', 'ar' => 'الاختبار القبلي (غير ملزم بالنجاح)', 'en' => 'Pre-test (pass not required)', 'settings' => ['pass_percent' => 0, 'max_attempts' => 1, 'shuffle_questions' => true, 'shuffle_options' => true, 'show_answers' => 'never'], 'questions' => $this->quizBasics()],
                    ]],
                    ['ar' => 'الورشة', 'en' => 'Workshop', 'lessons' => [
                        ['type' => 'presentation', 'ar' => 'العرض الرئيسي', 'en' => 'Main presentation', 'url' => self::SLIDES, 'slides' => 14, 'settings' => ['min_view_percent' => 100, 'downloadable' => true], 'desc_ar' => 'يجب عرض كل الشرائح. التحميل مسموح.', 'desc_en' => 'Every slide must be viewed. Download allowed.'],
                        ['type' => 'article', 'ar' => 'دليل التطبيق', 'en' => 'Practice guide', 'body_ar' => "### خطوات التطبيق\n\n1. اختر هدفاً واحداً.\n2. جرّب الأداة لمدة 10 دقائق.\n3. دوّن ملاحظاتك.\n\n> التعلّم بالتطبيق أقوى من القراءة وحدها.", 'body_en' => "### Steps\n\n1. Pick a single goal.\n2. Try the tool for 10 minutes.\n3. Write down your notes.\n\n> Learning by doing beats reading alone."],
                    ]],
                    ['ar' => 'بعد الورشة', 'en' => 'After', 'lessons' => [
                        ['type' => 'quiz', 'ar' => 'الاختبار البعدي (10 دقائق، محاولتان)', 'en' => 'Post-test (10 minutes, two attempts)', 'settings' => ['pass_percent' => 80, 'max_attempts' => 2, 'time_limit_minutes' => 10, 'shuffle_questions' => true, 'shuffle_options' => true, 'show_answers' => 'never'], 'questions' => $this->quizBasics()],
                        ['type' => 'survey', 'ar' => 'تقييم الورشة', 'en' => 'Workshop feedback', 'survey' => $this->feedbackSurvey()],
                    ]],
                ],
            ],
            4 => [
                'title_ar' => 'دورة تجريبية ٤: مهارات التواصل الفعّال', 'title_en' => 'Test course 4: Effective communication', 'level' => 'intermediate', 'hours' => 2,
                'summary_ar' => 'مقالات ودروس اختيارية واستبيان بكل أنواع الأسئلة، وشهادة تصدرها الإدارة يدوياً.', 'summary_en' => 'Articles, optional lessons and a survey with every question type; the certificate is issued by an administrator.',
                'features_ar' => 'درس مسودة غير ظاهر للمتدرب، ودروس اختيارية لا تُحتسب في الإكمال.', 'features_en' => 'A draft lesson stays hidden from learners and optional lessons do not count towards completion.',
                'objectives' => ['الاستماع الفعال', 'صياغة الرسالة بوضوح'], 'sequential' => false, 'auto_certificate' => false,
                'modules' => [
                    ['ar' => 'أساسيات التواصل', 'en' => 'Communication basics', 'lessons' => [
                        ['type' => 'article', 'ar' => 'عناصر الرسالة', 'en' => 'Parts of a message', 'body_ar' => "## عناصر الرسالة\n\n**المرسل** و**الرسالة** و**المستقبل** و**التغذية الراجعة**.\n\n| العنصر | دوره |\n|---|---|\n| المرسل | يصوغ الفكرة |\n| المستقبل | يفسّرها |", 'body_en' => "## Parts of a message\n\n**Sender**, **message**, **receiver** and **feedback**."],
                        ['type' => 'article', 'ar' => 'قراءة إضافية (اختيارية)', 'en' => 'Extra reading (optional)', 'required' => false, 'body_ar' => 'محتوى إضافي لمن يرغب في التوسع.', 'body_en' => 'Extra content for those who want more.'],
                        ['type' => 'video', 'ar' => 'مثال تطبيقي (اختياري)', 'en' => 'Worked example (optional)', 'required' => false, 'url' => self::CLIP.'ForBiggerMeltdowns.mp4', 'duration' => 15, 'settings' => $free],
                        ['type' => 'article', 'ar' => 'درس قيد الإعداد (مسودة)', 'en' => 'Lesson in preparation (draft)', 'status' => 'draft', 'body_ar' => 'لا يظهر للمتدربين.', 'body_en' => 'Hidden from learners.'],
                    ]],
                    ['ar' => 'التقييم', 'en' => 'Assessment', 'lessons' => [
                        ['type' => 'quiz', 'ar' => 'اختبار الاستماع الفعال', 'en' => 'Active listening quiz', 'settings' => ['pass_percent' => 60, 'shuffle_questions' => false, 'shuffle_options' => false, 'show_answers' => 'after_submit'], 'questions' => [
                            $this->multi('أيٌّ مما يلي من علامات الاستماع الفعال؟', 'Which of these show active listening?', ['التواصل البصري', 'مقاطعة المتحدث', 'إعادة صياغة ما قيل', 'النظر إلى الهاتف'], ['Eye contact', 'Interrupting', 'Paraphrasing', 'Checking the phone'], [0, 2]),
                            $this->tf('التغذية الراجعة جزء من عملية التواصل.', 'Feedback is part of communication.', true),
                        ]],
                        ['type' => 'survey', 'ar' => 'استبيان بكل أنواع الأسئلة', 'en' => 'Survey with every question type', 'survey' => $this->fullSurvey()],
                    ]],
                ],
            ],
            5 => [
                'title_ar' => 'دورة تجريبية ٥: الأمن الرقمي (صارمة)', 'title_en' => 'Test course 5: Digital security (strict)', 'level' => 'advanced', 'hours' => 2,
                'summary_ar' => 'دورة بأشد القواعد: مشاهدة بلا تقديم أو تسريع، نجاح 90٪ وثلاث محاولات. افتح التسجيل بنفسك من التطبيق.', 'summary_en' => 'The strictest rules: no seeking or speed-up, 90% to pass and three attempts. Register yourself from the app.',
                'features_ar' => 'غير مسجَّل فيها أحد مسبقاً لتجربة التسجيل الذاتي.', 'features_en' => 'Nobody is pre-enrolled, so self-registration can be tested.',
                'objectives' => ['حماية الحسابات', 'اكتشاف الرسائل المشبوهة'], 'sequential' => true, 'auto_certificate' => true,
                'modules' => [
                    ['ar' => 'الحماية الأساسية', 'en' => 'Core protection', 'lessons' => [
                        ['type' => 'video', 'ar' => 'كلمات المرور (15 ثانية)', 'en' => 'Passwords (15 seconds)', 'url' => self::CLIP.'ForBiggerFun.mp4', 'duration' => 60, 'settings' => $strict],
                        ['type' => 'presentation', 'ar' => 'التصيّد الإلكتروني', 'en' => 'Phishing', 'url' => self::SLIDES, 'slides' => 14, 'settings' => ['min_view_percent' => 100, 'downloadable' => false]],
                        ['type' => 'article', 'ar' => 'قائمة التحقق اليومية', 'en' => 'Daily checklist', 'body_ar' => "- فعّل التحقق بخطوتين\n- لا تشارك رمز التحقق\n- حدّث جهازك", 'body_en' => "- Turn on two-step verification\n- Never share a code\n- Keep your device updated"],
                    ]],
                    ['ar' => 'الاختبار النهائي', 'en' => 'Final exam', 'lessons' => [
                        ['type' => 'quiz', 'ar' => 'الاختبار النهائي (90٪، 3 محاولات)', 'en' => 'Final exam (90%, 3 attempts)', 'settings' => ['pass_percent' => 90, 'max_attempts' => 3, 'time_limit_minutes' => 15, 'shuffle_questions' => true, 'shuffle_options' => true, 'show_answers' => 'after_submit'], 'questions' => [
                            $this->single('ما أقوى كلمة مرور؟', 'Which password is the strongest?', ['12345678', 'اسمك وتاريخ ميلادك', 'عبارة طويلة عشوائية'], ['12345678', 'Your name and birthday', 'A long random phrase'], 2),
                            $this->tf('يجوز مشاركة رمز التحقق مع الدعم الفني.', 'You may share a verification code with support.', false),
                            $this->multi('ما علامات رسالة التصيّد؟', 'What are signs of phishing?', ['استعجال غير مبرر', 'رابط غير مألوف', 'بريد من زميل تعرفه ويخاطبك باسمك', 'طلب بيانات سرية'], ['Unjustified urgency', 'Unfamiliar link', 'An email from a colleague you know', 'A request for secrets'], [0, 1, 3]),
                        ]],
                    ]],
                ],
            ],
        ];
    }

    // Question builders ---------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function quizBasics(): array
    {
        return [
            $this->single('ما الميزة الأهم للتعلم الرقمي؟', 'What is the main benefit of digital learning?', ['المرونة في الوقت والمكان', 'يتطلب قاعة', 'لا يمكن قياسه'], ['Flexibility of time and place', 'It needs a room', 'It cannot be measured'], 0, 'التعلم الرقمي يتيح التعلم في أي وقت.', 'Digital learning can happen any time.'),
            $this->tf('يمكن تتبع تقدم المتدرب تلقائياً.', 'A learner’s progress can be tracked automatically.', true),
            $this->multi('أيٌّ من هذه أنواع الدروس في الدورة؟', 'Which of these are lesson types?', ['فيديو', 'اختبار', 'عرض تقديمي', 'طابعة'], ['Video', 'Quiz', 'Slides', 'Printer'], [0, 1, 2]),
        ];
    }

    private function single(string $ar, string $en, array $optionsAr, array $optionsEn, int $correct, ?string $explainAr = null, ?string $explainEn = null): array
    {
        return ['type' => 'single', 'text_ar' => $ar, 'text_en' => $en, 'points' => 1, 'explanation_ar' => $explainAr, 'explanation_en' => $explainEn, 'options' => $this->options($optionsAr, $optionsEn, [$correct])];
    }

    private function multi(string $ar, string $en, array $optionsAr, array $optionsEn, array $correct): array
    {
        return ['type' => 'multiple', 'text_ar' => $ar, 'text_en' => $en, 'points' => 2, 'options' => $this->options($optionsAr, $optionsEn, $correct)];
    }

    private function tf(string $ar, string $en, bool $true): array
    {
        return ['type' => 'true_false', 'text_ar' => $ar, 'text_en' => $en, 'points' => 1, 'options' => [
            ['id' => 'true', 'text_ar' => 'صح', 'text_en' => 'True', 'correct' => $true],
            ['id' => 'false', 'text_ar' => 'خطأ', 'text_en' => 'False', 'correct' => ! $true],
        ]];
    }

    private function options(array $ar, array $en, array $correct): array
    {
        return array_map(fn ($text, $i) => ['id' => chr(97 + $i), 'text_ar' => $text, 'text_en' => $en[$i] ?? $text, 'correct' => in_array($i, $correct, true)], $ar, array_keys($ar));
    }

    /** @return list<array<string, mixed>> */
    private function feedbackSurvey(): array
    {
        return [
            ['type' => 'rating', 'text_ar' => 'ما تقييمك العام للدورة؟', 'text_en' => 'How do you rate the course overall?'],
            ['type' => 'text', 'text_ar' => 'ما الذي أعجبك؟', 'text_en' => 'What did you like?', 'required' => false],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fullSurvey(): array
    {
        return [
            ['type' => 'rating', 'text_ar' => 'قيّم وضوح المحتوى', 'text_en' => 'Rate the clarity of the content'],
            ['type' => 'nps', 'text_ar' => 'ما احتمال أن توصي بالدورة لزميل؟ (0-10)', 'text_en' => 'How likely are you to recommend the course? (0-10)'],
            ['type' => 'choice', 'text_ar' => 'ما أنسب جهاز لك للتعلّم؟', 'text_en' => 'Which device suits you best?', 'options' => $this->options(['الهاتف', 'الحاسوب', 'الجهاز اللوحي'], ['Phone', 'Computer', 'Tablet'], [])],
            ['type' => 'multiple', 'text_ar' => 'أي أنواع الدروس تفضّل؟', 'text_en' => 'Which lesson types do you prefer?', 'options' => $this->options(['فيديو', 'مقال', 'عرض تقديمي', 'اختبار'], ['Video', 'Article', 'Slides', 'Quiz'], [])],
            ['type' => 'text', 'text_ar' => 'اقتراحاتك للتحسين', 'text_en' => 'Your suggestions', 'required' => false],
        ];
    }
}
