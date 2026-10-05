<?php

namespace App\Services\Reports;

use ZipArchive;

/**
 * A small writer for valid .docx files (headings, paragraphs, tables, right-to-left aware). It needs nothing but the
 * zip extension, so it works the same on a laptop, in CI and on Vercel.
 */
class DocxWriter
{
    /** @var list<string> */
    private array $body = [];

    public function __construct(private readonly bool $rtl = true) {}

    public function heading(string $text, int $level = 1): self
    {
        $size = [1 => 36, 2 => 30, 3 => 26][$level] ?? 26;
        $this->body[] = $this->paragraph($text, ['bold' => true, 'size' => $size, 'color' => '7B1E3A', 'spacingAfter' => 160]);

        return $this;
    }

    public function text(string $text, array $opts = []): self
    {
        $this->body[] = $this->paragraph($text, $opts);

        return $this;
    }

    /** @param list<string> $items */
    public function bullets(array $items): self
    {
        foreach ($items as $item) {
            $this->body[] = $this->paragraph('• '.$item, ['spacingAfter' => 60]);
        }

        return $this;
    }

    /** @param list<string> $head @param list<list<string|int|float|null>> $rows */
    public function table(array $head, array $rows): self
    {
        $cell = fn (string $t, bool $h) => '<w:tc><w:tcPr><w:tcBorders>'.implode('', array_map(fn ($b) => "<w:$b w:val=\"single\" w:sz=\"4\" w:color=\"BBBBBB\"/>", ['top', 'left', 'bottom', 'right'])).'</w:tcBorders>'.($h ? '<w:shd w:val="clear" w:color="auto" w:fill="F3E8EC"/>' : '').'</w:tcPr>'.$this->paragraph($t, ['bold' => $h, 'size' => 20]).'</w:tc>';
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'.($this->rtl ? '<w:bidiVisual/>' : '').'</w:tblPr>';
        $xml .= '<w:tr>'.implode('', array_map(fn ($h) => $cell((string) $h, true), $head)).'</w:tr>';
        foreach ($rows as $row) {
            $xml .= '<w:tr>'.implode('', array_map(fn ($c) => $cell((string) ($c ?? ''), false), $row)).'</w:tr>';
        }
        $this->body[] = $xml.'</w:tbl>'.$this->paragraph('', []);

        return $this;
    }

    private function paragraph(string $text, array $o): string
    {
        $rpr = ($this->rtl ? '<w:rtl/>' : '').(! empty($o['bold']) ? '<w:b/><w:bCs/>' : '').(isset($o['color']) ? '<w:color w:val="'.$o['color'].'"/>' : '').'<w:sz w:val="'.($o['size'] ?? 22).'"/><w:szCs w:val="'.($o['size'] ?? 22).'"/>';
        $ppr = ($this->rtl ? '<w:bidi/>' : '').'<w:spacing w:after="'.($o['spacingAfter'] ?? 100).'"/>'.($this->rtl ? '<w:jc w:val="left"/>' : '');   // "left" is the start edge of a right-to-left paragraph

        return '<w:p><w:pPr>'.$ppr.'</w:pPr><w:r><w:rPr>'.$rpr.'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</w:t></w:r></w:p>';
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.implode('', $this->body).'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'.($this->rtl ? '<w:bidi/>' : '').'</w:sectPr></w:body></w:document>');
        $zip->close();
    }

    public function bytes(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $this->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
