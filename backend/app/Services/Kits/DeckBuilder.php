<?php

namespace App\Services\Kits;

/**
 * Turns a content outline (from Claude or the built-in template) into a designed, editable deck.
 *
 * Outline: {title, subtitle?, language, slides: [{type, title, subtitle?, bullets?, left?, right?, quote?, minutes?, notes?, image_prompt?}]}
 * Types: title, section, agenda, content, two_column, image_text, quote, activity, summary, assessment.
 */
class DeckBuilder
{
    private string $lang = 'ar';

    private array $t = [];

    private string $align = 'right';

    public function build(array $outline, ?string $language = null): array
    {
        $this->lang = ($language ?? $outline['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $this->t = DeckModel::theme($this->lang);
        $this->align = $this->lang === 'ar' ? 'right' : 'left';

        $deck = DeckModel::blank($this->lang);
        $deckTitle = (string) ($outline['title'] ?? '');
        foreach ($outline['slides'] ?? [] as $spec) {
            $deck['slides'][] = $this->slide((array) $spec, $deckTitle);
        }

        return DeckModel::normalize($deck);
    }

    private function slide(array $spec, string $deckTitle): array
    {
        $type = $spec['type'] ?? 'content';
        $notes = (string) ($spec['notes'] ?? '');

        return match ($type) {
            'title' => DeckModel::slide('title', $this->titleSlide($spec), ['color' => $this->t['primary']], $notes),
            'section' => DeckModel::slide('section', $this->sectionSlide($spec), ['color' => '#5E0E26'], $notes),
            'quote' => DeckModel::slide('quote', $this->quoteSlide($spec), ['color' => '#F7F3EA'], $notes),
            default => DeckModel::slide($type, $this->contentSlide($type, $spec, $deckTitle), ['color' => '#FFFFFF'], $notes),
        };
    }

    // Element helpers ----------------------------------------------------------------------

    private function para(string $text, int $size, array $o = []): array
    {
        return ['text' => $text, 'size' => $size, 'bold' => $o['bold'] ?? false, 'italic' => $o['italic'] ?? false, 'color' => $o['color'] ?? $this->t['text'],
            'align' => $o['align'] ?? $this->align, 'bullet' => $o['bullet'] ?? false, 'level' => $o['level'] ?? 0];
    }

    private function text(float $x, float $y, float $w, float $h, array $paragraphs, array $style = []): array
    {
        return ['id' => DeckModel::id('e'), 'type' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0, 'paragraphs' => $paragraphs,
            'style' => $style + ['valign' => 'top', 'lineHeight' => 1.3, 'padding' => 8]];
    }

    private function shape(string $shape, float $x, float $y, float $w, float $h, ?string $fill, array $o = []): array
    {
        return ['id' => DeckModel::id('e'), 'type' => 'shape', 'shape' => $shape, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0,
            'fill' => $fill, 'stroke' => $o['stroke'] ?? null, 'strokeWidth' => $o['strokeWidth'] ?? 0, 'radius' => $o['radius'] ?? 0, 'opacity' => $o['opacity'] ?? 1];
    }

    private function image(float $x, float $y, float $w, float $h, string $prompt): array
    {
        return ['id' => DeckModel::id('e'), 'type' => 'image', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'rotation' => 0, 'asset_id' => null, 'alt' => $prompt, 'prompt' => $prompt, 'fit' => 'cover', 'radius' => 16];
    }

    private function bullets(array $items, int $size, bool $numbered = false): array
    {
        $out = [];
        foreach (array_values(array_filter(array_map('strval', $items), fn ($i) => trim($i) !== '')) as $i => $item) {
            $out[] = $this->para($numbered ? ($i + 1).'. '.$item : $item, $size, ['bullet' => ! $numbered]);
        }

        return $out ?: [$this->para('', $size)];
    }

    private function fontSizeFor(array $items, int $max = 30): int
    {
        $chars = array_sum(array_map(fn ($i) => mb_strlen((string) $i), $items));
        $n = count($items);

        return match (true) {
            $n > 8 || $chars > 700 => 20, $n > 6 || $chars > 520 => 22, $n > 4 || $chars > 380 => 26, default => $max,
        };
    }

    /** Start edge of the reading direction for the accent bar. */
    private function edgeX(float $w): float
    {
        return $this->lang === 'ar' ? 1280 - $w : 0;
    }

    // Slide types --------------------------------------------------------------------------

    private function titleSlide(array $s): array
    {
        $els = [
            $this->shape('ellipse', $this->lang === 'ar' ? -180 : 900, 420, 560, 560, $this->t['accent'], ['opacity' => 0.22]),
            $this->shape('ellipse', $this->lang === 'ar' ? 980 : -120, -160, 420, 420, '#FFFFFF', ['opacity' => 0.08]),
            $this->shape('rect', $this->lang === 'ar' ? 1100 : 100, 200, 80, 6, $this->t['accent']),
            $this->text(100, 224, 1080, 190, [$this->para((string) ($s['title'] ?? ''), 56, ['bold' => true, 'color' => '#FFFFFF'])], ['valign' => 'top', 'lineHeight' => 1.2]),
        ];
        if (! empty($s['subtitle'])) {
            $els[] = $this->text(100, 430, 1080, 110, [$this->para((string) $s['subtitle'], 26, ['color' => '#F3E9D2'])], ['lineHeight' => 1.35]);
        }

        return $els;
    }

    private function sectionSlide(array $s): array
    {
        return [
            $this->shape('rect', $this->edgeX(16), 0, 16, 720, $this->t['accent']),
            $this->text(100, 250, 1080, 160, [$this->para((string) ($s['title'] ?? ''), 52, ['bold' => true, 'color' => '#FFFFFF'])], ['valign' => 'middle']),
            ...(! empty($s['subtitle']) ? [$this->text(100, 420, 1080, 80, [$this->para((string) $s['subtitle'], 24, ['color' => '#F3E9D2'])])] : []),
        ];
    }

    private function quoteSlide(array $s): array
    {
        return [
            $this->text($this->lang === 'ar' ? 1040 : 80, 40, 160, 220, [$this->para('“', 200, ['bold' => true, 'color' => $this->t['accent'], 'align' => 'center'])]),
            $this->text(140, 190, 1000, 300, [$this->para((string) ($s['quote'] ?? $s['title'] ?? ''), 40, ['italic' => true, 'color' => $this->t['primary'], 'align' => 'center'])], ['valign' => 'middle', 'lineHeight' => 1.45]),
            ...(! empty($s['subtitle']) ? [$this->text(240, 520, 800, 60, [$this->para('— '.$s['subtitle'], 22, ['color' => $this->t['muted'], 'align' => 'center'])])] : []),
        ];
    }

    private function frame(array $s, string $deckTitle): array
    {
        return [
            $this->shape('rect', 0, 0, 1280, 14, $this->t['primary']),
            $this->text(80, 40, 1120, 92, [$this->para((string) ($s['title'] ?? ''), 38, ['bold' => true, 'color' => $this->t['primary']])], ['valign' => 'middle', 'lineHeight' => 1.15]),
            $this->shape('rect', $this->lang === 'ar' ? 1080 : 88, 138, 120, 5, $this->t['accent']),
            $this->text(80, 672, 1120, 32, [$this->para($deckTitle, 13, ['color' => $this->t['muted']])], ['padding' => 4]),
        ];
    }

    private function contentSlide(string $type, array $s, string $deckTitle): array
    {
        $els = $this->frame($s, $deckTitle);
        $bullets = (array) ($s['bullets'] ?? []);

        switch ($type) {
            case 'two_column':
                [$first, $second] = [(array) ($s['left'] ?? $bullets), (array) ($s['right'] ?? [])];
                $size = $this->fontSizeFor(array_merge($first, $second), 26);
                $xs = $this->lang === 'ar' ? [660, 80] : [80, 660];
                foreach ([[$first, $xs[0], $s['left_title'] ?? null], [$second, $xs[1], $s['right_title'] ?? null]] as [$items, $x, $heading]) {
                    $els[] = $this->shape('round', $x, 170, 540, 470, '#F7F3EA', ['radius' => 18]);
                    $paras = $heading ? [$this->para((string) $heading, $size + 2, ['bold' => true, 'color' => $this->t['primary']])] : [];
                    $els[] = $this->text($x + 12, 186, 516, 440, array_merge($paras, $this->bullets($items, $size)), ['lineHeight' => 1.35]);
                }
                break;

            case 'image_text':
                $size = $this->fontSizeFor($bullets, 28);
                $textX = $this->lang === 'ar' ? 600 : 80;
                $imgX = $this->lang === 'ar' ? 80 : 760;
                $els[] = $this->text($textX, 170, 600, 470, $this->bullets($bullets, $size), ['lineHeight' => 1.4]);
                $els[] = $this->image($imgX, 170, 440, 440, (string) ($s['image_prompt'] ?? $s['title'] ?? ''));
                break;

            case 'activity':
                $size = $this->fontSizeFor($bullets, 26);
                $badgeX = $this->lang === 'ar' ? 80 : 900;
                $textX = $this->lang === 'ar' ? 420 : 80;
                $els[] = $this->text($textX, 170, 780, 470, $this->bullets($bullets, $size, true), ['lineHeight' => 1.4]);
                $els[] = $this->shape('round', $badgeX, 170, 300, 250, $this->t['primary'], ['radius' => 22]);
                $minutes = (int) ($s['minutes'] ?? 15);
                $els[] = $this->text($badgeX, 190, 300, 210, [
                    $this->para((string) $minutes, 84, ['bold' => true, 'color' => '#FFFFFF', 'align' => 'center']),
                    $this->para($this->lang === 'ar' ? 'دقيقة' : 'minutes', 26, ['color' => '#F3E9D2', 'align' => 'center']),
                ], ['valign' => 'middle']);
                $els[] = $this->text($badgeX, 440, 300, 190, [$this->para($this->lang === 'ar' ? 'نشاط تفاعلي' : 'Interactive activity', 24, ['bold' => true, 'color' => $this->t['accent'], 'align' => 'center'])], ['valign' => 'middle']);
                break;

            case 'summary':
                $items = array_slice(array_values(array_filter($bullets, fn ($b) => trim((string) $b) !== '')), 0, 6);
                $cols = count($items) > 4 ? 3 : (count($items) > 2 ? 2 : 1);
                $rows = (int) ceil(max(1, count($items)) / $cols);
                $cw = (1120 - 24 * ($cols - 1)) / $cols;
                $ch = min(220, (470 - 24 * ($rows - 1)) / $rows);
                foreach ($items as $i => $item) {
                    $c = $i % $cols;
                    $r = intdiv($i, $cols);
                    $x = $this->lang === 'ar' ? 1200 - $cw - $c * ($cw + 24) : 80 + $c * ($cw + 24);
                    $y = 170 + $r * ($ch + 24);
                    $els[] = $this->shape('round', $x, $y, $cw, $ch, '#F7F3EA', ['radius' => 18]);
                    $els[] = $this->shape('rect', $this->lang === 'ar' ? $x + $cw - 8 : $x, $y + 18, 8, $ch - 36, $this->t['accent']);
                    $els[] = $this->text($x + 14, $y + 10, $cw - 28, $ch - 20, [$this->para((string) $item, $cols === 3 ? 22 : 26)], ['valign' => 'middle', 'lineHeight' => 1.35]);
                }
                break;

            case 'assessment':
                $els[] = $this->text(80, 170, 1120, 470, $this->bullets($bullets, $this->fontSizeFor($bullets, 28), true), ['lineHeight' => 1.5]);
                break;

            default: // content, agenda
                $numbered = $type === 'agenda';
                $els[] = $this->text(80, 170, 1120, 470, $this->bullets($bullets, $this->fontSizeFor($bullets, 30), $numbered), ['lineHeight' => 1.4]);
        }

        return $els;
    }
}
