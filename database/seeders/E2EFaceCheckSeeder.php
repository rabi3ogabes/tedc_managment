<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Program;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\User;
use App\Services\FeatureSettings;
use Illuminate\Database\Seeder;

/**
 * The fixture for the browser smoke test of the camera presence check (web/e2e/face-presence.spec.ts): a fast exam
 * (three seconds without a face is an event, the first event is a flag) for the local test learner, started fresh on
 * every run. Development only; it refuses to run on production. Needs the demo and sample data.
 */
class E2EFaceCheckSeeder extends Seeder
{
    public const TITLE = 'E2E camera check';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('The E2E fixture never runs on production.');

            return;
        }
        $admin = User::where('email', 'admin@tedc.qa')->firstOrFail();
        app(FeatureSettings::class)->set('proctoring', true, 'Browser smoke test of the camera presence check', $admin);

        $learner = User::where('email', 'trainee4@tedc.qa')->with('employee')->firstOrFail();
        $program = Program::where('code', 'EKIT-QUESTIONING')->firstOrFail();
        $bank = QuestionBank::where('title_en', 'Adaptive practice bank: questioning')->firstOrFail();
        $reg = Registration::firstOrCreate(['program_id' => $program->id, 'employee_id' => $learner->employee->id], ['source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
        if ($reg->status !== Registration::STATUS_APPROVED) {
            $reg->update(['status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
        }

        $exam = Assessment::firstOrCreate(['program_id' => $program->id, 'title_en' => self::TITLE], [
            'kind' => 'quiz', 'title_ar' => 'اختبار آلي: التحقق بالكاميرا', 'time_limit_minutes' => 30, 'max_attempts' => 100, 'pass_percent' => 60, 'feedback_mode' => 'after_submit', 'status' => 'published',
            'proctoring' => ['enabled' => true, 'action' => 'flag', 'face_check' => true, 'face_absent_seconds' => 3, 'face_absent_limit' => 0, 'multiple_faces_limit' => 1, 'looking_away_limit' => 5,
                'face_check_interval_ms' => 1000, 'face_fallback' => 'flagged'],
        ]);
        if (! $exam->sections()->exists()) {
            $exam->sections()->create(['selection' => 'random', 'bank_id' => $bank->id, 'count' => 3, 'points_per_question' => 1, 'sort_order' => 0]);
        }
        // Every run starts from a clean slate.
        AssessmentAttempt::where('assessment_id', $exam->id)->where('registration_id', $reg->id)->delete();

        $this->command?->line('E2E_FACE '.json_encode(['assessment_id' => $exam->id, 'program_id' => $program->id, 'title_ar' => $exam->title_ar, 'title_en' => $exam->title_en]));
    }
}
