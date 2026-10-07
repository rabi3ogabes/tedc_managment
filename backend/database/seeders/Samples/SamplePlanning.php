<?php

namespace Database\Seeders\Samples;

use App\Models\Employee;
use App\Models\IndividualNeed;
use App\Models\InstitutionalRequest;
use App\Models\NeedsCycle;
use App\Models\Nomination;
use App\Models\ProgramProposal;
use App\Models\Registration;
use App\Models\RegistrationForm;
use App\Models\RegistrationRequest;
use App\Models\Skill;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanChange;
use App\Models\TrainingPlanItem;
use App\Models\WithdrawalRequest;

/** The annual plan and the needs cycle with proposals, requests and individual needs; nominations, withdrawals and open registration forms. */
class SamplePlanning
{
    use Steps;

    public function __construct(private readonly SampleContext $c) {}

    public function run(): void
    {
        $this->step('plan', fn () => $this->plan());
        $this->step('needs', fn () => $this->needs());
        $this->step('registrations', fn () => $this->registrations());
    }

    private function plan(): void
    {
        $head = $this->c->user('planning@tedc.qa') ?? $this->c->admin();
        $year = (int) date('Y') + 1;
        if (TrainingPlan::where('year', $year)->exists()) {
            return;
        }
        $plan = TrainingPlan::create(['year' => $year, 'version' => 1, 'title_ar' => "الخطة التدريبية السنوية {$year}", 'title_en' => "Annual training plan {$year}", 'status' => 'in_review', 'rules' => [], 'submitted_by' => $head->id, 'submitted_at' => now()->subDays(2), 'notes' => 'نسخة تجريبية للمراجعة.']);
        $rows = [
            ['التقويم التكويني في الصف', 'Formative assessment in class', 'high', 12, 360, 24, 'needs'],
            ['القيادة التربوية لمديري المدارس', 'Educational leadership for principals', 'high', 6, 150, 30, 'needs'],
            ['توظيف الذكاء الاصطناعي في التعليم', 'AI in education', 'medium', 8, 240, 18, 'manual'],
            ['الدعم النفسي والاجتماعي للطلبة', 'Psychosocial support for students', 'medium', 5, 125, 12, 'carry_over'],
            ['ورشة طارئة: استعداد الاختبارات الوطنية', 'Emergency: national exam readiness', 'high', 3, 90, 6, 'emergency'],
        ];
        foreach ($rows as $i => [$ar, $en, $prio, $groups, $seats, $hours, $src]) {
            $item = TrainingPlanItem::create(['plan_id' => $plan->id, 'title_ar' => $ar, 'title_en' => $en, 'audience' => 'معلمو المدارس', 'priority' => $prio, 'priority_score' => 90 - $i * 8, 'planned_groups' => $groups, 'planned_seats' => $seats, 'planned_hours' => $hours,
                'window_start' => now()->addMonths(2 + $i)->startOfMonth(), 'window_end' => now()->addMonths(3 + $i)->startOfMonth(), 'source' => $src, 'status' => 'planned', 'is_emergency' => $src === 'emergency', 'rationale_ar' => 'استنادًا إلى فجوات الكفايات ونتائج الأثر.', 'rationale_en' => 'Based on competency gaps and impact results.']);
            if ($i === 2) {
                TrainingPlanChange::create(['plan_id' => $plan->id, 'item_id' => $item->id, 'change_type' => 'modified', 'before' => ['planned_seats' => 200], 'after' => ['planned_seats' => 240], 'reason' => 'زيادة الطلب بحسب الاستبيان.', 'changed_by' => $head->id]);
            }
        }
    }

    private function needs(): void
    {
        $admin = $this->c->admin();
        $school = $this->c->user('school@tedc.qa') ?? $admin;
        $year = (int) date('Y');
        $cycle = NeedsCycle::firstOrCreate(['year' => $year, 'title_en' => "Training needs cycle {$year}"], ['title_ar' => "دورة الاحتياجات التدريبية {$year}", 'opens_at' => now()->subDays(20), 'closes_at' => now()->addDays(25), 'status' => 'open', 'settings' => []]);
        if (ProgramProposal::where('cycle_id', $cycle->id)->exists()) {
            return;
        }
        $p1 = ProgramProposal::create(['cycle_id' => $cycle->id, 'entity_type' => 'school', 'entity_name' => 'مدرسة تجريبية', 'submitted_by' => $school->id, 'program_title_ar' => 'استراتيجيات التعلم التعاوني', 'program_title_en' => 'Cooperative learning strategies', 'groups_count' => 2, 'axes' => ['التعلم التعاوني', 'إدارة المجموعات'], 'target_description' => 'معلمو المرحلة الابتدائية', 'days' => 2, 'hours' => 12, 'kit_availability' => 'none', 'importance' => 'high', 'priority_rank' => 1, 'justification' => 'ضعف مهارات العمل الجماعي في نتائج التقييم الداخلي.', 'status' => 'submitted']);
        ProgramProposal::create(['cycle_id' => $cycle->id, 'entity_type' => 'school', 'entity_name' => 'مدرسة تجريبية', 'submitted_by' => $school->id, 'program_title_ar' => 'تصميم الاختبارات الإلكترونية', 'program_title_en' => 'Designing online tests', 'groups_count' => 1, 'axes' => ['بنوك الأسئلة'], 'days' => 1, 'hours' => 6, 'importance' => 'medium', 'priority_rank' => 2, 'justification' => 'التحول إلى الاختبارات الرقمية.', 'status' => 'approved', 'reviewer_id' => $admin->id, 'review_note' => 'يُدرج في الخطة.']);
        InstitutionalRequest::create(['cycle_id' => $cycle->id, 'requested_by' => $school->id, 'entity_name' => 'مدرسة تجريبية', 'title' => 'تدريب فريق الدعم الفني على المنصة', 'need_degree' => 'high', 'objectives' => 'تمكين الفريق من حل المشكلات الأساسية.', 'preferred_window' => 'الفصل الثاني', 'status' => 'submitted']);
        $skill = Skill::query()->first();
        foreach ($this->c->trainees() as $i => $u) {
            if ($e = $u->employee) {
                IndividualNeed::firstOrCreate(['employee_id' => $e->id, 'skill_id' => $skill?->id, 'source' => 'self'], ['cycle_id' => $cycle->id, 'current_level' => 2, 'required_level' => 4, 'gap' => 2, 'priority_score' => 70 - $i * 5, 'explanation_ar' => 'فجوة بين مستواك الحالي والمطلوب لوظيفتك.', 'explanation_en' => 'Gap between your level and your job requirement.', 'status' => $i === 0 ? 'approved' : 'submitted']);
            }
        }
    }

    private function registrations(): void
    {
        $admin = $this->c->admin();
        $school = $this->c->user('school@tedc.qa') ?? $admin;
        $program = $this->c->program('TEST-P1');
        $e = $this->c->trainees()[0]?->employee;
        if ($program && $e && ! Nomination::where('program_id', $program->id)->exists()) {
            $other = Employee::where('id', '!=', $e->id)->whereDoesntHave('registrations', fn ($q) => $q->where('program_id', $program->id))->first();
            if ($other) {
                Nomination::create(['program_id' => $program->id, 'employee_id' => $other->id, 'school_id' => $other->school_id, 'nominated_by' => $school->id, 'nominator_type' => 'school_admin', 'justification' => 'مرشح لسد فجوة في التقويم.', 'status' => 'pending']);
            }
        }
        $reg = $program && $e ? Registration::where('program_id', $program->id)->where('employee_id', $e->id)->first() : null;
        if ($reg && ! WithdrawalRequest::where('registration_id', $reg->id)->exists()) {
            WithdrawalRequest::create(['registration_id' => $reg->id, 'requested_by' => $e->user_id, 'reason_code' => 'work_assignment', 'reason_text' => 'تكليف عمل طارئ في نفس الفترة.', 'timing' => 'before_start', 'stage' => 'manager', 'status' => 'pending', 'is_late' => false]);
        }
        $form = RegistrationForm::firstOrCreate(['slug' => 'open-workshop'], ['title_ar' => 'التسجيل في الورشة المفتوحة', 'title_en' => 'Open workshop registration', 'intro_ar' => 'سجّل بياناتك للمشاركة في الورشة المفتوحة.', 'intro_en' => 'Register your details for the open workshop.', 'audience' => 'external',
            'fields' => [['key' => 'name', 'type' => 'short_text', 'label_ar' => 'الاسم', 'label_en' => 'Name', 'required' => true], ['key' => 'employer', 'type' => 'short_text', 'label_ar' => 'جهة العمل', 'label_en' => 'Employer', 'required' => false]], 'conditions' => [], 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(30), 'is_active' => true]);
        RegistrationRequest::firstOrCreate(['number' => 'RQ-SAMPLE-001'], ['form_id' => $form->id, 'data' => ['name' => 'سارة العلي', 'employer' => 'مؤسسة خاصة'], 'email' => 'sara.sample@example.qa', 'phone' => '55501234', 'status' => 'new']);
    }
}
