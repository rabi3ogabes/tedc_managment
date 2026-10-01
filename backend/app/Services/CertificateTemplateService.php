<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Program;
use App\Models\TrainerCertificate;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Mpdf\Mpdf;
use Throwable;

/**
 * Certificate designs. A template is a page size, an optional background image (made from an uploaded PDF or
 * picture) and a list of elements positioned in percent of the page:
 *
 *  text  — x y w h, text with {{placeholders}}, font, size (pt), color, bold, align, valign, dir, line
 *  qr    — x y w h  (verification code of the certificate)
 *  image — x y w h, src (an asset uploaded with the template)
 *  rect  — x y w h, stroke, stroke_width (mm), fill, radius (mm)  (frames, bands, lines)
 */
class CertificateTemplateService
{
    public const FONTS = ['xbriyaz' => 'Naskh', 'dejavusans' => 'Sans', 'dejavuserif' => 'Serif'];

    public const TOKENS = [
        'trainee' => ['name', 'name_en', 'school', 'program', 'program_en', 'hours', 'date', 'start_date', 'end_date', 'academic_year', 'certificate_no', 'code', 'center', 'center_en', 'signer1_name', 'signer1_title', 'signer2_name', 'signer2_title'],
        'trainer' => ['name', 'name_en', 'school', 'program', 'program_en', 'hours', 'date', 'start_date', 'end_date', 'academic_year', 'certificate_no', 'code', 'center', 'center_en', 'signer1_name', 'signer1_title', 'signer2_name', 'signer2_title'],
    ];

    public function __construct(private readonly FileStorage $storage) {}

    // Choosing a template ---------------------------------------------------------------------------------------

    /** The program's template for the kind, else the default one of that kind (null = the built-in layout). */
    public function resolve(string $kind, ?Program $program): ?CertificateTemplate
    {
        $id = $program ? ($kind === CertificateTemplate::TRAINER ? $program->trainer_certificate_template_id : $program->certificate_template_id) : null;
        if ($id && ($template = CertificateTemplate::where('status', 'active')->find($id))) {
            return $template;
        }

        return CertificateTemplate::where('kind', $kind)->where('status', 'active')->where('is_default', true)->first();
    }

    /** Creates the two stock designs the first time templates are needed, so administrators have something to start from. */
    public function ensureDefaults(): void
    {
        foreach ([CertificateTemplate::TRAINEE => ['شهادة إتمام — كلاسيك', 'Completion — classic'], CertificateTemplate::TRAINER => ['شكر وتقدير — كلاسيك', 'Thank-you — classic']] as $kind => [$ar, $en]) {
            if (! CertificateTemplate::where('kind', $kind)->exists()) {
                CertificateTemplate::create(['name_ar' => $ar, 'name_en' => $en, 'kind' => $kind, 'width_mm' => 297, 'height_mm' => 210, 'elements' => $this->stockElements($kind), 'is_default' => true]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function stockElements(string $kind): array
    {
        $maroon = '#7b1e3a';
        $t = fn (string $id, array $box, string $text, array $style = []) => ['id' => $id, 'type' => 'text', 'x' => $box[0], 'y' => $box[1], 'w' => $box[2], 'h' => $box[3], 'text' => $text] + $style + ['font' => 'xbriyaz', 'size' => 13, 'color' => '#3a3a46', 'bold' => false, 'align' => 'center', 'valign' => 'middle', 'dir' => 'rtl', 'line' => 1.5];
        $trainer = $kind === CertificateTemplate::TRAINER;

        return [
            ['id' => 'frame', 'type' => 'rect', 'x' => 3.03, 'y' => 4.29, 'w' => 93.94, 'h' => 91.42, 'stroke' => $maroon, 'stroke_width' => 0.6, 'fill' => '', 'radius' => 0],
            $t('gem-top', [35, 5.5, 30, 4], '◆ ◇ ◆', ['font' => 'dejavusans', 'size' => 10, 'color' => $maroon]),
            $t('center', [58, 8, 36, 7], "{{center}}\n{{center_en}}", ['size' => 11, 'bold' => true, 'color' => $maroon, 'align' => 'right', 'dir' => 'rtl']),
            $t('title', [10, 15, 80, 14], $trainer ? 'شكر وتقدير' : 'شهادة إتمام برنامج تدريبي', ['size' => 40, 'bold' => true, 'color' => '#8a6d3b']),
            $t('lead', [10, 32, 80, 6], $trainer ? 'يسر {{center}} أن يتقدم بالشكر والتقدير للسيد/ة:' : 'يسر {{center}} أن يشهد بأن السيد/ة:'),
            $t('name', [10, 39, 80, 9], '{{name}}', ['size' => 22, 'bold' => true, 'color' => $maroon]),
            $t('school', [10, 48, 80, 5], '{{school}}', ['size' => 14, 'color' => '#34568b']),
            $t('line2', [10, 54, 80, 6], $trainer ? 'وذلك لمشاركته/ا الفاعلة وتعاونه/ا المثمر في تدريب برنامج:' : 'قد أتمّ/ت بنجاح حضور برنامج:'),
            $t('program', [10, 60, 80, 7], '{{program}}', ['size' => 17, 'color' => '#2f8a64']),
            $t('body', [10, 68, 80, 14], $trainer
                ? "والذي تم تنفيذه بمعدل ( {{hours}} ) ساعات تدريبية أسهمت في بناء الاتجاهات التربوية وتنمية قدرات الكوادر التعليمية والارتقاء بالممارسات المهنية.\nمع خالص الدعوات لكم بدوام التميز والريادة."
                : "بمعدل ( {{hours}} ) ساعات تدريبية، وذلك ضمن جهود المركز في بناء القدرات وتنمية الكوادر التعليمية والارتقاء بالممارسات المهنية.\nمع خالص التمنيات بدوام التميز والريادة."),
            ['id' => 'qr', 'type' => 'qr', 'x' => 46.6, 'y' => 75.5, 'w' => 6.8, 'h' => 9.6],
            $t('meta', [35, 85.5, 30, 6], "{{certificate_no}} · {{code}}\n{{date}}", ['font' => 'dejavusans', 'size' => 7.5, 'color' => '#8a8a96', 'dir' => 'ltr']),
            $t('sig2', [64, 78, 28, 9], "{{signer2_name}}\n{{signer2_title}}", ['size' => 11, 'color' => $maroon, 'valign' => 'top']),
            $t('sig1', [8, 78, 28, 9], "{{signer1_name}}\n{{signer1_title}}", ['size' => 11, 'color' => $maroon, 'valign' => 'top']),
            $t('gem-bottom', [35, 92.2, 30, 4], '◆ ◇ ◆', ['font' => 'dejavusans', 'size' => 10, 'color' => $maroon]),
        ];
    }

    // Placeholder values ----------------------------------------------------------------------------------------

    /** @return array<string, string> */
    public function tokensFor(Certificate|TrainerCertificate $certificate): array
    {
        $program = $certificate->program;
        $center = app(ThemeService::class)->centerName();
        if ($certificate instanceof Certificate) {
            $certificate->loadMissing(['employee.user', 'employee.school']);
            $user = $certificate->employee->user;
            $name = $user->name_ar ?: $user->name;
            $nameEn = $user->name;
            $school = $certificate->employee->school?->name_ar ?? '';
        } else {
            $certificate->loadMissing(['trainer.school']);
            $trainer = $certificate->trainer;
            $name = trim(($trainer->title_ar ? $trainer->title_ar.' ' : '').($trainer->name_ar ?: $trainer->name_en));
            $nameEn = (string) $trainer->name_en;
            $school = $trainer->school?->name_ar ?? (string) $trainer->organization;
        }

        return [
            'name' => $name, 'name_en' => $nameEn, 'school' => $school,
            'hours' => rtrim(rtrim(number_format((float) $certificate->hours, 1), '0'), '.'),
            'date' => $certificate->issued_at->format('Y-m-d'),
            'certificate_no' => $certificate->certificate_no, 'code' => $certificate->verification_code,
        ] + $this->commonTokens($program, $center);
    }

    /** Example values for the designer's preview. @return array<string, string> */
    public function sampleTokens(): array
    {
        return [
            'name' => 'د. سامر يحيى الدريعي', 'name_en' => 'Dr. Samer Al-Dreiee', 'school' => 'مدرسة ناصر بن عبدالله الثانوية للبنين',
            'program' => 'خارطة التعلم اليومية', 'program_en' => 'Daily Learning Map', 'hours' => '10', 'date' => now()->format('Y-m-d'),
            'start_date' => now()->subDays(10)->format('Y-m-d'), 'end_date' => now()->format('Y-m-d'), 'academic_year' => $this->academicYear(now()),
            'certificate_no' => 'TEDC-2026-A1B2C3', 'code' => 'A1B2C3D4E5F6',
        ] + $this->commonTokens(null, app(ThemeService::class)->centerName());
    }

    /** @param  array{ar: string, en: string}  $center  @return array<string, string> */
    private function commonTokens(?Program $program, array $center): array
    {
        $signers = config('tedc.certificates.signers', []);

        return [
            'program' => (string) $program?->title_ar, 'program_en' => (string) $program?->title_en,
            'start_date' => $program?->start_date?->format('Y-m-d') ?? '', 'end_date' => $program?->end_date?->format('Y-m-d') ?? '',
            'academic_year' => $program?->start_date ? $this->academicYear($program->start_date) : '',
            'center' => $center['ar'], 'center_en' => $center['en'],
            'signer1_name' => $signers[0]['name_ar'] ?? '', 'signer1_title' => $signers[0]['title_ar'] ?? '',
            'signer2_name' => $signers[1]['name_ar'] ?? '', 'signer2_title' => $signers[1]['title_ar'] ?? '',
        ];
    }

    private function academicYear(\DateTimeInterface $date): string
    {
        $year = (int) $date->format('Y');

        return (int) $date->format('n') >= 8 ? "{$year}-".($year + 1) : ($year - 1)."-{$year}";
    }

    // Rendering -------------------------------------------------------------------------------------------------

    public function renderFor(CertificateTemplate $template, Certificate|TrainerCertificate $certificate): string
    {
        return $this->render($template, $this->tokensFor($certificate), $certificate->verificationUrl(), $certificate->certificate_no);
    }

    /**
     * @param  array<string, string>  $tokens
     * @param  list<array<string, mixed>>|null  $elements  unsaved elements from the designer (overrides the stored ones)
     */
    public function render(CertificateTemplate $template, array $tokens, string $verificationUrl, string $title, ?array $elements = null): string
    {
        $w = (float) $template->width_mm;
        $h = (float) $template->height_mm;
        $mm = fn (float $pct, float $total) => round($pct / 100 * $total, 2);

        $html = '<html><head><meta charset="utf-8"><style>body{margin:0;font-family:xbriyaz,dejavusans;}div,table,img{box-sizing:border-box;} td{padding:0;}</style></head><body>';

        if ($background = $this->backgroundData($template)) {
            $html .= '<div style="position:absolute;left:0;top:0;width:'.$w.'mm;height:'.$h.'mm;"><img src="'.$background.'" style="width:'.$w.'mm;height:'.$h.'mm;"></div>';
        }

        $qr = null;
        foreach ($elements ?? $template->elements ?? [] as $el) {
            $x = $mm((float) ($el['x'] ?? 0), $w);
            $y = $mm((float) ($el['y'] ?? 0), $h);
            $ew = $mm((float) ($el['w'] ?? 10), $w);
            $eh = $mm((float) ($el['h'] ?? 10), $h);
            $box = "position:absolute;left:{$x}mm;top:{$y}mm;width:{$ew}mm;height:{$eh}mm;";

            switch ($el['type'] ?? '') {
                case 'rect':
                    $stroke = $this->color($el['stroke'] ?? '');
                    $fill = $this->color($el['fill'] ?? '');
                    $sw = max(0, (float) ($el['stroke_width'] ?? 0));
                    $style = $box.($fill ? "background-color:{$fill};" : '').($stroke && $sw > 0 ? "border:{$sw}mm solid {$stroke};" : '').((float) ($el['radius'] ?? 0) > 0 ? 'border-radius:'.(float) $el['radius'].'mm;' : '');
                    $html .= '<div style="'.$style.'"></div>';
                    break;
                case 'qr':
                    $qr ??= (new QRCode(new QROptions(['outputBase64' => true, 'eccLevel' => EccLevel::M, 'scale' => 6])))->render($verificationUrl);
                    $html .= '<div style="'.$box.'"><img src="'.$qr.'" style="width:'.$ew.'mm;height:'.$eh.'mm;"></div>';
                    break;
                case 'image':
                    if ($data = $this->assetData($template, (string) ($el['src'] ?? ''))) {
                        $html .= '<div style="'.$box.'"><img src="'.$data.'" style="width:'.$ew.'mm;height:'.$eh.'mm;"></div>';
                    }
                    break;
                case 'text':
                    $html .= $this->textElement($el, $box, $tokens, $eh);
                    break;
            }
        }
        $html .= '</body></html>';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [$w, $h],
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'default_font' => 'xbriyaz',
            'tempDir' => storage_path('app/mpdf'),
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /** @param  array<string, mixed>  $el  @param  array<string, string>  $tokens */
    private function textElement(array $el, string $box, array $tokens, float $height): string
    {
        $text = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/u', fn ($m) => $tokens[$m[1]] ?? '', (string) ($el['text'] ?? ''));
        if (trim((string) $text) === '') {
            return '';
        }
        $font = array_key_exists($el['font'] ?? '', self::FONTS) ? $el['font'] : 'xbriyaz';
        $size = min(200, max(4, (float) ($el['size'] ?? 13)));
        $align = in_array($el['align'] ?? '', ['left', 'right', 'center', 'justify'], true) ? $el['align'] : 'center';
        $valign = in_array($el['valign'] ?? '', ['top', 'middle', 'bottom'], true) ? $el['valign'] : 'middle';
        $dir = ($el['dir'] ?? 'rtl') === 'ltr' ? 'ltr' : 'rtl';
        $line = min(3, max(0.8, (float) ($el['line'] ?? 1.4)));
        $color = $this->color($el['color'] ?? '#000000') ?: '#000000';
        $style = "font-family:{$font};font-size:{$size}pt;color:{$color};text-align:{$align};line-height:{$line};".(! empty($el['bold']) ? 'font-weight:bold;' : '');
        $content = nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));

        return '<div style="'.$box.'"><table style="width:100%;height:'.$height.'mm;" cellspacing="0" cellpadding="0"><tr><td valign="'.$valign.'" dir="'.$dir.'" style="'.$style.'">'.$content.'</td></tr></table></div>';
    }

    private function color(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : '';
    }

    // Files -----------------------------------------------------------------------------------------------------

    private function backgroundData(CertificateTemplate $template): ?string
    {
        return $template->background_path ? $this->fileData($template->background_path) : null;
    }

    private function assetData(CertificateTemplate $template, string $name): ?string
    {
        // Assets live under the template's own folder; never accept a path that leaves it.
        if ($name === '' || str_contains($name, '..') || str_contains($name, '/')) {
            return null;
        }

        return $this->fileData("templates/{$template->id}/assets/{$name}");
    }

    private function fileData(string $path): ?string
    {
        try {
            $bytes = $this->storage->get('certificates', $path);
        } catch (Throwable) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    public function readFile(string $path): string
    {
        return $this->storage->get('certificates', $path);
    }
}
