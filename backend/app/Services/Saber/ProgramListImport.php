<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\User;
use App\Services\ProgramCatalogSettings;
use App\Services\TrainingGroupService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * «استيراد قائمة البرامج» (docs/saber/04-data-tools.md, D2): the centre's programme list workbook — every sheet with a header row holding
 * «اسم البرنامج» — read with its type, delivery, audience, hours, days, objective, number of groups, kit, execution date and notes; checked
 * (issues listed per sheet and row) before anything is written; each row then updates the programme of the same name or creates it as a
 * draft with its groups. Also readable from a OneDrive / SharePoint link shared with «anyone with the link».
 */
class ProgramListImport
{
    private const COLUMNS = [
        'name' => ['اسم البرنامج'], 'type' => ['نوع البرنامج'], 'method' => ['آلية التدريب', 'الية التدريب', 'طريقة التنفيذ'], 'audience' => ['الفئة المستهدفة'],
        'hours' => ['عدد الساعات'], 'days' => ['عدد الأيام', 'عدد الايام'], 'objective' => ['الهدف العام'], 'groups' => ['الكتل التدريبية', 'عدد المجموعات'],
        'kit' => ['توفر الحقيبة', 'الحقيبة التدريبية'], 'date' => ['موعد تنفيذ', 'موعد التنفيذ'], 'notes' => ['ملاحظات', 'الملاحظات'],
    ];

    /** Hosts a programme list may be read from by link (nothing else is fetched). */
    private const LINK_HOSTS = ['sharepoint.com', 'onedrive.live.com', '1drv.ms'];

    public function __construct(private readonly ProgramCatalogSettings $catalog, private readonly TrainingGroupService $groups) {}

    /** Downloads a shared OneDrive / SharePoint workbook to a temporary file. */
    public function download(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        abort_unless(str_starts_with($url, 'https://') && collect(self::LINK_HOSTS)->contains(fn ($h) => $host === $h || str_ends_with($host, ".{$h}")), 422, __('messages.saber.import.bad_link'));
        $res = Http::timeout(60)->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]])->get($url.(str_contains($url, '?') ? '&' : '?').'download=1');
        if (! $res->successful() || strlen($res->body()) < 100) {
            throw new BusinessRuleException(__('messages.saber.import.link_failed'), 'link_failed');
        }
        $path = tempnam(sys_get_temp_dir(), 'saber').'.xlsx';
        file_put_contents($path, $res->body());

        return $path;
    }

    /** @return array{rows: list<array<string, mixed>>, issues: list<array{sheet: string, row: int, level: string, message: string}>} */
    public function preview(string $path): array
    {
        $book = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
        $existing = Program::get(['id', 'title_ar', 'title_en', 'training_days'])->mapWithKeys(fn ($p) => [ArabicMatch::normalize($p->title_ar ?: $p->title_en) => $p]);
        $rows = [];
        $issues = [];
        $seen = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $title = $sheet->getTitle();
            $data = $sheet->toArray(null, true, false, false);
            $headerAt = null;
            foreach (array_slice($data, 0, 15, true) as $i => $row) {
                if (collect($row)->contains(fn ($v) => is_string($v) && ArabicMatch::normalize($v) === ArabicMatch::normalize('اسم البرنامج'))) {
                    $headerAt = $i;
                    break;
                }
            }
            if ($headerAt === null) {
                $issues[] = ['sheet' => $title, 'row' => 0, 'level' => 'warning', 'message' => __('messages.saber.import.no_header')];

                continue;
            }
            $cols = $this->columns($data[$headerAt]);
            foreach (array_slice($data, $headerAt + 1, null, true) as $i => $row) {
                $excelRow = $i + 1;
                $get = fn (string $k) => isset($cols[$k]) ? $row[$cols[$k]] ?? null : null;
                $name = trim(preg_replace('/\s+/u', ' ', (string) $get('name')) ?? '');
                if ($name === '' || $name === '--') {
                    if (collect($row)->filter(fn ($v) => $v !== null && trim((string) $v) !== '')->isNotEmpty()) {
                        $issues[] = ['sheet' => $title, 'row' => $excelRow, 'level' => 'warning', 'message' => __('messages.saber.import.no_name')];
                    }

                    continue;
                }
                $key = ArabicMatch::normalize($name);
                if (isset($seen[$key])) {
                    $issues[] = ['sheet' => $title, 'row' => $excelRow, 'level' => 'warning', 'message' => __('messages.saber.import.duplicate', ['name' => $name])];

                    continue;
                }
                $seen[$key] = true;
                $hours = $this->number($get('hours'));
                $days = (int) $this->number($get('days'));
                $date = $this->date($get('date'));
                if ($get('date') !== null && trim((string) $get('date')) !== '' && ! $date) {
                    $issues[] = ['sheet' => $title, 'row' => $excelRow, 'level' => 'warning', 'message' => __('messages.saber.import.bad_date', ['value' => (string) $get('date')])];
                }
                if (! $days) {
                    $issues[] = ['sheet' => $title, 'row' => $excelRow, 'level' => 'info', 'message' => __('messages.saber.import.no_days', ['name' => $name])];
                }
                $match = $existing[$key] ?? null;
                $rows[] = [
                    'sheet' => $title, 'row' => $excelRow, 'name' => $name, 'type' => $this->text($get('type')), 'method' => $this->text($get('method')), 'audience' => $this->text($get('audience')),
                    'hours' => $hours, 'days' => $days ?: null, 'objective' => $this->text($get('objective')), 'groups' => max(1, (int) $this->number($get('groups'))),
                    'kit' => $this->text($get('kit')), 'date' => $date, 'notes' => $this->text($get('notes')),
                    'action' => $match ? 'update' : 'create', 'program_id' => $match?->id,
                ];
            }
        }
        if ($rows === []) {
            throw new BusinessRuleException(__('messages.saber.import.no_rows'), 'no_rows');
        }

        return ['rows' => $rows, 'issues' => $issues];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, groups_added: int}
     */
    public function apply(array $rows, User $actor): array
    {
        $out = ['created' => 0, 'updated' => 0, 'groups_added' => 0];
        foreach ($rows as $r) {
            DB::transaction(function () use ($r, $actor, &$out) {
                $program = ! empty($r['program_id']) ? Program::find($r['program_id']) : null;
                $program ??= Program::get(['id', 'title_ar', 'title_en'])->first(fn ($p) => ArabicMatch::normalize($p->title_ar ?: $p->title_en) === ArabicMatch::normalize($r['name']));
                $mode = $this->mode($r['method'] ?? null);
                $kit = $this->kit($r['kit'] ?? null);
                $days = $r['days'] ? (int) $r['days'] : null;
                $hours = $r['hours'] ? (float) $r['hours'] : null;
                if ($program) {
                    $program->update(array_filter([
                        'training_days' => $days, 'total_hours' => $hours, 'delivery_mode' => $mode, 'kit_ready' => $kit,
                        'start_date' => $program->start_date ? null : ($r['date'] ?? null),
                        'objectives' => empty($program->objectives) && ! empty($r['objective']) ? [$r['objective']] : null,
                    ], fn ($v) => $v !== null));
                    $out['updated']++;
                } else {
                    $program = Program::create([
                        'code' => $this->catalog->nextCode(null), 'title_ar' => $r['name'], 'title_en' => $r['name'], 'status' => Program::STATUS_DRAFT,
                        'total_hours' => $hours ?? ($days ? $days * 5 : 5), 'training_days' => $days, 'delivery_mode' => $mode ?? 'in_person', 'kit_ready' => $kit,
                        'start_date' => $r['date'] ?? null, 'capacity' => 25, 'min_attendance_percent' => 80, 'registration_modes' => Program::MODES,
                        'objectives' => ! empty($r['objective']) ? [$r['objective']] : null,
                        'description_ar' => collect([$r['type'] ? "نوع البرنامج: {$r['type']}" : null, $r['audience'] ? "الفئة المستهدفة: {$r['audience']}" : null, $r['notes'] ? "ملاحظات: {$r['notes']}" : null])->filter()->implode("\n") ?: null,
                        'created_by' => $actor->id,
                    ]);
                    $out['created']++;
                }
                for ($n = $program->groups()->count(); $n < min((int) ($r['groups'] ?? 1), 30); $n++) {
                    $this->groups->create($program, [], $actor);
                    $out['groups_added']++;
                }
            });
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, int> field → column index */
    private function columns(array $header): array
    {
        $cols = [];
        foreach ($header as $i => $v) {
            $h = ArabicMatch::normalize((string) $v);
            foreach (self::COLUMNS as $field => $names) {
                if (! isset($cols[$field]) && collect($names)->contains(fn ($n) => $h !== '' && str_starts_with($h, ArabicMatch::normalize($n)))) {
                    $cols[$field] = $i;
                }
            }
        }

        return $cols;
    }

    private function text(mixed $v): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');

        return $s === '' || $s === '--' ? null : $s;
    }

    private function number(mixed $v): ?float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }

        return preg_match('/\d+(\.\d+)?/', strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m) ? (float) $m[0] : null;
    }

    private function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return CarbonImmutable::instance($v)->toDateString();
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function mode(?string $method): ?string
    {
        $m = ArabicMatch::normalize($method);

        return match (true) {
            $m === '' => null,
            str_contains($m, 'بعد') || str_contains($m, 'online') => 'online',
            str_contains($m, 'مدمج') || str_contains($m, 'مختلط') || str_contains($m, 'hybrid') => 'hybrid',
            default => 'in_person',
        };
    }

    private function kit(?string $kit): ?bool
    {
        $k = ArabicMatch::normalize($kit);

        return match (true) {
            $k === '' => null,
            $k === 'لا' || str_starts_with($k, 'غير') || str_contains($k, 'قيد') => false,
            default => true,
        };
    }
}
