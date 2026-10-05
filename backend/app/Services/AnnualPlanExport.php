<?php

namespace App\Services;

use App\Models\TrainingPlan;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Excel and PDF copies of the annual plan (Arabic first, English beside it). */
class AnnualPlanExport
{
    public function __construct(private readonly AnnualPlanService $plans) {}

    public function xlsx(TrainingPlan $plan): string
    {
        $exec = $this->plans->execution($plan);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle((string) $plan->year);
        $sheet->setRightToLeft(true);
        $sheet->fromArray([["{$plan->title_ar} / {$plan->title_en}", $plan->year, 'v'.$plan->version, $plan->status], []], null, 'A1');
        $sheet->fromArray([['البند', 'Item', 'الأولوية', 'الدرجة', 'المجموعات المخططة', 'المنفذة', 'المقاعد المخططة', 'الساعات', 'من', 'إلى', 'الحالة', 'التبرير']], null, 'A3');
        $row = 4;
        foreach ($plan->items()->orderByDesc('priority_score')->get() as $i) {
            $done = collect($exec['items'])->firstWhere('id', $i->id)['executed_groups'] ?? 0;
            $sheet->fromArray([[$i->title_ar, $i->title_en, $i->priority, $i->priority_score, $i->planned_groups, $done, $i->planned_seats, $i->planned_hours, $i->window_start?->toDateString(), $i->window_end?->toDateString(), $i->status, $i->rationale_ar]], null, 'A'.$row++);
        }
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function pdf(TrainingPlan $plan): string
    {
        $exec = $this->plans->execution($plan);
        $rows = '';
        foreach ($plan->items()->orderByDesc('priority_score')->get() as $i) {
            $rows .= '<tr><td>'.e($i->title_ar).'<br><small>'.e($i->title_en).'</small></td><td>'.e($i->priority).'</td><td>'.$i->planned_groups.'</td><td>'.$i->planned_seats.'</td><td>'.$i->planned_hours.'</td><td>'.e($i->window_start?->toDateString()).' → '.e($i->window_end?->toDateString()).'</td></tr>';
        }
        $t = $exec['totals'];
        $html = '<style>body{font-family:dejavusans;font-size:10pt}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bbb;padding:5px;text-align:right}th{background:#f1f1f1}</style>'
            .'<div dir="rtl"><h2>'.e($plan->title_ar).' — '.$plan->year.'</h2><p>'.e($plan->title_en).' · v'.$plan->version.' · '.e($plan->status).'</p>'
            ."<p>التنفيذ {$t['execution_percent']}% · التغيير بعد الاعتماد {$t['changed_percent']}% · طارئ {$t['emergency_percent']}%</p>"
            .'<table><tr><th>البند / Item</th><th>الأولوية</th><th>المجموعات</th><th>المقاعد</th><th>الساعات</th><th>النافذة</th></tr>'.$rows.'</table></div>';

        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetTitle($plan->title_ar);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
