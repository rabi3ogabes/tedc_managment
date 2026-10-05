<?php

namespace App\Services\Reports;

use App\Services\ThemeService;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * One document model, three outputs: Excel (a summary sheet and one sheet per table), PDF (Arabic shaped, bar charts and tables)
 * and Word (narrative). Phase 12 reuses it for every report.
 *
 * Document: ['title' => string, 'subtitle' => ?string, 'sections' => list of
 *   ['heading' => string, 'paragraphs' => list<string>, 'bullets' => list<string>, 'table' => ['head' => list<string>, 'rows' => list<list>], 'bars' => list<['label' => string, 'value' => float, 'max' => float]>]]
 */
class ReportExporter
{
    public const MIME = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'csv' => 'text/csv; charset=UTF-8'];

    public function render(array $doc, string $format, string $locale = 'ar'): string
    {
        return match ($format) {
            'xlsx' => $this->xlsx($doc, $locale === 'ar'),
            'pdf' => $this->pdf($doc, $locale === 'ar'),
            'docx' => $this->docx($doc, $locale === 'ar'),
            'csv' => $this->csv($doc),
            default => abort(422),
        };
    }

    public function docx(array $doc, bool $rtl): string
    {
        $w = new DocxWriter($rtl);
        $w->heading($doc['title'], 1);
        if (! empty($doc['subtitle'])) {
            $w->text($doc['subtitle'], ['color' => '666666']);
        }
        foreach ($doc['sections'] as $s) {
            if (! empty($s['heading'])) {
                $w->heading($s['heading'], 2);
            }
            foreach ($s['paragraphs'] ?? [] as $p) {
                $w->text($p);
            }
            if (! empty($s['bullets'])) {
                $w->bullets($s['bullets']);
            }
            if (! empty($s['bars'])) {
                $w->table([$s['heading'] ?? '', '%'], array_map(fn ($b) => [$b['label'], $b['value']], $s['bars']));
            }
            if (! empty($s['table'])) {
                $w->table($s['table']['head'], $s['table']['rows']);
            }
        }

        return $w->bytes();
    }

    public function xlsx(array $doc, bool $rtl): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($this->name($doc['title'], 'Summary'));
        $sheet->setRightToLeft($rtl);
        $r = 1;
        $sheet->setCellValue("A{$r}", $doc['title'])->getStyle("A{$r}")->getFont()->setBold(true)->setSize(14);
        $r += 2;
        foreach ($doc['sections'] as $s) {
            if (! empty($s['heading'])) {
                $sheet->setCellValue("A{$r}", $s['heading'])->getStyle("A{$r}")->getFont()->setBold(true);
                $r++;
            }
            foreach (array_merge($s['paragraphs'] ?? [], $s['bullets'] ?? []) as $p) {
                $sheet->setCellValue("A{$r}", $p);
                $r++;
            }
            foreach ($s['bars'] ?? [] as $b) {
                $sheet->setCellValue("A{$r}", $b['label'])->setCellValue("B{$r}", $b['value']);
                $r++;
            }
            $r++;
        }
        $sheet->getColumnDimension('A')->setWidth(60);
        $i = 1;
        foreach ($doc['sections'] as $s) {
            if (empty($s['table']) || count($s['table']['rows']) === 0) {
                continue;
            }
            $t = $book->createSheet()->setTitle($this->name($s['heading'] ?? '', 'Table '.$i++));
            $t->setRightToLeft($rtl);
            $t->fromArray(array_merge([$s['table']['head']], array_map(fn ($row) => array_map(fn ($c) => is_scalar($c) || $c === null ? $c : json_encode($c, JSON_UNESCAPED_UNICODE), $row), $s['table']['rows'])), null, 'A1');
            $t->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($s['table']['head'])).'1')->getFont()->setBold(true);
            foreach (range(1, count($s['table']['head'])) as $col) {
                $t->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
        }
        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function csv(array $doc): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($doc['sections'] as $s) {
            if (empty($s['table'])) {
                continue;
            }
            fputcsv($out, [$s['heading'] ?? '']);
            fputcsv($out, $s['table']['head']);
            foreach ($s['table']['rows'] as $row) {
                fputcsv($out, array_map(fn ($c) => is_scalar($c) || $c === null ? $c : json_encode($c, JSON_UNESCAPED_UNICODE), $row));
            }
            fputcsv($out, []);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function pdf(array $doc, bool $rtl): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $center = app(ThemeService::class)->centerName();
        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';
        $html = "<html dir=\"{$dir}\"><head><meta charset=\"utf-8\"><style>body{font-family:dejavusans;font-size:10pt;color:#222;} h1{color:#7b1e3a;font-size:20pt;margin:0 0 2mm;} h2{color:#7b1e3a;font-size:13pt;border-bottom:1px solid #d9b8c4;padding-bottom:1mm;margin-top:6mm;} .sub{color:#666;margin-bottom:4mm;} table{border-collapse:collapse;width:100%;margin:2mm 0;} th{background:#f3e8ec;text-align:{$align};} th,td{border:1px solid #cfcfcf;padding:1.5mm 2mm;font-size:9pt;text-align:{$align};} .bar{background:#eee;height:4mm;} .fill{background:#c9a24d;height:4mm;}</style></head><body>";
        $html .= '<div style="color:#999;font-size:8pt;">'.$e($center[$rtl ? 'ar' : 'en'] ?? '').'</div><h1>'.$e($doc['title']).'</h1>'.(! empty($doc['subtitle']) ? '<div class="sub">'.$e($doc['subtitle']).'</div>' : '');
        foreach ($doc['sections'] as $s) {
            $html .= ! empty($s['heading']) ? '<h2>'.$e($s['heading']).'</h2>' : '';
            foreach ($s['paragraphs'] ?? [] as $p) {
                $html .= '<p>'.$e($p).'</p>';
            }
            if (! empty($s['bullets'])) {
                $html .= '<ul>'.implode('', array_map(fn ($b) => '<li>'.$e($b).'</li>', $s['bullets'])).'</ul>';
            }
            if (! empty($s['bars'])) {
                $html .= '<table>'.implode('', array_map(fn ($b) => '<tr><td style="width:38%">'.$e($b['label']).'</td><td><div class="bar"><div class="fill" style="width:'.max(1, min(100, round(($b['max'] ?? 100) > 0 ? $b['value'] / $b['max'] * 100 : 0))).'%"></div></div></td><td style="width:12%">'.$e($b['value']).'</td></tr>', $s['bars'])).'</table>';
            }
            if (! empty($s['table'])) {
                $html .= '<table><tr>'.implode('', array_map(fn ($h) => '<th>'.$e($h).'</th>', $s['table']['head'])).'</tr>'.implode('', array_map(fn ($row) => '<tr>'.implode('', array_map(fn ($c) => '<td>'.$e(is_scalar($c) || $c === null ? $c : json_encode($c, JSON_UNESCAPED_UNICODE)).'</td>', $row)).'</tr>', $s['table']['rows'])).'</table>';
            }
        }
        $html .= '</body></html>';

        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 14, 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetTitle($doc['title']);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private function name(string $text, string $fallback): string
    {
        $clean = trim(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $text));

        return mb_substr($clean !== '' ? $clean : $fallback, 0, 28);
    }
}
