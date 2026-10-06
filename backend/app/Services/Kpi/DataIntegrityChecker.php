<?php

namespace App\Services\Kpi;

use Illuminate\Support\Facades\DB;

/**
 * Automated consistency checks over the data: orphans, duplicates and impossible states. The rate is the share of checked records that pass.
 * Each check is a count of violations over the records it looked at; `details()` names them for the administrator.
 */
class DataIntegrityChecker
{
    /** @return array<string, array{records: int, violations: int}> */
    public function checks(): array
    {
        $count = fn (string $sql, array $b = []) => (int) (DB::selectOne($sql, $b)->n ?? 0);

        return [
            'registrations_without_program_or_employee' => [
                'records' => $count('select count(*) as n from registrations'),
                'violations' => $count('select count(*) as n from registrations r where not exists (select 1 from employees e where e.id = r.employee_id) or not exists (select 1 from programs p where p.id = r.program_id)'),
            ],
            'attendance_checkout_before_checkin' => [
                'records' => $count('select count(*) as n from attendance where check_in_at is not null and check_out_at is not null'),
                'violations' => $count('select count(*) as n from attendance where check_in_at is not null and check_out_at is not null and check_out_at < check_in_at'),
            ],
            'duplicate_employee_numbers' => [
                'records' => $count('select count(*) as n from employees'),
                'violations' => $count('select coalesce(sum(c - 1), 0) as n from (select count(*) as c from employees where employee_no is not null group by employee_no having count(*) > 1) d'),
            ],
            'certificates_for_unfinished_registrations' => [
                'records' => $count("select count(*) as n from certificates where status = 'valid'"),
                'violations' => $count("select count(*) as n from certificates c join registrations r on r.id = c.registration_id where c.status = 'valid' and r.status in ('rejected','cancelled','withdrawn')"),
            ],
            'completed_without_approval' => [
                'records' => $count("select count(*) as n from registrations where status = 'completed'"),
                'violations' => $count("select count(*) as n from registrations where status = 'completed' and approved_at is null and source <> 'self'"),
            ],
            'impossible_percentages' => [
                'records' => $count('select count(*) as n from registrations where attendance_percent is not null'),
                'violations' => $count('select count(*) as n from registrations where attendance_percent < 0 or attendance_percent > 100'),
            ],
            'group_dates_reversed' => [
                'records' => $count('select count(*) as n from training_groups where start_date is not null and end_date is not null'),
                'violations' => $count('select count(*) as n from training_groups where start_date is not null and end_date is not null and end_date < start_date'),
            ],
        ];
    }

    /** @return array{value: float, meta: array<string, mixed>} */
    public function rate(): array
    {
        $checks = $this->checks();
        $records = array_sum(array_column($checks, 'records'));
        $violations = array_sum(array_column($checks, 'violations'));

        return ['value' => $records ? round((1 - $violations / $records) * 100, 3) : 100.0, 'meta' => ['records' => $records, 'violations' => $violations, 'checks' => $checks]];
    }
}
