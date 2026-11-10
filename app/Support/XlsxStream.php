<?php

namespace App\Support;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Reads the rows of an .xlsx sheet one at a time with a few megabytes of memory — a 50 000-row, 29-column extract loads in seconds
 * where a spreadsheet library would take gigabytes. Values come back as they are stored: text as strings, numbers (dates included,
 * as Excel day numbers) as int or float, empty cells as null. Row keys are 0-based column positions.
 */
final class XlsxStream
{
    /** @return Generator<int, array<int, int|float|string|null>> */
    public static function rows(string $path, ?string $sheet = null): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Not a readable .xlsx file: {$path}");
        }
        try {
            $target = self::sheetPath($zip, $sheet);
            $strings = self::sharedStrings($zip);
        } finally {
            $zip->close();
        }

        $r = new XMLReader;
        if (! $r->open('zip://'.$path.'#'.$target, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Cannot open the sheet {$target} in {$path}");
        }
        try {
            $row = null;
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'row') {
                    $row = [];
                    if ($r->isEmptyElement) {
                        yield $row;
                        $row = null;
                    }

                    continue;
                }
                if ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'row' && $row !== null) {
                    yield $row;
                    $row = null;

                    continue;
                }
                if ($row !== null && $r->nodeType === XMLReader::ELEMENT && $r->name === 'c') {
                    [$col, $value] = self::cell($r, $strings);
                    if ($value !== null) {
                        $row[$col] = $value;
                    }
                }
            }
        } finally {
            $r->close();
        }
    }

    /** @param list<string> $strings @return array{0: int, 1: int|float|string|null} */
    private static function cell(XMLReader $r, array $strings): array
    {
        $ref = (string) $r->getAttribute('r');
        $type = (string) $r->getAttribute('t');
        $col = self::column($ref);
        $value = null;
        $inline = '';
        if (! $r->isEmptyElement) {
            $depth = $r->depth;
            while ($r->read() && ! ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'c' && $r->depth === $depth)) {
                if ($r->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($r->name === 'v') {
                    $value = $r->readString();
                } elseif ($r->name === 't') {
                    $inline .= $r->readString();
                }
            }
        }
        if ($type === 'inlineStr') {
            $value = $inline;
        } elseif ($value === null) {
            return [$col, null];
        } elseif ($type === 's') {
            $value = $strings[(int) $value] ?? null;
        } elseif ($type === 'b') {
            $value = $value === '1' ? 1 : 0;
        } elseif ($type === 'str' || $type === 'e') {
            // formula text or an error: kept as text
        } else {
            $value = is_numeric($value) ? (str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value) : $value;
        }

        return [$col, $value];
    }

    private static function column(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }

    private static function sheetPath(ZipArchive $zip, ?string $sheet): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            throw new RuntimeException('The workbook has no sheets.');
        }
        $targets = [];
        foreach (simplexml_load_string($rels)->Relationship ?? [] as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }
        $xml = simplexml_load_string($wb);
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xml->xpath('//m:sheets/m:sheet') ?: [] as $s) {
            if ($sheet !== null && (string) $s['name'] !== $sheet) {
                continue;
            }
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $t = $targets[$rid] ?? null;
            if ($t) {
                return ltrim(str_starts_with($t, '/') ? $t : 'xl/'.$t, '/');
            }
        }
        throw new RuntimeException('The sheet '.($sheet ?? '(first)').' was not found.');
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $out = [];
        $r = new XMLReader;
        $r->XML($zip->getFromName('xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT);
        $cur = null;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'si') {
                $cur = '';
                if ($r->isEmptyElement) {
                    $out[] = '';
                    $cur = null;
                }
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->name === 't' && $cur !== null) {
                $cur .= $r->readString();
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'si' && $cur !== null) {
                $out[] = $cur;
                $cur = null;
            }
        }
        $r->close();

        return $out;
    }
}
