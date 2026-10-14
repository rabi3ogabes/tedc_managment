<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

/**
 * The Ministry's Lusail typeface in PDFs. Until its licensed files are in the PDF font folder (Lusail-Regular.ttf, and Lusail-Bold.ttf if
 * there is one) nothing changes: the documents keep the fonts mPDF ships. Once they are, every document that goes through here is set in
 * Lusail, with mPDF's own fonts still behind it for any glyph Lusail lacks.
 */
final class PdfFonts
{
    /** @param  array<string, mixed>  $config  the options of a new Mpdf @return array<string, mixed> */
    public static function apply(array $config): array
    {
        $dir = (string) config('tedc.fonts.pdf');
        if ($dir === '' || ! is_file($dir.'/Lusail-Regular.ttf')) {
            return $config;
        }
        $bold = is_file($dir.'/Lusail-Bold.ttf') ? 'Lusail-Bold.ttf' : 'Lusail-Regular.ttf';

        return array_replace($config, [
            'fontDir' => array_merge($config['fontDir'] ?? (new ConfigVariables)->getDefaults()['fontDir'], [$dir]),
            'fontdata' => ($config['fontdata'] ?? (new FontVariables)->getDefaults()['fontdata']) + ['lusail' => ['R' => 'Lusail-Regular.ttf', 'B' => $bold, 'useOTL' => 0xFF, 'useKashida' => 75]],
            'default_font' => 'lusail',
            'autoScriptToLang' => false,   // mPDF would pick its own font for Arabic text
            'autoLangToFont' => false,
            'useSubstitutions' => true,
        ]);
    }
}
