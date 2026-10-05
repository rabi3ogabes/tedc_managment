<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use App\Support\AccessScope;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Bulk registration import from Excel / CSV.
 * Expected columns (first row = header): employee_no | notes
 * Each row runs through the same eligibility and capacity rules as a direct nomination.
 */
class BulkImportService
{
    public function __construct(private readonly RegistrationService $registrations) {}

    /** @return array{imported: int, waitlisted: int, failed: int, rows: array<int, array>} */
    public function importRegistrations(Program $program, string $path, User $actor, bool $override = false): array
    {
        try {
            $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable) {
            throw new BusinessRuleException(__('messages.import.invalid_file'), 'invalid_file');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($sheet) ?? []);
        $noIndex = array_search('employee_no', $header, true);
        if ($noIndex === false) {
            throw new BusinessRuleException(__('messages.import.invalid_file'), 'invalid_file');
        }
        $notesIndex = array_search('notes', $header, true);

        $report = ['imported' => 0, 'waitlisted' => 0, 'failed' => 0, 'rows' => []];
        $scope = AccessScope::current($actor);

        foreach ($sheet as $i => $row) {
            $line = $i + 2;
            $number = trim((string) ($row[$noIndex] ?? ''));
            if ($number === '') {
                continue;
            }

            $employee = Employee::where('employee_no', $number)
                ->tap(fn ($q) => $scope->constrainEmployees($q))
                ->first();

            if (! $employee) {
                $report['failed']++;
                $report['rows'][] = ['row' => $line, 'employee_no' => $number, 'status' => 'failed', 'message' => __('messages.import.row_employee_missing', ['row' => $line, 'no' => $number])];

                continue;
            }

            try {
                $registration = $this->registrations->register($program, $employee, Registration::SOURCE_BULK, $actor, null, $override);
                if ($notesIndex !== false && filled($row[$notesIndex] ?? null)) {
                    $registration->update(['notes' => (string) $row[$notesIndex]]);
                }
                $registration->status === Registration::STATUS_WAITLISTED ? $report['waitlisted']++ : $report['imported']++;
                $report['rows'][] = ['row' => $line, 'employee_no' => $number, 'status' => $registration->status, 'message' => null];
            } catch (BusinessRuleException $e) {
                $report['failed']++;
                $report['rows'][] = [
                    'row' => $line,
                    'employee_no' => $number,
                    'status' => 'failed',
                    'message' => $e->getMessage().(isset($e->details['summary']) ? ' — '.$e->details['summary'] : ''),
                ];
            }
        }

        return $report;
    }

    public function template(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->fromArray([['employee_no', 'notes'], ['E-10001', 'ترشيح من الإدارة']]);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(40);

        $path = tempnam(sys_get_temp_dir(), 'tedc').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
