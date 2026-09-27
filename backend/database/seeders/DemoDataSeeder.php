<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\Skill;
use App\Models\SupervisorEvaluation;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\Trainer;
use App\Models\TrainingNeed;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\ImpactService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Realistic demo data for a Qatar-based training center.
 * Demo accounts (password: Tedc@2026!):
 *   admin@tedc.qa, center@tedc.qa, coordinator@tedc.qa, trainer@tedc.qa,
 *   school@tedc.qa, teacher@tedc.qa, supervisor@tedc.qa, executive@tedc.qa
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'Tedc@2026!';

    private array $firstMale = ['محمد', 'أحمد', 'عبدالله', 'خالد', 'حمد', 'جاسم', 'علي', 'يوسف', 'فهد', 'ناصر', 'سعد', 'عبدالرحمن', 'راشد', 'مبارك', 'سلطان'];

    private array $firstFemale = ['مريم', 'فاطمة', 'نورة', 'عائشة', 'الجازي', 'شيخة', 'هند', 'سارة', 'لطيفة', 'موزة', 'ريم', 'أسماء', 'دانة', 'منيرة', 'حصة'];

    private array $family = ['الكواري', 'المري', 'الهاجري', 'النعيمي', 'السليطي', 'المهندي', 'الخليفي', 'العطية', 'الدوسري', 'الكبيسي', 'المناعي', 'البوعينين', 'الملا', 'السويدي', 'اليافعي'];

    private array $latin = [
        'محمد' => 'Mohammed', 'أحمد' => 'Ahmed', 'عبدالله' => 'Abdullah', 'خالد' => 'Khalid', 'حمد' => 'Hamad', 'جاسم' => 'Jassim', 'علي' => 'Ali', 'يوسف' => 'Yousef',
        'فهد' => 'Fahad', 'ناصر' => 'Nasser', 'سعد' => 'Saad', 'عبدالرحمن' => 'Abdulrahman', 'راشد' => 'Rashid', 'مبارك' => 'Mubarak', 'سلطان' => 'Sultan',
        'مريم' => 'Maryam', 'فاطمة' => 'Fatima', 'نورة' => 'Noora', 'عائشة' => 'Aisha', 'الجازي' => 'Aljazi', 'شيخة' => 'Sheikha', 'هند' => 'Hind', 'سارة' => 'Sara',
        'لطيفة' => 'Latifa', 'موزة' => 'Moza', 'ريم' => 'Reem', 'أسماء' => 'Asma', 'دانة' => 'Dana', 'منيرة' => 'Munira', 'حصة' => 'Hessa',
        'الكواري' => 'Al-Kuwari', 'المري' => 'Al-Marri', 'الهاجري' => 'Al-Hajri', 'النعيمي' => 'Al-Naimi', 'السليطي' => 'Al-Sulaiti', 'المهندي' => 'Al-Mohannadi',
        'الخليفي' => 'Al-Khulaifi', 'العطية' => 'Al-Attiyah', 'الدوسري' => 'Al-Dosari', 'الكبيسي' => 'Al-Kubaisi', 'المناعي' => 'Al-Mannai', 'البوعينين' => 'Al-Buainain',
        'الملا' => 'Al-Mulla', 'السويدي' => 'Al-Suwaidi', 'اليافعي' => 'Al-Yafei',
    ];

    private string $passwordHash;

    public function run(): void
    {
        mt_srand(2026);
        // Hash once: the `hashed` cast keeps already-hashed values as-is.
        $this->passwordHash = Hash::make(self::PASSWORD);

        $schools = $this->schools();
        $trainers = $this->trainers();
        $employees = $this->employees($schools);
        $this->staffAccounts($schools, $employees);
        $programs = $this->programs($trainers);
        $this->enrollments($programs, $employees);
        $this->trainingNeeds($schools);
        $this->news();
    }

    private function schools(): array
    {
        $rows = [
            ['QS-001', 'مدرسة الدوحة الثانوية للبنين', 'Doha Secondary School for Boys', 'government', 'boys', 'secondary', 'doha', 25.2854, 51.5310, true],
            ['QS-002', 'مدرسة الريان الابتدائية للبنات', 'Al Rayyan Primary School for Girls', 'government', 'girls', 'primary', 'al_rayyan', 25.2919, 51.4244, true],
            ['QS-003', 'مدرسة الوكرة الإعدادية للبنين', 'Al Wakrah Preparatory School for Boys', 'government', 'boys', 'preparatory', 'al_wakrah', 25.1715, 51.6034, true],
            ['QS-004', 'مدرسة الخور الثانوية للبنات', 'Al Khor Secondary School for Girls', 'government', 'girls', 'secondary', 'al_khor', 25.6804, 51.4969, true],
            ['QS-005', 'مدرسة الشمال النموذجية', 'Al Shamal Model School', 'government', 'mixed', 'primary', 'al_shamal', 26.1293, 51.2009, false],
            ['QS-006', 'مدرسة أم صلال الإعدادية للبنات', 'Umm Salal Preparatory School for Girls', 'government', 'girls', 'preparatory', 'umm_salal', 25.4152, 51.4065, true],
            ['QS-007', 'مدرسة الضعاين الابتدائية للبنين', 'Al Daayen Primary School for Boys', 'government', 'boys', 'primary', 'al_daayen', 25.4282, 51.4868, false],
            ['QS-008', 'مدرسة الشحانية الثانوية', 'Al Shahaniya Secondary School', 'government', 'boys', 'secondary', 'al_shahaniya', 25.3713, 51.2046, false],
            ['QS-009', 'روضة اللؤلؤة', 'Al Lulu Kindergarten', 'government', 'mixed', 'kindergarten', 'doha', 25.3190, 51.5270, true],
            ['QS-010', 'مدرسة الغرافة الابتدائية للبنات', 'Al Gharrafa Primary School for Girls', 'government', 'girls', 'primary', 'al_rayyan', 25.3385, 51.4560, true],
            ['QS-011', 'مدرسة مسيعيد النموذجية', 'Mesaieed Model School', 'community', 'mixed', 'multi', 'al_wakrah', 24.9909, 51.5493, false],
            ['QS-012', 'مدرسة الوعب الإعدادية للبنين', 'Al Waab Preparatory School for Boys', 'government', 'boys', 'preparatory', 'al_rayyan', 25.2615, 51.4631, true],
            ['QS-013', 'مدرسة لوسيل الثانوية للبنات', 'Lusail Secondary School for Girls', 'government', 'girls', 'secondary', 'al_daayen', 25.4200, 51.5040, true],
            ['QS-014', 'المدرسة الدولية بالدوحة', 'Doha International School', 'international', 'mixed', 'multi', 'doha', 25.2760, 51.4900, false],
        ];

        return collect($rows)->map(fn ($r) => School::updateOrCreate(['code' => $r[0]], [
            'name_ar' => $r[1], 'name_en' => $r[2], 'type' => $r[3], 'gender' => $r[4], 'stage' => $r[5], 'region' => $r[6],
            'latitude' => $r[7], 'longitude' => $r[8], 'is_partner' => $r[9], 'status' => 'active',
            'email' => strtolower($r[0]).'@schools.edu.qa', 'phone' => '+974 4'.mt_rand(1000000, 9999999),
        ]))->all();
    }

    private function trainers(): array
    {
        $rows = [
            ['د. نورة المهندي', 'Dr. Noora Al-Mohannadi', 'خبيرة القيادة التربوية', 'Educational Leadership Expert', ['educational_leadership', 'strategic_planning'], 4.9],
            ['د. خالد السليطي', 'Dr. Khalid Al-Sulaiti', 'مستشار التحول الرقمي', 'Digital Transformation Advisor', ['ai_in_education', 'digital_content'], 4.8],
            ['أ. مريم الكواري', 'Ms. Maryam Al-Kuwari', 'مدربة طرائق التدريس', 'Pedagogy Trainer', ['active_learning', 'differentiated_instruction'], 4.7],
            ['د. أحمد العطية', 'Dr. Ahmed Al-Attiyah', 'خبير القياس والتقويم', 'Assessment Specialist', ['assessment_for_learning', 'data_driven_instruction'], 4.8],
            ['أ. هند النعيمي', 'Ms. Hind Al-Naimi', 'أخصائية الرفاه الطلابي', 'Student Wellbeing Specialist', ['student_wellbeing', 'inclusive_education'], 4.6],
            ['م. يوسف الهاجري', 'Eng. Yousef Al-Hajri', 'مدرب STEM', 'STEM Trainer', ['stem_pedagogy', 'blended_learning'], 4.7],
            ['د. سارة الخليفي', 'Dr. Sara Al-Khulaifi', 'خبيرة الإرشاد المهني', 'Coaching Expert', ['coaching_mentoring', 'communication'], 4.9],
            ['أ. فهد المري', 'Mr. Fahad Al-Marri', 'مدرب إدارة الصف', 'Classroom Management Coach', ['classroom_management', 'behavior_management'], 4.5],
        ];

        $trainers = [];
        foreach ($rows as $i => [$ar, $en, $tAr, $tEn, $specs, $rating]) {
            $trainers[] = Trainer::updateOrCreate(['name_en' => $en], [
                'name_ar' => $ar, 'title_ar' => $tAr, 'title_en' => $tEn, 'specializations' => $specs, 'rating' => $rating,
                'bio_ar' => "{$tAr} بخبرة تزيد على ".(10 + $i).' عاماً في تطوير الكوادر التعليمية في دولة قطر ومنطقة الخليج.',
                'bio_en' => "{$tEn} with more than ".(10 + $i).' years of experience developing educators in Qatar and the Gulf region.',
                'email' => 'trainer'.($i + 1).'@tedc.qa', 'is_external' => $i >= 6, 'organization' => $i >= 6 ? 'Qatar University' : null, 'status' => 'active',
            ]);
        }

        return $trainers;
    }

    private function employees(array $schools): array
    {
        $titles = JobTitle::pluck('id', 'code');
        $skills = Skill::all();
        $weights = ['TEACHER' => 60, 'SENIOR_TEACHER' => 12, 'SUBJECT_COORD' => 8, 'VICE_PRINCIPAL' => 3, 'ACADEMIC_ADVISOR' => 4, 'SOCIAL_SPECIALIST' => 4, 'PSYCH_SPECIALIST' => 2, 'LEARNING_RESOURCES' => 3, 'IT_SPECIALIST' => 2, 'ADMIN_OFFICER' => 2];
        $pool = collect($weights)->flatMap(fn ($w, $code) => array_fill(0, $w, $code))->all();
        $stageFor = ['kindergarten' => 'kindergarten', 'primary' => 'primary', 'preparatory' => 'preparatory', 'secondary' => 'secondary', 'multi' => 'primary'];

        $employees = [];
        $n = 10000;
        foreach ($schools as $school) {
            $count = mt_rand(10, 16);
            $principal = null;
            for ($i = 0; $i < $count; $i++) {
                $female = $school->gender === 'girls' || ($school->gender !== 'boys' && mt_rand(0, 1));
                $code = $i === 0 ? 'PRINCIPAL' : $pool[array_rand($pool)];
                $employee = $this->makeEmployee(++$n, $female, $school, $titles[$code], $stageFor[$school->stage], $principal?->id);
                $principal ??= $employee;

                foreach ($skills->random(mt_rand(3, 6)) as $skill) {
                    $employee->skills()->syncWithoutDetaching([$skill->id => ['level' => mt_rand(1, 4), 'source' => 'self']]);
                }
                $employees[] = $employee;
            }
        }

        return $employees;
    }

    private function makeEmployee(int $no, bool $female, School $school, string $jobTitleId, string $stage, ?string $supervisorId, ?string $email = null, ?array $name = null): Employee
    {
        [$first, $last] = $name ?? [($female ? $this->firstFemale : $this->firstMale)[array_rand($female ? $this->firstFemale : $this->firstMale)], $this->family[array_rand($this->family)]];

        $user = User::updateOrCreate(['email' => $email ?? "e{$no}@schools.edu.qa"], [
            'name' => $this->latin[$first].' '.$this->latin[$last],
            'name_ar' => "{$first} {$last}",
            'password' => $this->passwordHash,
            'locale' => 'ar',
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching(Role::where('slug', Role::EMPLOYEE)->pluck('id'));

        $experience = round(mt_rand(5, 250) / 10, 1);

        return Employee::updateOrCreate(['user_id' => $user->id], [
            'school_id' => $school->id,
            'job_title_id' => $jobTitleId,
            'supervisor_id' => $supervisorId,
            'employee_no' => "E-{$no}",
            'national_id' => (string) mt_rand(28000000000, 29999999999),
            'gender' => $female ? 'female' : 'male',
            'nationality' => mt_rand(0, 3) ? 'Qatar' : 'Jordan',
            'hire_date' => now()->subYears((int) $experience)->toDateString(),
            'experience_years' => $experience,
            'education_stage' => $stage,
            'qualification' => ['bachelor', 'bachelor', 'master', 'phd', 'diploma'][mt_rand(0, 4)],
            'status' => 'active',
        ]);
    }

    private function staffAccounts(array $schools, array $employees): void
    {
        $accounts = [
            ['admin@tedc.qa', 'System Administrator', 'مدير النظام', [Role::SUPER_ADMIN]],
            ['center@tedc.qa', 'Hessa Al-Mannai', 'حصة المناعي', [Role::CENTER_ADMIN]],
            ['coordinator@tedc.qa', 'Rashid Al-Kubaisi', 'راشد الكبيسي', [Role::COORDINATOR]],
            ['executive@tedc.qa', 'Sultan Al-Attiyah', 'سلطان العطية', [Role::EXECUTIVE]],
        ];
        foreach ($accounts as [$email, $name, $nameAr, $roles]) {
            $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'name_ar' => $nameAr, 'password' => $this->passwordHash, 'locale' => 'ar', 'status' => 'active']);
            $user->roles()->sync(Role::whereIn('slug', $roles)->pluck('id'));
        }

        // Trainer account linked to the first trainer profile.
        $trainerUser = User::updateOrCreate(['email' => 'trainer@tedc.qa'], ['name' => 'Dr. Noora Al-Mohannadi', 'name_ar' => 'د. نورة المهندي', 'password' => $this->passwordHash, 'locale' => 'ar', 'status' => 'active']);
        $trainerUser->roles()->sync(Role::where('slug', Role::TRAINER)->pluck('id'));
        Trainer::orderBy('name_en')->where('name_en', 'Dr. Noora Al-Mohannadi')->update(['user_id' => $trainerUser->id]);

        // School admin = principal of the first school.
        $school = $schools[1];
        $principal = Employee::where('school_id', $school->id)->whereNull('supervisor_id')->first();
        $principal->user->update(['email' => 'school@tedc.qa']);
        $principal->user->roles()->syncWithoutDetaching(Role::whereIn('slug', [Role::SCHOOL_ADMIN, Role::SUPERVISOR])->pluck('id'));

        // Showcase teacher (eligible for most programs) supervised by the principal.
        $teacher = $this->makeEmployee(99001, true, $school, JobTitle::where('code', 'TEACHER')->value('id'), 'primary', $principal->id, 'teacher@tedc.qa', ['مريم', 'الكواري']);
        $teacher->update(['experience_years' => 4.5, 'qualification' => 'master']);
        $supervisor = $this->makeEmployee(99002, false, $school, JobTitle::where('code', 'SUBJECT_COORD')->value('id'), 'primary', $principal->id, 'supervisor@tedc.qa', ['خالد', 'السليطي']);
        $supervisor->user->roles()->syncWithoutDetaching(Role::where('slug', Role::SUPERVISOR)->pluck('id'));
        $teacher->update(['supervisor_id' => $supervisor->id]);
        foreach (Skill::whereIn('code', ['classroom_management', 'active_learning', 'communication'])->get() as $skill) {
            $teacher->skills()->syncWithoutDetaching([$skill->id => ['level' => 3, 'source' => 'self']]);
        }
    }

    private function programs(array $trainers): array
    {
        $cat = ProgramCategory::pluck('id', 'slug');
        $skill = Skill::pluck('id', 'code');
        $rooms = TrainingRoom::pluck('id')->all();
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');

        // [code, category, ar, en, summary_ar, summary_en, level, hours, capacity, mode, start offset days, sessions, skills, trainer idx, featured, cover]
        $rows = [
            ['LDR-101', 'leadership', 'القيادة التربوية الفعّالة', 'Effective Educational Leadership', 'برنامج متكامل لبناء قادة مدارس قادرين على إحداث التغيير وتحقيق رؤية قطر الوطنية 2030.', 'A comprehensive program building school leaders who drive change in line with Qatar National Vision 2030.', 'advanced', 24, 30, 'in_person', 21, 6, ['educational_leadership' => 4, 'strategic_planning' => 4, 'coaching_mentoring' => 3], 0, true, 'leadership'],
            ['AI-201', 'digital', 'أدوات الذكاء الاصطناعي للمعلمين', 'AI Tools for Education', 'توظيف أدوات الذكاء الاصطناعي في التخطيط للدروس وتصميم المحتوى والتقويم بطريقة آمنة ومسؤولة.', 'Using AI tools for lesson planning, content design and assessment — safely and responsibly.', 'intermediate', 18, 40, 'hybrid', 10, 6, ['ai_in_education' => 4, 'digital_content' => 3], 1, true, 'ai'],
            ['CLM-110', 'pedagogy', 'إدارة الصف الإيجابية', 'Positive Classroom Management', 'استراتيجيات عملية لبناء بيئة صفية آمنة ومحفزة للتعلم.', 'Practical strategies for a safe, motivating learning environment.', 'beginner', 12, 35, 'in_person', 5, 4, ['classroom_management' => 4, 'behavior_management' => 3], 7, true, 'classroom'],
            ['AFL-150', 'assessment', 'التقويم من أجل التعلم', 'Assessment for Learning', 'تصميم أدوات تقويم تكويني وتوظيف البيانات لتحسين نتائج الطلاب.', 'Designing formative assessment and using data to improve student outcomes.', 'intermediate', 15, 30, 'in_person', 35, 5, ['assessment_for_learning' => 4, 'data_driven_instruction' => 3], 3, false, 'assessment'],
            ['DIF-120', 'pedagogy', 'التعليم المتمايز داخل الفصل', 'Differentiated Instruction in Practice', 'تلبية احتياجات جميع المتعلمين من خلال التخطيط المتمايز.', 'Meeting every learner’s needs through differentiated planning.', 'intermediate', 12, 30, 'in_person', 45, 4, ['differentiated_instruction' => 4, 'inclusive_education' => 3], 2, true, 'differentiation'],
            ['WEL-130', 'wellbeing', 'الرفاه النفسي للطلاب', 'Student Wellbeing & Mental Health', 'تمكين الكوادر التعليمية من دعم الصحة النفسية للطلاب.', 'Equipping educators to support student mental health.', 'beginner', 9, 40, 'online', 14, 3, ['student_wellbeing' => 4, 'behavior_management' => 3], 4, false, 'wellbeing'],
            ['STEM-210', 'pedagogy', 'تدريس STEM القائم على المشاريع', 'Project-Based STEM Teaching', 'تصميم مشاريع تعلم تكاملية في العلوم والتقنية والهندسة والرياضيات.', 'Designing integrated STEM learning projects.', 'intermediate', 18, 25, 'in_person', 60, 6, ['stem_pedagogy' => 4, 'active_learning' => 3], 5, false, 'stem'],
            ['CCH-220', 'leadership', 'الإرشاد والتوجيه المهني للمعلمين', 'Coaching & Mentoring for Teachers', 'بناء ثقافة التطوير المهني المستمر عبر الإرشاد بين الأقران.', 'Building a culture of continuous growth through peer coaching.', 'advanced', 15, 25, 'in_person', -60, 5, ['coaching_mentoring' => 4, 'communication' => 4], 6, false, 'coaching'],
            ['BLD-140', 'digital', 'التعلم المدمج والمنصات الرقمية', 'Blended Learning & Digital Platforms', 'تصميم تجارب تعلم مدمجة فعّالة.', 'Designing effective blended learning experiences.', 'beginner', 12, 40, 'online', -90, 4, ['blended_learning' => 3, 'digital_content' => 3], 1, false, 'blended'],
            ['ACT-100', 'pedagogy', 'استراتيجيات التعلم النشط', 'Active Learning Strategies', 'تحويل الطالب إلى محور العملية التعليمية.', 'Putting the learner at the centre of every lesson.', 'beginner', 9, 35, 'in_person', -120, 3, ['active_learning' => 4], 2, false, 'active'],
            ['RES-300', 'professional', 'البحث الإجرائي لتطوير الممارسات', 'Action Research for Better Practice', 'منهجية البحث الإجرائي لتحسين التدريس داخل المدرسة.', 'Action research methodology to improve teaching in schools.', 'advanced', 21, 20, 'hybrid', -45, 6, ['research_skills' => 4, 'data_driven_instruction' => 3], 3, false, 'research'],
            ['INC-160', 'wellbeing', 'التعليم الدامج وذوي الاحتياجات', 'Inclusive Education', 'ممارسات دمج الطلاب ذوي الاحتياجات الخاصة في الفصول.', 'Practices for including students with special needs.', 'intermediate', 12, 30, 'in_person', 75, 4, ['inclusive_education' => 4, 'differentiated_instruction' => 3], 4, false, 'inclusive'],
        ];

        $programs = [];
        foreach ($rows as [$code, $category, $ar, $en, $sumAr, $sumEn, $level, $hours, $capacity, $mode, $offset, $sessionCount, $skills, $trainerIdx, $featured, $cover]) {
            $start = today()->addDays($offset);
            $end = $start->copy()->addDays(($sessionCount - 1) * 2);
            $status = match (true) {
                $end->lt(today()) => Program::STATUS_COMPLETED,
                $start->lte(today()) => Program::STATUS_IN_PROGRESS,
                default => Program::STATUS_REGISTRATION_OPEN,
            };

            $program = Program::updateOrCreate(['code' => $code], [
                'category_id' => $cat[$category],
                'title_ar' => $ar, 'title_en' => $en, 'summary_ar' => $sumAr, 'summary_en' => $sumEn,
                'description_ar' => $sumAr.' يتضمن البرنامج ورش عمل تطبيقية ومهام عملية ومتابعة لقياس أثر التدريب بعد 30 و60 و90 يوماً.',
                'description_en' => $sumEn.' The program includes hands-on workshops, practical tasks and follow-up impact measurement after 30, 60 and 90 days.',
                'objectives' => ['تطبيق المهارات المكتسبة في الممارسة اليومية', 'تبادل الخبرات بين المشاركين', 'قياس الأثر على تعلم الطلاب'],
                'delivery_mode' => $mode, 'level' => $level, 'total_hours' => $hours, 'capacity' => $capacity,
                'min_attendance_percent' => 80, 'requires_tasks' => true, 'requires_evaluation' => true,
                'start_date' => $start, 'end_date' => $end,
                'registration_opens_at' => $start->copy()->subDays(40), 'registration_closes_at' => $start->copy()->subDay()->endOfDay(),
                'registration_modes' => Program::MODES, 'status' => $status, 'is_featured' => $featured,
                'cover_path' => "/images/programs/{$cover}.jpg",
            ]);

            $program->skills()->sync(collect($skills)->mapWithKeys(fn ($lvl, $c) => [$skill[$c] => ['target_level' => $lvl]]));
            $program->trainers()->sync([$trainers[$trainerIdx]->id => ['role' => 'lead']]);

            if ($program->sessions()->doesntExist()) {
                for ($s = 0; $s < $sessionCount; $s++) {
                    $day = $start->copy()->addDays($s * 2)->setTime(8, 30);
                    ProgramSession::create([
                        'program_id' => $program->id, 'trainer_id' => $trainers[$trainerIdx]->id,
                        'training_room_id' => $mode === 'online' ? null : $rooms[$s % count($rooms)],
                        'sequence' => $s + 1,
                        'title_ar' => 'الجلسة '.($s + 1).': '.['المفاهيم الأساسية', 'التطبيق العملي', 'دراسات حالة', 'ورشة تصميم', 'المشروع التطبيقي', 'العرض والتقييم'][$s % 6],
                        'title_en' => 'Session '.($s + 1).': '.['Core Concepts', 'Hands-on Practice', 'Case Studies', 'Design Workshop', 'Applied Project', 'Showcase & Review'][$s % 6],
                        'starts_at' => $day, 'ends_at' => $day->copy()->addHours((int) ceil($hours / $sessionCount)),
                        'location_text' => $mode === 'online' ? 'Microsoft Teams' : 'مركز التدريب — الدوحة',
                        'online_url' => $mode !== 'in_person' ? 'https://teams.microsoft.com/l/meetup-join/demo' : null,
                        'activities' => ['عرض تقديمي', 'عمل تعاوني', 'نشاط تطبيقي'],
                        'status' => $day->isPast() ? 'completed' : 'scheduled',
                    ]);
                }

                Task::create([
                    'program_id' => $program->id,
                    'title_ar' => 'خطة تطبيق داخل الفصل', 'title_en' => 'Classroom application plan',
                    'instructions_ar' => 'أعدّ خطة لتطبيق ما تعلمته في أحد دروسك وأرفقها بصيغة PDF أو Word.',
                    'instructions_en' => 'Prepare a plan to apply what you learned in one of your lessons (PDF or Word).',
                    'due_at' => $end->copy()->addDays(7), 'submission_types' => ['pdf', 'word', 'text'], 'is_required' => true,
                ]);
            }

            // Eligibility rules — the platform's canonical example on AI-201:
            // Teacher AND experience > 2 years AND has not completed ACT-100 already covered.
            if ($program->eligibilityRules()->doesntExist()) {
                if ($code === 'AI-201') {
                    $program->eligibilityRules()->createMany([
                        ['field' => 'job_category', 'operator' => 'in', 'value' => ['teaching', 'leadership'], 'sort_order' => 0],
                        ['field' => 'experience_years', 'operator' => 'gt', 'value' => 2, 'sort_order' => 1],
                        ['field' => 'completed_program', 'operator' => 'not_completed', 'value' => ['AI-201'], 'sort_order' => 2],
                    ]);
                } elseif ($code === 'LDR-101') {
                    $program->eligibilityRules()->createMany([
                        ['field' => 'job_category', 'operator' => 'eq', 'value' => 'leadership', 'sort_order' => 0,
                            'message_ar' => 'البرنامج مخصص لشاغلي الوظائف القيادية (مدير، نائب مدير، منسق)', 'message_en' => 'Reserved for leadership roles (principal, vice principal, coordinator)'],
                        ['field' => 'experience_years', 'operator' => 'gte', 'value' => 5, 'sort_order' => 1],
                    ]);
                } elseif ($code === 'CLM-110') {
                    $program->eligibilityRules()->create(['field' => 'job_title', 'operator' => 'in', 'value' => ['TEACHER', 'SENIOR_TEACHER'], 'sort_order' => 0]);
                    $program->targetGroups()->create(['job_title_id' => $teacher, 'description' => 'المعلمون الجدد والمعلمون']);
                } elseif ($code === 'RES-300') {
                    $program->eligibilityRules()->create(['field' => 'qualification', 'operator' => 'in', 'value' => ['bachelor', 'master', 'phd'], 'sort_order' => 0]);
                }
            }

            $programs[] = $program;
        }

        return $programs;
    }

    private function enrollments(array $programs, array $employees): void
    {
        $impact = app(ImpactService::class);
        $comments = [
            'برنامج ثري ومحتوى تطبيقي مباشر، طبقت الاستراتيجيات في فصلي من الأسبوع الأول.',
            'المدرب متمكن والأنشطة عملية جداً، أنصح به جميع الزملاء.',
            'أفضل برنامج تدريبي حضرته هذا العام، تنظيم احترافي ومتابعة مستمرة.',
            'ساعدني البرنامج على تحسين إدارة الوقت داخل الصف وزيادة تفاعل الطلاب.',
        ];

        foreach ($programs as $program) {
            if ($program->registrations()->exists()) {
                continue;
            }

            $pool = collect($employees)->shuffle()->take((int) min($program->capacity, mt_rand(12, 24)));
            $sessions = $program->sessions()->get();

            foreach ($pool as $employee) {
                $finished = $program->status === Program::STATUS_COMPLETED;
                $started = $program->status === Program::STATUS_IN_PROGRESS;

                $registration = Registration::create([
                    'program_id' => $program->id,
                    'employee_id' => $employee->id,
                    'source' => [Registration::SOURCE_SELF, Registration::SOURCE_SCHOOL, Registration::SOURCE_CENTER][mt_rand(0, 2)],
                    'status' => $finished || $started ? Registration::STATUS_APPROVED : [Registration::STATUS_APPROVED, Registration::STATUS_APPROVED, Registration::STATUS_PENDING][mt_rand(0, 2)],
                    'approved_at' => now()->subDays(mt_rand(10, 40)),
                    'eligibility_snapshot' => ['eligible' => true, 'status' => 'eligible'],
                    'created_at' => ($program->start_date ?? now())->copy()->subDays(mt_rand(5, 35)),
                ]);

                if (! $finished && ! $started) {
                    continue;
                }

                $attendedAll = mt_rand(1, 10) > 2;
                $minutes = 0;
                $total = 0;
                foreach ($sessions as $session) {
                    if ($session->starts_at->isFuture()) {
                        continue;
                    }
                    $total += $session->durationMinutes();
                    if ($attendedAll || mt_rand(0, 1)) {
                        $late = mt_rand(1, 10) === 1;
                        $in = $session->starts_at->copy()->addMinutes($late ? 20 : mt_rand(-10, 5));
                        $attended = (int) $in->max($session->starts_at)->diffInMinutes($session->ends_at);
                        Attendance::create([
                            'program_session_id' => $session->id, 'registration_id' => $registration->id, 'employee_id' => $employee->id,
                            'check_in_at' => $in, 'check_out_at' => $session->ends_at, 'method' => 'qr',
                            'status' => $late ? 'late' : 'present', 'minutes_attended' => $attended,
                        ]);
                        $minutes += $attended;
                    }
                }
                $percent = $total ? round($minutes / $total * 100, 2) : 0;
                $registration->update(['attendance_percent' => $percent]);

                if (! $finished) {
                    continue;
                }

                foreach ($program->tasks as $task) {
                    TaskSubmission::create([
                        'task_id' => $task->id, 'registration_id' => $registration->id, 'employee_id' => $employee->id,
                        'text_response' => 'خطة تطبيق مرفقة تتضمن الأهداف والأنشطة وأدوات التقويم.',
                        'status' => $percent >= 80 ? TaskSubmission::STATUS_APPROVED : TaskSubmission::STATUS_CHANGES,
                        'reviewed_at' => now()->subDays(20),
                    ]);
                }

                $ratings = ['content' => mt_rand(4, 5), 'trainer' => mt_rand(4, 5), 'organization' => mt_rand(3, 5), 'relevance' => mt_rand(3, 5)];
                Evaluation::create([
                    'program_id' => $program->id, 'registration_id' => $registration->id, 'employee_id' => $employee->id,
                    'ratings' => $ratings, 'satisfaction_score' => round(array_sum($ratings) / 20 * 100, 2),
                    'pre_test_score' => mt_rand(40, 65), 'post_test_score' => mt_rand(70, 98),
                    'comments' => $comments[array_rand($comments)], 'allow_testimonial' => mt_rand(0, 1) === 1,
                    'submitted_at' => $program->end_date,
                ]);

                if ($percent < 80) {
                    $registration->update(['certificate_status' => 'blocked', 'tasks_completed' => false, 'evaluation_completed' => true]);

                    continue;
                }

                $completedAt = Carbon::parse($program->end_date)->addDays(2);
                $registration->update([
                    'status' => Registration::STATUS_COMPLETED, 'completed_at' => $completedAt, 'certificate_status' => 'issued',
                    'tasks_completed' => true, 'evaluation_completed' => true,
                ]);
                Certificate::create([
                    'certificate_no' => 'TEDC-'.$completedAt->year.'-'.strtoupper(Str::random(6)),
                    'verification_code' => strtoupper(Str::random(12)),
                    'registration_id' => $registration->id, 'employee_id' => $employee->id, 'program_id' => $program->id,
                    'issued_at' => $completedAt, 'hours' => $program->total_hours, 'status' => 'valid',
                ]);
                foreach ($program->skills as $skill) {
                    $employee->skills()->syncWithoutDetaching([$skill->id => ['level' => $skill->pivot->target_level, 'source' => 'training', 'program_id' => $program->id, 'verified_at' => $completedAt]]);
                }

                $impact->scheduleFollowUps($registration);
                foreach ($registration->impactSurveys()->get() as $survey) {
                    if ($survey->scheduled_for->isFuture()) {
                        continue;
                    }
                    if (mt_rand(1, 10) <= 8) {
                        $applied = ['yes', 'yes', 'partially', 'no'][mt_rand(0, 3)];
                        $survey->update([
                            'status' => 'completed', 'sent_at' => $survey->scheduled_for, 'completed_at' => $survey->scheduled_for->copy()->addDays(mt_rand(1, 6)),
                            'applied_learning' => $applied, 'application_score' => ['yes' => mt_rand(80, 100), 'partially' => mt_rand(50, 75), 'no' => mt_rand(10, 30)][$applied],
                            'changes_observed' => 'تحسن ملحوظ في تفاعل الطلاب ومشاركتهم.',
                            'skills_improved' => $program->skills->pluck('name_ar')->all(),
                            'needs_support' => mt_rand(1, 5) === 1,
                        ]);
                    } else {
                        $survey->update(['status' => 'sent', 'sent_at' => $survey->scheduled_for]);
                    }
                }
                if ($survey = $registration->impactSurveys()->where('stage_days', 60)->where('status', 'completed')->first()) {
                    SupervisorEvaluation::create([
                        'registration_id' => $registration->id, 'program_id' => $program->id, 'employee_id' => $employee->id,
                        'supervisor_user_id' => $employee->supervisor?->user_id, 'application_score' => mt_rand(60, 98),
                        'behavior_change' => 'أصبح أكثر تنظيماً في إدارة الأنشطة الصفية.', 'submitted_at' => $survey->completed_at,
                    ]);
                }
                $impact->score($registration);
            }
        }

        // The showcase teacher gets a completed history and one active program.
        $teacher = Employee::whereHas('user', fn ($q) => $q->where('email', 'teacher@tedc.qa'))->first();
        $past = Program::where('code', 'ACT-100')->first();
        if ($teacher && $past && ! Registration::where('employee_id', $teacher->id)->exists()) {
            $reg = Registration::create([
                'program_id' => $past->id, 'employee_id' => $teacher->id, 'source' => Registration::SOURCE_SELF, 'status' => Registration::STATUS_COMPLETED,
                'attendance_percent' => 100, 'tasks_completed' => true, 'evaluation_completed' => true, 'certificate_status' => 'issued',
                'completed_at' => Carbon::parse($past->end_date)->addDay(), 'approved_at' => Carbon::parse($past->start_date)->subDays(5),
            ]);
            foreach ($past->sessions as $session) {
                Attendance::create([
                    'program_session_id' => $session->id, 'registration_id' => $reg->id, 'employee_id' => $teacher->id,
                    'check_in_at' => $session->starts_at->copy()->subMinutes(5), 'check_out_at' => $session->ends_at, 'method' => 'qr',
                    'status' => 'present', 'minutes_attended' => $session->durationMinutes(),
                ]);
            }
            Certificate::create([
                'certificate_no' => 'TEDC-'.now()->year.'-DEMO01', 'verification_code' => 'TEDCDEMO2026',
                'registration_id' => $reg->id, 'employee_id' => $teacher->id, 'program_id' => $past->id,
                'issued_at' => $reg->completed_at, 'hours' => $past->total_hours, 'status' => 'valid',
            ]);
            Evaluation::create([
                'program_id' => $past->id, 'registration_id' => $reg->id, 'employee_id' => $teacher->id,
                'ratings' => ['content' => 5, 'trainer' => 5, 'organization' => 5, 'relevance' => 5], 'satisfaction_score' => 100,
                'post_test_score' => 92, 'comments' => 'تجربة ملهمة غيرت طريقة تخطيطي للدروس.', 'allow_testimonial' => true, 'submitted_at' => $reg->completed_at,
            ]);
            $impact->scheduleFollowUps($reg);
            $impact->score($reg);

            $current = Program::where('code', 'CLM-110')->first();
            Registration::firstOrCreate(['program_id' => $current->id, 'employee_id' => $teacher->id], [
                'source' => Registration::SOURCE_SELF, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()->subDays(3),
            ]);
        }
    }

    private function trainingNeeds(array $schools): void
    {
        if (TrainingNeed::exists()) {
            return;
        }

        $skills = Skill::all()->keyBy('code');
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');
        $demand = [
            ['ai_in_education', 'critical', 'التوسع في استخدام التقنيات الحديثة ضمن الاستراتيجية الوطنية للتعليم'],
            ['classroom_management', 'high', 'ارتفاع أعداد المعلمين الجدد هذا العام الدراسي'],
            ['differentiated_instruction', 'high', 'تفاوت مستويات الطلاب في نتائج الاختبارات التشخيصية'],
            ['student_wellbeing', 'medium', 'زيادة حالات القلق والضغط النفسي لدى الطلاب'],
            ['data_driven_instruction', 'high', 'الحاجة لتحليل نتائج الاختبارات الوطنية'],
            ['inclusive_education', 'critical', 'دمج طلاب من ذوي الاحتياجات الخاصة'],
            ['arabic_literacy', 'high', 'ضعف مهارات القراءة لدى طلاب الصفوف الأولى'],
            ['stem_pedagogy', 'medium', 'تطوير مشاريع STEM للمشاركة في المسابقات الوطنية'],
        ];

        foreach ($schools as $school) {
            foreach (collect($demand)->shuffle()->take(mt_rand(2, 4)) as [$code, $priority, $reason]) {
                TrainingNeed::create([
                    'school_id' => $school->id, 'skill_id' => $skills[$code]->id, 'skill_name' => $skills[$code]->name_ar,
                    'employees_count' => mt_rand(3, 18), 'priority' => $priority, 'reason' => $reason,
                    'target_job_title_id' => $teacher, 'target_group' => 'المعلمون',
                    'status' => ['submitted', 'submitted', 'under_review', 'approved', 'planned'][mt_rand(0, 4)],
                    'created_at' => now()->subDays(mt_rand(1, 90)),
                ]);
            }
        }
    }

    private function news(): void
    {
        $items = [
            ['news', 'إطلاق البرنامج الوطني للذكاء الاصطناعي في التعليم', 'National AI in Education Program Launched', 'أعلن المركز عن إطلاق برنامج نوعي لتمكين المعلمين من توظيف أدوات الذكاء الاصطناعي بصورة آمنة ومسؤولة، بالتعاون مع وزارة التربية والتعليم والتعليم العالي.', 'The center launched a flagship program enabling teachers to use AI tools safely and responsibly, in cooperation with the Ministry of Education and Higher Education.', 3, '/images/news/ai.jpg'],
            ['news', 'تخريج الدفعة الخامسة من برنامج القيادة التربوية', 'Fifth Cohort Graduates from Educational Leadership Program', 'احتفل المركز بتخريج 30 قائداً تربوياً من مختلف مدارس الدولة بعد إتمامهم متطلبات البرنامج بنجاح.', 'The center celebrated the graduation of 30 school leaders from across the country.', 12, '/images/news/graduation.jpg'],
            ['announcement', 'فتح باب التسجيل في برامج الفصل الدراسي الأول', 'Registration Opens for First Semester Programs', 'يسر المركز الإعلان عن فتح باب التسجيل في البرامج التدريبية للفصل الدراسي الأول عبر المنصة والتطبيق.', 'Registration is now open for first-semester training programs via the platform and mobile app.', 20, '/images/news/registration.jpg'],
            ['news', 'شراكة استراتيجية لتطوير قياس أثر التدريب', 'Strategic Partnership on Training Impact Measurement', 'وقّع المركز مذكرة تفاهم لتطوير منهجية قياس أثر التدريب على الأداء المهني وتعلم الطلاب.', 'The center signed an MoU to advance training impact measurement on professional performance and student learning.', 35, '/images/news/partnership.jpg'],
        ];

        foreach ($items as [$type, $ar, $en, $bodyAr, $bodyEn, $days, $cover]) {
            Announcement::updateOrCreate(['title_en' => $en], [
                'type' => $type, 'title_ar' => $ar, 'body_ar' => $bodyAr, 'body_en' => $bodyEn,
                'audience' => 'all', 'is_public' => true, 'published_at' => now()->subDays($days), 'cover_path' => $cover,
            ]);
        }
    }
}
