<?php

namespace App\Ai\Forecast;

use App\Models\Forecast;
use App\Models\RiskFlag;
use Illuminate\Support\Collection;

/**
 * Forecasts and open risks as report documents (RPT-11), rendered by the shared ReportExporter to Excel, PDF and Word:
 * a section per dimension with the projected value, its range, the trend against the last known year, the confidence
 * and the explanation, plus a bar chart of the largest values for the PDF.
 */
class ForecastReport
{
    public const MAX_ROWS = 5000;

    private const DIMENSIONS = ['competency' => ['الكفايات', 'Competencies'], 'job' => ['المسميات الوظيفية', 'Job titles'], 'school' => ['المدارس', 'Schools']];

    private const RISKS = [
        'hours_shortfall' => ['نقص ساعات التطوير المهني', 'Professional development hours shortfall'],
        'licence_gap' => ['فجوة في متطلبات الرخصة', 'Licence requirement gap'],
        'group_underfill' => ['مجموعة ضعيفة الإقبال', 'Under-filled group'],
        'low_satisfaction' => ['رضا منخفض', 'Low satisfaction'],
    ];

    /** @return array{title: string, subtitle: string, sections: list<array<string, mixed>>} */
    public function forecasts(int $year, ?string $dimension, string $lang): array
    {
        $ar = $lang !== 'en';
        $rows = Forecast::where('year', $year)->when($dimension, fn ($q, $d) => $q->where('dimension', $d))->orderByDesc('value')->limit(self::MAX_ROWS)->get();
        $head = $ar ? ['الموضوع', 'القيمة المتوقعة', 'الحد الأدنى', 'الحد الأعلى', 'الاتجاه', 'الثقة', 'النموذج', 'التفسير'] : ['Subject', 'Forecast', 'Low', 'High', 'Trend', 'Confidence', 'Model', 'Explanation'];
        $sections = [[
            'heading' => $ar ? 'ملخص' : 'Summary',
            'paragraphs' => [$ar ? "عدد التوقعات: {$rows->count()}. القيم تقديرية مع مدى ثقة، وتُبنى من سجل الاحتياجات والرخص للسنوات السابقة." : "Forecasts: {$rows->count()}. Values are estimates with a range, built from the needs and licence history of earlier years."],
        ]];
        foreach (self::DIMENSIONS as $key => $names) {
            /** @var Collection<int, Forecast> $group */
            $group = $rows->where('dimension', $key)->values();
            if ($group->isEmpty()) {
                continue;
            }
            $top = $group->take(12);
            $max = (float) max(1, $top->max('value'));
            $sections[] = [
                'heading' => $names[$ar ? 0 : 1],
                'bars' => $top->map(fn (Forecast $f) => ['label' => $f->label, 'value' => round((float) $f->value, 1), 'max' => $max])->all(),
                'table' => ['head' => $head, 'rows' => $group->map(fn (Forecast $f) => [
                    $f->label, round((float) $f->value, 1), round((float) $f->low, 1), round((float) $f->high, 1), $this->trend($f, $ar),
                    round(ForecastService::confidence($f) * 100).'%', $f->model, (string) $f->explanation,
                ])->all()],
            ];
        }

        return [
            'title' => $ar ? "توقعات الاحتياج التدريبي لعام {$year}" : "Training demand forecasts for {$year}",
            'subtitle' => ($ar ? 'البعد: ' : 'Dimension: ').($dimension ? self::DIMENSIONS[$dimension][$ar ? 0 : 1] : ($ar ? 'الكل' : 'All')).' · '.($ar ? 'أُنشئ في ' : 'Generated on ').now()->format('Y-m-d H:i'),
            'sections' => $sections,
        ];
    }

    /** @return array{title: string, subtitle: string, sections: list<array<string, mixed>>} */
    public function risks(?string $type, string $lang): array
    {
        $ar = $lang !== 'en';
        $rows = RiskFlag::whereNull('resolved_at')->when($type, fn ($q, $t) => $q->where('type', $t))->orderByDesc('score')->limit(self::MAX_ROWS)->get();
        $name = fn (string $t) => self::RISKS[$t][$ar ? 0 : 1] ?? $t;
        $byType = $rows->groupBy('type');

        return [
            'title' => $ar ? 'قائمة المخاطر المفتوحة' : 'Open risks',
            'subtitle' => ($ar ? 'النوع: ' : 'Type: ').($type ? $name($type) : ($ar ? 'الكل' : 'All')).' · '.($ar ? 'أُنشئ في ' : 'Generated on ').now()->format('Y-m-d H:i'),
            'sections' => [
                ['heading' => $ar ? 'حسب النوع' : 'By type', 'bars' => $byType->map(fn ($g, $t) => ['label' => $name((string) $t), 'value' => $g->count(), 'max' => max(1, $byType->map->count()->max())])->values()->all()],
                ['heading' => $ar ? 'المخاطر' : 'Risks', 'table' => [
                    'head' => $ar ? ['النوع', 'الموضوع', 'الدرجة', 'الأسباب', 'الحالة', 'تاريخ الرصد'] : ['Type', 'Subject', 'Score', 'Reasons', 'Status', 'Flagged on'],
                    'rows' => $rows->map(fn (RiskFlag $r) => [$name($r->type), $r->label, round((float) $r->score * 100).'%', implode(' · ', array_map('strval', (array) $r->reasons)), $ar ? 'مفتوح' : 'Open', $r->created_at?->format('Y-m-d')])->all(),
                ]],
            ],
        ];
    }

    /** The change from the last known year to the forecast, as an arrow and a percentage. */
    private function trend(Forecast $f, bool $ar): string
    {
        $values = array_values((array) ($f->history['values'] ?? []));
        $last = $values ? (float) end($values) : 0.0;
        if ($last <= 0) {
            return $ar ? 'جديد' : 'new';
        }
        $pct = round(((float) $f->value - $last) / $last * 100);

        return ($pct > 2 ? '▲ ' : ($pct < -2 ? '▼ ' : '■ ')).($pct > 0 ? '+' : '').$pct.'%';
    }
}
