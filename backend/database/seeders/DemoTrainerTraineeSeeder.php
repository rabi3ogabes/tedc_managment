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
 * Lets the demo trainer account (trainer@tedc.qa) try the mobile app as a trainee: it gets an employee profile,
 * an approved registration in a test program with a target group, and daily sessions in a test room, so the
 * home screen, QR attendance (scan the code shown on the dashboard) and push notifications can all be tested.
 * Idempotent: safe to run on every deploy; it keeps the test program's sessions available for the coming days.
 */
class DemoTrainerTraineeSeeder extends Seeder
{
    public const PROGRAM = 'TEST-APP';

    public function run(): void
    {
        $user = User::where('email', 'trainer@tedc.qa')->first();
        $teacher = Employee::whereHas('user', fn ($q) => $q->where('email', 'teacher@tedc.qa'))->first();
        if (! $user || ! $teacher) {
            return;
        }

        $user->roles()->syncWithoutDetaching(Role::whereIn('slug', [Role::TRAINER, Role::EMPLOYEE])->pluck('id'));
        $jobTitle = JobTitle::where('code', 'TEACHER')->value('id');

        $employee = Employee::updateOrCreate(['user_id' => $user->id], [
            'school_id' => $teacher->school_id, 'job_title_id' => $jobTitle, 'supervisor_id' => $teacher->supervisor_id,
            'employee_no' => 'E-99003', 'national_id' => '29000000003', 'gender' => 'female', 'nationality' => 'Qatar',
            'birth_date' => now()->subYears(36)->toDateString(), 'hire_date' => now()->subYears(8)->toDateString(),
            'experience_years' => 8, 'education_stage' => 'primary', 'qualification' => 'master', 'specialization' => 'اللغة العربية', 'status' => 'active',
        ]);

        // Rooms without coordinates are not location-checked; add the real position under Settings → Rooms to test that too.
        $room = TrainingRoom::firstOrCreate(['code' => 'TEST-ROOM'], ['name_ar' => 'قاعة اختبار التطبيق', 'name_en' => 'App test room', 'capacity' => 30]);

        $trainer = Trainer::where('user_id', $user->id)->first();
        $start = today();
        $program = Program::updateOrCreate(['code' => self::PROGRAM], [
            'category_id' => ProgramCategory::query()->value('id'),
            'title_ar' => 'برنامج تجريبي لاختبار التطبيق', 'title_en' => 'App test program',
            'summary_ar' => 'برنامج لتجربة التطبيق: الصفحة الرئيسية وتسجيل الحضور بالرمز والإشعارات.',
            'summary_en' => 'A program for trying the app: the home screen, QR attendance and notifications.',
            'description_ar' => 'جلسات يومية مفتوحة لاختبار تسجيل الحضور. امسح الرمز المعروض في لوحة التحكم (الجلسة ← رمز الحضور).',
            'description_en' => 'Daily sessions kept open to test attendance. Scan the code shown on the dashboard (session → attendance code).',
            'objectives' => ['اختبار التطبيق', 'اختبار الإشعارات'],
            'delivery_mode' => 'in_person', 'level' => 'beginner', 'total_hours' => 12, 'capacity' => 50,
            'min_attendance_percent' => 80, 'requires_tasks' => false, 'requires_evaluation' => false,
            'start_date' => $start->copy()->subDay(), 'end_date' => $start->copy()->addDays(30),
            'registration_opens_at' => $start->copy()->subDays(30), 'registration_closes_at' => $start->copy()->addDays(30)->endOfDay(),
            'registration_modes' => Program::MODES, 'status' => Program::STATUS_IN_PROGRESS, 'is_featured' => false,
        ]);
        if ($trainer) {
            $program->trainers()->syncWithoutDetaching([$trainer->id => ['role' => 'lead']]);
        }

        // The test group: teachers of the primary stage.
        TargetGroup::firstOrCreate(['program_id' => $program->id, 'description' => 'مجموعة الاختبار — معلمو المرحلة الابتدائية'], ['job_title_id' => $jobTitle, 'education_stage' => 'primary']);

        // A long daily session (07:00-22:00) for today and the next two weeks, so check-in is open whenever you test.
        foreach (range(0, 14) as $offset) {
            $day = $start->copy()->addDays($offset);
            ProgramSession::firstOrCreate(['program_id' => $program->id, 'starts_at' => $day->copy()->setTime(7, 0)], [
                'sequence' => $offset + 1, 'trainer_id' => $trainer?->id, 'training_room_id' => $room->id,
                'title_ar' => 'جلسة اختبار '.$day->toDateString(), 'title_en' => 'Test session '.$day->toDateString(),
                'ends_at' => $day->copy()->setTime(22, 0),
                'location_text' => 'قاعة اختبار التطبيق', 'activities' => ['اختبار'], 'status' => 'scheduled',
            ]);
        }

        Registration::firstOrCreate(['program_id' => $program->id, 'employee_id' => $employee->id], [
            'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now(),
        ]);
    }
}
