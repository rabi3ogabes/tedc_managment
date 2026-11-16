<?php

namespace App\Services\Programs;

use App\Models\AuditLog;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\Trainer;
use App\Models\User;
use App\Services\NeedsSurveys\SurveyAudience;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The two moments a programme reaches the people around it: registration opens, and trainees are assigned directly by
 * the centre's specialist. Each time the programme's trainers and the system administrators (school admins) of the
 * schools concerned are told, in the app (push), by e-mail and by SMS — the channels each person and the platform have
 * switched on; a trainee's own notice is the existing "program assigned" one. Every send goes through the notification
 * service, so templates, switches, quiet hours and delivery tracking all apply.
 */
class ProgramAnnouncer
{
    public function __construct(private readonly NotificationService $notifications, private readonly SurveyAudience $audiences) {}

    // ───────────────────────────── registration opens

    /** Announces the opening once per programme (the mark is kept on the programme); returns who was told. @return array{trainers: int, schools: int, admins: int} */
    public function registrationOpened(Program $program): array
    {
        $none = ['trainers' => 0, 'schools' => 0, 'admins' => 0];
        if ($program->status !== Program::STATUS_REGISTRATION_OPEN || $program->registration_announced_at) {
            return $none;
        }
        // Marked first, so a second request or the scheduler never repeats it.
        if (! Program::whereKey($program->id)->whereNull('registration_announced_at')->update(['registration_announced_at' => now()])) {
            return $none;
        }
        $program->refresh()->loadMissing('sessions');
        $title = ['ar' => 'فُتح التسجيل في برنامج «'.$program->title_ar.'»', 'en' => 'Registration is open for "'.($program->title_en ?: $program->title_ar).'"'];
        $when = $this->when($program);
        $out = $none;

        foreach ($this->trainers($program) as $user) {
            $this->notifications->send($user, 'program.registration_open.trainer', ['ar' => 'فُتح التسجيل في برنامجك', 'en' => 'Registration opened for your programme'],
                ['ar' => $title['ar'].". {$when['ar']} راجع جلساتك وقاعتك.", 'en' => $title['en'].". {$when['en']} See your sessions and room."], $this->data($program, $title));
            $out['trainers']++;
        }

        $counts = $this->eligibleBySchool($program);
        foreach ($this->schoolAdmins($counts->keys()->all()) as $schoolId => $admins) {
            $n = (int) ($counts[$schoolId] ?? 0);
            foreach ($admins as $admin) {
                $this->notifications->send($admin, 'program.registration_open.school', ['ar' => 'فُتح التسجيل في برنامج لموظفي مدرستك', 'en' => 'Registration opened for a programme for your staff'],
                    ['ar' => $title['ar'].". {$when['ar']} عدد موظفي مدرستك ضمن الفئة المستهدفة: {$n}.", 'en' => $title['en'].". {$when['en']} Staff of your school in the target group: {$n}."], $this->data($program, $title, ['eligible' => $n]));
                $out['admins']++;
            }
            $out['schools']++;
        }
        AuditLog::create(['action' => 'program_registration_announced', 'auditable_type' => Program::class, 'auditable_id' => $program->id, 'new_values' => $out]);

        return $out;
    }

    /** Announces every programme that is open and not yet announced (the scheduler's pass, after dates open registrations in bulk). */
    public function sweep(): int
    {
        $n = 0;
        foreach (Program::where('status', Program::STATUS_REGISTRATION_OPEN)->whereNull('registration_announced_at')->limit(200)->get() as $program) {
            $this->registrationOpened($program);
            $n++;
        }

        return $n;
    }

    // ───────────────────────────── direct assignment of trainees

    /**
     * One notice to the programme's trainers and one to each school's system administrators for a batch of trainees the
     * centre assigned (the trainees themselves get "program assigned" from the registration service).
     *
     * @param  iterable<Registration>  $registrations
     * @return array{trainers: int, admins: int, trainees: int}
     */
    public function traineesAssigned(Program $program, iterable $registrations): array
    {
        $regs = (new EloquentCollection(is_array($registrations) ? $registrations : iterator_to_array($registrations, false)))->filter(fn (Registration $r) => $r->status === Registration::STATUS_APPROVED && $r->source !== Registration::SOURCE_SELF)->values();
        $out = ['trainers' => 0, 'admins' => 0, 'trainees' => $regs->count()];
        if ($regs->isEmpty()) {
            return $out;
        }
        $regs->load('employee.user:id,name,name_ar', 'employee:id,user_id,school_id');
        $program->loadMissing('sessions');
        $n = $regs->count();
        $title = ['ar' => 'برنامج «'.$program->title_ar.'»', 'en' => '"'.($program->title_en ?: $program->title_ar).'"'];
        $names = fn (Collection $c, string $lang) => $c->map(fn (Registration $r) => $r->employee?->user?->displayName($lang))->filter()->take(8)->implode($lang === 'ar' ? '، ' : ', ').($c->count() > 8 ? ($lang === 'ar' ? ' وغيرهم' : ' and more') : '');
        $seated = Registration::where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->count();
        $when = $this->when($program);

        foreach ($this->trainers($program) as $user) {
            $this->notifications->send($user, 'program.trainees_assigned.trainer', ['ar' => 'رُشّح متدربون لبرنامجك', 'en' => 'Trainees were assigned to your programme'],
                ['ar' => "رُشّح {$n} متدرباً مباشرة لـ{$title['ar']} (المقاعد المشغولة الآن: {$seated}). {$when['ar']}", 'en' => "{$n} trainees were assigned directly to {$title['en']} (seats taken now: {$seated}). {$when['en']}"], $this->data($program, $title, ['count' => $n]));
            $out['trainers']++;
        }
        foreach ($regs->groupBy(fn (Registration $r) => $r->employee?->school_id) as $schoolId => $group) {
            if (! $schoolId) {
                continue;
            }
            foreach ($this->schoolAdmins([$schoolId])[$schoolId] ?? [] as $admin) {
                $k = $group->count();
                $this->notifications->send($admin, 'program.trainees_assigned.school', ['ar' => 'رُشّح موظفون من مدرستك لبرنامج تدريبي', 'en' => 'Staff of your school were assigned to a programme'],
                    ['ar' => "رُشّح {$k} من موظفي مدرستك مباشرة لـ{$title['ar']}: {$names($group, 'ar')}. {$when['ar']}", 'en' => "{$k} of your school's staff were assigned directly to {$title['en']}: {$names($group, 'en')}. {$when['en']}"], $this->data($program, $title, ['count' => $k, 'school_id' => $schoolId]));
                $out['admins']++;
            }
        }
        AuditLog::create(['action' => 'program_trainees_assigned_announced', 'auditable_type' => Program::class, 'auditable_id' => $program->id, 'new_values' => $out]);

        return $out;
    }

    // ───────────────────────────── who and what

    /** The programme's trainers as active users: the programme's own, its sessions' and its groups' confirmed ones. @return Collection<int, User> */
    public function trainers(Program $program): Collection
    {
        $program->loadMissing('trainers:id,user_id');
        $ids = $program->trainers->pluck('user_id');
        $ids = $ids->merge(Trainer::whereIn('id', $program->sessions()->whereNotNull('trainer_id')->pluck('trainer_id'))->pluck('user_id'));
        $ids = $ids->merge(Trainer::whereIn('id', GroupTrainer::whereIn('group_id', $program->groups()->pluck('id'))->where('status', GroupTrainer::APPROVED)->pluck('trainer_id'))->pluck('user_id'));

        return User::whereIn('id', $ids->filter()->unique()->values())->where('status', 'active')->get();
    }

    /** @param  list<string>  $schoolIds @return array<string, Collection<int, User>> the school's system administrators (school-admin role), by school */
    public function schoolAdmins(array $schoolIds): array
    {
        if ($schoolIds === []) {
            return [];
        }
        $admins = User::whereHas('roles', fn ($q) => $q->where('slug', Role::SCHOOL_ADMIN))->where('status', 'active')->whereHas('employee', fn ($q) => $q->whereIn('school_id', $schoolIds))->with('employee:id,user_id,school_id')->get();

        return $admins->groupBy(fn (User $u) => $u->employee?->school_id)->all();
    }

    /** How many eligible staff each school has in the programme's target group (every school when the programme is open to all). @return Collection<string, int> */
    public function eligibleBySchool(Program $program): Collection
    {
        $audience = SurveyAudience::clean($program->audience ?? []);

        return $this->audiences->query($audience)->whereNotNull('employees.school_id')->reorder()->selectRaw('employees.school_id as sid, count(*) as c')->groupBy('employees.school_id')->pluck('c', 'sid')->map(fn ($c) => (int) $c);
    }

    /** @return array{ar: string, en: string} */
    private function when(Program $program): array
    {
        $first = $program->sessions->first();
        $date = $first?->starts_at ?? $program->start_date;
        if (! $date) {
            return ['ar' => '', 'en' => ''];
        }
        $d = $date->copy()->timezone(config('app.timezone'));
        $room = $first?->room?->name_ar;

        return ['ar' => 'يبدأ في '.$d->format('Y-m-d').($first ? ' الساعة '.$d->format('H:i') : '').($room ? " في {$room}." : '.'), 'en' => 'Starts on '.$d->format('Y-m-d').($first ? ' at '.$d->format('H:i') : '').($first?->room?->name_en ? ' in '.$first->room->name_en.'.' : '.')];
    }

    /** @param  array{ar: string, en: string}  $title @param  array<string, mixed>  $extra */
    private function data(Program $program, array $title, array $extra = []): array
    {
        return ['program_id' => $program->id, 'program_code' => $program->code, 'route' => '/admin/programs/'.$program->id, 'detail_ar' => $title['ar'], 'detail_en' => $title['en']] + $extra;
    }

    /** Used by tests and the admin tools: the schools of a programme's target group with their names. @return list<array{id: string, name_ar: string, eligible: int}> */
    public function targetSchools(Program $program): array
    {
        $counts = $this->eligibleBySchool($program);

        return School::whereIn('id', $counts->keys())->orderBy('name_ar')->get(['id', 'name_ar'])->map(fn (School $s) => ['id' => $s->id, 'name_ar' => $s->name_ar, 'eligible' => (int) $counts[$s->id]])->all();
    }
}
