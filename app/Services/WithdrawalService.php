<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Registration;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WithdrawalReason;
use App\Models\WithdrawalRequest;
use Illuminate\Http\UploadedFile;

/**
 * Leaving a program: free before the manager approved and while registration is open; otherwise a request that goes to the
 * direct manager and — once the centre approved the seat — to the program supervisor. Policy and reasons are settings, not code.
 */
class WithdrawalService
{
    public const DEFAULT_POLICY = ['min_days_before_start' => 0, 'allow_after_start' => true, 'late_counts_in_reports' => true];

    public function __construct(private readonly RegistrationService $registrations, private readonly NotificationService $notifications, private readonly FileStorage $files) {}

    public function policy(): array
    {
        return array_replace(self::DEFAULT_POLICY, SiteSetting::find('withdrawal.policy')?->value ?? []);
    }

    public function savePolicy(array $policy): array
    {
        SiteSetting::updateOrCreate(['key' => 'withdrawal.policy'], ['value' => array_replace($this->policy(), $policy), 'updated_by' => auth()->id()]);

        return $this->policy();
    }

    public function timing(Registration $r): string
    {
        $program = $r->program;
        if ($program->start_date && $program->start_date->lte(today())) {
            return 'after_start';
        }
        $group = $r->trainingGroup;
        $closes = $group?->registration_closes_at ?? $program->registration_closes_at;

        return ! $closes || $closes->isFuture() ? 'during_window' : 'before_start';
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array{mode: string, request?: WithdrawalRequest, registration: Registration}
     */
    public function withdraw(Registration $registration, User $by, ?string $reasonCode, ?string $text, array $files = []): array
    {
        $registration->loadMissing(['program', 'trainingGroup', 'employee']);
        if (! in_array($registration->status, [Registration::STATUS_PENDING_MANAGER, Registration::STATUS_PENDING, Registration::STATUS_WAITLISTED, Registration::STATUS_APPROVED], true)) {
            throw new BusinessRuleException(__('messages.withdrawal.not_possible'), 'withdrawal_not_possible');
        }
        $timing = $this->timing($registration);
        $policy = $this->policy();
        if ($timing === 'after_start' && ! $policy['allow_after_start']) {
            throw new BusinessRuleException(__('messages.withdrawal.after_start'), 'withdrawal_after_start');
        }

        $managerApproved = $registration->manager_decided_at !== null;
        $free = ! $managerApproved && $registration->status !== Registration::STATUS_APPROVED && $timing === 'during_window';
        if ($free) {
            $this->registrations->transition($registration, Registration::STATUS_WITHDRAWN, $by, $text);

            return ['mode' => 'direct', 'registration' => $registration->refresh()];
        }

        $reasons = WithdrawalReason::where('is_active', true)->get();
        $reason = $reasons->firstWhere('code', $reasonCode);
        if ($reasons->isNotEmpty() && ! $reason) {
            throw new BusinessRuleException(__('messages.withdrawal.reason_required'), 'reason_required');
        }
        if ($reason?->requires_attachment && ! $files) {
            throw new BusinessRuleException(__('messages.withdrawal.attachment_required'), 'attachment_required');
        }
        if (WithdrawalRequest::where('registration_id', $registration->id)->where('status', 'pending')->exists()) {
            throw new BusinessRuleException(__('messages.withdrawal.already_requested'), 'withdrawal_pending');
        }

        $paths = array_map(fn (UploadedFile $f) => ['name' => $f->getClientOriginalName(), 'path' => $this->files->upload($f, 'documents', 'withdrawals/'.$registration->id)], $files);
        $late = $timing === 'after_start' || ($registration->program->start_date && $registration->program->start_date->diffInDays(today(), false) > -$policy['min_days_before_start'] && $policy['min_days_before_start'] > 0);
        $managerId = $registration->manager_id ?? $this->registrations->resolveManager($registration->employee)?->id;
        $request = WithdrawalRequest::create([
            'registration_id' => $registration->id, 'requested_by' => $by->id, 'reason_code' => $reasonCode, 'reason_text' => $text, 'attachments' => $paths ?: null, 'timing' => $timing,
            'stage' => $managerId ? 'manager' : 'supervisor', 'status' => 'pending', 'manager_id' => $managerId, 'is_late' => (bool) $late,
        ]);
        $this->notifyStage($request);

        return ['mode' => 'request', 'request' => $request, 'registration' => $registration];
    }

    public function decide(WithdrawalRequest $request, User $by, string $decision, ?string $note): WithdrawalRequest
    {
        if ($request->status !== 'pending') {
            throw new BusinessRuleException(__('messages.withdrawal.decided'), 'withdrawal_decided');
        }
        if ($decision === 'rejected' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        $registration = $request->registration()->with(['program', 'employee.user'])->first();

        if ($request->stage === 'manager') {
            abort_unless($request->manager_id === $by->id || $by->hasPermission('registrations.approve_manager') && $by->id !== $registration->employee->user_id, 403);
            $request->update(['manager_decision' => $decision, 'manager_note' => $note, 'manager_id' => $by->id]);
            if ($decision === 'approved' && $registration->status === Registration::STATUS_APPROVED) {
                $request->update(['stage' => 'supervisor']);
                $this->notifyStage($request);

                return $request->fresh();
            }
        } else {
            abort_unless($by->hasPermission('withdrawals.decide'), 403);
            $request->update(['supervisor_decision' => $decision, 'supervisor_note' => $note, 'supervisor_id' => $by->id]);
        }

        $request->update(['stage' => 'done', 'status' => $decision]);
        if ($decision === 'approved') {
            $this->registrations->transition($registration, Registration::STATUS_WITHDRAWN, $by, $note);
        }
        $this->tellEmployee($request, $registration, $decision, $note);

        return $request->fresh();
    }

    private function notifyStage(WithdrawalRequest $request): void
    {
        $registration = $request->registration()->with(['program', 'employee.user'])->first();
        $name = $registration->employee->user?->displayName('ar') ?? '—';
        $program = $registration->program;
        $ids = $request->stage === 'manager' ? array_filter([$request->manager_id]) : $this->supervisors($registration);
        $ids && $this->notifications->broadcast($ids, 'withdrawal.requested', ['ar' => 'طلب انسحاب بانتظار قرارك', 'en' => 'A withdrawal request awaits your decision'],
            ['ar' => "{$name} يطلب الانسحاب من «{$program->title_ar}».", 'en' => "{$name} asks to withdraw from \"{$program->title_en}\"."], ['detail_ar' => "{$name} يطلب الانسحاب من «{$program->title_ar}».", 'detail_en' => "{$name} asks to withdraw from \"{$program->title_en}\".", 'withdrawal_id' => $request->id, 'program_id' => $program->id, 'route' => '/admin/approvals']);
    }

    /** @return list<string> */
    private function supervisors(Registration $registration): array
    {
        $ids = array_filter([$registration->trainingGroup?->supervisor_id, $registration->program->coordinator_id]);

        return $ids ?: User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'withdrawals.decide'))->pluck('id')->all();
    }

    private function tellEmployee(WithdrawalRequest $request, Registration $registration, string $decision, ?string $note): void
    {
        $program = $registration->program;
        [$ar, $en] = $decision === 'approved' ? ['اعتُمد انسحابك', 'Your withdrawal was approved'] : ['رُفض طلب انسحابك', 'Your withdrawal request was rejected'];
        $this->notifications->send($registration->employee->user_id, 'withdrawal.decided', ['ar' => 'قرار بشأن طلب الانسحاب', 'en' => 'Decision on your withdrawal request'],
            ['ar' => "{$ar} من «{$program->title_ar}»".($note ? " — {$note}" : '').'.', 'en' => "{$en} for \"{$program->title_en}\"".($note ? " — {$note}" : '').'.'],
            ['detail_ar' => "{$ar} من «{$program->title_ar}»", 'detail_en' => "{$en} for \"{$program->title_en}\"", 'registration_id' => $registration->id, 'program_id' => $program->id]);
    }
}
