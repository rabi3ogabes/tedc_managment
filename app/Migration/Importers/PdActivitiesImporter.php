<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\PdActivity;
use Illuminate\Support\Facades\DB;

/** Professional-development hours recorded elsewhere. They come in approved, with the hours as given. */
class PdActivitiesImporter extends Importer
{
    public function kind(): string
    {
        return 'pd';
    }

    public function fields(): array
    {
        return [
            'employee_no' => $this->f('الرقم الوظيفي', 'Employee no.', true, ['personal no']), 'type_code' => $this->f('رمز نوع النشاط', 'Activity type code', true, ['type', 'النوع']), 'title' => $this->f('النشاط', 'Activity', true, ['activity', 'title']),
            'date' => $this->f('التاريخ', 'Date', true, ['starts on']), 'hours' => $this->f('الساعات', 'Hours', true, ['hours']), 'provider' => $this->f('الجهة', 'Provider', false, ['organisation']),
        ];
    }

    public function key(array $m): ?string
    {
        $e = Cleanser::code($m['employee_no'] ?? '');
        $t = Cleanser::name($m['title'] ?? '');
        $d = Cleanser::date($m['date'] ?? null);

        return $e !== '' && $t !== '' ? $e.'|'.mb_strtolower($t).'|'.$d : null;
    }

    public function clean(array $m): array
    {
        $c = ['employee_no' => Cleanser::code($m['employee_no'] ?? ''), 'type_code' => Cleanser::code($m['type_code'] ?? ''), 'title' => Cleanser::name($m['title'] ?? ''), 'date' => Cleanser::date($m['date'] ?? null), 'hours' => Cleanser::number($m['hours'] ?? ''), 'provider' => Cleanser::name($m['provider'] ?? '') ?: null];
        $e = $this->missing($c, ['employee_no', 'type_code', 'title', 'date']);
        if (filled($m['date'] ?? null) && $c['date'] === null) {
            $e[] = 'invalid:date';
        }
        if ($c['hours'] === null || $c['hours'] <= 0 || $c['hours'] > 400) {
            $e[] = 'invalid:hours';
        }
        if ($c['employee_no'] && ! EmployeesImporter::employeeId($c['employee_no'])) {
            $e[] = 'unknown:employee_no';
        }
        if ($c['type_code'] && ! DB::table('pd_activity_types')->whereRaw('upper(code) = ?', [$c['type_code']])->exists()) {
            $e[] = 'unknown:type_code';
        }

        return [$c, array_values(array_unique($e))];
    }

    public function exists(array $c): bool
    {
        return PdActivity::where('employee_id', EmployeesImporter::employeeId($c['employee_no']))->where('title', $c['title'])->where('starts_on', $c['date'])->exists();
    }

    public function apply(array $c): array
    {
        $employee = EmployeesImporter::employeeId($c['employee_no']);
        $existing = PdActivity::where('employee_id', $employee)->where('title', $c['title'])->where('starts_on', $c['date'])->first();
        if ($existing) {
            $before = $existing->only(['duration_hours', 'approved_hours', 'computed_hours']);
            $existing->forceFill(['duration_hours' => $c['hours'], 'approved_hours' => $c['hours'], 'computed_hours' => $c['hours']])->save();

            return ['action' => 'updated', 'id' => $existing->id, 'before' => $before, 'created' => []];
        }
        $a = PdActivity::create(['employee_id' => $employee, 'type_id' => DB::table('pd_activity_types')->whereRaw('upper(code) = ?', [$c['type_code']])->value('id'), 'title' => $c['title'], 'provider' => $c['provider'], 'starts_on' => $c['date'], 'duration_hours' => $c['hours'],
            'computed_hours' => $c['hours'], 'approved_hours' => $c['hours'], 'status' => 'approved', 'decided_at' => now()]);

        return ['action' => 'created', 'id' => $a->id, 'before' => null, 'created' => [['table' => 'pd_activities', 'id' => $a->id]]];
    }
}
