<?php

namespace Database\Seeders\Samples;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\CareerPath;
use App\Models\CareerPathLevel;
use App\Models\ClassroomObservation;
use App\Models\CompetencyDomain;
use App\Models\EmployeePathProgress;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\EvaluationInterview;
use App\Models\EvaluationResponse;
use App\Models\JobGroup;
use App\Models\KnowledgeTransfer;
use App\Models\PassException;
use App\Models\PassingPolicy;
use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\PdAnnualTarget;
use App\Models\PdRecognitionRequest;
use App\Models\PerformanceAppraisal;
use App\Models\ProfessionalLicence;
use App\Models\Program;
use App\Models\ProgramEquivalence;
use App\Models\ProgramEvaluationReport;
use App\Models\ProgramUnit;
use App\Models\SatisfactionAlert;
use App\Services\EvaluationService;

/** Passing rules and exceptions, attempts, evaluation rounds, interviews and reports, observations and appraisals, career paths, licences, professional development and knowledge transfer. */
class SampleLearning
{
    use Steps;

    public function __construct(private readonly SampleContext $c) {}

    public function run(): void
    {
        $this->step('passing', fn () => $this->passing());
        $this->step('attempts', fn () => $this->attempts());
        $this->step('evaluation', fn () => $this->evaluation());
        $this->step('appraisal', fn () => $this->appraisal());
        $this->step('career', fn () => $this->career());
        $this->step('pd', fn () => $this->pd());
        $this->step('structure', fn () => $this->structure());
    }

    private function passing(): void
    {
        $p = $this->c->program('TEST-P1');
        $admin = $this->c->admin();
        if (! $p || PassingPolicy::where('scope', 'program')->where('scope_id', $p->id)->exists()) {
            return;
        }
        PassingPolicy::create(['scope' => 'program', 'scope_id' => $p->id, 'mode' => 'weighted', 'pass_threshold' => 70, 'criteria' => [['key' => 'attendance', 'weight' => 40], ['key' => 'tasks', 'weight' => 20], ['key' => 'assessments', 'weight' => 40]], 'assessment_ids' => [], 'participation_rules' => [], 'allow_test_out' => false, 'hours_mode' => 'actual', 'certificate_types' => 'both', 'attendance_certificate_min' => 60, 'survey_required_for_download' => false, 'task_approval' => 'trainer_then_supervisor', 'updated_by' => $admin->id]);
        if (($u = $this->c->trainees()[3] ?? null) && ($reg = $this->c->registration($u, $p))) {
            PassException::create(['registration_id' => $reg->id, 'criterion' => 'attendance', 'reason' => 'غياب بعذر طبي موثق لحالة طارئة.', 'granted_by' => $admin->id, 'granted_at' => now()->subDays(2)]);
        }
    }

    private function attempts(): void
    {
        $a = Assessment::with('sections')->first();
        if (! $a || AssessmentAttempt::query()->exists()) {
            return;
        }
        foreach ($this->c->trainees() as $i => $u) {
            $reg = $this->c->registration($u, Program::find($a->program_id));
            if (! $reg) {
                continue;
            }
            AssessmentAttempt::create(['assessment_id' => $a->id, 'registration_id' => $reg->id, 'attempt_no' => 1, 'started_at' => now()->subDays(3 + $i), 'submitted_at' => now()->subDays(3 + $i)->addMinutes(18), 'delivery' => 'online', 'questions' => [], 'answers' => [], 'auto_score' => 6 + $i, 'manual_score' => 0, 'max_score' => 10, 'score_percent' => (6 + $i) * 10, 'passed' => (6 + $i) >= 7, 'status' => 'graded', 'graded_at' => now()->subDays(2)]);
        }
    }

    private function evaluation(): void
    {
        $prog = $this->c->program('TEST-P1');
        $admin = $this->c->admin();
        $trainer = $this->c->user('trainer@tedc.qa') ?? $admin;
        if (! $prog || EvaluationAssignment::query()->exists()) {
            return;
        }
        app(EvaluationService::class)->defaultFor('satisfaction');   // makes sure the standard forms exist
        $form = EvaluationForm::where('kind', 'satisfaction')->first() ?? EvaluationForm::first();
        $withQuestions = EvaluationForm::whereNotNull('questions')->get()->first(fn ($f) => count($f->questions ?? []) > 0) ?? $form;
        $qs = collect($withQuestions->questions ?? [])->pluck('id')->take(4)->all();
        foreach ($this->c->trainees() as $i => $u) {
            $a = EvaluationAssignment::create(['form_id' => $withQuestions->id, 'program_id' => $prog->id, 'respondent_type' => 'trainee', 'respondent_user_id' => $u->id, 'due_at' => now()->addDays(5), 'sent_at' => now()->subDay(), 'status' => $i < 3 ? 'submitted' : 'pending', 'assigned_by' => $admin->id]);
            if ($i < 3) {
                EvaluationResponse::create(['assignment_id' => $a->id, 'answers' => collect($qs)->mapWithKeys(fn ($q) => [$q => 4 + ($i % 2)])->all(), 'form_version' => $withQuestions->version, 'score' => 80 + $i * 5, 'submitted_at' => now()->subHours(10 + $i)]);
            }
        }
        EvaluationInterview::create(['program_id' => $prog->id, 'interviewee_name' => 'مديرة مدرسة تجريبية', 'interviewer_id' => $admin->id, 'held_at' => now()->subDays(4), 'method' => 'phone', 'questions_answers' => [['question' => 'ما أثر البرنامج في الصف؟', 'answer' => 'لاحظنا تحسنًا في تفاعل الطلبة.']], 'summary' => 'انطباع إيجابي عن الأثر مع طلب برامج متقدمة.', 'sentiment' => 'positive']);
        SatisfactionAlert::create(['program_id' => $prog->id, 'response_rate' => 62.5, 'average' => 58.0, 'threshold' => 70, 'notified_at' => now()->subDay()]);
        ProgramEvaluationReport::create(['program_id' => $prog->id, 'period' => date('Y'), 'metrics' => ['satisfaction' => 86.8, 'attendance' => 91, 'pass_rate' => 78], 'qualitative' => ['themes' => ['تطبيق عملي', 'وقت إضافي']], 'classification' => 'effective', 'classification_reasons' => ['رضا مرتفع', 'أثر ملموس'], 'recommendations_ar' => 'التوسع في المجموعات القادمة وإضافة تدريب عملي.', 'recommendations_en' => 'Expand the next groups and add practice time.', 'status' => 'draft', 'prepared_by' => $admin->id]);
        foreach (array_slice($this->c->trainees(), 0, 2) as $u) {
            if ($e = $u->employee) {
                ClassroomObservation::create(['employee_id' => $e->id, 'observer_user_id' => $trainer->id, 'observer_role' => 'trainer', 'observed_on' => now()->subDays(6)->toDateString(), 'subject' => 'اللغة العربية', 'grade' => 'الصف الرابع', 'scores' => ['planning' => 4, 'delivery' => 4, 'assessment' => 3], 'overall' => 4, 'notes' => 'درس منظم مع حاجة لتنويع أسئلة التقويم.', 'source' => 'training']);
            }
        }
    }

    private function appraisal(): void
    {
        foreach ($this->c->trainees() as $i => $u) {
            if ($e = $u->employee) {
                PerformanceAppraisal::firstOrCreate(['employee_id' => $e->id, 'year' => (int) date('Y') - 1], ['rating_code' => ['E', 'VG', 'G', 'NI'][$i % 4], 'score' => 90 - $i * 8, 'source' => 'hr_import', 'imported_at' => now()->subMonths(2)]);
            }
        }
    }

    private function career(): void
    {
        $path = CareerPath::firstOrCreate(['title_en' => 'Teacher promotion path'], ['type' => 'promotion', 'title_ar' => 'مسار ترقية المعلم', 'description_ar' => 'من معلم إلى معلم أول إلى خبير.', 'description_en' => 'From teacher to senior teacher to expert.', 'job_title_ids' => [], 'is_active' => true]);
        foreach ([[1, 'معلم', 'Teacher', 0], [2, 'معلم أول', 'Senior teacher', 40], [3, 'معلم خبير', 'Expert teacher', 90]] as [$n, $ar, $en, $hours]) {
            CareerPathLevel::firstOrCreate(['path_id' => $path->id, 'level_no' => $n], ['title_ar' => $ar, 'title_en' => $en, 'conditions' => [], 'required_programs' => [], 'min_pd_hours' => $hours, 'validity_months' => 36, 'renewal_conditions' => []]);
        }
        foreach ($this->c->trainees() as $i => $u) {
            if ($e = $u->employee) {
                EmployeePathProgress::firstOrCreate(['employee_id' => $e->id, 'path_id' => $path->id], ['current_level_no' => 1 + ($i > 1 ? 1 : 0), 'target_level_no' => 3, 'status' => $i === 3 ? 'eligible' : 'in_progress', 'explanation' => ['hours' => 20 + $i * 10], 'evaluated_at' => now()->subDay()]);
                ProfessionalLicence::firstOrCreate(['employee_id' => $e->id, 'licence_no' => 'LIC-SMP-'.(100 + $i)], ['path_id' => $path->id, 'level_no' => 1, 'issued_at' => now()->subYear()->toDateString(), 'expires_at' => now()->addMonths($i === 0 ? 1 : 24)->toDateString(), 'status' => 'active', 'source' => 'manual']);
            }
        }
        JobGroup::firstOrCreate(['name_en' => 'Primary school teachers'], ['name_ar' => 'معلمو المرحلة الابتدائية', 'rule' => ['education_stage' => 'primary']]);
    }

    private function pd(): void
    {
        $admin = $this->c->admin();
        $types = [];
        foreach ([['WORKSHOP', 'ورشة تدريبية', 'Workshop'], ['CONF', 'مؤتمر', 'Conference'], ['PEER', 'زيارة صفية تبادلية', 'Peer visit']] as [$code, $ar, $en]) {
            $types[$code] = PdActivityType::firstOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en, 'hour_rules' => ['attendee' => ['factor' => 1], 'presenter' => ['factor' => 1.5], 'organiser' => ['factor' => 2], 'author' => ['factor' => 2.5], 'cap_year' => 60], 'evidence_required' => false, 'is_active' => true]);
        }
        PdAnnualTarget::firstOrCreate(['year' => (int) date('Y')], ['audience' => ['all' => true], 'min_hours' => 40, 'counts' => ['programs' => true, 'pd' => true]]);
        foreach ($this->c->trainees() as $i => $u) {
            $e = $u->employee;
            if (! $e) {
                continue;
            }
            $act = PdActivity::firstOrCreate(['employee_id' => $e->id, 'title' => 'مؤتمر التعليم الرقمي'], ['type_id' => $types['CONF']->id, 'provider' => 'جامعة قطر', 'domain' => 'digital', 'starts_on' => now()->subDays(20 + $i)->toDateString(), 'ends_on' => now()->subDays(19 + $i)->toDateString(), 'duration_hours' => 8, 'participation_level' => 'attendee', 'computed_hours' => 8, 'approved_hours' => $i % 2 ? null : 8, 'status' => $i % 2 ? 'pending' : 'approved', 'decided_at' => $i % 2 ? null : now()->subDays(10)]);
            if ($i === 0) {
                PdRecognitionRequest::firstOrCreate(['pd_activity_id' => $act->id], ['center_decision' => 'pending']);
            }
            if ($reg = $this->c->registration($u)) {
                KnowledgeTransfer::firstOrCreate(['registration_id' => $reg->id, 'employee_id' => $e->id], ['due_on' => now()->addDays(14)->toDateString(), 'delivered_on' => $i === 0 ? now()->subDay()->toDateString() : null, 'hours' => 2, 'beneficiary_count' => $i === 0 ? 12 : 0, 'method' => 'workshop', 'status' => $i === 0 ? 'pending_review' : 'pending']);
            }
        }
    }

    private function structure(): void
    {
        CompetencyDomain::firstOrCreate(['code' => 'SMP-PED'], ['name_ar' => 'الكفايات التربوية', 'name_en' => 'Pedagogical competencies', 'sort_order' => 1]);
        $a = $this->c->program('TEST-P1');
        $b = $this->c->program('TEST-P2');
        if ($a && $b) {
            ProgramEquivalence::firstOrCreate(['program_id' => $a->id, 'equivalent_program_id' => $b->id], ['bidirectional' => true, 'note' => 'برنامجان متكافئان في المحتوى.']);
        }
        if ($a) {
            foreach ([['الوحدة الأولى: التخطيط', 'Unit 1: Planning', 4], ['الوحدة الثانية: التنفيذ', 'Unit 2: Delivery', 4], ['الوحدة الثالثة: التقويم', 'Unit 3: Assessment', 4]] as $i => [$ar, $en, $h]) {
                ProgramUnit::firstOrCreate(['program_id' => $a->id, 'title_en' => $en], ['sort_order' => $i + 1, 'title_ar' => $ar, 'objectives' => [], 'hours' => $h, 'summary_ar' => 'ملخص الوحدة.', 'summary_en' => 'Unit summary.']);
            }
        }
    }
}
