<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\NeedsSurvey;
use App\Models\NeedsSurveyRecipient;
use App\Models\NeedsSurveyResponse;
use App\Models\User;
use App\Services\NeedsSurveys\SurveySchema;
use App\Services\NeedsSurveys\SurveyTemplates;
use Illuminate\Database\Seeder;

/**
 * Demo training-needs survey with realistic answers, so the survey report and the
 * "generate training needs" flow can be explored right away. Runs once (idempotent).
 */
class DemoNeedsSurveySeeder extends Seeder
{
    private const SPECIALIZATIONS = ['اللغة العربية', 'الرياضيات', 'العلوم', 'اللغة الإنجليزية', 'التربية الإسلامية', 'الحاسب الآلي', 'الدراسات الاجتماعية', 'التربية الخاصة'];

    public function run(): void
    {
        if (NeedsSurvey::query()->exists() || Employee::query()->doesntExist()) {
            return;
        }
        mt_srand(2030);

        // Demo employees have no specialization yet: give them one so the audience filters have values.
        Employee::whereNull('specialization')->orderBy('employee_no')->get()->each(fn (Employee $e, int $i) => $e->updateQuietly(['specialization' => self::SPECIALIZATIONS[$i % count(self::SPECIALIZATIONS)]]));

        $templates = app(SurveyTemplates::class);
        $template = $templates->find('teacher_tna');
        $admin = User::where('email', 'center@tedc.qa')->first() ?? User::first();

        $survey = NeedsSurvey::create([
            'title' => 'الاحتياجات التدريبية للمعلمين 2026 – 2027',
            'description' => $template['description'],
            'source' => 'template',
            'template_key' => 'teacher_tna',
            'questions' => SurveySchema::normalize($template['questions']),
            'audience' => [],
            'settings' => ['accent' => $template['accent'], 'show_progress' => true, 'thank_you' => 'شكراً لمشاركتك، إجاباتك تصنع برامج العام القادم.'],
            'status' => 'published',
            'published_at' => now()->subDays(12),
            'closes_at' => now()->addDays(18),
            'created_by' => $admin?->id,
        ]);

        // Weakest areas in the demo: AI, differentiation and inclusion.
        $bias = ['r1' => 3.7, 'r2' => 2.9, 'r3' => 3.6, 'r4' => 3.3, 'r5' => 3.0, 'r6' => 2.7, 'r7' => 3.6];
        $digitalBias = ['r1' => 2.8, 'r2' => 3.2, 'r3' => 3.4];
        $score = fn (float $mean) => max(1, min(5, (int) round($mean + (mt_rand(-150, 150) / 100))));

        $employees = Employee::with('user')->where('status', 'active')->limit(400)->get();
        foreach ($employees as $employee) {
            $recipient = NeedsSurveyRecipient::create([
                'survey_id' => $survey->id, 'user_id' => $employee->user_id, 'employee_id' => $employee->id,
                'notified_at' => $survey->published_at,
            ]);
            if (mt_rand(1, 100) > 72 || in_array($employee->user?->email, ['teacher@tedc.qa'], true)) {
                continue; // not answered yet (the demo teacher can still answer)
            }

            $novice = $employee->experience_years < 5 ? -0.5 : 0.3;
            $teach = collect($bias)->map(fn ($m) => $score($m + $novice))->all();
            $digital = collect($digitalBias)->map(fn ($m) => $score($m))->all();
            $weakest = collect(['o1' => $teach['r1'], 'o2' => $teach['r2'], 'o3' => $teach['r4'], 'o4' => $digital['r1'], 'o5' => $teach['r6'], 'o6' => mt_rand(2, 5), 'o7' => mt_rand(2, 5), 'o8' => mt_rand(2, 5)])
                ->sort()->keys()->take(3)->values()->all();
            $at = $survey->published_at->copy()->addMinutes(mt_rand(30, 60 * 24 * 11));

            NeedsSurveyResponse::create([
                'survey_id' => $survey->id,
                'user_id' => $employee->user_id,
                'school_id' => $employee->school_id,
                'job_title_id' => $employee->job_title_id,
                'experience_years' => $employee->experience_years,
                'specialization' => $employee->specialization,
                'nationality' => $employee->nationality,
                'gender' => $employee->gender,
                'education_stage' => $employee->education_stage,
                'answers' => [
                    'teach' => $teach,
                    'digital' => $digital,
                    'top' => $weakest,
                    'format' => ['in_person', 'blended', 'blended', 'online', 'self_paced'][mt_rand(0, 4)],
                    'timing' => ['morning', 'afternoon', 'weekend', 'holidays', 'morning'][mt_rand(0, 4)],
                ] + (mt_rand(1, 6) === 1 ? ['other' => ['تدريب على إعداد الاختبارات الإلكترونية', 'مهارات الإلقاء والتواصل', 'تصميم المشاريع الطلابية', 'التعامل مع صعوبات التعلم'][mt_rand(0, 3)]] : []),
                'duration_seconds' => mt_rand(140, 520),
                'submitted_at' => $at,
            ]);
            $recipient->update(['responded_at' => $at]);
        }
    }
}
