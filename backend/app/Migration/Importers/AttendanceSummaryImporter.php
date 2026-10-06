<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\Program;
use App\Models\Registration;

/** Historical attendance as one figure per person and program (the old system kept no sessions): it sets the registration's attendance percentage. */
class AttendanceSummaryImporter extends Importer
{
    public function kind(): string
    {
        return 'attendance';
    }

    public function fields(): array
    {
        return [
            'employee_no' => $this->f('الرقم الوظيفي', 'Employee no.', true, ['personal no'], 'E-1001'), 'program_code' => $this->f('رمز البرنامج', 'Program code', true, ['program']), 'attendance_percent' => $this->f('نسبة الحضور', 'Attendance %', true, ['attendance', 'الحضور'], '92'),
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
        $c = ['employee_no' => Cleanser::code($m['employee_no'] ?? ''), 'program_code' => Cleanser::code($m['program_code'] ?? ''), 'attendance_percent' => Cleanser::number(str_replace('%', '', (string) ($m['attendance_percent'] ?? '')))];
        $e = $this->missing($c, ['employee_no', 'program_code']);
        if ($c['attendance_percent'] === null || $c['attendance_percent'] < 0 || $c['attendance_percent'] > 100) {
            $e[] = 'invalid:attendance_percent';
        }
        if ($c['employee_no'] && $c['program_code'] && ! $this->registration($c)) {
            $e[] = 'unknown:registration';   // the person was not registered in that program: import registrations first
        }

        return [$c, $e];
    }

    private function registration(array $c): ?Registration
    {
        return Registration::where('employee_id', EmployeesImporter::employeeId($c['employee_no']))->where('program_id', Program::where('code', $c['program_code'])->value('id'))->first();
    }

    public function exists(array $c): bool
    {
        return true;
    }

    public function apply(array $c): array
    {
        $r = $this->registration($c);
        $before = ['attendance_percent' => $r->attendance_percent];
        $r->update(['attendance_percent' => $c['attendance_percent']]);

        return ['action' => 'updated', 'id' => $r->id, 'before' => $before, 'created' => []];
    }
}
