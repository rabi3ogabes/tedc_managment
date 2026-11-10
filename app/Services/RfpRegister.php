<?php

namespace App\Services;

/**
 * Reads docs/rfp/gap-register.md (the living list of the RFP's 278 requirements) into the structure the
 * "RFP Compliance" screen and the compliance sheet use. The markdown stays the single source of truth.
 */
class RfpRegister
{
    private const STATUS = ['✅' => 'available', '🟡' => 'partial', '🔴' => 'missing', '⚪' => 'vendor'];

    /** @return array<string, mixed> */
    public function build(string $path): array
    {
        return $this->parse((string) file_get_contents($path)) + ['generated_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function parse(string $markdown): array
    {
        $modules = [];
        $phases = [];
        $mandatory31 = [];
        $module = null;
        $section = null;

        foreach (preg_split('/\R/u', $markdown) as $line) {
            if (preg_match('/^## (.+)$/u', $line, $m)) {
                $section = str_contains($m[1], 'mandatory items') ? 'mandatory' : (str_contains($m[1], 'Full register') ? 'register' : 'other');
                $module = null;

                continue;
            }
            if (preg_match('/^### Phase (\d+) — (.+?)\s+\((\d+)\)\s*$/u', $line, $m)) {
                $phases[(int) $m[1]] = ['phase' => (int) $m[1], 'title' => trim($m[2]), 'open' => (int) $m[3]];

                continue;
            }
            if ($section === null) {
                continue;
            }
            if ($section === 'register' && preg_match('/^### ([A-Z]+) · (.+?) — (.+)$/u', $line, $m)) {
                $module = count($modules);
                $modules[] = ['code' => $m[1], 'title_en' => trim($m[2]), 'title_ar' => trim($m[3]), 'items' => []];

                continue;
            }
            if ($section === 'register' && $module !== null && preg_match('/^\|\s*([A-Z]+-\d+)(\s*★)?\s*\|\s*(.+?)\s*\|\s*(✅|🟡|🔴)\s+\w+\s*\|\s*(.*)\|\s*(—|\d+)\s*\|\s*$/u', $line, $m)) {
                $modules[$module]['items'][] = [
                    'id' => $m[1], 'mandatory' => trim($m[2]) !== '', 'requirement' => trim($m[3]), 'status' => self::STATUS[$m[4]],
                    'evidence' => trim($m[5]), 'phase' => $m[6] === '—' ? null : (int) $m[6],
                ];

                continue;
            }
            if ($section === 'mandatory' && preg_match('/^\|\s*(\d+)\s*\|\s*(.+?)\s*\|\s*(✅|🟡|🔴|⚪)\s+\w+\s*\|\s*(.*?)\s*\|\s*$/u', $line, $m)) {
                $mandatory31[] = ['no' => (int) $m[1], 'requirement' => trim($m[2]), 'status' => self::STATUS[$m[3]], 'notes' => trim($m[4])];
            }
        }

        $items = collect($modules)->flatMap(fn ($m) => $m['items']);
        $count = fn (string $status) => $items->where('status', $status)->count();
        $byPhase = $items->whereNotNull('phase')->groupBy('phase');
        ksort($phases);

        return [
            'totals' => [
                'total' => $items->count(), 'available' => $count('available'), 'partial' => $count('partial'), 'missing' => $count('missing'),
                'mandatory' => $items->where('mandatory', true)->count(), 'open' => $items->count() - $count('available'),
            ],
            'phases' => array_values(array_map(fn (array $p) => $p + [
                'items' => $byPhase->get($p['phase'], collect())->count(),
                'done' => $byPhase->get($p['phase'], collect())->where('status', 'available')->count(),
            ], $phases)),
            'modules' => $modules,
            'mandatory31' => $mandatory31,
        ];
    }
}
