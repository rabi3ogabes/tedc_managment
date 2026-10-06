<?php

namespace App\Ops;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\DataSubjectRequest;
use App\Models\Employee;
use App\Models\User;
use App\Security\SecurityEvents;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What Qatar Law No. 13 of 2016 gives a person over their own data: to see it (a download of everything held about them), to have it corrected, erased or restricted,
 * and to object to its use. Requests are answered within 30 days; the people responsible are reminded before the date and after it.
 */
class DataSubjectService
{
    public const TYPES = ['access', 'correction', 'erasure', 'objection', 'restriction'];

    public const DAYS = 30;

    public function __construct(private readonly DataClassification $classification, private readonly NotificationService $notifications) {}

    public function request(User $user, string $type, ?string $details): DataSubjectRequest
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new BusinessRuleException('Unknown request type.', 'bad_type');
        }
        if (DataSubjectRequest::where('user_id', $user->id)->where('type', $type)->whereIn('status', ['received', 'in_progress'])->exists()) {
            throw new BusinessRuleException('You already have an open request of this kind.', 'duplicate');
        }
        $r = DataSubjectRequest::create(['user_id' => $user->id, 'type' => $type, 'details' => $details ? mb_substr(strip_tags($details), 0, 2000) : null, 'status' => 'received', 'due_at' => now()->addDays(self::DAYS)]);
        SecurityEvents::record('dsr_received', $user, 'ok', ['type' => $type]);
        $ids = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'privacy.manage'))->pluck('id')->all();
        $ids && $this->notifications->broadcast($ids, 'dsr.received', ['ar' => 'طلب جديد من صاحب بيانات', 'en' => 'New data-subject request'], ['ar' => 'النوع: '.$type.' — الموعد النهائي '.$r->due_at->toDateString(), 'en' => 'Type: '.$type.' — due '.$r->due_at->toDateString()], ['request_id' => $r->id, 'route' => '/admin/privacy'], raw: true);

        return $r;
    }

    public function decide(DataSubjectRequest $r, User $by, string $status, ?string $resolution): DataSubjectRequest
    {
        if (! in_array($status, ['in_progress', 'completed', 'rejected'], true)) {
            throw new BusinessRuleException('Unknown status.', 'bad_status');
        }
        if (in_array($status, ['completed', 'rejected'], true) && trim((string) $resolution) === '') {
            throw new BusinessRuleException('Write the answer given to the person.', 'resolution_required');
        }
        $r->update(['status' => $status, 'handled_by' => $by->id, 'resolution' => $resolution ? mb_substr($resolution, 0, 3000) : $r->resolution, 'completed_at' => in_array($status, ['completed', 'rejected'], true) ? now() : null]);
        AuditLog::create(['user_id' => $by->id, 'action' => 'dsr_'.$status, 'auditable_type' => User::class, 'auditable_id' => $r->user_id, 'new_values' => ['request' => $r->id, 'type' => $r->type]]);
        if (in_array($status, ['completed', 'rejected'], true)) {
            $this->notifications->send($r->user_id, 'dsr.decided', ['ar' => 'تم الرد على طلبك', 'en' => 'Your data request was answered'], ['ar' => (string) $resolution, 'en' => (string) $resolution], ['request_id' => $r->id, 'route' => '/portal/privacy'], raw: true);
        }

        return $r;
    }

    /** Reminds the handlers five days before a request is due and again when it is overdue (once each). @return int reminders sent */
    public function sweep(): int
    {
        $ids = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'privacy.manage'))->pluck('id')->all();
        $n = 0;
        foreach (DataSubjectRequest::whereIn('status', ['received', 'in_progress'])->where('due_at', '<=', now()->addDays(5))->get() as $r) {
            $stage = $r->due_at->isPast() ? 'overdue' : 'soon';
            if ($ids && Cache::add("dsr-reminder:{$r->id}:{$stage}", 1, now()->addDays(30))) {
                $this->notifications->broadcast($ids, 'dsr.reminder', ['ar' => $stage === 'overdue' ? 'طلب بيانات متأخر' : 'طلب بيانات يقترب موعده', 'en' => $stage === 'overdue' ? 'A data request is overdue' : 'A data request is almost due'],
                    ['ar' => 'النوع: '.$r->type, 'en' => 'Type: '.$r->type], ['request_id' => $r->id, 'route' => '/admin/privacy'], raw: true);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Everything the platform holds about the person, section by section. Secrets are left out; the person's own national ID is included.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $employee = Employee::where('user_id', $user->id)->first();
        $sections = [];
        foreach (Schema::getTables() as $t) {
            $table = $t['name'];
            if (in_array($table, Anonymiser::SKIP, true) || in_array($table, ['users', 'employees', 'siem_outbox'], true)) {
                continue;
            }
            $cols = Schema::getColumnListing($table);
            $q = null;
            if (in_array('user_id', $cols, true)) {
                $q = DB::table($table)->where('user_id', $user->id);
            } elseif ($employee && in_array('employee_id', $cols, true)) {
                $q = DB::table($table)->where('employee_id', $employee->id);
            }
            if (! $q) {
                continue;
            }
            $restricted = $this->classification->columnsOf($table, 'restricted');
            $rows = $q->limit(5000)->get()->map(fn ($r) => array_diff_key((array) $r, array_flip($restricted)))->all();
            if ($rows) {
                $sections[$table] = $rows;
            }
        }
        $account = array_diff_key($user->only(['id', 'name', 'name_ar', 'email', 'phone', 'locale', 'status', 'created_at', 'last_login_at']), []);
        $profile = $employee ? $employee->makeVisible(['national_id'])->toArray() : null;
        AuditLog::create(['user_id' => $user->id, 'action' => 'personal_data_exported', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => ['sections' => array_keys($sections)]]);
        SecurityEvents::record('personal_data_export', $user, 'ok', ['sections' => count($sections)]);

        return ['generated_at' => now()->toIso8601String(), 'law' => 'Qatar Law No. 13 of 2016 — right of access', 'account' => $account, 'employee' => $profile, 'sections' => $sections];
    }
}
