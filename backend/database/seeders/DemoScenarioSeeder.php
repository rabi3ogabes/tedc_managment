<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\KitMember;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\SiteSetting;
use App\Models\TargetGroup;
use App\Models\Trainer;
use App\Models\TrainingKit;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CertificateService;
use App\Services\Notifications\ProgramSurvey;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use App\Services\TrainerCertificateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The presentation scenario: one training journey told with real data — an in-person, an online, a hybrid and a
 * finished program, plus a draft one in planning — with every kind of user taking part (administrators, trainer,
 * trainees, school administrator, QA and kit developers). Every session runs 08:00–13:00. Dates are relative to
 * today so the story is always current, and the notifications of each step are in the people's inboxes.
 *
 * Built through the platform's own services (registration, approval, attendance, survey, certificates), so what
 * the audience sees is exactly what really happens. Running it again resets the scenario programs (SC-1 … SC-5).
 */
class DemoScenarioSeeder extends Seeder
{
    public const CODES = ['SC-1', 'SC-2', 'SC-3', 'SC-4', 'SC-5'];

    public const ANCHOR_KEY = 'demo_scenario';

    private User $admin;

    private User $center;

    private RegistrationService $registrations;

    private NotificationService $notes;

    /** @var array<string, Employee> */
    private array $people = [];

    /** The build is split in phases so a slow remote database never hits the time limit of a single request. */
    public const PHASES = ['accounts', 'sc1', 'sc2', 'sc3', 'sc4', 'sc5'];

    /** @param  string|null  $phase  one of PHASES, or null for the whole scenario */
    public function run(?string $phase = null): void
    {
        $this->center = User::where('email', 'center@tedc.qa')->first() ?? User::first();
        $this->admin = User::where('email', 'admin@tedc.qa')->first() ?? $this->center;
        $jobTitleId = Employee::whereHas('user', fn ($q) => $q->where('email', 'teacher@tedc.qa'))->value('job_title_id');
        if (! $this->center || ! $jobTitleId) {
            return; // the demo organisation is not seeded
        }
        $this->registrations = app(RegistrationService::class);
        $this->notes = app(NotificationService::class);

        foreach (['teacher' => 'teacher@tedc.qa', 't1' => 'trainee1@tedc.qa', 't2' => 'trainee2@tedc.qa', 't3' => 'trainee3@tedc.qa', 't4' => 'trainee4@tedc.qa'] as $key => $email) {
            $employee = Employee::whereHas('user', fn ($q) => $q->where('email', $email))->first();
            $employee && $this->people[$key] = $employee;
        }
        if (count($this->people) < 5) {
            return; // run DemoTestAccountsSeeder first
        }

        $phases = $phase ? [$phase] : array_values(array_diff(self::PHASES, ['accounts']));
        $rooms = $this->rooms();
        $trainers = $this->trainers();

        foreach ($phases as $current) {
            match ($current) {
                'sc1' => $this->first($rooms, $trainers, $jobTitleId),
                'sc2' => $this->online($trainers[1], $jobTitleId),
                'sc3' => $this->hybrid($rooms[1], $trainers[2], $jobTitleId),
                'sc4' => $this->finished($rooms[1], $trainers[0], $jobTitleId),
                'sc5' => $this->last($jobTitleId),
                default => null,
            };
        }
    }

    private function first(array $rooms, array $trainers, string $job): void
    {
        $this->reset();
        $this->normaliseTimes();
        $this->inPerson($rooms[0], $trainers[0], $job);
    }

    private function last(string $job): void
    {
        $this->draft($job);
        $this->kits($this->kitPeople(), ['SC-1', 'SC-2', 'SC-3']);
        $this->invitations(Program::where('code', 'SC-3')->first());
        SiteSetting::updateOrCreate(['key' => self::ANCHOR_KEY], ['value' => ['anchor' => today()->toDateString()]]);
    }

    // The five programs --------------------------------------------------------------------------------------------

    /** SC-1: in person at the center, running now (two sessions done, one today, one coming). */
    private function inPerson(TrainingRoom $room, Trainer $trainer, string $job): Program
    {
        $p = $this->program('SC-1', 'in_person', Program::STATUS_IN_PROGRESS, 'برنامج الإدارة الصفية الفعّالة', 'Effective Classroom Management', 'حضوري في المركز: استراتيجيات إدارة الصف وبناء بيئة تعلم إيجابية.', 4, $job, -5, 2);
        $sessions = $this->sessions($p, $trainer, $room, [[-4, 'completed', 'in_person'], [-2, 'completed', 'in_person'], [0, 'scheduled', 'in_person'], [2, 'scheduled', 'in_person']], 'إدارة الصف');
        $this->assigned($trainer, $p, -6);

        $t = $this->people;
        $this->at(-6, 9, fn () => $this->assign($p, $t['t1']));
        $this->at(-6, 11, fn () => $this->selfRegister($p, $t['teacher']));
        $this->at(-5, 9, fn () => $this->decide($p, $t['teacher'], Registration::STATUS_APPROVED));
        $this->at(-6, 13, fn () => $this->selfRegister($p, $t['t2']));
        $this->at(-5, 10, fn () => $this->decide($p, $t['t2'], Registration::STATUS_APPROVED));
        $this->at(-1, 15, fn () => $this->selfRegister($p, $t['t3']));      // waits for the administrator
        $this->at(-1, 16, fn () => $this->selfRegister($p, $t['t4']));      // the seats are full: waiting list

        $this->attend($sessions[0], $t['t1'], 'present');
        $this->attend($sessions[1], $t['t1'], 'present');
        $this->attend($sessions[2], $t['t1'], 'present');
        $this->attend($sessions[0], $t['teacher'], 'present');
        $this->attend($sessions[1], $t['teacher'], 'late');
        $this->attend($sessions[0], $t['t2'], 'absent');
        $this->attend($sessions[1], $t['t2'], 'present');

        $this->reminders($p, $sessions[2], [$t['t1'], $t['teacher'], $t['t2']], $trainer);

        return $p;
    }

    /** SC-2: fully online (live sessions on Teams + a short course), registration open. */
    private function online(Trainer $trainer, string $job): Program
    {
        $p = $this->program('SC-2', 'online', Program::STATUS_REGISTRATION_OPEN, 'برنامج التحول الرقمي في التدريس', 'Digital Transformation in Teaching', 'عن بُعد عبر Microsoft Teams مع محتوى إلكتروني للدراسة الذاتية.', 20, $job, 1, 4, [
            'instructions_ar' => 'ادخل قبل الموعد بخمس دقائق من التطبيق ← جلستي ← انضمام. الكاميرا اختيارية والميكروفون مغلق عند الدخول.',
            'instructions_en' => 'Join five minutes early from the app → my session → join. Camera is optional; the microphone starts muted.',
        ]);
        $this->sessions($p, $trainer, null, [[1, 'scheduled', 'online'], [3, 'scheduled', 'online'], [5, 'scheduled', 'online'], [7, 'scheduled', 'online']], 'التحول الرقمي');
        $this->course($p);
        $this->assigned($trainer, $p, -3);

        $t = $this->people;
        $this->at(-2, 10, fn () => $this->assign($p, $t['t1']));
        $this->at(-2, 12, fn () => $this->selfRegister($p, $t['t2']));      // pending
        $this->at(-1, 9, fn () => $this->selfRegister($p, $t['teacher']));
        $this->at(-1, 11, fn () => $this->decide($p, $t['teacher'], Registration::STATUS_REJECTED, 'لا تنطبق عليه الفئة المستهدفة لهذا البرنامج'));

        return $p;
    }

    /** SC-3: hybrid — a day in the room, a day online. */
    private function hybrid(TrainingRoom $room, Trainer $trainer, string $job): Program
    {
        $p = $this->program('SC-3', 'hybrid', Program::STATUS_REGISTRATION_OPEN, 'برنامج القيادة التربوية الحديثة', 'Modern Educational Leadership', 'مدمج: لقاءات حضورية في المركز وجلسات عن بُعد.', 25, $job, 3, 10);
        $this->sessions($p, $trainer, $room, [[3, 'scheduled', 'in_person'], [5, 'scheduled', 'online'], [8, 'scheduled', 'in_person'], [10, 'scheduled', 'online']], 'القيادة التربوية');
        $this->assigned($trainer, $p, -2);

        $t = $this->people;
        $this->at(-1, 9, fn () => $this->assign($p, $t['t3']));
        $this->at(-1, 10, fn () => $this->selfRegister($p, $t['t4']));
        $this->at(-1, 11, fn () => $this->selfRegister($p, $t['teacher']));
        $this->at(0, 7, fn () => $this->decide($p, $t['teacher'], Registration::STATUS_APPROVED));

        return $p;
    }

    /** SC-4: finished — attendance, survey and certificates (one downloadable, one waiting for the survey, one blocked). */
    private function finished(TrainingRoom $room, Trainer $trainer, string $job): Program
    {
        $p = $this->program('SC-4', 'in_person', Program::STATUS_COMPLETED, 'برنامج التقويم التكويني', 'Formative Assessment', 'برنامج منتهٍ: حضور واستبيان وشهادات.', 10, $job, -14, -10);
        $sessions = $this->sessions($p, $trainer, $room, [[-14, 'completed', 'in_person'], [-12, 'completed', 'in_person'], [-10, 'completed', 'in_person']], 'التقويم التكويني');
        $p->update(['requires_evaluation' => false, 'survey_mode' => 'manual']);
        $this->assigned($trainer, $p, -20);

        $t = $this->people;
        foreach (['t1', 't2', 'teacher'] as $k) {
            $this->at(-18, 10, fn () => $this->assign($p, $t[$k]));
        }
        foreach ($sessions as $s) {
            $this->attend($s, $t['t1'], 'present');
            $this->attend($s, $t['t2'], 'present');
        }
        $this->attend($sessions[0], $t['teacher'], 'present');
        $this->attend($sessions[1], $t['teacher'], 'absent');
        $this->attend($sessions[2], $t['teacher'], 'absent');

        // The survey opens after the last session; only the first trainee answered.
        $this->at(-9, 8, fn () => app(ProgramSurvey::class)->open($p->refresh(), $this->center));
        $this->at(-9, 14, function () use ($p, $t) {
            $r = Registration::where('program_id', $p->id)->where('employee_id', $t['t1']->id)->first();
            Evaluation::updateOrCreate(['registration_id' => $r->id], [
                'program_id' => $p->id, 'employee_id' => $t['t1']->id, 'ratings' => ['content' => 5, 'trainer' => 5, 'organization' => 4, 'relevance' => 5],
                'satisfaction_score' => 95, 'post_test_score' => 92, 'pre_test_score' => 61, 'comments' => 'برنامج ممتاز وتطبيقي، أفدت منه مباشرة في صفي.', 'allow_testimonial' => true, 'submitted_at' => now(),
            ]);
        });

        $certificates = app(CertificateService::class);
        $this->at(-8, 10, function () use ($p, $t, $certificates) {
            foreach (['t1', 't2'] as $k) {
                $r = Registration::where('program_id', $p->id)->where('employee_id', $t[$k]->id)->first();
                try {
                    $this->registrations->transition($r, Registration::STATUS_COMPLETED, $this->center);
                    $certificates->issue($r->refresh(), $this->center);
                } catch (Throwable $e) {
                    report($e);
                }
            }
            $r = Registration::where('program_id', $p->id)->where('employee_id', $t['t1']->id)->first();
            $r?->certificate && $certificates->announce($r->certificate);
            $certificates->refreshStatus(Registration::where('program_id', $p->id)->where('employee_id', $t['teacher']->id)->first());
        });

        // The trainer delivered every session: her thank-you certificate is ready too.
        $this->at(-9, 16, function () use ($p, $trainer) {
            try {
                app(TrainerCertificateService::class)->issueIfComplete($trainer, $p->refresh());
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $p;
    }

    /** SC-5: still being planned. */
    private function draft(string $job): Program
    {
        $p = $this->program('SC-5', 'hybrid', Program::STATUS_DRAFT, 'برنامج مدرسة المستقبل', 'School of the Future', 'برنامج قيد التخطيط: يظهر في المسودات حتى يُعتمد ويُفتح للتسجيل.', 30, $job, 30, 34);
        $this->sessions($p, $this->trainers()[2], $this->rooms()[1], [[30, 'scheduled', 'in_person'], [32, 'scheduled', 'online'], [34, 'scheduled', 'in_person']], 'مدرسة المستقبل');

        return $p;
    }

    // Building blocks ----------------------------------------------------------------------------------------------

    private function program(string $code, string $mode, string $status, string $ar, string $en, string $summary, int $capacity, string $job, int $startOffset, int $endOffset, ?array $remote = null): Program
    {
        $start = today()->addDays($startOffset);
        $end = today()->addDays($endOffset);
        $program = Program::updateOrCreate(['code' => $code], [
            'category_id' => ProgramCategory::query()->value('id'), 'title_ar' => $ar, 'title_en' => $en, 'summary_ar' => $summary, 'summary_en' => $en,
            'description_ar' => $summary.' كل الجلسات من الثامنة صباحاً حتى الواحدة ظهراً.', 'description_en' => "{$en}. Every session runs from 8 am to 1 pm.",
            'objectives' => ['تطبيق المهارات في الصف', 'قياس أثر التدريب على تعلم الطلاب'], 'delivery_mode' => $mode, 'level' => 'intermediate', 'total_hours' => 5 * max(1, $this->count($code)), 'capacity' => $capacity,
            'min_attendance_percent' => 80, 'requires_tasks' => false, 'requires_evaluation' => true, 'start_date' => $start, 'end_date' => $end,
            'registration_opens_at' => $start->copy()->subDays(30), 'registration_closes_at' => $end->copy()->endOfDay(), 'registration_modes' => Program::MODES,
            'status' => $status, 'is_featured' => $code === 'SC-1', 'remote' => $remote, 'coordinator_id' => User::where('email', 'coordinator@tedc.qa')->value('id') ?? $this->center->id, 'survey_mode' => 'auto', 'survey_auto_hours' => 24,
        ]);
        TargetGroup::firstOrCreate(['program_id' => $program->id, 'description' => 'معلمو المرحلة الابتدائية'], ['education_stage' => 'primary']);

        return $program;
    }

    private function count(string $code): int
    {
        return ['SC-1' => 4, 'SC-2' => 4, 'SC-3' => 4, 'SC-4' => 3, 'SC-5' => 3][$code] ?? 3;
    }

    /** @param  list<array{0: int, 1: string, 2: string}>  $plan  [day offset, status, mode] @return list<ProgramSession> */
    private function sessions(Program $program, Trainer $trainer, ?TrainingRoom $room, array $plan, string $title): array
    {
        $out = [];
        foreach ($plan as $i => [$offset, $status, $mode]) {
            $day = today()->addDays($offset);
            $online = $mode === 'online';
            $out[] = ProgramSession::create([
                'program_id' => $program->id, 'trainer_id' => $trainer->id, 'training_room_id' => $online ? null : $room?->id, 'sequence' => $i + 1,
                'title_ar' => "{$title} — اللقاء ".($i + 1), 'title_en' => "{$program->title_en} — session ".($i + 1),
                'starts_at' => $day->copy()->setTime(8, 0), 'ends_at' => $day->copy()->setTime(13, 0), 'status' => $status, 'mode' => $mode,
                'location_text' => $online ? null : $room?->name_ar, 'online_platform' => $online ? 'teams' : null,
                'online_url' => $online ? 'https://teams.microsoft.com/l/meetup-join/tedc-'.Str::lower($program->code).'-'.($i + 1) : null, 'online_passcode' => $online ? '246810' : null,
                'activities' => ['عرض تقديمي', 'ورشة تطبيقية', 'نقاش ومشاركة'],
            ]);
        }

        return $out;
    }

    private function course(Program $program): void
    {
        $program->update(['has_course' => true, 'course_sequential' => true, 'course_completion_percent' => 100, 'course_auto_certificate' => false]);
        $module = $program->courseModules()->create(['title_ar' => 'المحتوى الإلكتروني', 'title_en' => 'Online content', 'sort_order' => 1]);
        $module->lessons()->create(['program_id' => $program->id, 'type' => 'article', 'title_ar' => 'مدخل إلى التحول الرقمي', 'title_en' => 'Introduction', 'sort_order' => 1, 'status' => 'published', 'is_required' => true, 'duration_seconds' => 0, 'slide_count' => 0,
            'body_ar' => "## ما التحول الرقمي؟\n\nتوظيف الأدوات الرقمية لتحسين التعليم والتعلم.\n\n- أدوات تفاعلية\n- تقييم فوري\n- تعلم مرن", 'settings' => []]);
        $module->lessons()->create(['program_id' => $program->id, 'type' => 'video', 'title_ar' => 'فيديو تعريفي (15 ثانية)', 'title_en' => 'Intro video', 'sort_order' => 2, 'status' => 'published', 'is_required' => true, 'duration_seconds' => 15, 'slide_count' => 0,
            'source' => 'url', 'external_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 'settings' => ['allow_seeking' => true, 'min_watch_percent' => 80, 'max_speed' => 2, 'pause_when_hidden' => true]]);
        $quiz = $module->lessons()->create(['program_id' => $program->id, 'type' => 'quiz', 'title_ar' => 'اختبار قصير', 'title_en' => 'Short quiz', 'sort_order' => 3, 'status' => 'published', 'is_required' => true, 'duration_seconds' => 0, 'slide_count' => 0,
            'settings' => ['pass_percent' => 60, 'shuffle_questions' => false, 'shuffle_options' => false, 'show_answers' => 'after_submit']]);
        $quiz->questions()->create(['type' => 'true_false', 'text_ar' => 'التحول الرقمي يقتصر على شراء الأجهزة.', 'text_en' => 'Digital transformation is only about buying devices.', 'points' => 1, 'sort_order' => 1,
            'options' => [['id' => 'true', 'text_ar' => 'صح', 'text_en' => 'True', 'correct' => false], ['id' => 'false', 'text_ar' => 'خطأ', 'text_en' => 'False', 'correct' => true]]]);
    }

    // People and rooms ---------------------------------------------------------------------------------------------

    /** @return list<TrainingRoom> */
    private function rooms(): array
    {
        $make = fn (string $code, string $ar, string $en, int $cap, float $lat, float $lng) => tap(TrainingRoom::updateOrCreate(['code' => $code], [
            'name_ar' => $ar, 'name_en' => $en, 'capacity' => $cap, 'building' => 'مبنى المركز', 'floor' => '1', 'latitude' => $lat, 'longitude' => $lng, 'is_accessible' => true,
            'facilities' => ['projector', 'whiteboard', 'wifi'], 'status' => 'active',
        ]), fn (TrainingRoom $r) => $r->display_token ?: $r->forceFill(['display_token' => Str::random(40)])->save());

        return [$make('SC-ROOM-1', 'قاعة الابتكار', 'Innovation Hall', 30, 25.3180, 51.4390), $make('SC-ROOM-2', 'قاعة الإبداع', 'Creativity Hall', 25, 25.3182, 51.4393)];
    }

    /** @return list<Trainer> */
    private function trainers(): array
    {
        $lead = Trainer::where('name_en', 'Dr. Noora Al-Mohannadi')->first() ?? Trainer::query()->first();
        $one = Trainer::where('email', 'trainer1@tedc.qa')->first() ?? $lead;
        $two = Trainer::where('email', 'trainer2@tedc.qa')->first() ?? $lead;

        return [$lead, $one, $two];
    }

    /** @return array{dev: ?User, qa: ?User} */
    private function kitPeople(): array
    {
        return ['dev' => User::where('email', 'kits@tedc.qa')->first(), 'qa' => User::where('email', 'qa@tedc.qa')->first()];
    }

    private function kits(array $people, array $codes): void
    {
        $programs = array_map(fn (string $code) => Program::where('code', $code)->first(), $codes);
        $owner = $people['dev'] ?? $this->center;
        $rows = [
            [$programs[0], 'KIT-SC-1', 'in_person', TrainingKit::PUBLISHED, -8],
            [$programs[1], 'KIT-SC-2', 'online', TrainingKit::IN_REVIEW, -2],
            [$programs[2], 'KIT-SC-3', 'hybrid', TrainingKit::IN_DEVELOPMENT, -1],
        ];
        foreach ($rows as [$program, $code, $delivery, $status, $when]) {
            $kit = TrainingKit::updateOrCreate(['code' => $code], [
                'title_ar' => 'حقيبة '.$program->title_ar, 'title_en' => 'Kit — '.$program->title_en, 'program_id' => $program->id, 'category_id' => $program->category_id, 'delivery' => $delivery,
                'status' => $status, 'audience' => 'معلمو المرحلة الابتدائية', 'duration_hours' => $program->total_hours, 'objectives' => $program->objectives, 'owner_id' => $owner->id, 'created_by' => $this->center->id,
                'due_at' => today()->addDays(7), 'description_ar' => 'عرض تقديمي ودليل المدرب ودليل المتدرب وأدوات التقويم.',
                'submitted_at' => $status !== TrainingKit::IN_DEVELOPMENT ? now()->addDays($when) : null,
                'approved_at' => $status === TrainingKit::PUBLISHED ? now()->addDays($when) : null, 'published_at' => $status === TrainingKit::PUBLISHED ? now()->addDays($when) : null,
            ]);
            if ($people['qa']) {
                KitMember::updateOrCreate(['kit_id' => $kit->id, 'user_id' => $people['qa']->id], ['role' => KitMember::QA]);
                $this->at($when - 3, 9, fn () => $this->notes->send($people['qa'], 'kit.assigned', ['ar' => 'أُضفت إلى فريق حقيبة تدريبية', 'en' => 'You were added to a training kit team'],
                    ['ar' => "«{$kit->title_ar}» — بواسطة {$this->center->displayName('ar')}", 'en' => "\"{$kit->title_en}\""], ['kit_id' => $kit->id, 'program_id' => $program->id]));
            }
            if ($status === TrainingKit::IN_REVIEW && $people['qa']) {
                $this->at($when, 11, fn () => $this->notes->send($people['qa'], 'kit.review', ['ar' => 'حقيبة بانتظار مراجعتك', 'en' => 'A kit awaits your review'],
                    ['ar' => "«{$kit->title_ar}» أُرسلت للمراجعة.", 'en' => "\"{$kit->title_en}\" was submitted for review."], ['kit_id' => $kit->id, 'program_id' => $program->id]));
            }
        }
    }

    // Steps of the story -------------------------------------------------------------------------------------------

    private function assign(Program $program, Employee $employee): void
    {
        $this->registrations->register($program, $employee, Registration::SOURCE_CENTER, $this->center, null, true);
    }

    private function selfRegister(Program $program, Employee $employee): void
    {
        $this->registrations->register($program, $employee, Registration::SOURCE_SELF, $employee->user, null, true);
    }

    private function decide(Program $program, Employee $employee, string $to, ?string $note = null): void
    {
        $r = Registration::where('program_id', $program->id)->where('employee_id', $employee->id)->first();
        $r && $this->registrations->transition($r, $to, $this->center, $note, 'demo scenario');
    }

    private function attend(ProgramSession $session, Employee $employee, string $status): void
    {
        $r = Registration::where('program_id', $session->program_id)->where('employee_id', $employee->id)->first();
        if (! $r || ! in_array($r->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true)) {
            return;
        }
        $this->stamp($session->starts_at->copy()->addMinutes(5), fn () => app(AttendanceService::class)->mark($session, $r, $status, $this->center));
    }

    private function assigned(Trainer $trainer, Program $program, int $dayOffset): void
    {
        $program->trainers()->syncWithoutDetaching([$trainer->id => ['role' => 'lead']]);
        if ($trainer->user_id) {
            $this->at($dayOffset, 9, fn () => $this->notes->send($trainer->user_id, 'trainer.assigned', ['ar' => 'أُسند إليك برنامج تدريبي', 'en' => 'A program was assigned to you'],
                ['ar' => "أنت مدرب برنامج «{$program->title_ar}» — يبدأ {$program->start_date->format('Y-m-d')}. راجع جلساتك وقاعتك في تطبيقك.", 'en' => "You are the trainer of \"{$program->title_en}\"."], ['program_id' => $program->id, 'route' => '/training']));
        }
    }

    /** Today's reminder and the check-in notification, for the trainees and the trainer. @param  list<Employee>  $trainees */
    private function reminders(Program $program, ProgramSession $session, array $trainees, Trainer $trainer): void
    {
        $users = collect($trainees)->map->user_id->filter()->when($trainer->user_id, fn ($c) => $c->push($trainer->user_id))->unique();
        $data = ['session_id' => $session->id, 'program_id' => $program->id];
        $this->at(-1, 18, fn () => $this->notes->broadcast($users, 'session.reminder', ['ar' => 'تذكير بجلسة تدريبية', 'en' => 'Training session reminder'],
            ['ar' => "جلسة «{$session->title_ar}» من برنامج «{$program->title_ar}» تبدأ غداً الساعة 8:00 صباحاً في {$session->location_text}.", 'en' => 'Your session starts tomorrow at 8:00 am.'], $data));
        $this->at(0, 7, fn () => $this->notes->broadcast($users, 'session.attendance_open', ['ar' => 'بدأ تسجيل الحضور', 'en' => 'Check-in is open'],
            ['ar' => "افتح التطبيق وامسح رمز الحضور لجلسة «{$session->title_ar}». يتطلب التسجيل وجودك في مكان التدريب.", 'en' => 'Open the app and scan the code.'], $data));
    }

    /** The school principal, the executive and the kit developer hear about the journey too. */
    private function invitations(Program $hybrid): void
    {
        $data = ['program_id' => $hybrid->id, 'program_code' => $hybrid->code];
        foreach (['school@tedc.qa', 'executive@tedc.qa'] as $email) {
            $user = User::where('email', $email)->first();
            $user && $this->at(-3, 10, fn () => $this->notes->send($user, 'program.invite', ['ar' => 'برنامج تدريبي جديد يناسبك', 'en' => 'A new training program for you'],
                ['ar' => "«{$hybrid->title_ar}» متاح للتسجيل — مدمج: لقاءات حضورية وأخرى عن بُعد من 8 صباحاً إلى 1 ظهراً.", 'en' => "\"{$hybrid->title_en}\" is open for registration."], $data));
        }
        $dev = User::where('email', 'kits@tedc.qa')->first();
        $kit = TrainingKit::where('code', 'KIT-SC-2')->first();
        if ($dev && $kit) {
            $this->at(-1, 13, fn () => $this->notes->send($dev, 'kit.comment', ['ar' => 'ملاحظة جديدة على حقيبتك', 'en' => 'New comment on your kit'],
                ['ar' => "مراجع الجودة علّق على «{$kit->title_ar}»: أضف اختباراً قصيراً بعد كل فيديو.", 'en' => 'The QA reviewer commented on your kit.'], ['kit_id' => $kit->id, 'program_id' => $kit->program_id]));
        }
    }

    /**
     * Keeps the story current: every day the scenario moves forward with the calendar (sessions, program and kit dates and
     * the notification dates), so "today's session" is always today. Nothing is deleted and nobody's progress is lost.
     */
    public static function advance(): int
    {
        $anchor = SiteSetting::find(self::ANCHOR_KEY)?->value['anchor'] ?? null;
        $delta = $anchor ? (int) Carbon::parse($anchor)->startOfDay()->diffInDays(today(), false) : 0;
        if ($delta <= 0) {
            return 0;
        }
        $programs = Program::whereIn('code', self::CODES)->get();
        foreach ($programs as $program) {
            $program->forceFill(array_filter([
                'start_date' => $program->start_date?->copy()->addDays($delta), 'end_date' => $program->end_date?->copy()->addDays($delta),
                'registration_opens_at' => $program->registration_opens_at?->copy()->addDays($delta), 'registration_closes_at' => $program->registration_closes_at?->copy()->addDays($delta),
            ]))->saveQuietly();
            $program->sessions->each(fn (ProgramSession $s) => $s->forceFill(['starts_at' => $s->starts_at->copy()->addDays($delta), 'ends_at' => $s->ends_at->copy()->addDays($delta)])->saveQuietly());
        }
        $kits = TrainingKit::whereIn('code', ['KIT-SC-1', 'KIT-SC-2', 'KIT-SC-3'])->get();
        $kits->each(fn (TrainingKit $k) => $k->forceFill(['due_at' => $k->due_at?->copy()->addDays($delta)])->saveQuietly());

        $notifications = AppNotification::query()->where(fn ($q) => $q->whereIn('data->program_id', $programs->pluck('id'))->orWhereIn('data->kit_id', $kits->pluck('id')))->get();
        $notifications->each(function (AppNotification $n) use ($delta) {
            $at = min(now(), $n->created_at->copy()->addDays($delta));
            DB::table('notifications')->where('id', $n->id)->update(['created_at' => $at, 'updated_at' => $at]);
        });
        SiteSetting::where('key', self::ANCHOR_KEY)->update(['value' => ['anchor' => today()->toDateString()]]);

        return $delta;
    }

    // Plumbing -----------------------------------------------------------------------------------------------------

    /** Runs a step and dates the notifications it produced, so the inboxes read like a real week. */
    private function at(int $dayOffset, int $hour, callable $step): void
    {
        $this->stamp(today()->addDays($dayOffset)->setTime($hour, 0), $step);
    }

    private function stamp(Carbon $when, callable $step): void
    {
        $before = now();
        $step();
        $when = $when->isFuture() ? now() : $when;
        AppNotification::where('created_at', '>=', $before->copy()->subSecond())->orderBy('created_at')->get()->each(function (AppNotification $n, int $i) use ($when) {
            $stamp = $when->copy()->addSeconds($i * 20);
            DB::table('notifications')->where('id', $n->id)->update(['created_at' => $stamp, 'updated_at' => $stamp]);
        });
    }

    /** Removes the scenario programs (and what hangs off them) so it can be rebuilt relative to today. */
    private function reset(): void
    {
        $ids = Program::withTrashed()->whereIn('code', self::CODES)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        $regs = Registration::whereIn('program_id', $ids)->pluck('id');
        Certificate::whereIn('registration_id', $regs)->delete();
        Evaluation::whereIn('registration_id', $regs)->delete();
        foreach ($ids as $id) {
            DB::table('notifications')->where('data->program_id', $id)->delete();
        }
        TrainingKit::whereIn('code', ['KIT-SC-1', 'KIT-SC-2', 'KIT-SC-3'])->get()->each(function (TrainingKit $kit) {
            DB::table('notifications')->where('data->kit_id', $kit->id)->delete();
            $kit->delete();
        });
        Program::withTrashed()->whereIn('id', $ids)->get()->each->forceDelete();
    }

    /** Every program day is 08:00–13:00: the demo programs that were created with other times are aligned too. */
    private function normaliseTimes(): void
    {
        ProgramSession::query()->whereHas('program', fn ($q) => $q->where('code', 'not like', 'TEST-%'))->get()->each(function (ProgramSession $s) {
            $day = $s->starts_at->copy()->startOfDay();
            if ($s->starts_at->format('H:i') !== '08:00' || $s->ends_at->format('H:i') !== '13:00' || ! $s->ends_at->isSameDay($s->starts_at)) {
                $s->forceFill(['starts_at' => $day->copy()->setTime(8, 0), 'ends_at' => $day->copy()->setTime(13, 0)])->saveQuietly();
            }
        });
    }
}
