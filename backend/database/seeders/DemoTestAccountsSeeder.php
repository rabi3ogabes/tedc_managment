<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TargetGroup;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Test accounts for trying the mobile app: 4 trainees and 2 trainers (one tap to sign in on the login screen),
 * and 4 numbered test programs they are assigned to. The dashboard (Settings → Test accounts) spells out which
 * account belongs to which programs. Idempotent: safe to run on every deploy; sessions are kept available.
 */
class DemoTestAccountsSeeder extends Seeder
{
    public const PASSWORD = 'Tedc@2026!';

    /** Which programs (1-4) each test account is assigned to. */
    public const TRAINEE_PROGRAMS = [1 => [1], 2 => [1, 2], 3 => [1, 2, 3], 4 => [1, 2, 3, 4]];

    public const TRAINER_PROGRAMS = [1 => [1, 2], 2 => [3, 4]];

    public static function emails(): array
    {
        return array_merge(
            array_map(fn ($n) => "trainee{$n}@tedc.qa", array_keys(self::TRAINEE_PROGRAMS)),
            array_map(fn ($n) => "trainer{$n}@tedc.qa", array_keys(self::TRAINER_PROGRAMS)),
        );
    }

    public function run(): void
    {
        $teacher = Employee::whereHas('user', fn ($q) => $q->where('email', 'teacher@tedc.qa'))->first();
        $jobTitle = JobTitle::where('code', 'TEACHER')->value('id');
        if (! $teacher || ! $jobTitle) {
            return; // the demo organisation is not seeded
        }
        $room = TrainingRoom::firstOrCreate(['code' => 'TEST-ROOM'], ['name_ar' => 'قاعة اختبار التطبيق', 'name_en' => 'App test room', 'capacity' => 30]);

        // Trainers (also given an employee profile so the mobile app opens for them).
        $trainers = [];
        foreach (array_keys(self::TRAINER_PROGRAMS) as $n) {
            $user = $this->account("trainer{$n}@tedc.qa", "مدرب تجريبي {$n}", "Test Trainer {$n}", [Role::TRAINER, Role::EMPLOYEE]);
            $this->profile($user, $teacher, $jobTitle, 90010 + $n, 'female');
            $trainers[$n] = Trainer::updateOrCreate(['user_id' => $user->id], [
                'name_ar' => "مدرب تجريبي {$n}", 'name_en' => "Test Trainer {$n}", 'title_ar' => 'مدرب اختبار', 'title_en' => 'Test trainer',
                'email' => $user->email, 'is_external' => false, 'status' => 'active',
            ]);
        }

        // The four numbered programs.
        $programs = [];
        $start = today();
        foreach (range(1, 4) as $n) {
            $programs[$n] = Program::updateOrCreate(['code' => "TEST-P{$n}"], [
                'category_id' => ProgramCategory::query()->value('id'),
                'title_ar' => "برنامج اختبار رقم {$n}", 'title_en' => "Test program {$n}",
                'summary_ar' => "برنامج رقم {$n} لتجربة تطبيق الجوال.", 'summary_en' => "Program number {$n} for trying the mobile app.",
                'description_ar' => 'جلسات يومية مفتوحة لاختبار تسجيل الحضور والإشعارات والاستبيان.', 'description_en' => 'Daily sessions kept open to test attendance, notifications and the survey.',
                'objectives' => ['اختبار التطبيق'], 'delivery_mode' => 'in_person', 'level' => 'beginner', 'total_hours' => 12, 'capacity' => 50,
                'min_attendance_percent' => 80, 'requires_tasks' => false, 'requires_evaluation' => false,
                'start_date' => $start->copy()->subDay(), 'end_date' => $start->copy()->addDays(30),
                'registration_opens_at' => $start->copy()->subDays(30), 'registration_closes_at' => $start->copy()->addDays(30)->endOfDay(),
                'registration_modes' => Program::MODES, 'status' => Program::STATUS_IN_PROGRESS, 'is_featured' => false,
            ]);
            TargetGroup::firstOrCreate(['program_id' => $programs[$n]->id, 'description' => 'مجموعة الاختبار'], ['job_title_id' => $jobTitle, 'education_stage' => 'primary']);

            $leads = collect(self::TRAINER_PROGRAMS)->filter(fn ($list) => in_array($n, $list, true))->keys()->map(fn ($t) => $trainers[$t]->id);
            $programs[$n]->trainers()->syncWithoutDetaching($leads->mapWithKeys(fn ($id) => [$id => ['role' => 'lead']])->all());

            foreach (range(0, 14) as $offset) {
                $day = $start->copy()->addDays($offset);
                ProgramSession::firstOrCreate(['program_id' => $programs[$n]->id, 'starts_at' => $day->copy()->setTime(7, 0)], [
                    'sequence' => $offset + 1, 'trainer_id' => $leads->first(), 'training_room_id' => $room->id,
                    'title_ar' => "برنامج {$n} — جلسة {$day->toDateString()}", 'title_en' => "Program {$n} — session {$day->toDateString()}",
                    'ends_at' => $day->copy()->setTime(22, 0), 'location_text' => 'قاعة اختبار التطبيق', 'activities' => ['اختبار'], 'status' => 'scheduled',
                ]);
            }
        }

        // Trainees and their assignments.
        foreach (self::TRAINEE_PROGRAMS as $n => $list) {
            $user = $this->account("trainee{$n}@tedc.qa", "متدرب تجريبي {$n}", "Test Trainee {$n}", [Role::EMPLOYEE]);
            $employee = $this->profile($user, $teacher, $jobTitle, 90020 + $n, $n % 2 ? 'male' : 'female');
            foreach ($list as $p) {
                Registration::firstOrCreate(['program_id' => $programs[$p]->id, 'employee_id' => $employee->id], [
                    'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now(),
                ]);
            }
        }
    }

    private function account(string $email, string $ar, string $en, array $roles): User
    {
        $user = User::updateOrCreate(['email' => $email], ['name' => $en, 'name_ar' => $ar, 'password' => self::PASSWORD, 'locale' => 'ar', 'status' => 'active']);
        $user->roles()->syncWithoutDetaching(Role::whereIn('slug', $roles)->pluck('id'));

        return $user;
    }

    private function profile(User $user, Employee $like, string $jobTitle, int $no, string $gender): Employee
    {
        return Employee::updateOrCreate(['user_id' => $user->id], [
            'school_id' => $like->school_id, 'job_title_id' => $jobTitle, 'supervisor_id' => $like->supervisor_id,
            'employee_no' => "E-{$no}", 'national_id' => (string) (29000000000 + $no), 'gender' => $gender, 'nationality' => 'Qatar',
            'birth_date' => now()->subYears(32)->toDateString(), 'hire_date' => now()->subYears(6)->toDateString(),
            'experience_years' => 6, 'education_stage' => 'primary', 'qualification' => 'bachelor', 'specialization' => 'اللغة العربية', 'status' => 'active',
        ]);
    }
}
