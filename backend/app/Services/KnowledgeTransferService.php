<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\KnowledgeTransfer;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Indirect training: after a program the trainee passes the knowledge on, with beneficiaries, hours and evidence, before a deadline. */
class KnowledgeTransferService
{
    public function __construct(private readonly NotificationService $notifications, private readonly EvaluationService $evaluations, private readonly CertificateService $certificates, private readonly AnnualHoursService $hours) {}

    /** The program's setting, completed with defaults. @return array<string, mixed> */
    public function config(Registration $r): array
    {
        return array_replace(['required' => false, 'min_beneficiaries' => 5, 'min_hours' => 1, 'deadline_days' => 30, 'evidence_required' => true], $r->program->knowledge_transfer ?? []);
    }

    /** When a trainee completes a program that requires it, the task and its deadline appear. */
    public function createFor(Registration $r): ?KnowledgeTransfer
    {
        $r->loadMissing('program', 'employee');
        $c = $this->config($r);
        if (! $c['required']) {
            return null;
        }
        $kt = KnowledgeTransfer::firstOrCreate(['registration_id' => $r->id], ['employee_id' => $r->employee_id, 'due_on' => today()->addDays((int) $c['deadline_days']), 'status' => 'pending']);
        if ($kt->wasRecentlyCreated && $r->employee->user_id) {
            $this->notifications->send($r->employee->user_id, 'knowledge_transfer.due', ['ar' => 'مطلوب منك نقل المعرفة', 'en' => 'Please pass the knowledge on'],
                ['ar' => "انقل ما تعلمته في «{$r->program->title_ar}» إلى زملائك قبل {$kt->due_on->toDateString()}.", 'en' => "Pass what you learned in \"{$r->program->title_en}\" on to colleagues before {$kt->due_on->toDateString()}."], ['registration_id' => $r->id]);
        }

        return $kt;
    }

    /**
     * @param  array<string, mixed>  $data  delivered_on, hours, method, beneficiaries[{employee_id?, name?}]
     * @param  list<UploadedFile|string>  $evidence
     */
    public function submit(KnowledgeTransfer $kt, array $data, array $evidence): KnowledgeTransfer
    {
        $kt->loadMissing('registration.program');
        if (! in_array($kt->status, ['pending', 'rejected'], true)) {
            throw new BusinessRuleException(__('messages.kt.locked'), 'kt_locked');
        }
        $c = $this->config($kt->registration);
        $people = collect($data['beneficiaries'] ?? [])->filter(fn ($b) => ! empty($b['employee_id']) || filled($b['name'] ?? null))->unique(fn ($b) => $b['employee_id'] ?? mb_strtolower($b['name']))->values();
        $errors = [];
        if ($people->count() < (int) $c['min_beneficiaries']) {
            $errors['beneficiaries'] = __('messages.kt.min_beneficiaries', ['n' => $c['min_beneficiaries']]);
        }
        if ((float) $data['hours'] < (float) $c['min_hours']) {
            $errors['hours'] = __('messages.kt.min_hours', ['n' => $c['min_hours']]);
        }
        if ($c['evidence_required'] && $evidence === [] && empty($kt->evidence)) {
            $errors['evidence'] = __('messages.kt.evidence_required');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $stored = $evidence ? $this->evaluations->evidenceItems($evidence, "kt/{$kt->id}", 8, 'evidence') : ($kt->evidence ?? []);
        $kt->update(['delivered_on' => $data['delivered_on'], 'hours' => $data['hours'], 'method' => $data['method'], 'beneficiaries' => $people->all(), 'beneficiary_count' => $people->count(), 'evidence' => $stored, 'status' => 'pending_review', 'note' => null]);

        return $kt->refresh();
    }

    /** @param  'approve'|'reject'  $decision */
    public function decide(KnowledgeTransfer $kt, string $decision, ?string $note, User $by): KnowledgeTransfer
    {
        if ($kt->status !== 'pending_review') {
            throw new BusinessRuleException(__('messages.kt.not_pending'), 'kt_not_pending');
        }
        if ($decision === 'reject' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.pd.note_required'), 'note_required');
        }
        $kt->loadMissing('registration.program', 'employee');
        $kt->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewer_id' => $by->id, 'note' => $note]);
        $this->notifications->send($kt->employee->user_id, 'knowledge_transfer.decided', ['ar' => 'قرار بشأن نقل المعرفة', 'en' => 'Decision on your knowledge transfer'],
            ['ar' => ($decision === 'approve' ? 'اعتُمد نقل المعرفة' : 'لم يُعتمد نقل المعرفة').($note ? " — {$note}" : ''), 'en' => ($decision === 'approve' ? 'Your knowledge transfer was approved' : 'Your knowledge transfer was not approved').($note ? " — {$note}" : '')], ['registration_id' => $kt->registration_id]);
        $this->certificates->refreshStatus($kt->registration);

        return $kt->refresh();
    }

    /** Daily: a reminder three days before the deadline, one when it is missed. @return array{due: int, overdue: int} */
    public function remind(): array
    {
        $due = $overdue = 0;
        KnowledgeTransfer::with('employee', 'registration.program')->where('status', 'pending')->whereNotNull('due_on')->each(function (KnowledgeTransfer $kt) use (&$due, &$overdue) {
            $days = (int) today()->diffInDays($kt->due_on, false);
            $title = $kt->registration->program;
            if ($days < 0 && $kt->reminded_at === null || ($days < 0 && $kt->reminded_at->lt($kt->due_on))) {
                $this->notifications->send($kt->employee->user_id, 'knowledge_transfer.overdue', ['ar' => 'تأخر نقل المعرفة', 'en' => 'Knowledge transfer is overdue'], ['ar' => "فات موعد نقل المعرفة لبرنامج «{$title->title_ar}».", 'en' => "The knowledge transfer deadline for \"{$title->title_en}\" has passed."], ['registration_id' => $kt->registration_id]);
                $kt->update(['reminded_at' => now()]);
                $overdue++;
            } elseif ($days >= 0 && $days <= 3 && $kt->reminded_at === null) {
                $this->notifications->send($kt->employee->user_id, 'knowledge_transfer.due', ['ar' => 'اقترب موعد نقل المعرفة', 'en' => 'Knowledge transfer is due soon'], ['ar' => "يتبقى {$days} يوم على موعد نقل المعرفة لبرنامج «{$title->title_ar}».", 'en' => "{$days} day(s) left to pass on the knowledge of \"{$title->title_en}\"."], ['registration_id' => $kt->registration_id]);
                $kt->update(['reminded_at' => now()]);
                $due++;
            }
        });

        return compact('due', 'overdue');
    }

    /** Direct trainees against indirect beneficiaries and the hours transferred. @return array<string, mixed> */
    public function reach(string $programId): array
    {
        $direct = Registration::where('program_id', $programId)->where('status', Registration::STATUS_COMPLETED)->count();
        $approved = KnowledgeTransfer::whereHas('registration', fn ($q) => $q->where('program_id', $programId))->where('status', 'approved')->get();

        return ['direct_trainees' => $direct, 'transfers' => $approved->count(), 'indirect_beneficiaries' => (int) $approved->sum('beneficiary_count'), 'hours' => round((float) $approved->sum('hours'), 1), 'pending' => KnowledgeTransfer::whereHas('registration', fn ($q) => $q->where('program_id', $programId))->whereIn('status', ['pending', 'pending_review'])->count()];
    }
}
