<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingGroup;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Attendance sheets: per session and per group (Excel + PDF) and a blank printable sheet with a QR for the paper fallback. */
class AttendanceExport
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function sessionXlsx(ProgramSession $session): string
    {
        $report = $this->attendance->sessionReport($session);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle('Attendance');
        $sheet->setRightToLeft(true);
        $sheet->fromArray([[$session->title_ar.' / '.$session->title_en, $session->starts_at->toDateTimeString()], []], null, 'A1');
        $sheet->fromArray([['الرقم الوظيفي', 'الاسم', 'المدرسة', 'الحالة', 'الطريقة', 'الدخول', 'الخروج', 'الدقائق']], null, 'A3');
        $methods = $session->attendance()->pluck('method', 'registration_id');
        $row = 4;
        foreach ($report['rows'] as $r) {
            $sheet->fromArray([[$r['employee_no'], $r['name'], $r['school'], $r['status'], $methods[$r['registration_id']] ?? '', $r['check_in_at'], $r['check_out_at'], $r['minutes']]], null, 'A'.$row++);
        }
        $sheet->fromArray([['Total', $report['expected'], 'present', $report['present'], 'late', $report['late'], 'absent', $report['absent']]], null, 'A'.($row + 1));

        return $this->bytes($sheet->getParent());
    }

    public function sessionPdf(ProgramSession $session): string
    {
        $report = $this->attendance->sessionReport($session);
        $rows = '';
        foreach ($report['rows'] as $i => $r) {
            $rows .= '<tr><td>'.($i + 1).'</td><td>'.e($r['employee_no']).'</td><td>'.e($r['name']).'</td><td>'.e($r['school']).'</td><td>'.e($r['status']).'</td><td>'.e(substr((string) $r['check_in_at'], 11, 5)).'</td><td>'.e(substr((string) $r['check_out_at'], 11, 5)).'</td></tr>';
        }
        $html = $this->style().'<div dir="rtl"><h2>'.e($session->title_ar).' — '.e($session->title_en).'</h2><p>'.e($session->starts_at->toDateTimeString()).'</p>'
            ."<p>المتوقع {$report['expected']} · حاضر {$report['present']} · متأخر {$report['late']} · غائب {$report['absent']}</p>"
            .'<table><tr><th>#</th><th>الرقم</th><th>الاسم</th><th>المدرسة</th><th>الحالة</th><th>الدخول</th><th>الخروج</th></tr>'.$rows.'</table></div>';

        return $this->pdf($html, 'A4');
    }

    public function groupXlsx(TrainingGroup $group): string
    {
        $group->loadMissing('program');
        $sessions = ProgramSession::where('program_id', $group->program_id)->when(! $group->program->hasSingleGroup(), fn ($q) => $q->where('training_group_id', $group->id))->where('status', '!=', 'cancelled')->orderBy('starts_at')->get();
        $regs = Registration::with(['employee.user:id,name,name_ar'])->where('program_id', $group->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->when(! $group->program->hasSingleGroup(), fn ($q) => $q->where('training_group_id', $group->id))->get();
        $records = Attendance::whereIn('registration_id', $regs->pluck('id'))->get()->groupBy('registration_id');

        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->fromArray([array_merge(['الاسم'], $sessions->map(fn ($s) => $s->starts_at->format('m-d H:i'))->all(), ['النسبة %'])], null, 'A1');
        $row = 2;
        foreach ($regs as $r) {
            $by = ($records[$r->id] ?? collect())->keyBy('program_session_id');
            $sheet->fromArray([array_merge([$r->employee->user?->displayName()], $sessions->map(fn ($s) => $by->get($s->id)?->status ?? 'absent')->all(), [$r->attendance_percent])], null, 'A'.$row++);
        }

        return $this->bytes($sheet->getParent());
    }

    public function groupPdf(TrainingGroup $group): string
    {
        $group->loadMissing('program');
        $sessions = ProgramSession::where('program_id', $group->program_id)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get();
        $regs = Registration::with('employee.user:id,name,name_ar')->where('program_id', $group->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
        $records = Attendance::whereIn('registration_id', $regs->pluck('id'))->get()->groupBy('registration_id');
        $head = '<th>الاسم</th>'.$sessions->map(fn ($s) => '<th>'.e($s->starts_at->format('m-d')).'</th>')->implode('').'<th>%</th>';
        $body = '';
        foreach ($regs as $r) {
            $by = ($records[$r->id] ?? collect())->keyBy('program_session_id');
            $body .= '<tr><td>'.e($r->employee->user?->displayName()).'</td>'.$sessions->map(fn ($s) => '<td>'.e(substr($by->get($s->id)?->status ?? 'absent', 0, 1)).'</td>')->implode('').'<td>'.e($r->attendance_percent).'</td></tr>';
        }

        return $this->pdf($this->style().'<div dir="rtl"><h2>'.e($group->program->title_ar).' — '.e($group->code).'</h2><table><tr>'.$head.'</tr>'.$body.'</table></div>', 'A4-L');
    }

    /** Names, empty signature boxes and a QR of the session, to print when the screens fail. */
    public function blankSheet(ProgramSession $session): string
    {
        $qr = (new QRCode(new QROptions(['outputBase64' => true, 'eccLevel' => EccLevel::M, 'scale' => 4])))->render(rtrim((string) config('tedc.web_url'), '/').'/admin/sessions/'.$session->id.'/qr');
        $regs = Registration::with(['employee.user:id,name,name_ar'])->where('program_id', $session->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get()->sortBy(fn ($r) => $r->employee->user?->displayName());
        $rows = '';
        foreach ($regs->values() as $i => $r) {
            $rows .= '<tr style="height:34px"><td>'.($i + 1).'</td><td>'.e($r->employee->employee_no).'</td><td>'.e($r->employee->user?->displayName()).'</td><td></td><td></td></tr>';
        }
        $html = $this->style().'<div dir="rtl"><img src="'.$qr.'" style="float:left;width:80px"><h2>'.e($session->title_ar).' — '.e($session->title_en).'</h2><p>'.e($session->starts_at->toDateTimeString()).'</p><table><tr><th>#</th><th>الرقم</th><th>الاسم</th><th>توقيع الدخول</th><th>توقيع الخروج</th></tr>'.$rows.'</table></div>';

        return $this->pdf($html, 'A4');
    }

    private function style(): string
    {
        return '<style>body{font-family:dejavusans;font-size:10pt}table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:4px;text-align:right}th{background:#eee}</style>';
    }

    private function pdf(string $html, string $format): string
    {
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => $format, 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function bytes(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        (new Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
