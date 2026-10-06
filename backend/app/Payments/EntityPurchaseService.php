<?php

namespace App\Payments;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\EntityAccount;
use App\Models\Registration;
use App\Models\SeatVoucher;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Entities (private schools, other bodies) buy seats for their staff; the seats come as vouchers that are given to people, who redeem them to register. */
class EntityPurchaseService
{
    public function __construct(private readonly NotificationService $notifications, private readonly RegistrationService $registrations, private readonly SeatHolds $holds, private readonly PaymentSettings $settings) {}

    /** @return Collection<int, EntityAccount> */
    public function accountsOf(User $user)
    {
        return EntityAccount::whereIn('id', DB::table('entity_account_users')->where('user_id', $user->id)->select('entity_account_id'))->where('status', 'active')->get();
    }

    public function adminOf(User $user, EntityAccount $e): bool
    {
        return DB::table('entity_account_users')->where(['user_id' => $user->id, 'entity_account_id' => $e->id])->exists();
    }

    /**
     * Gives vouchers to people. Each entry names the person by employee number or e-mail and, optionally, the group.
     *
     * @param  list<array{group_id?: string, employee_no?: ?string, email?: ?string, voucher_id?: ?string}>  $entries
     * @return array{assigned: int, failed: list<array<string, mixed>>}
     */
    public function assign(EntityAccount $entity, array $entries): array
    {
        $assigned = 0;
        $failed = [];
        foreach ($entries as $i => $e) {
            try {
                $v = ! empty($e['voucher_id'])
                    ? SeatVoucher::where('entity_account_id', $entity->id)->whereKey($e['voucher_id'])->first()
                    : SeatVoucher::where('entity_account_id', $entity->id)->where('status', 'available')->where('expires_at', '>', now())->when(! empty($e['group_id']), fn ($q) => $q->where('group_id', $e['group_id']))->orderBy('expires_at')->first();
                if (! $v || ! in_array($v->status, ['available', 'assigned'], true) || $v->expires_at->isPast()) {
                    throw new BusinessRuleException('No free voucher'.(! empty($e['group_id']) ? ' for this group.' : '.'), 'no_voucher');
                }
                $emp = ! empty($e['employee_no']) ? Employee::where('employee_no', trim($e['employee_no']))->first() : null;
                $email = strtolower(trim((string) ($e['email'] ?? '')));
                if (! $emp && $email !== '') {
                    $emp = Employee::whereIn('user_id', User::whereRaw('lower(email) = ?', [$email])->select('id'))->first();
                }
                if (! $emp && $email === '') {
                    throw new BusinessRuleException('Give an employee number or an e-mail address.', 'person_required');
                }
                $v->update(['assigned_employee_id' => $emp?->id, 'assigned_email' => $emp ? null : $email, 'status' => 'assigned']);
                $user = $emp?->user ?? ($email ? User::whereRaw('lower(email) = ?', [$email])->first() : null);
                if ($user) {
                    $this->notifications->send($user, 'voucher.assigned', ['ar' => 'حصلت على مقعد تدريبي', 'en' => 'You have been given a training seat'], ['ar' => 'استخدم الرمز '.$v->code.' للتسجيل قبل '.$v->expires_at->toDateString(), 'en' => 'Use code '.$v->code.' to register before '.$v->expires_at->toDateString()], ['voucher_id' => $v->id, 'route' => '/portal/vouchers'], raw: true);
                }
                $assigned++;
            } catch (BusinessRuleException $ex) {
                $failed[] = ['row' => $i + 1, 'error' => $ex->getMessage(), 'code' => $ex->errorCode];
            }
        }

        return ['assigned' => $assigned, 'failed' => $failed];
    }

    /** The person uses a voucher to register. Their eligibility is checked like anyone's; the seat the entity paid for is theirs. */
    public function redeem(User $user, string $code): SeatVoucher
    {
        $v = SeatVoucher::whereRaw('upper(code) = ?', [strtoupper(trim($code))])->first();
        if (! $v || ! in_array($v->status, ['available', 'assigned'], true)) {
            throw new BusinessRuleException('This voucher code cannot be used.', 'voucher_invalid');
        }
        if ($v->expires_at->isPast()) {
            throw new BusinessRuleException('This voucher has expired.', 'voucher_expired');
        }
        $emp = Employee::where('user_id', $user->id)->first();
        if (! $emp) {
            throw new BusinessRuleException('Your account has no employee profile yet.', 'no_profile');
        }
        if (($v->assigned_employee_id && $v->assigned_employee_id !== $emp->id) || ($v->assigned_email && strtolower($v->assigned_email) !== strtolower($user->email))) {
            throw new BusinessRuleException('This voucher was given to someone else.', 'voucher_other_person');
        }

        return DB::transaction(function () use ($v, $emp, $user) {
            $v = SeatVoucher::whereKey($v->id)->lockForUpdate()->first();
            if (! in_array($v->status, ['available', 'assigned'], true)) {
                throw new BusinessRuleException('This voucher cannot be used.', 'voucher_invalid');
            }
            $this->holds->release('voucher', $v->id);   // the seat held for the voucher becomes this person's seat
            $group = TrainingGroup::with('program')->findOrFail($v->group_id);
            $reg = $this->registrations->register($group->program, $emp, Registration::SOURCE_SELF, $user, null, false, $group);
            if (in_array($reg->status, [Registration::STATUS_PENDING_MANAGER, Registration::STATUS_PENDING], true)) {
                if ($reg->status === Registration::STATUS_PENDING_MANAGER) {
                    $reg = $this->registrations->transition($reg, Registration::STATUS_PENDING);
                }
                $reg = $this->registrations->transition($reg, Registration::STATUS_APPROVED, null, 'Voucher', 'Entity voucher');
            }
            $v->update(['status' => 'redeemed', 'assigned_employee_id' => $emp->id, 'registration_id' => $reg->id]);

            return $v;
        });
    }

    /** Vouchers past their date expire (their seats are released); entity admins hear about vouchers about to expire. @return array{expired: int, reminded: int} */
    public function sweep(): array
    {
        $expired = 0;
        SeatVoucher::whereIn('status', ['available', 'assigned'])->where('expires_at', '<=', now())->get()->each(function (SeatVoucher $v) use (&$expired) {
            $v->update(['status' => 'expired']);
            $this->holds->release('voucher', $v->id);
            $expired++;
        });
        $reminded = 0;
        $days = (int) $this->settings->all()['voucher_reminder_days'];
        $due = SeatVoucher::whereIn('status', ['available', 'assigned'])->whereNull('reminded_at')->where('expires_at', '<=', now()->addDays($days))->get()->groupBy('entity_account_id');
        foreach ($due as $entityId => $vs) {
            $admins = DB::table('entity_account_users')->where('entity_account_id', $entityId)->pluck('user_id')->all();
            $admins && $this->notifications->broadcast($admins, 'voucher.expiring', ['ar' => 'مقاعد على وشك الانتهاء', 'en' => 'Seats about to expire'], ['ar' => $vs->count().' مقعدًا لم تُستخدم وتنتهي قريبًا.', 'en' => $vs->count().' unused seats expire soon.'], ['route' => '/portal/entity'], raw: true);
            SeatVoucher::whereIn('id', $vs->pluck('id'))->update(['reminded_at' => now()]);
            $reminded += $vs->count();
        }
        $this->holds->prune();

        return ['expired' => $expired, 'reminded' => $reminded];
    }

    /** @return array<string, mixed> */
    public function usage(EntityAccount $e): array
    {
        $by = SeatVoucher::where('entity_account_id', $e->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $spent = (float) DB::table('orders')->where('entity_account_id', $e->id)->whereIn('status', ['paid', 'partially_refunded'])->sum('total');

        return ['vouchers' => $by, 'total' => (int) $by->sum(), 'redeemed' => (int) ($by['redeemed'] ?? 0), 'unused' => (int) (($by['available'] ?? 0) + ($by['assigned'] ?? 0)), 'expired' => (int) ($by['expired'] ?? 0), 'spent' => round($spent, 2)];
    }
}
