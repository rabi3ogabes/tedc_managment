<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Registration;
use App\Models\Role;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AttendanceExportTest extends TestCase
{
    private function world(): array
    {
        $program = $this->makeProgram();
        $s1 = $this->makeSession($program, now()->subDays(2), 2);
        $s2 = $this->makeSession($program, now()->subDay(), 2);
        $regs = [];
        foreach (range(1, 3) as $i) {
            $regs[] = Registration::create(['program_id' => $program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        }
        Attendance::create(['program_session_id' => $s1->id, 'registration_id' => $regs[0]->id, 'employee_id' => $regs[0]->employee_id, 'status' => 'present', 'method' => 'signature', 'minutes_attended' => 120, 'check_in_at' => $s1->starts_at, 'check_out_at' => $s1->ends_at]);
        Attendance::create(['program_session_id' => $s1->id, 'registration_id' => $regs[1]->id, 'employee_id' => $regs[1]->employee_id, 'status' => 'late', 'method' => 'qr', 'minutes_attended' => 90]);
        Attendance::create(['program_session_id' => $s2->id, 'registration_id' => $regs[0]->id, 'employee_id' => $regs[0]->employee_id, 'status' => 'present', 'method' => 'qr', 'minutes_attended' => 120]);

        return [$program, [$s1, $s2], $regs];
    }

    private function sheet(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $bytes);

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    public function test_a_session_exports_to_excel_with_correct_totals_and_to_pdf(): void
    {
        [, [$s1]] = $this->world();
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $xlsx = $this->asUser($coordinator)->get("/api/v1/admin/sessions/{$s1->id}/attendance/export?format=xlsx")->assertOk()->getContent();
        $rows = $this->sheet($xlsx);
        $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('signature', $flat);
        $this->assertStringContainsString('late', $flat);
        $totals = collect($rows)->first(fn ($r) => in_array('Total', $r, true) || in_array('الإجمالي', $r, true));
        $this->assertNotNull($totals);
        $this->assertContains(3, array_map('intval', array_filter($totals, 'is_numeric')), 'expected 3');

        $pdf = $this->asUser($coordinator)->get("/api/v1/admin/sessions/{$s1->id}/attendance/export?format=pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_group_exports_one_column_per_session_and_the_blank_sheet_is_a_printable_pdf(): void
    {
        [$program] = $this->world();
        $group = $program->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $rows = $this->sheet($this->asUser($coordinator)->get("/api/v1/admin/groups/{$group->id}/attendance/export?format=xlsx")->assertOk()->getContent());
        $this->assertGreaterThanOrEqual(4, count($rows[0]), 'name + 2 sessions + percent');
        $this->assertCount(1 + 3 + 0, array_filter($rows, fn ($r) => array_filter($r) !== []));

        $session = $program->sessions()->first();
        $this->asUser($coordinator)->get("/api/v1/admin/sessions/{$session->id}/sheet.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->get("/api/v1/admin/sessions/{$session->id}/attendance/export?format=xlsx")->assertForbidden();
    }
}
