<?php

namespace App\Services\Admin;

use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Supabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Showcase accounts: one ready account for each kind of user (system administrator, head of the training centre, head of training,
 * head of planning, two supervisors, two trainers, two trainees) with a small training story around them, so the platform can be
 * shown on the live system without touching anyone real. Everything is marked — e-mails on SHOWCASE_DOMAIN, programmes and the
 * school coded TEST-SHOWCASE-* (TEST- codes never reach the public catalogue) — so remove() takes away exactly what build() made.
 * Passwords are random and never shown; the system administrator opens an account from Settings, or sets an app password for one.
 */
class ShowcaseAccounts
{
    public const DOMAIN = 'demo.shaheen.test';

    public const PREFIX = 'TEST-SHOWCASE';

    public const SCHOOL = self::PREFIX.'-SCHOOL';

    /** key => [group, roles, name_ar, name_en, has an employee record]. Order is the order on the page. */
    public const ACCOUNTS = [
        'admin' => ['leadership', [Role::SUPER_ADMIN], 'مدير النظام (تجريبي)', 'System administrator (demo)', false],
        'centre-head' => ['leadership', [Role::CENTER_ADMIN], 'رئيس مركز التدريب (تجريبي)', 'Head of the training centre (demo)', false],
        'training-head' => ['leadership', [Role::TRAINING_HEAD], 'رئيس قسم التدريب (تجريبي)', 'Head of training (demo)', false],
        'planning-head' => ['leadership', [Role::PLANNING_HEAD], 'رئيس قسم التخطيط (تجريبي)', 'Head of planning (demo)', false],
        'supervisor-1' => ['supervision', [Role::SUPERVISOR, Role::EMPLOYEE], 'المشرف التجريبي الأول', 'Demo supervisor 1', true],
        'supervisor-2' => ['supervision', [Role::SUPERVISOR, Role::EMPLOYEE], 'المشرفة التجريبية الثانية', 'Demo supervisor 2', true],
        'trainer-1' => ['training', [Role::TRAINER, Role::EMPLOYEE], 'المدرّب التجريبي الأول', 'Demo trainer 1', true],
        'trainer-2' => ['training', [Role::TRAINER, Role::EMPLOYEE], 'المدرّبة التجريبية الثانية', 'Demo trainer 2', true],
        'trainee-1' => ['learners', [Role::EMPLOYEE], 'المتدرّب التجريبي الأول', 'Demo trainee 1', true],
        'trainee-2' => ['learners', [Role::EMPLOYEE], 'المتدرّبة التجريبية الثانية', 'Demo trainee 2', true],
    ];

    /** Who each person reports to (their supervisor's key). */
    private const SUPERVISED_BY = ['trainee-1' => 'supervisor-1', 'trainer-1' => 'supervisor-1', 'trainee-2' => 'supervisor-2', 'trainer-2' => 'supervisor-2'];

    public static function email(string $key): string
    {
        return "{$key}@".self::DOMAIN;
    }

    /** @return list<string> */
    public static function emails(): array
    {
        return array_map(fn ($k) => self::email($k), array_keys(self::ACCOUNTS));
    }

    public static function isShowcase(User $user): bool
    {
        return in_array(strtolower($user->email), self::emails(), true);
    }

    public function exists(): bool
    {
        return User::whereIn('email', self::emails())->exists();
    }

    /** Creates the accounts and their training story, or brings them back to the starting point (dates move with today). */
    public function build(User $by): void
    {
        DB::transaction(function () use ($by) {
            $school = School::updateOrCreate(['code' => self::SCHOOL], [
                'name_ar' => 'مدرسة العرض التجريبية', 'name_en' => 'Showcase demo school', 'type' => 'government', 'stage' => 'primary',
                'region' => 'الدوحة', 'gender' => 'mixed', 'status' => 'inactive', 'source' => 'showcase',
            ]);
            $jobTitle = JobTitle::where('code', 'TEACHER')->value('id');

            $users = [];
            $employees = [];
            foreach (self::ACCOUNTS as $key => [, $roles, $ar, $en, $employee]) {
                $user = User::firstOrNew(['email' => self::email($key)]);
                if (! $user->exists) {
                    $user->forceFill(['password' => Str::password(32), 'password_changed_at' => now()]);
                }
                $user->forceFill(['name' => $en, 'name_ar' => $ar, 'locale' => 'ar', 'status' => 'active'])->save();
                // A supervisor sees the showcase school only; the leadership roles work Ministry-wide as their real holders do.
                $scope = in_array(Role::SUPERVISOR, $roles, true) ? ['scope_type' => 'school', 'scope_id' => $school->id] : ['scope_type' => 'ministry', 'scope_id' => null];
                $user->roles()->sync(Role::whereIn('slug', $roles)->pluck('id')->mapWithKeys(fn ($id) => [$id => $scope])->all());
                $users[$key] = $user;
                if ($employee) {
                    $employees[$key] = Employee::updateOrCreate(['user_id' => $user->id], [
                        'employee_no' => self::PREFIX.'-'.strtoupper($key), 'school_id' => $school->id, 'job_title_id' => $jobTitle,
                        'gender' => str_ends_with($key, '2') ? 'female' : 'male', 'nationality' => 'Qatar', 'status' => 'active',
                        'education_stage' => 'primary', 'qualification' => 'bachelor', 'specialization' => 'العلوم', 'experience_years' => 7,
                    ]);
                }
            }
            foreach (self::SUPERVISED_BY as $key => $supervisor) {
                $employees[$key]->update(['supervisor_id' => $employees[$supervisor]->id]);
            }

            $trainers = [];
            foreach (['trainer-1', 'trainer-2'] as $key) {
                $trainers[$key] = Trainer::updateOrCreate(['user_id' => $users[$key]->id], [
                    'name_ar' => $users[$key]->name_ar, 'name_en' => $users[$key]->name, 'title_ar' => 'مدرّب معتمد', 'title_en' => 'Certified trainer',
                    'email' => $users[$key]->email, 'is_external' => false, 'status' => 'active',
                ]);
            }

            $this->story($users, $employees, $trainers);
            $this->audit($by, 'showcase_accounts_built', ['accounts' => count($users)]);
        });
    }

    /** Removes the accounts and everything made for them: programmes, sessions, registrations, attendance, evaluations, notifications. */
    public function remove(User $by): array
    {
        $users = User::whereIn('email', self::emails())->get();
        $authIds = $users->pluck('auth_id')->filter()->values();

        $programs = DB::transaction(function () use ($users, $by) {
            $programs = Program::where('code', 'like', self::PREFIX.'-%')->get();
            foreach ($programs as $program) {
                $registrations = Registration::where('program_id', $program->id)->pluck('id');
                Evaluation::whereIn('registration_id', $registrations)->delete();
                Attendance::whereIn('registration_id', $registrations)->delete();
                Registration::whereIn('id', $registrations)->delete();
                ProgramSession::where('program_id', $program->id)->delete();
                $program->trainers()->detach();
                $program->forceDelete();
            }
            $ids = $users->pluck('id');
            AppNotification::whereIn('user_id', $ids)->delete();
            // What someone made while signed in as a showcase account (a kit, a comment) is showcase content too.
            DB::table('kit_comments')->whereIn('author_id', $ids)->delete();
            DB::table('training_kits')->whereIn('owner_id', $ids)->delete();
            Trainer::whereIn('user_id', $ids)->delete();
            Employee::whereIn('user_id', $ids)->update(['supervisor_id' => null]);
            Employee::whereIn('user_id', $ids)->forceDelete();
            foreach ($users as $user) {
                $user->roles()->detach();
                $user->forceDelete();
            }
            School::where('code', self::SCHOOL)->delete();
            $this->audit($by, 'showcase_accounts_removed', ['accounts' => $users->count(), 'programs' => $programs->count()]);

            return $programs->count();
        });

        // Their sign-ins in Supabase Auth go too (best effort: a failure leaves an account that matches nobody here).
        if (config('tedc.auth.driver') === 'supabase') {
            foreach ($authIds as $authId) {
                try {
                    Supabase::admin()->delete(Supabase::url("/auth/v1/admin/users/{$authId}"));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return ['accounts' => $users->count(), 'programs' => $programs];
    }

    /** Gives the account a new random password for signing in to the mobile app, and returns it once. */
    public function appPassword(User $user, User $by): string
    {
        $password = 'Demo-'.Str::password(12, symbols: false).'!';
        $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'failed_attempts' => 0, 'locked_until' => null])->save();

        if (config('tedc.auth.driver') === 'supabase') {
            $admin = Supabase::admin();
            if ($user->auth_id) {
                $admin->put(Supabase::url("/auth/v1/admin/users/{$user->auth_id}"), ['password' => $password, 'email_confirm' => true])->throw();
            } else {
                $id = $admin->post(Supabase::url('/auth/v1/admin/users'), [
                    'email' => $user->email, 'password' => $password, 'email_confirm' => true, 'user_metadata' => ['full_name' => $user->name, 'full_name_ar' => $user->name_ar],
                ])->throw()->json('id');
                $user->forceFill(['auth_id' => $id])->save();
            }
        }
        $this->audit($by, 'showcase_app_password', ['as' => $user->email], $user);

        return $password;
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $users = User::with('roles:id,slug,name_ar,name_en', 'employee:id,user_id')->whereIn('email', self::emails())->get()->keyBy(fn (User $u) => strtolower($u->email));
        $programs = Program::where('code', 'like', self::PREFIX.'-%')->withCount('registrations')->orderBy('code')->get();
        $en = app()->getLocale() === 'en';

        return [
            'exists' => $users->isNotEmpty(),
            'accounts' => collect(self::ACCOUNTS)->map(function ($spec, $key) use ($users, $en) {
                $user = $users->get(self::email($key));

                return [
                    'key' => $key, 'group' => $spec[0], 'email' => self::email($key), 'id' => $user?->id,
                    'name' => $user ? $user->displayName() : ($en ? $spec[3] : $spec[2]),
                    // The role that makes the account what it is (a supervisor's or a trainer's employee record goes without saying).
                    'roles' => $user ? $user->roles->reject(fn ($r) => $r->slug === Role::EMPLOYEE && $user->roles->count() > 1)->map(fn ($r) => $en ? $r->name_en : $r->name_ar)->values()->all() : [],
                    'app' => $spec[4], 'app_ready' => $user && AuditLog::where('action', 'showcase_app_password')->where('auditable_id', $user->id)->exists(),
                    'last_opened_at' => $user ? AuditLog::where('action', 'showcase_account_opened')->where('auditable_id', $user->id)->max('created_at') : null,
                ];
            })->values()->all(),
            'programs' => $programs->map(fn (Program $p) => [
                'id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title'), 'status' => $p->status,
                'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'trainees' => $p->registrations_count,
            ])->values()->all(),
        ];
    }

    public function find(string $key): ?User
    {
        return array_key_exists($key, self::ACCOUNTS) ? User::where('email', self::email($key))->first() : null;
    }

    public function audit(User $by, string $action, array $values, ?User $target = null): void
    {
        AuditLog::create([
            'user_id' => $by->id, 'action' => $action, 'auditable_type' => User::class, 'auditable_id' => $target?->id ?? $by->id,
            'new_values' => $values, 'ip_address' => request()?->ip(), 'user_agent' => substr((string) request()?->userAgent(), 0, 255),
        ]);
    }

    /**
     * The training story: a programme running this week (trainees attend and can evaluate it now), one finished last month (evaluated;
     * the supervisors can rate its impact on their staff), one opening next week (a registration waits for approval), and a draft
     * the planning head is preparing.
     *
     * @param  array<string, User>  $users
     * @param  array<string, Employee>  $employees
     * @param  array<string, Trainer>  $trainers
     */
    private function story(array $users, array $employees, array $trainers): void
    {
        $today = today();
        $category = ProgramCategory::query()->orderBy('created_at')->value('id');

        $running = $this->program(1, $category, Program::STATUS_IN_PROGRESS, $today->copy()->subDays(3), $today->copy()->addDays(3), [
            'التقويم من أجل التعلّم في الصف', 'Assessment for learning in the classroom', 'استراتيجيات عملية لتوظيف التقويم التكويني في التخطيط اليومي للدروس.', 'Practical strategies for using formative assessment in daily lesson planning.',
        ], ['survey_mode' => 'manual', 'survey_opened_at' => now(), 'survey_closed_at' => null]);
        $finished = $this->program(2, $category, Program::STATUS_COMPLETED, $today->copy()->subDays(32), $today->copy()->subDays(28), [
            'القيادة الصفية الفعّالة', 'Effective classroom leadership', 'إدارة الصف وبناء بيئة تعلّم إيجابية ومحفّزة.', 'Managing the classroom and building a positive, motivating learning environment.',
        ], ['survey_mode' => 'manual', 'survey_opened_at' => $today->copy()->subDays(28), 'survey_closed_at' => $today->copy()->subDays(14)]);
        $upcoming = $this->program(3, $category, Program::STATUS_REGISTRATION_OPEN, $today->copy()->addDays(7), $today->copy()->addDays(9), [
            'توظيف الذكاء الاصطناعي في التدريس', 'Using artificial intelligence in teaching', 'أدوات الذكاء الاصطناعي الآمنة لإعداد الدروس وتقويم التعلّم.', 'Safe AI tools for preparing lessons and assessing learning.',
        ]);
        $draft = $this->program(4, $category, Program::STATUS_DRAFT, $today->copy()->addDays(40), $today->copy()->addDays(44), [
            'خطة التطوير المهني للعام القادم', 'Next year’s professional development plan', 'مسودة يعدّها قسم التخطيط قبل اعتمادها.', 'A draft the planning section prepares before approval.',
        ]);

        $this->teach($running, [$trainers['trainer-1']], $today->copy()->subDays(3), 7);
        $this->teach($finished, [$trainers['trainer-2']], $today->copy()->subDays(32), 5);
        $this->teach($upcoming, [$trainers['trainer-1'], $trainers['trainer-2']], $today->copy()->addDays(7), 3);
        $this->teach($draft, [], $today->copy()->addDays(40), 0);

        // This week: both trainees attend; the second missed one day.
        foreach (['trainee-1' => [], 'trainee-2' => [1]] as $key => $absent) {
            $registration = $this->enrol($running, $employees[$key], Registration::STATUS_APPROVED);
            $this->attend($running, $registration, $absent);
        }
        // Last month: completed and evaluated.
        foreach (['trainee-1' => [5, 5, 4, 5, 5], 'trainee-2' => [4, 5, 4, 4, 5]] as $key => $ratings) {
            $registration = $this->enrol($finished, $employees[$key], Registration::STATUS_COMPLETED);
            $this->attend($finished, $registration, []);
            $registration->update(['completed_at' => $finished->end_date, 'attendance_percent' => 100, 'evaluation_completed' => true, 'pass_status' => 'passed']);
            Evaluation::updateOrCreate(['registration_id' => $registration->id], [
                'program_id' => $finished->id, 'employee_id' => $employees[$key]->id, 'ratings' => $ratings, 'satisfaction_score' => round(array_sum($ratings) / count($ratings) / 5 * 100, 2),
                'comments' => $key === 'trainee-1' ? 'برنامج ثري بالأمثلة العملية، طبّقت أغلب الاستراتيجيات مع طلابي.' : 'مفيد جداً، وأتمنى زيادة وقت التطبيق العملي.',
                'allow_testimonial' => true, 'submitted_at' => $finished->end_date->copy()->setTime(13, 30),
            ]);
        }
        // Next week: the first trainee is in; the second waits for the centre's approval.
        $this->enrol($upcoming, $employees['trainee-1'], Registration::STATUS_APPROVED);
        $this->enrol($upcoming, $employees['trainee-2'], Registration::STATUS_PENDING);

        $this->inbox($users, $running, $finished, $upcoming, $draft);
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string}  $text */
    private function program(int $n, ?string $category, string $status, Carbon $start, Carbon $end, array $text, array $extra = []): Program
    {
        return Program::updateOrCreate(['code' => self::PREFIX."-{$n}"], $extra + [
            'category_id' => $category, 'title_ar' => $text[0], 'title_en' => $text[1], 'summary_ar' => $text[2], 'summary_en' => $text[3],
            'description_ar' => $text[2].' (برنامج تجريبي للعرض.)', 'description_en' => $text[3].' (Showcase programme.)',
            'objectives' => ['تطبيق عملي داخل الصف', 'تبادل الخبرات بين المعلمين'], 'delivery_mode' => 'in_person', 'level' => 'intermediate',
            'total_hours' => 15, 'capacity' => 25, 'min_attendance_percent' => 80, 'requires_tasks' => false, 'requires_evaluation' => true,
            'start_date' => $start, 'end_date' => $end, 'registration_opens_at' => $start->copy()->subDays(21), 'registration_closes_at' => $start->copy()->subDay()->endOfDay(),
            'registration_modes' => Program::MODES, 'status' => $status, 'is_featured' => false,
        ]);
    }

    /** @param  list<Trainer>  $leads */
    private function teach(Program $program, array $leads, Carbon $start, int $days): void
    {
        $program->trainers()->sync(collect($leads)->mapWithKeys(fn (Trainer $t) => [$t->id => ['role' => 'lead']])->all());
        ProgramSession::where('program_id', $program->id)->delete();
        foreach (range(1, $days) as $i) {
            $day = $start->copy()->addDays($i - 1);
            ProgramSession::create([
                'program_id' => $program->id, 'sequence' => $i, 'trainer_id' => $leads[($i - 1) % max(1, count($leads))]->id ?? null,
                'title_ar' => "اليوم {$i}", 'title_en' => "Day {$i}", 'starts_at' => $day->copy()->setTime(8, 0), 'ends_at' => $day->copy()->setTime(11, 0),
                'location_text' => 'قاعة التدريب 3', 'activities' => ['عرض', 'تطبيق عملي'], 'status' => $day->isPast() && ! $day->isToday() ? 'completed' : 'scheduled',
            ]);
        }
    }

    private function enrol(Program $program, Employee $employee, string $status): Registration
    {
        return Registration::updateOrCreate(['program_id' => $program->id, 'employee_id' => $employee->id], [
            'source' => Registration::SOURCE_CENTER, 'status' => $status, 'approved_at' => $status === Registration::STATUS_PENDING ? null : $program->start_date->copy()->subDays(10),
        ]);
    }

    /** Present at every session that has already ended, except on the listed days (1-based). @param list<int> $absent */
    private function attend(Program $program, Registration $registration, array $absent): void
    {
        Attendance::where('registration_id', $registration->id)->delete();
        foreach (ProgramSession::where('program_id', $program->id)->where('ends_at', '<', now())->orderBy('sequence')->get() as $session) {
            $here = ! in_array($session->sequence, $absent, true);
            Attendance::create([
                'program_session_id' => $session->id, 'registration_id' => $registration->id, 'employee_id' => $registration->employee_id,
                'status' => $here ? 'present' : 'absent', 'method' => 'qr', 'minutes_attended' => $here ? 180 : 0,
                'check_in_at' => $here ? $session->starts_at->copy()->addMinutes(4) : null, 'check_out_at' => $here ? $session->ends_at : null,
            ]);
        }
    }

    /** @param  array<string, User>  $users */
    private function inbox(array $users, Program $running, Program $finished, Program $upcoming, Program $draft): void
    {
        $note = fn (string $key, string $type, string $ar, string $en, string $bodyAr, string $bodyEn, array $data = []) => AppNotification::create([
            'user_id' => $users[$key]->id, 'type' => $type, 'title_ar' => $ar, 'title_en' => $en, 'body_ar' => $bodyAr, 'body_en' => $bodyEn, 'data' => $data,
        ]);
        AppNotification::whereIn('user_id', collect($users)->pluck('id'))->delete();

        foreach (array_keys($users) as $key) {
            $note($key, 'showcase_welcome', 'مرحباً بك في الحساب التجريبي', 'Welcome to the showcase account',
                'هذا حساب للعرض فيه بيانات تجريبية؛ جرّب كل ما يتاح لهذا الدور.', 'A showcase account with sample data: try everything this role can do.');
        }
        foreach (['trainee-1', 'trainee-2'] as $key) {
            $note($key, 'survey_opened', 'استبيان البرنامج متاح الآن', 'The programme survey is open', "قيّم برنامج «{$running->title_ar}» من صفحة البرنامج.", "Rate “{$running->title_en}” from the programme page.", ['program_id' => $running->id]);
        }
        $note('trainee-2', 'registration_pending', 'طلب تسجيلك قيد المراجعة', 'Your registration is under review', "طلبك في «{$upcoming->title_ar}» بانتظار اعتماد المركز.", "Your request for “{$upcoming->title_en}” awaits the centre’s approval.", ['program_id' => $upcoming->id]);
        foreach (['trainer-1' => $running, 'trainer-2' => $finished] as $key => $program) {
            $note($key, 'program_assigned', 'أُسند إليك برنامج', 'A programme was assigned to you', "أنت المدرّب الرئيس لبرنامج «{$program->title_ar}».", "You are the lead trainer of “{$program->title_en}”.", ['program_id' => $program->id]);
        }
        foreach (['supervisor-1', 'supervisor-2'] as $key) {
            $note($key, 'impact_due', 'قيّم أثر التدريب على فريقك', 'Rate the training’s impact on your team', "أنهى أحد أفراد فريقك «{$finished->title_ar}»؛ قيّم أثره من «فريقي».", "Someone on your team finished “{$finished->title_en}”: rate its impact from My team.", ['program_id' => $finished->id]);
        }
        foreach (['centre-head', 'training-head'] as $key) {
            $note($key, 'registration_pending', 'طلب تسجيل بانتظار الاعتماد', 'A registration awaits approval', "طلب «{$users['trainee-2']->name_ar}» في «{$upcoming->title_ar}».", "{$users['trainee-2']->name}’s request for “{$upcoming->title_en}”.", ['program_id' => $upcoming->id]);
        }
        $note('planning-head', 'plan_draft', 'مسودة برنامج بانتظار استكمالها', 'A draft programme to complete', "استكمل «{$draft->title_ar}» قبل رفعه للاعتماد.", "Complete “{$draft->title_en}” before sending it for approval.", ['program_id' => $draft->id]);
    }
}
