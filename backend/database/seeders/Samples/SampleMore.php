<?php

namespace Database\Seeders\Samples;

use App\Models\Assessment;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\CourseLessonVersion;
use App\Models\DeviceToken;
use App\Models\EntityAccount;
use App\Models\ExternalCompletion;
use App\Models\ExternalCourse;
use App\Models\Forecast;
use App\Models\LearnerMastery;
use App\Models\Material;
use App\Models\NeedsRule;
use App\Models\NotificationDelivery;
use App\Models\PageBlock;
use App\Models\PageVersion;
use App\Models\ProgramGrant;
use App\Models\Question;
use App\Models\Registration;
use App\Models\RegistrationPriorityRule;
use App\Models\ResourceShare;
use App\Models\Skill;
use App\Services\Assessment\AttemptService;
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
            RegistrationPriorityRule::create(['scope' => 'global', 'criteria' => [['key' => 'years_since_last_training', 'weight' => 40], ['key' => 'gap_priority', 'weight' => 60]], 'weights' => [], 'is_active' => true]);
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
