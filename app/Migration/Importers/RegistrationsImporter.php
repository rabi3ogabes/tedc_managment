<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\Program;
use App\Models\Registration;

/** Who took which program in the past (completed, approved, cancelled…), with the completion date and the attendance percentage. */
class RegistrationsImporter extends Importer
{
    public function kind(): string
    {
        return 'registrations';
    }

    public function fields(): array
    {
        return [
            'employee_no' => $this->f('الرقم الوظيفي', 'Employee no.', true, ['personal no', 'رقم الموظف'], 'E-1001'), 'program_code' => $this->f('رمز البرنامج', 'Program code', true, ['program', 'البرنامج'], 'PRG-2023-01'),
            'status' => $this->f('الحالة', 'Status', false, ['result', 'النتيجة'], 'completed'), 'completed_at' => $this->f('تاريخ الإكمال', 'Completed on', false, ['completion date']), 'attendance_percent' => $this->f('نسبة الحضور', 'Attendance %', false, ['attendance']),
        ];
    }

    public function key(array $m): ?string
    {
        $e = Cleanser::code($m['employee_no'] ?? '');
        $p = Cleanser::code($m['program_code'] ?? '');

        return $e !== '' && $p !== '' ? "{$e}|{$p}" : null;
    }

    public function clean(array $m): array
    {
        $status = match (mb_strtolower(trim((string) ($m['status'] ?? 'completed')))) {
            '', 'completed', 'complete', 'passed', 'مكتمل', 'ناجح', 'اجتاز' => 'completed', 'approved', 'مقبول', 'معتمد' => 'approved', 'cancelled', 'canceled', 'ملغى' => 'cancelled', 'rejected', 'مرفوض' => 'rejected', 'withdrawn', 'منسحب' => 'withdrawn', default => null,
        };
        $c = ['employee_no' => Cleanser::code($m['employee_no'] ?? ''), 'program_code' => Cleanser::code($m['program_code'] ?? ''), 'status' => $status, 'completed_at' => Cleanser::date($m['completed_at'] ?? null), 'attendance_percent' => Cleanser::number($m['attendance_percent'] ?? '')];
        $e = $this->missing($c, ['employee_no', 'program_code']);
        if ($status === null) {
            $e[] = 'invalid:status';
        }
        if (filled($m['completed_at'] ?? null) && $c['completed_at'] === null) {
            $e[] = 'invalid:completed_at';
        }
        if ($c['attendance_percent'] !== null && ($c['attendance_percent'] < 0 || $c['attendance_percent'] > 100)) {
            $e[] = 'invalid:attendance_percent';
        }
        if ($c['employee_no'] && ! EmployeesImporter::employeeId($c['employee_no'])) {
            $e[] = 'unknown:employee_no';
        }
        if ($c['program_code'] && ! Program::where('code', $c['program_code'])->exists()) {
            $e[] = 'unknown:program_code';
        }

        return [$c, $e];
    }

    public function exists(array $c): bool
    {
        return Registration::where('employee_id', EmployeesImporter::employeeId($c['employee_no']))->where('program_id', Program::where('code', $c['program_code'])->value('id'))->exists();
    }

    public function apply(array $c): array
    {
        $employee = EmployeesImporter::employeeId($c['employee_no']);
        $program = Program::where('code', $c['program_code'])->value('id');
        $r = Registration::where('employee_id', $employee)->where('program_id', $program)->first();
        $fields = array_filter(['status' => $c['status'], 'completed_at' => $c['completed_at'], 'attendance_percent' => $c['attendance_percent']], fn ($v) => $v !== null);
        if ($r) {
            $before = $r->only(array_keys($fields));
            $r->fill($fields)->save();

            return ['action' => 'updated', 'id' => $r->id, 'before' => $before, 'created' => []];
        }
        $r = Registration::create(['program_id' => $program, 'employee_id' => $employee, 'source' => 'bulk_import', 'approved_at' => now()] + $fields + ['status' => 'completed']);

        return ['action' => 'created', 'id' => $r->id, 'before' => null, 'created' => [['table' => 'registrations', 'id' => $r->id]]];
    }
}
