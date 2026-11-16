<?php

namespace App\Integrations\Ministry;

use App\Integrations\Contracts\ContractGateway;
use App\Integrations\IntegrationManager;
use App\Models\Employee;
use Generator;

/**
 * "TEDC vs HR differences": the whole HR list (through the contract and mapping in force) set beside TEDC's employees, so the people who
 * keep the data can see where the two disagree. Only fields HR actually sends are compared (an empty HR field is not a difference, as in the
 * sync), a leaver HR lists that TEDC never had is not a gap, and the national number is only ever reported as "differs", never shown.
 */
class HrReconciliation
{
    /** The most entries per list in the screen; the file holds them all. */
    public const SHOWN = 200;

    /** Fields whose values are personal identifiers: the report says that they differ, not what they are. */
    private const HIDDEN = ['national_id'];

    public function __construct(private readonly IntegrationManager $hub, private readonly ContractGateway $contracts) {}

    /**
     * @return array{
     *     system: string, driver: string, generated_at: string, hr_total: int, tedc_total: int,
     *     summary: array{matching: int, different: int, only_in_hr: int, only_in_tedc: int},
     *     different: list<array{employee_no: string, name: ?string, fields: list<array{field: string, tedc: mixed, hr: mixed}>}>,
     *     only_in_hr: list<array{employee_no: string, name: ?string}>, only_in_tedc: list<array{employee_no: string, name: ?string}>, truncated: bool
     * }
     */
    public function report(string $key): array
    {
        $all = $this->compute($key);
        $truncated = false;
        foreach (['different', 'only_in_hr', 'only_in_tedc'] as $list) {
            if (count($all[$list]) > self::SHOWN) {
                $all[$list] = array_slice($all[$list], 0, self::SHOWN);
                $truncated = true;
            }
        }

        return $all + ['truncated' => $truncated];
    }

    /** One line per difference, for the file: kind, employee number, name, field, TEDC's value, HR's value. @return Generator<int, list<string>> */
    public function lines(string $key): Generator
    {
        $all = $this->compute($key);
        yield ['kind', 'employee_no', 'name', 'field', 'tedc', 'hr'];
        foreach ($all['different'] as $row) {
            foreach ($row['fields'] as $f) {
                yield ['different', $row['employee_no'], (string) $row['name'], $f['field'], (string) ($f['tedc'] ?? ''), (string) ($f['hr'] ?? '')];
            }
        }
        foreach ($all['only_in_hr'] as $row) {
            yield ['only_in_hr', $row['employee_no'], (string) $row['name'], '', '', ''];
        }
        foreach ($all['only_in_tedc'] as $row) {
            yield ['only_in_tedc', $row['employee_no'], (string) $row['name'], '', '', ''];
        }
    }

    // ---- internals ------------------------------------------------------------------------------------

    /** @return array<string, mixed> every difference, not cut for the screen */
    private function compute(string $key): array
    {
        $integration = $this->hub->get($key);
        $rows = $this->hub->call($key, 'reconciliation', function (array $s, $i) use ($key) {
            if ($i->driver === 'fake') {
                return (array) (is_string($s['fake_employees'] ?? null) ? json_decode($s['fake_employees'], true) : ($s['fake_employees'] ?? []));
            }

            return $this->contracts->records($key, 'fetch_employees', [], $s);   // the whole list, not just what changed
        }, ['full' => true]);

        $hrByNo = [];
        foreach ($rows as $row) {
            $no = trim((string) (is_array($row) ? ($row['employee_no'] ?? '') : ''));
            if ($no !== '') {
                $hrByNo[$no] = $row;
            }
        }

        $different = [];
        $onlyHr = [];
        $matching = 0;
        foreach (array_chunk(array_keys($hrByNo), 500) as $numbers) {
            $found = Employee::whereIn('employee_no', $numbers)->with(['user', 'jobTitle', 'school', 'department', 'supervisor'])->get()->keyBy('employee_no');
            foreach ($numbers as $no) {
                $hr = $hrByNo[$no];
                $employee = $found->get((string) $no);
                if (! $employee) {
                    if (! $this->left($hr)) {
                        $onlyHr[] = ['employee_no' => (string) $no, 'name' => $this->text($hr['name'] ?? $hr['name_ar'] ?? null)];
                    }

                    continue;
                }
                $fields = $this->differences($hr, $employee);
                if ($fields === []) {
                    $matching++;
                } else {
                    $different[] = ['employee_no' => (string) $no, 'name' => $employee->user?->displayName(), 'fields' => $fields];
                }
            }
        }

        $onlyTedc = [];
        $tedcTotal = 0;
        Employee::where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'left'))->with('user')->orderBy('id')->chunkById(500, function ($employees) use ($hrByNo, &$onlyTedc, &$tedcTotal) {
            foreach ($employees as $employee) {
                $tedcTotal++;
                if (! isset($hrByNo[(string) $employee->employee_no])) {
                    $onlyTedc[] = ['employee_no' => (string) $employee->employee_no, 'name' => $employee->user?->displayName()];
                }
            }
        });

        return [
            'system' => $key, 'driver' => $integration->driver, 'generated_at' => now()->toIso8601String(), 'hr_total' => count($hrByNo), 'tedc_total' => $tedcTotal,
            'summary' => ['matching' => $matching, 'different' => count($different), 'only_in_hr' => count($onlyHr), 'only_in_tedc' => count($onlyTedc)],
            'different' => $different, 'only_in_hr' => $onlyHr, 'only_in_tedc' => $onlyTedc,
        ];
    }

    /** @return list<array{field: string, tedc: mixed, hr: mixed}> */
    private function differences(array $hr, Employee $e): array
    {
        $tedc = [
            'name' => $e->user?->name, 'name_ar' => $e->user?->name_ar, 'email' => $e->user?->email, 'status' => $e->status === 'left' ? 'left' : 'active',
            'job_title_code' => $e->jobTitle?->code, 'school_code' => [$e->school?->code, $e->school?->moe_no], 'department_code' => $e->department?->code,
            'supervisor_employee_no' => $e->supervisor?->employee_no, 'gender' => $e->gender, 'nationality' => $e->nationality,
            'hire_date' => $e->hire_date?->format('Y-m-d'), 'experience_years' => $e->experience_years, 'qualification' => $e->qualification, 'specialization' => $e->specialization,
            'grade_level' => $e->grade_level, 'education_stage' => $e->education_stage, 'birth_date' => $e->birth_date?->format('Y-m-d'), 'national_id' => $e->national_id,
        ];
        $out = [];
        foreach ($tedc as $field => $mine) {
            $theirs = $hr[$field] ?? null;
            if ($field === 'status') {
                $theirs = $theirs === null || $theirs === '' ? null : ($this->left($hr) ? 'left' : 'active');
            }
            if ($theirs === null || $theirs === '' || $this->same($field, $mine, $theirs)) {
                continue;   // HR sends nothing for it, or they agree
            }
            $hidden = in_array($field, self::HIDDEN, true);
            $out[] = ['field' => $field, 'tedc' => $hidden ? null : (is_array($mine) ? $this->text($mine[0] ?? $mine[1] ?? null) : $this->text($mine)), 'hr' => $hidden ? null : $this->text($theirs)];
        }

        return $out;
    }

    private function same(string $field, mixed $mine, mixed $theirs): bool
    {
        if (is_array($mine)) {   // the school: HR may name it by TEDC's code or by the Ministry number
            return in_array($this->fold($theirs), array_map(fn ($v) => $this->fold($v), array_filter($mine)), true);
        }
        if ($field === 'experience_years') {
            return is_numeric($mine) && is_numeric($theirs) ? abs((float) $mine - (float) $theirs) < 0.005 : $this->fold($mine) === $this->fold($theirs);
        }
        if (in_array($field, ['hire_date', 'birth_date'], true)) {
            return $this->fold($mine) === $this->fold(substr((string) $theirs, 0, 10));
        }

        return $this->fold($mine) === $this->fold($theirs);
    }

    private function fold(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }

    private function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function left(array $row): bool
    {
        return in_array(strtolower((string) ($row['status'] ?? 'active')), ['left', 'inactive', 'terminated', 'resigned', 'retired'], true);
    }
}
