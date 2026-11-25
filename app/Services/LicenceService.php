<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ProfessionalLicence;
use Carbon\Carbon;

/** The licence register: import (idempotent), expiry and the 90 / 60 / 30-day reminders. */
class LicenceService
{
    public const REMINDERS = [90, 60, 30];

    public function __construct(private readonly NotificationService $notifications, private readonly CareerPathEngine $paths) {}

    /**
     * @param  list<array<string, mixed>>  $rows  employee_no, path_id?, level_no, licence_no, issued_at, expires_at?
     * @return array{created: int, updated: int, errors: list<array<string, mixed>>}
     */
    public function import(array $rows, string $source = 'licences_system'): array
    {
        $created = $updated = 0;
        $errors = [];
        foreach ($rows as $i => $r) {
            $employee = Employee::where('employee_no', $r['employee_no'] ?? null)->first();
            if (! $employee || empty($r['licence_no']) || empty($r['level_no']) || empty($r['issued_at'])) {
                $errors[] = ['line' => $i + 2, 'reason' => ! $employee ? 'unknown_employee' : 'missing_field'];

                continue;
            }
            try {
                $issued = Carbon::parse($r['issued_at'])->toDateString();
                $expires = ! empty($r['expires_at']) ? Carbon::parse($r['expires_at'])->toDateString() : null;
            } catch (\Throwable) {
                $errors[] = ['line' => $i + 2, 'reason' => 'bad_date'];

                continue;
            }
            $row = ProfessionalLicence::updateOrCreate(['employee_id' => $employee->id, 'licence_no' => $r['licence_no']], ['path_id' => $r['path_id'] ?? null, 'level_no' => (int) $r['level_no'], 'issued_at' => $issued, 'expires_at' => $expires, 'status' => $expires && $expires < today()->toDateString() ? 'expired' : 'active', 'source' => $source, 'synced_at' => now()]);
            $row->wasRecentlyCreated ? $created++ : $updated++;
            $this->paths->evaluate($employee);
        }

        return compact('created', 'updated', 'errors');
    }

    /** Daily: expire what ran out (once) and send each reminder once. @return array{expired: int, reminded: int} */
    public function monitor(): array
    {
        $expired = $reminded = 0;
        ProfessionalLicence::with('employee.user')->where('status', 'active')->whereNotNull('expires_at')->each(function (ProfessionalLicence $l) use (&$expired, &$reminded) {
            $days = (int) today()->diffInDays($l->expires_at, false);
            if ($days < 0) {
                $l->update(['status' => 'expired']);
                $this->notify($l, 'licence.expired', ['ar' => 'انتهت رخصتك المهنية', 'en' => 'Your professional licence has expired'], "انتهت الرخصة {$l->licence_no}.", "Licence {$l->licence_no} has expired.");
                $this->paths->evaluate($l->employee);
                $expired++;

                return;
            }
            $sent = $l->reminded ?? [];
            foreach (array_reverse(self::REMINDERS) as $d) {
                if ($days <= $d && ! in_array($d, $sent, true)) {
                    // Only the closest reminder is sent when several thresholds were crossed at once.
                    $sent = array_merge($sent, array_filter(self::REMINDERS, fn ($x) => $x >= $d));
                    $l->update(['reminded' => array_values(array_unique($sent))]);
                    $this->notify($l, 'licence.expiring', ['ar' => 'رخصتك المهنية تقترب من الانتهاء', 'en' => 'Your professional licence is about to expire'], "تنتهي الرخصة {$l->licence_no} بعد {$days} يوماً.", "Licence {$l->licence_no} expires in {$days} days.");
                    $reminded++;
                    break;
                }
            }
        });

        return compact('expired', 'reminded');
    }

    private function notify(ProfessionalLicence $l, string $event, array $title, string $ar, string $en): void
    {
        $uids = array_filter([$l->employee->user_id, app(RegistrationService::class)->resolveManager($l->employee)?->id]);
        foreach (array_unique($uids) as $uid) {
            $this->notifications->send($uid, $event, $title, ['ar' => $ar, 'en' => $en], ['licence_id' => $l->id]);
        }
    }
}
