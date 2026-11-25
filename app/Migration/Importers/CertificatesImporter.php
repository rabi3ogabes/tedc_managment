<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\Registration;
use Illuminate\Support\Str;

/** Certificates issued by the old system. Each keeps its number; the person's completed registration is created if it is missing. */
class CertificatesImporter extends Importer
{
    public function kind(): string
    {
        return 'certificates';
    }

    public function fields(): array
    {
        return [
            'certificate_no' => $this->f('رقم الشهادة', 'Certificate no.', true, ['number', 'الرقم'], 'CERT-2022-0001'), 'employee_no' => $this->f('الرقم الوظيفي', 'Employee no.', true, ['personal no']), 'program_code' => $this->f('رمز البرنامج', 'Program code', true, ['program']),
            'issued_at' => $this->f('تاريخ الإصدار', 'Issued on', true, ['issue date', 'date']), 'hours' => $this->f('الساعات', 'Hours', false, ['hours']),
        ];
    }

    public function key(array $m): ?string
    {
        $k = Cleanser::code($m['certificate_no'] ?? '');

        return $k !== '' ? $k : null;
    }

    public function clean(array $m): array
    {
        $c = ['certificate_no' => Cleanser::code($m['certificate_no'] ?? ''), 'employee_no' => Cleanser::code($m['employee_no'] ?? ''), 'program_code' => Cleanser::code($m['program_code'] ?? ''), 'issued_at' => Cleanser::date($m['issued_at'] ?? null), 'hours' => Cleanser::number($m['hours'] ?? '')];
        $e = $this->missing($c, ['certificate_no', 'employee_no', 'program_code', 'issued_at']);
        if (filled($m['issued_at'] ?? null) && $c['issued_at'] === null) {
            $e[] = 'invalid:issued_at';
        }
        if ($c['employee_no'] && ! EmployeesImporter::employeeId($c['employee_no'])) {
            $e[] = 'unknown:employee_no';
        }
        if ($c['program_code'] && ! Program::where('code', $c['program_code'])->exists()) {
            $e[] = 'unknown:program_code';
        }

        return [$c, array_values(array_unique($e))];
    }

    public function exists(array $c): bool
    {
        return Certificate::where('certificate_no', $c['certificate_no'])->exists();
    }

    public function apply(array $c): array
    {
        $existing = Certificate::where('certificate_no', $c['certificate_no'])->first();
        if ($existing) {
            $before = $existing->only(['issued_at', 'hours']);
            $existing->forceFill(array_filter(['issued_at' => $c['issued_at'], 'hours' => $c['hours']], fn ($v) => $v !== null))->save();

            return ['action' => 'updated', 'id' => $existing->id, 'before' => $before, 'created' => []];
        }
        $employee = EmployeesImporter::employeeId($c['employee_no']);
        $program = Program::where('code', $c['program_code'])->first();
        $created = [];
        $reg = Registration::where('employee_id', $employee)->where('program_id', $program->id)->first();
        if (! $reg) {
            $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $employee, 'source' => 'bulk_import', 'status' => 'completed', 'approved_at' => now(), 'completed_at' => $c['issued_at']]);
            $created[] = ['table' => 'registrations', 'id' => $reg->id];
        }
        $cert = Certificate::create(['certificate_no' => $c['certificate_no'], 'verification_code' => strtoupper(Str::random(10)), 'registration_id' => $reg->id, 'employee_id' => $employee, 'program_id' => $program->id,
            'issued_at' => $c['issued_at'], 'hours' => $c['hours'] ?? $program->total_hours, 'status' => 'valid', 'meta' => ['imported' => true]]);
        array_unshift($created, ['table' => 'certificates', 'id' => $cert->id]);

        return ['action' => 'created', 'id' => $cert->id, 'before' => null, 'created' => $created];
    }
}
