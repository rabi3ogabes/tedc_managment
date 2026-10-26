<?php

namespace App\Services\Saber;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reads any Excel / CSV workbook for the SABER+ imports: its sheets with a preview of their first rows (so the administrator can say which
 * row holds the headers and which column is what), the rows of a sheet as raw values, and dates and times in every common form (Excel
 * serials, dd/mm/yyyy, ISO, 08:00, 8:30 ص/م).
 */
class WorkbookReader
{
    /** @var array<string, Spreadsheet> */
    private array $books = [];

    /** @return list<array{name: string, rows: list<list<string>>, columns: int, count: int, header_row: int}> */
    public function inspect(string $path): array
    {
        $out = [];
        // Loaded with its formats here, so dates and times show as the administrator sees them in Excel.
        $book = IOFactory::createReaderForFile($path)->load($path);
        foreach ($book->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            $preview = array_map(fn ($r) => array_map(fn ($v) => $v === null ? '' : trim((string) $v), $r), array_slice($rows, 0, 25));
            $columns = (int) collect($preview)->map(fn ($r) => count(array_filter($r, fn ($v) => $v !== '')) ? max(array_keys(array_filter($r, fn ($v) => $v !== ''))) + 1 : 0)->max();
            $out[] = ['name' => $sheet->getTitle(), 'rows' => array_map(fn ($r) => array_slice($r, 0, max($columns, 1)), $preview), 'columns' => $columns, 'count' => count($rows), 'header_row' => $this->headerRow($preview) + 1];
        }

        return $out;
    }

    /** @return list<list<mixed>> the sheet's rows (0-based), raw values */
    public function rows(string $path, ?string $sheet = null): array
    {
        $book = $this->book($path);
        $ws = ($sheet !== null && $sheet !== '' ? $book->getSheetByName($sheet) : null)
            ?? collect($book->getWorksheetIterator())->first(fn ($s) => str_contains($s->getTitle(), 'تقويم'))
            ?? $book->getSheet(0);

        return $ws->toArray(null, true, false, false);
    }

    public function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return CarbonImmutable::instance($v)->toDateString();
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        $s = trim(strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }

        return null;
    }

    public function time(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return $v->format('H:i');
        }
        if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) {
            $minutes = (int) round((float) $v * 1440);

            return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
        }
        $s = trim(strtr((string) $v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (! preg_match('/(\d{1,2})(?::(\d{2}))?\s*(ص|م|am|pm)?/iu', $s, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);
        $suffix = mb_strtolower($m[3] ?? '');
        if (in_array($suffix, ['م', 'pm'], true) && $h < 12) {
            $h += 12;
        }
        if (in_array($suffix, ['ص', 'am'], true) && $h === 12) {
            $h = 0;
        }

        return $h < 24 && $min < 60 ? sprintf('%02d:%02d', $h, $min) : null;
    }

    /** The row (0-based) most likely to hold the headers: the first of the most filled text rows among the first 15. */
    private function headerRow(array $rows): int
    {
        $best = 0;
        $at = 0;
        foreach (array_slice($rows, 0, 15) as $i => $r) {
            $n = count(array_filter($r, fn ($v) => $v !== '' && ! is_numeric($v)));
            if ($n > $best) {
                [$best, $at] = [$n, $i];
            }
        }

        return $at;
    }

    private function book(string $path): Spreadsheet
    {
        return $this->books[$path] ??= IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
    }
}
