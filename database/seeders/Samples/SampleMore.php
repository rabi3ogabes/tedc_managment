<?php

namespace Database\Seeders\Samples;

use App\Models\Assessment;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\CourseLessonVersion;
use App\Models\CourseModule;
use App\Models\DeviceToken;
use App\Models\EntityAccount;
use App\Models\ExternalCompletion;
use App\Models\ExternalCourse;
use App\Models\Forecast;
use App\Models\LearnerMastery;
use App\Models\Material;
use App\Models\NeedsRule;
use App\Models\NotificationDelivery;
use App\Models\OfflineDevice;
use App\Models\PageBlock;
use App\Models\PageVersion;
use App\Models\ProgramGrant;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\RegistrationPriorityRule;
use App\Models\ResourceShare;
use App\Models\Skill;
use App\Services\Assessment\AttemptService;
use App\Services\Assessment\OfflineExamService;
use App\Services\Content\OfflineService;
use App\Services\CourseService;
use Illuminate\Support\Str;

/** E-kit learners (one finishes with a certificate, one is half way, one has just started), assessment attempts, mastery, ratings, shared resources, grants, device and delivery records, forecasts and rules. */
class SampleMore
{
    use Steps;

    public function __construct(private readonly SampleContext $c, private readonly CourseService $course, private readonly AttemptService $attempts) {}

    public function run(): void
    {
        $this->step('kit learners', fn () => $this->kitLearners());
        $this->step('assessment', fn () => $this->assessment());
        $this->step('offline', fn () => $this->offline());
        $this->step('adaptive', fn () => $this->adaptive());
        $this->step('face check', fn () => $this->faceCheck());
        $this->step('content', fn () => $this->content());
        $this->step('access', fn () => $this->access());
        $this->step('delivery', fn () => $this->delivery());
        $this->step('forecasts', fn () => $this->forecasts());
        $this->step('rules', fn () => $this->rules());
    }

    private function kitLearners(): void
    {
        $program = $this->c->program('EKIT-PORTAL');
        $t = $this->c->trainees();
        if (! $program || count($t) < 3 || Registration::where('program_id', $program->id)->exists()) {
            return;
        }
        $lessons = CourseLesson::where('program_id', $program->id)->where('status', 'published')->orderBy('sort_order')->get();
        foreach ([[$t[0], 99], [$t[1], 5], [$t[2], 1]] as [$u, $count]) {
            $emp = $u->employee;
            $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $emp->id, 'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
            foreach ($lessons->take($count) as $lesson) {
                if ($lesson->type === 'quiz') {
                    $answers = $lesson->questions->mapWithKeys(fn ($q) => [$q->id => collect($q->options)->where('correct', true)->pluck('id')->all()])->all();
                    $this->course->submitQuiz($lesson, $reg->fresh(), $answers, 60);
                } else {
                    $this->course->open($lesson, $reg->fresh());
                    $this->course->complete($lesson, $reg->fresh());
                }
            }
            $this->course->recompute($reg->fresh());
        }
    }

    /** A phone that keeps a course offline, and an offline exam package issued to it (TYP-12). */
    private function offline(): void
    {
        $program = $this->c->program('EKIT-QUESTIONING');
        $t = $this->c->trainees();
        $u = $t[3] ?? null;
        $a = $program ? Assessment::where('program_id', $program->id)->where('status', 'published')->first() : null;
        if (! $u || ! $a || OfflineDevice::query()->exists()) {
            return;
        }
        $reg = $this->c->registration($u, $program)
            ?? Registration::create(['program_id' => $program->id, 'employee_id' => $u->employee->id, 'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
        $a->update(['allow_offline' => true, 'offline_window_hours' => 72]);
        $device = app(OfflineService::class)->device($u->id, 'sample-device-'.substr($u->id, 0, 8), 'android');
        $device->update(['name' => 'Sample phone']);
        app(OfflineExamService::class)->issue($reg, $a, $device->device_id);
    }

    /**
     * Adaptive practice (AI-03): questions on questioning at three levels, one learner part-way through, and a review
     * lesson that reaches the learners whose mastery is low (the lesson hook assigns it when it is published).
     */
    private function adaptive(): void
    {
        $program = $this->c->program('EKIT-QUESTIONING');
        $t = $this->c->trainees();
        // The competency the first learner is weakest in, so the review lesson has someone to reach.
        $first = $program && $t ? $this->c->registration($t[0], $program) : null;
        $skill = Skill::find($first ? LearnerMastery::where('registration_id', $first->id)->orderBy('mastery')->value('skill_id') : null) ?? Skill::first();
        $module = $program ? CourseModule::where('program_id', $program->id)->orderBy('sort_order')->first() : null;
        if (! $program || ! $skill || ! $module || count($t) < 2 || Assessment::where('program_id', $program->id)->where('adaptive', true)->exists()) {
            return;
        }
        $bank = QuestionBank::create(['title_ar' => 'بنك التدريب التكيّفي: طرح الأسئلة', 'title_en' => 'Adaptive practice bank: questioning', 'program_id' => $program->id, 'visibility' => 'program']);
        $items = [
            'easy' => [
                ['أيّ الأسئلة الآتية يدعو المتعلم إلى إجابة أطول؟', 'Which question invites a longer answer?', [['لماذا تعتقد أن النبتة نمت أسرع؟', 'Why do you think the plant grew faster?'], ['هل نمت النبتة؟', 'Did the plant grow?']]],
                ['كم ينتظر المعلم عادةً بعد طرح السؤال؟', 'How long should a teacher usually wait after asking a question?', [['من 3 إلى 5 ثوانٍ تقريباً', 'About 3 to 5 seconds'], ['أقل من ثانية', 'Less than a second'], ['دقيقة كاملة قبل أن يتحدث أحد', 'A full minute before anyone speaks']]],
                ['يبدأ السؤال المفتوح عادةً بـ…', 'An open question usually starts with…', [['كيف أو لماذا', 'How or why'], ['هل', 'Is or are']]],
            ],
            'medium' => [
                ['أجاب متعلم إجابة خاطئة. أيّ متابعة تُبقيه يفكّر؟', 'A student answers wrongly. Which follow-up keeps them thinking?', [['ما الذي جعلك تختار هذه الإجابة؟', 'What made you choose that answer?'], ['خطأ، التالي.', 'Wrong. Next.'], ['من يجيب غيره؟', 'Anyone else?']]],
                ['أيّ سؤال يتحقق من الفهم لا من التذكّر؟', 'Which question checks understanding rather than recall?', [['كيف تشرح هذه القاعدة لتلميذ أصغر منك؟', 'How would you explain this rule to a younger pupil?'], ['ما التعريف الوارد في الصفحة 12؟', 'What is the definition on page 12?']]],
                ['ما الغرض الرئيس من «سؤال المفصل»؟', 'What is the main purpose of a hinge question?', [['أن يقرر المعلم بسرعة إن كان الصف مستعداً للانتقال', 'To decide quickly whether the class is ready to move on'], ['ملء الوقت في نهاية الحصة', 'To fill time at the end of the lesson'], ['رصد درجات للتقرير', 'To record marks for the report']]],
            ],
            'hard' => [
                ['في أسلوب «دون رفع الأيدي» يقوم المعلم بـ…', 'In a "no hands up" approach the teacher…', [['اختيار من يجيب، فيستعد كل متعلم بإجابة', 'chooses who answers, so every student prepares an answer'], ['سؤال المتطوعين فقط', 'asks only the volunteers'], ['طرح الأسئلة كتابةً فقط', 'asks questions only in writing']]],
                ['يقدّم المتعلمون إجابات قصيرة عن أسئلتك المفتوحة. ما الخطوة التالية الأفضل؟', 'Students give short answers to your open questions. What is the best next step?', [['قدّم نموذجاً لإجابة كاملة وامنحهم وقتاً للتفكير في أزواج قبل المشاركة', 'Model a full answer and give pairs thinking time before sharing'], ['تحوّل إلى أسئلة نعم أو لا', 'Switch to yes-or-no questions'], ['أجب عن الأسئلة بنفسك', 'Answer the questions yourself']]],
                ['أيّ تسلسل يصعد في مستويات بلوم؟', "Which sequence climbs Bloom's levels?", [['تذكّر ← شرح ← تطبيق ← تقويم', 'Recall → explain → apply → evaluate'], ['تقويم ← تذكّر ← تطبيق ← شرح', 'Evaluate → recall → apply → explain'], ['تطبيق ← تذكّر ← تقويم ← شرح', 'Apply → recall → evaluate → explain']]],
            ],
        ];
        foreach ($items as $level => $questions) {
            foreach ($questions as [$ar, $en, $options]) {
                Question::create(['bank_id' => $bank->id, 'type' => 'single_choice', 'stem_ar' => $ar, 'stem_en' => $en, 'difficulty' => $level, 'points' => 1, 'version' => 1, 'status' => 'active', 'skill_ids' => [$skill->id],
                    'payload' => ['options' => array_map(fn ($o, $k) => ['id' => chr(97 + $k), 'text_ar' => $o[0], 'text_en' => $o[1], 'correct' => $k === 0], $options, array_keys($options))]]);
            }
        }
        $practice = Assessment::create(['program_id' => $program->id, 'kind' => 'practice', 'adaptive' => true, 'title_ar' => 'تدريب تكيّفي: طرح الأسئلة', 'title_en' => 'Adaptive practice: questioning',
            'max_attempts' => 5, 'pass_percent' => 60, 'shuffle_options' => true, 'feedback_mode' => 'after_submit', 'show_score' => true, 'show_correct_answers' => true, 'status' => 'published']);
        $practice->sections()->create(['selection' => 'random', 'bank_id' => $bank->id, 'count' => 6, 'points_per_question' => 1, 'sort_order' => 0]);

        // One learner part-way through: two right answers so far, so the next question is harder.
        if ($first) {
            $at = $this->attempts->start($first, $practice);
            foreach (range(1, 2) as $i) {
                $current = collect($at->fresh()->questions)->last();
                $right = collect($current['payload']['options'])->firstWhere('correct', true)['id'];
                $this->attempts->adaptiveAnswer($at->fresh(), $current['id'], $right);
            }
        }

        CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'مراجعة: '.$skill->name_ar, 'title_en' => 'Review: '.$skill->name_en,
            'body_ar' => '<p>عودة سريعة إلى أساسيات هذه الكفاية بأمثلة من الصف، ثم ثلاثة أسئلة قصيرة للتأكد.</p>', 'body_en' => '<p>A short return to the basics of this competency with classroom examples, then three quick checks.</p>',
            'sort_order' => 900, 'is_required' => false, 'status' => 'published', 'settings' => ['remedial' => true, 'skill_id' => $skill->id]]);
    }

    /** An exam with the camera presence check (EXM-10), and one finished attempt whose flag a reviewer cleared with a reason. */
    private function faceCheck(): void
    {
        $program = $this->c->program('EKIT-QUESTIONING');
        $bank = QuestionBank::where('title_en', 'Adaptive practice bank: questioning')->first();
        $t = $this->c->trainees();
        if (! $program || ! $bank || ! $t || Assessment::where('program_id', $program->id)->where('title_en', 'Questioning check (camera presence)')->exists()) {
            return;
        }
        $exam = Assessment::create(['program_id' => $program->id, 'kind' => 'quiz', 'title_ar' => 'اختبار طرح الأسئلة (تحقق بالكاميرا)', 'title_en' => 'Questioning check (camera presence)',
            'time_limit_minutes' => 20, 'max_attempts' => 3, 'pass_percent' => 60, 'feedback_mode' => 'after_submit', 'show_score' => true, 'status' => 'published',
            'proctoring' => ['enabled' => true, 'fullscreen' => false, 'tab_switch_limit' => 3, 'action' => 'flag', 'face_check' => true, 'face_absent_seconds' => 10, 'face_absent_limit' => 1,
                'multiple_faces_limit' => 1, 'looking_away_limit' => 3, 'face_check_interval_ms' => 1000, 'face_fallback' => 'flagged']]);
        $exam->sections()->create(['selection' => 'random', 'bank_id' => $bank->id, 'count' => 4, 'points_per_question' => 1, 'sort_order' => 0]);

        $reg = $this->c->registration($t[0], $program);
        if (! $reg) {
            return;
        }
        $at = $this->attempts->start($reg, $exam);
        $answers = [];
        foreach ($at->questions as $q) {
            $answers[$q['id']] = collect($q['payload']['options'] ?? [])->firstWhere('correct', true)['id'] ?? 'a';
        }
        $at->update(['answers' => $answers]);
        $at = $this->attempts->submit($at->fresh());
        $started = $at->started_at;
        $reviewer = $this->c->admin();
        $at->update(['integrity' => [
            'events' => ['face_absent' => 2, 'looking_away' => 1, 'visibility_hidden' => 1], 'flags' => ['face_absent'],
            'log' => [['type' => 'face_absent', 'at' => $started->copy()->addMinutes(3)->toIso8601String()], ['type' => 'visibility_hidden', 'at' => $started->copy()->addMinutes(7)->toIso8601String()],
                ['type' => 'looking_away', 'at' => $started->copy()->addMinutes(7)->addSeconds(40)->toIso8601String()], ['type' => 'face_absent', 'at' => $started->copy()->addMinutes(12)->toIso8601String()]],
            'timeline' => [3 => ['face_absent' => 1], 7 => ['visibility_hidden' => 1, 'looking_away' => 1], 12 => ['face_absent' => 1]],
            'consent' => ['given_at' => $started->toIso8601String(), 'version' => '2026-10', 'camera' => true],
            'reviews' => $reviewer ? ['face_absent' => ['decision' => 'clear', 'note' => 'إضاءة خافتة خلف المتدرب؛ بقي أمام الجهاز طوال الوقت.', 'by' => $reviewer->id, 'by_name' => $reviewer->displayName(), 'at' => now()->toIso8601String()]] : [],
        ]]);
    }

    private function assessment(): void
    {
        $t = $this->c->trainees();
        $program = $this->c->program('EKIT-QUESTIONING');
        if (! $program || ! $t || Registration::where('program_id', $program->id)->exists()) {
            return;
        }
        $a = Assessment::where('program_id', $program->id)->where('status', 'published')->first();
        foreach (array_slice($t, 0, 3) as $i => $u) {
            $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $u->employee->id, 'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
            if (! $a) {
                continue;
            }
            $at = $this->attempts->start($reg, $a);
            $answers = [];
            foreach ($at->questions as $q) {
                $qid = is_array($q) ? ($q['id'] ?? null) : null;
                if ($qid) {
                    $opts = Question::find($qid)?->payload['options'] ?? [];
                    $right = collect($opts)->firstWhere('correct', true)['id'] ?? 'a';
                    $wrong = collect($opts)->firstWhere('correct', false)['id'] ?? 'b';
                    $answers[$qid] = $i === 2 ? $wrong : $right;
                }
            }
            $this->attempts->autosave($at, $answers);
            $this->attempts->submit($at->fresh());
        }
        foreach (array_slice($t, 0, 2) as $u) {
            if ($reg = $this->c->registration($u, $program)) {
                foreach (Skill::limit(3)->get() as $k => $skill) {
                    LearnerMastery::firstOrCreate(['registration_id' => $reg->id, 'skill_id' => $skill->id], ['mastery' => 0.45 + $k * 0.2, 'evidence_count' => 3 + $k]);
                }
            }
        }
    }

    private function content(): void
    {
        $t = $this->c->trainees();
        $lesson = CourseLesson::where('type', 'article')->orderBy('created_at')->first();
        $admin = $this->c->admin();
        if ($lesson) {
            foreach ($t as $i => $u) {
                ContentRating::firstOrCreate(['subject_type' => 'lesson', 'subject_id' => $lesson->id, 'user_id' => $u->id], ['stars' => 4 + ($i % 2), 'review' => $i === 0 ? 'شرح واضح ومباشر.' : null, 'status' => 'published']);
            }
            CourseLessonVersion::firstOrCreate(['lesson_id' => $lesson->id, 'version' => 1], ['snapshot' => $lesson->only(['title_ar', 'title_en', 'body_ar', 'body_en']), 'note' => 'النسخة الأولى', 'created_by' => $admin->id]);
        }
        $prog = $this->c->program('TEST-P1');
        ExternalCourse::firstOrCreate(['provider' => 'maktabati', 'external_id' => 'MK-SMP-1'], ['title' => 'دورة التعلم الإلكتروني الفعّال', 'url' => 'https://example.qa/external/mk-smp-1', 'hours' => 6, 'meta' => [], 'program_id' => $prog?->id, 'synced_at' => now()->subDay()]);
        if ($prog && $t && ($reg = $this->c->registration($t[0], $prog)) && ! ExternalCompletion::where('registration_id', $reg->id)->exists()) {
            ExternalCompletion::create(['registration_id' => $reg->id, 'evidence' => ['link' => 'https://example.qa/certificate/ext-1'], 'note' => 'أتممت الدورة الخارجية.', 'source' => 'maktabati', 'status' => 'pending']);
        }
        if (! PageVersion::where('page', 'home')->exists()) {
            PageVersion::create(['page' => 'home', 'version' => 1, 'blocks' => PageBlock::where('page', 'home')->get()->map->only(['type', 'config', 'sort_order', 'is_visible'])->all(), 'note' => 'نسخة منشورة أولى', 'published_by' => $admin->id]);
        }
    }

    private function access(): void
    {
        $t = $this->c->trainees();
        $prog = $this->c->program('TEST-P1');
        $admin = $this->c->admin();
        if ($prog && ! ResourceShare::query()->exists()) {
            $share = ['resource_type' => 'material', 'resource_id' => Material::where('program_id', $prog->id)->value('id') ?? (string) Str::uuid(), 'target_type' => 'program', 'target_id' => $prog->id];
            ResourceShare::firstOrCreate($share, ['permission' => 'view', 'shared_by' => $admin->id]);
        }
        if ($prog && $t && ! ProgramGrant::query()->exists()) {
            ProgramGrant::create(['program_id' => $prog->id, 'user_id' => $t[0]->id, 'ability' => 'attendance', 'granted_by' => $admin->id, 'expires_at' => now()->addMonth()]);
        }
        if (! \DB::table('entity_account_users')->exists() && ($e = EntityAccount::first()) && ($u = $this->c->user('school@tedc.qa'))) {
            \DB::table('entity_account_users')->insert(['entity_account_id' => $e->id, 'user_id' => $u->id, 'role' => 'buyer']);
        }
        if (! RegistrationPriorityRule::query()->exists()) {
            RegistrationPriorityRule::create(['scope' => 'global', 'criteria' => ['no_training_in_months', 'approved_individual_need', 'plan_targeted', 'registration_date'], 'weights' => ['no_training_in_months' => 40, 'approved_individual_need' => 30, 'plan_targeted' => 20, 'registration_date' => 10], 'is_active' => true]);
        }
    }

    private function delivery(): void
    {
        $t = $this->c->trainees();
        if (! $t) {
            return;
        }
        foreach ($t as $i => $u) {
            DeviceToken::firstOrCreate(['user_id' => $u->id, 'token' => 'sample-token-'.$i], ['platform' => $i % 2 ? 'ios' : 'android', 'locale' => 'ar', 'app_version' => '1.0.0', 'device_name' => 'جهاز تجريبي '.($i + 1), 'last_seen_at' => now()->subHours($i)]);
        }
        if (! NotificationDelivery::query()->exists()) {
            foreach ($t as $i => $u) {
                foreach ([['in_app', 'read'], ['push', 'delivered'], ['email', $i === 3 ? 'failed' : 'sent']] as [$ch, $st]) {
                    NotificationDelivery::create(['user_id' => $u->id, 'channel' => $ch, 'type' => 'registration.approved', 'status' => $st, 'to' => $ch === 'email' ? $u->email : null, 'attempts' => 1, 'sent_at' => now()->subHours(4), 'failed_reason' => $st === 'failed' ? 'صندوق المستلم ممتلئ' : null]);
                }
            }
        }
    }

    private function forecasts(): void
    {
        if (Forecast::query()->exists()) {
            return;
        }
        $year = (int) date('Y') + 1;
        foreach ([['competency', 'الطلب على التقويم التكويني', 380, 320, 440], ['competency', 'الطلب على القيادة التربوية', 210, 170, 260], ['job', 'ساعات التدريب الكلية', 1150, 980, 1320]] as $i => [$dim, $label, $v, $lo, $hi]) {
            Forecast::create(['dimension' => $dim, 'subject_id' => null, 'label' => $label, 'year' => $year, 'value' => $v, 'low' => $lo, 'high' => $hi, 'model' => 'linear-trend', 'explanation' => 'اتجاه صاعد خلال السنوات الثلاث الماضية مع توسّع الفئات المستهدفة.',
                'history' => ['years' => [$year - 3, $year - 2, $year - 1], 'values' => [round($v * 0.6), round($v * 0.75), round($v * 0.9)], 'signals' => []]]);
        }
    }

    private function rules(): void
    {
        if (! NeedsRule::query()->exists()) {
            NeedsRule::create(['name_ar' => 'ضعف التقويم → ورشة التقويم', 'name_en' => 'Weak assessment → assessment workshop', 'trigger' => 'low_appraisal', 'conditions' => ['rating_code' => ['NI']], 'action' => ['suggest_program' => 'ASS-101'], 'is_active' => true, 'sort_order' => 1]);
        }
    }
}
