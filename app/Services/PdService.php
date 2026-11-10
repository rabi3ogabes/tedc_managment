<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\PdRecognitionRequest;
use App\Models\User;

/** External professional development: hours from the type's rules, the manager's approval, recognition by the centre. */
class PdService
{
    public const LEVELS = ['attendee', 'presenter', 'organiser', 'author'];

    public function __construct(private readonly NotificationService $notifications, private readonly RegistrationService $registrations, private readonly AnnualHoursService $hours, private readonly CareerPathEngine $paths) {}

    /** Declared hours × the factor of the participation level, capped per activity. */
    public function compute(PdActivityType $type, string $level, float $declared): float
    {
        $rule = $type->hour_rules[$level] ?? ['factor' => 1];
        $hours = $declared * (float) ($rule['factor'] ?? 1);
        if (isset($rule['cap_activity']) && $rule['cap_activity'] !== null) {
            $hours = min($hours, (float) $rule['cap_activity']);
        }

        return round($hours, 1);
    }

    public function save(Employee $employee, array $data, ?PdActivity $existing = null): PdActivity
    {
        $type = PdActivityType::where('is_active', true)->findOrFail($data['type_id']);
        if ($existing && ! in_array($existing->status, ['draft', 'returned'], true)) {
            throw new BusinessRuleException(__('messages.pd.locked'), 'pd_locked');
        }
        $computed = $this->compute($type, $data['participation_level'] ?? 'attendee', (float) $data['duration_hours']);
        $attrs = $data + ['computed_hours' => $computed];
        $attrs['computed_hours'] = $computed;

        return $existing ? tap($existing)->update($attrs) : PdActivity::create($attrs + ['employee_id' => $employee->id, 'status' => 'draft']);
    }

    public function submit(PdActivity $a): PdActivity
    {
        $a->loadMissing('type', 'employee.user');
        if (! in_array($a->status, ['draft', 'returned'], true)) {
            throw new BusinessRuleException(__('messages.pd.locked'), 'pd_locked');
        }
        if ($a->type->evidence_required && empty($a->evidence)) {
            throw new BusinessRuleException(__('messages.pd.evidence_required'), 'evidence_required');
        }
        $manager = $this->registrations->resolveManager($a->employee);
        $a->update(['status' => 'pending_manager', 'manager_id' => $manager?->id, 'manager_note' => null]);

        $name = $a->employee->user->displayName();
        $recipients = $manager ? [$manager->id] : User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'pd.approve'))->where('status', 'active')->pluck('id')->all();
        foreach ($recipients as $uid) {
            $this->notifications->send($uid, 'pd.submitted', ['ar' => 'نشاط تطوير مهني بانتظار اعتمادك', 'en' => 'A professional-development activity awaits your approval'], ['ar' => "{$name}: {$a->title} ({$a->computed_hours} ساعة)", 'en' => "{$name}: {$a->title} ({$a->computed_hours} h)"], ['pd_activity_id' => $a->id]);
        }

        return $a;
    }

    /** @param  'approve'|'reject'|'return'  $decision */
    public function decide(PdActivity $a, string $decision, ?string $note, User $by, ?float $hours = null): PdActivity
    {
        if ($a->status !== 'pending_manager') {
            throw new BusinessRuleException(__('messages.pd.not_pending'), 'pd_not_pending');
        }
        if ($decision !== 'approve' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.pd.note_required'), 'note_required');
        }
        $a->loadMissing('type', 'employee.user');
        $approved = null;
        if ($decision === 'approve') {
            $approved = $this->capped($a, min((float) ($hours ?? $a->computed_hours), (float) $a->computed_hours));
        }
        $a->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$decision], 'manager_id' => $by->id, 'manager_note' => $note, 'decided_at' => now(), 'approved_hours' => $approved]);

        if ($decision === 'approve' && $a->recognition_request) {
            PdRecognitionRequest::firstOrCreate(['pd_activity_id' => $a->id]);
        }
        $verb = ['approve' => ['اعتُمد', 'approved'], 'reject' => ['رُفض', 'rejected'], 'return' => ['أُعيد للتعديل', 'returned']][$decision];
        $this->notifications->send($a->employee->user_id, 'pd.decided', ['ar' => 'قرار بشأن نشاط تطويرك المهني', 'en' => 'Decision on your professional-development activity'],
            ['ar' => "{$verb[0]} نشاط «{$a->title}»".($note ? " — {$note}" : ''), 'en' => "\"{$a->title}\" was {$verb[1]}".($note ? " — {$note}" : '')], ['pd_activity_id' => $a->id]);
        $decision === 'approve' && $this->paths->evaluate($a->employee);

        return $a->refresh();
    }

    /** The per-year cap of the type is enforced on approval. */
    private function capped(PdActivity $a, float $hours): float
    {
        $cap = $a->type->hour_rules['cap_year'] ?? null;
        if ($cap === null) {
            return round($hours, 1);
        }
        $used = (float) PdActivity::where('employee_id', $a->employee_id)->where('type_id', $a->type_id)->where('status', 'approved')->where('id', '!=', $a->id)
            ->whereBetween('starts_on', $this->hours->bounds($this->hours->yearOf($a->starts_on)))->get()->sum(fn ($x) => (float) ($x->approved_hours ?? $x->computed_hours));

        return round(max(0.0, min($hours, (float) $cap - $used)), 1);
    }

    /** The centre decides a recognition request: the hours it recognises and the programs it treats as equivalent. */
    public function recognise(PdRecognitionRequest $r, string $decision, ?float $hours, array $equivalentProgramIds, ?string $note, User $by): PdRecognitionRequest
    {
        if ($r->center_decision) {
            throw new BusinessRuleException(__('messages.pd.already_decided'), 'already_decided');
        }
        $r->loadMissing('activity.employee.user');
        $r->update(['center_decision' => $decision, 'recognised_hours' => $decision === 'approved' ? $hours : null, 'equivalent_program_ids' => $decision === 'approved' ? $equivalentProgramIds : null, 'decided_by' => $by->id, 'note' => $note, 'decided_at' => now()]);
        if ($decision === 'approved' && $hours !== null) {
            $r->activity->update(['approved_hours' => $hours]);
        }
        $this->notifications->send($r->activity->employee->user_id, 'pd.recognition_decided', ['ar' => 'قرار الاعتراف بنشاطك', 'en' => 'Recognition decision'],
            ['ar' => $decision === 'approved' ? "اعترف المركز بنشاط «{$r->activity->title}» ({$hours} ساعة)." : "لم يعترف المركز بنشاط «{$r->activity->title}»".($note ? " — {$note}" : ''), 'en' => $decision === 'approved' ? "The centre recognised \"{$r->activity->title}\" ({$hours} h)." : "The centre did not recognise \"{$r->activity->title}\"".($note ? " — {$note}" : '')], ['pd_activity_id' => $r->pd_activity_id]);
        $this->paths->evaluate($r->activity->employee);

        return $r->refresh();
    }

    /** The stock activity types, created the first time they are needed. */
    public function ensureTypes(): void
    {
        if (PdActivityType::exists()) {
            return;
        }
        $rules = fn (array $f, ?float $cap = null) => ['attendee' => ['factor' => $f[0]], 'presenter' => ['factor' => $f[1]], 'organiser' => ['factor' => $f[2]], 'author' => ['factor' => $f[3]]] + ($cap ? ['cap_year' => $cap] : []);
        foreach ([['conference', 'مؤتمر', 'Conference', $rules([1, 1.5, 1.5, 2], 40)], ['course', 'دورة خارجية', 'External course', $rules([1, 1.5, 1.5, 2])], ['workshop', 'ورشة عمل', 'Workshop', $rules([1, 1.5, 1.5, 2], 60)], ['research', 'بحث أو نشر علمي', 'Research or publication', $rules([1, 1, 1, 1.5], 50)]] as [$code, $ar, $en, $r]) {
            PdActivityType::create(['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'hour_rules' => $r, 'evidence_required' => true]);
        }
    }
}
