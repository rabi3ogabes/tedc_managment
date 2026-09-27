<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Report;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Report::where('type', '!=', 'ai_insight')->latest()->paginate($this->perPage($request)));
    }

    /**
     * Program participation report (Excel): participants, attendance, tasks, evaluation, certificate & impact.
     */
    public function program(Program $program): BinaryFileResponse
    {
        $rows = Registration::with(['employee.user', 'employee.school', 'employee.jobTitle', 'certificate'])
            ->where('program_id', $program->id)
            ->get()
            ->map(fn (Registration $r) => [
                $r->employee->employee_no,
                $r->employee->user->name_ar ?? $r->employee->user->name,
                $r->employee->school?->name_ar,
                $r->employee->jobTitle?->name_ar,
                $r->source,
                $r->status,
                $r->attendance_percent,
                $r->tasks_completed ? 'نعم' : 'لا',
                $r->evaluation_completed ? 'نعم' : 'لا',
                $r->certificate_status,
                $r->certificate?->certificate_no,
                $r->impact_score,
            ]);

        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle(mb_substr($program->code, 0, 30));
        $sheet->fromArray(array_merge([[
            'الرقم الوظيفي', 'الاسم', 'المدرسة', 'المسمى الوظيفي', 'مصدر التسجيل', 'الحالة',
            'نسبة الحضور %', 'المهام', 'التقييم', 'حالة الشهادة', 'رقم الشهادة', 'مؤشر الأثر',
        ]], $rows->all()));
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        Report::create([
            'type' => 'program_participation',
            'title' => "{$program->code} — {$program->title_ar}",
            'parameters' => ['program_id' => $program->id],
            'status' => 'ready',
            'generated_by' => $this->user()->id,
        ]);

        return response()->download($path, "program-{$program->code}.xlsx")->deleteFileAfterSend();
    }

    /**
     * Snapshot of the executive dashboard persisted for later reference / board packs.
     */
    public function snapshot(AnalyticsService $analytics): JsonResponse
    {
        $report = Report::create([
            'type' => 'executive_snapshot',
            'title' => 'Executive snapshot '.now()->toDateString(),
            'payload' => $analytics->executive(),
            'status' => 'ready',
            'generated_by' => $this->user()->id,
        ]);

        return response()->json(['data' => $report], 201);
    }
}
