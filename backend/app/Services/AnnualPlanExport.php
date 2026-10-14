<?php

namespace App\Services;

use App\Models\TrainingPlan;
use App\Support\PdfFonts;
use App\Support\ProgramTypes;
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
        $c = $this->plans->compliance($plan);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->setTitle((string) $plan->year);
        $sheet->setRightToLeft(true);
        $sheet->fromArray([["{$plan->title_ar} / {$plan->title_en}", $plan->year, 'v'.$plan->version, $plan->status], []], null, 'A1');
        $sheet->fromArray([['البند', 'Item', 'نوع البرنامج', 'الأولوية', 'الدرجة', 'المجموعات المخططة', 'المنفذة', 'المقاعد المخططة', 'الساعات', 'من', 'إلى', 'الحالة', 'التبرير']], null, 'A3');
        $row = 4;
        foreach ($plan->items()->orderByDesc('priority_score')->get() as $i) {
            $done = collect($exec['items'])->firstWhere('id', $i->id)['executed_groups'] ?? 0;
            $status = $i->status === 'deferred' ? 'مؤجل ('.($i->deferred_reason === 'hours_over_cap' ? 'حد الساعات' : 'حد المقاعد').')' : $i->status;
            $sheet->fromArray([[$i->title_ar, $i->title_en, ProgramTypes::name($i->program_type), $i->priority, $i->priority_score, $i->planned_groups, $done, $i->planned_seats, $i->planned_hours, $i->window_start?->toDateString(), $i->window_end?->toDateString(), $status, $i->rationale_ar]], null, 'A'.$row++);
        }
        foreach ($this->complianceRows($c) as $line) {
            $sheet->fromArray([$line], null, 'A'.(++$row));
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
        $c = $this->plans->compliance($plan);
        $rows = '';
        foreach ($plan->items()->orderByDesc('priority_score')->get() as $i) {
            $rows .= '<tr><td>'.e($i->title_ar).'<br><small>'.e($i->title_en).'</small></td><td>'.e(ProgramTypes::name($i->program_type)).'</td><td>'.e($i->priority).'</td><td>'.$i->planned_groups.'</td><td>'.$i->planned_seats.'</td><td>'.$i->planned_hours.'</td><td>'.e($i->window_start?->toDateString()).' → '.e($i->window_end?->toDateString()).'</td><td>'.e($i->status).'</td></tr>';
        }
        $summary = collect($this->complianceRows($c))->map(fn ($r) => '<tr><td>'.e($r[0]).'</td><td>'.e($r[1]).'</td></tr>')->implode('');
        $t = $exec['totals'];
        $html = '<style>body{font-family:dejavusans;font-size:10pt}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bbb;padding:5px;text-align:right}th{background:#f1f1f1}</style>'
            .'<div dir="rtl"><h2>'.e($plan->title_ar).' — '.$plan->year.'</h2><p>'.e($plan->title_en).' · v'.$plan->version.' · '.e($plan->status).'</p>'
            ."<p>التنفيذ {$t['execution_percent']}% · التغيير بعد الاعتماد {$t['changed_percent']}% · طارئ {$t['emergency_percent']}%</p>"
            .'<table><tr><th>البند / Item</th><th>النوع</th><th>الأولوية</th><th>المجموعات</th><th>المقاعد</th><th>الساعات</th><th>النافذة</th><th>الحالة</th></tr>'.$rows.'</table>'
            .'<h3>الالتزام بقواعد العام / Compliance with the year\'s rules</h3><table>'.$summary.'</table></div>';

        $mpdf = new Mpdf(PdfFonts::apply(['mode' => 'utf-8', 'format' => 'A4-L', 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]));
        $mpdf->SetTitle($plan->title_ar);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /** @return list<array{0: string, 1: string}> the compliance summary as label / value rows */
    private function complianceRows(array $c): array
    {
        $cap = fn (array $x) => $x['max'] === null ? (string) $x['used'] : "{$x['used']} / {$x['max']}";
        $rows = [['الالتزام بقواعد العام / Compliance', ''], ['المقاعد / Seats', $cap($c['caps']['seats'])], ['الساعات / Hours', $cap($c['caps']['hours'])]];
        foreach ($c['by_type'] as $type => $n) {
            $rows[] = [$type === 'missing' ? 'النوع غير محدد / Type missing' : ProgramTypes::name($type).' / '.ProgramTypes::name($type, 'en'), (string) $n];
        }
        foreach ($c['mandatory'] as $m) {
            $rows[] = ['فئة إلزامية / Mandatory: '.$m['name_ar'], $m['covered'] ? 'مغطاة / covered' : 'غير مغطاة / missing'];
        }
        foreach ($c['violations'] as $v) {
            $rows[] = ['مخالفة / Violation', $v['message']['ar'].' — '.$v['message']['en']];
        }
        if ($c['exception']) {
            $rows[] = ['استثناء معتمد / Approved exception', $c['exception']['reason'].' — '.($c['exception']['by_name'] ?? '')];
        }

        return $rows;
    }
}
