<?php

namespace App\Services\Kits;

use App\Models\KitAsset;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Str;

/**
 * The editable slide-deck model shared by the API and the web editor.
 *
 * {
 *   version: 1, size: {w: 1280, h: 720}, theme: {primary, accent, text, background, font, dir},
 *   slides: [{ id, rev, layout, background: {color, asset_id}, notes,
 *     elements: [
 *       {id, type: 'text',  x, y, w, h, rotation, paragraphs: [{text, size, bold, italic, color, align, bullet, level}], style: {fontFamily, valign, fill, lineHeight, padding}},
 *       {id, type: 'image', x, y, w, h, rotation, asset_id, alt, prompt, fit, radius},
 *       {id, type: 'shape', shape: 'rect'|'round'|'ellipse'|'line', x, y, w, h, rotation, fill, stroke, strokeWidth, radius, opacity}
 *     ] }]
 * }
 */
final class DeckModel
{
    public const W = 1280;

    public const H = 720;

    public const MAX_SLIDES = 200;

    public const MAX_ELEMENTS = 80;

    public static function id(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(8));
    }

    /** @return array<string, string> */
    public static function theme(string $lang = 'ar'): array
    {
        return [
            'primary' => '#8A1538', 'accent' => '#A29475', 'text' => '#1A1A1A', 'background' => '#FFFFFF', 'muted' => '#6B7280',
            'font' => 'Qatar Sans', 'dir' => $lang === 'en' ? 'ltr' : 'rtl',
        ];
    }

    public static function blank(string $lang = 'ar'): array
    {
        return ['version' => 1, 'size' => ['w' => self::W, 'h' => self::H], 'theme' => self::theme($lang), 'slides' => []];
    }

    public static function slide(string $layout = 'blank', array $elements = [], array $background = [], string $notes = ''): array
    {
        return [
            'id' => self::id('s'), 'rev' => 1, 'layout' => $layout,
            'background' => $background + ['color' => '#FFFFFF', 'asset_id' => null],
            'notes' => $notes, 'elements' => array_values($elements),
        ];
    }

    /** Cleans an incoming deck: known types only, sane numbers, ids everywhere. */
    public static function normalize(array $deck): array
    {
        $theme = array_merge(self::theme(), array_intersect_key((array) ($deck['theme'] ?? []), self::theme()));
        $slides = [];
        foreach (array_slice((array) ($deck['slides'] ?? []), 0, self::MAX_SLIDES) as $slide) {
            $slides[] = self::normalizeSlide((array) $slide);
        }

        return ['version' => 1, 'size' => ['w' => self::W, 'h' => self::H], 'theme' => $theme, 'slides' => $slides];
    }

    public static function normalizeSlide(array $slide): array
    {
        $elements = [];
        foreach (array_slice((array) ($slide['elements'] ?? []), 0, self::MAX_ELEMENTS) as $el) {
            if ($clean = self::normalizeElement((array) $el)) {
                $elements[] = $clean;
            }
        }
        $bg = (array) ($slide['background'] ?? []);

        return [
            'id' => self::str($slide['id'] ?? null, 32) ?: self::id('s'),
            'rev' => max(1, (int) ($slide['rev'] ?? 1)),
            'layout' => self::str($slide['layout'] ?? 'blank', 24) ?: 'blank',
            'background' => ['color' => self::color($bg['color'] ?? '#FFFFFF', '#FFFFFF'), 'asset_id' => self::str($bg['asset_id'] ?? null, 40)],
            'notes' => mb_substr((string) ($slide['notes'] ?? ''), 0, 8000),
            'elements' => $elements,
            // Who last changed the slide; the server overwrites these on save.
            'by' => self::str($slide['by'] ?? null, 40), 'by_name' => isset($slide['by_name']) ? mb_substr((string) $slide['by_name'], 0, 80) : null,
            'at' => isset($slide['at']) ? mb_substr((string) $slide['at'], 0, 32) : null,
        ];
    }

    private static function normalizeElement(array $el): ?array
    {
        $type = $el['type'] ?? null;
        if (! in_array($type, ['text', 'image', 'video', 'audio', 'shape'], true)) {
            return null;
        }
        $base = [
            'id' => self::str($el['id'] ?? null, 32) ?: self::id('e'), 'type' => $type,
            'x' => self::num($el['x'] ?? 0, -2000, 3000), 'y' => self::num($el['y'] ?? 0, -2000, 3000),
            'w' => self::num($el['w'] ?? 100, 1, 4000), 'h' => self::num($el['h'] ?? 50, 1, 4000),
            'rotation' => self::num($el['rotation'] ?? 0, -360, 360),
        ];

        if ($type === 'text') {
            $paragraphs = [];
            foreach (array_slice((array) ($el['paragraphs'] ?? []), 0, 120) as $p) {
                $p = (array) $p;
                $paragraphs[] = [
                    'text' => mb_substr((string) ($p['text'] ?? ''), 0, 5000),
                    'size' => self::num($p['size'] ?? 24, 6, 200), 'bold' => (bool) ($p['bold'] ?? false), 'italic' => (bool) ($p['italic'] ?? false),
                    'color' => self::color($p['color'] ?? '#1A1A1A', '#1A1A1A'), 'align' => in_array($p['align'] ?? null, ['left', 'center', 'right'], true) ? $p['align'] : 'right',
                    'bullet' => (bool) ($p['bullet'] ?? false), 'level' => (int) max(0, min(4, (int) ($p['level'] ?? 0))),
                ];
            }
            $style = (array) ($el['style'] ?? []);

            return $base + [
                'paragraphs' => $paragraphs ?: [['text' => '', 'size' => 24, 'bold' => false, 'italic' => false, 'color' => '#1A1A1A', 'align' => 'right', 'bullet' => false, 'level' => 0]],
                'style' => [
                    'fontFamily' => self::str($style['fontFamily'] ?? null, 60), 'valign' => in_array($style['valign'] ?? null, ['top', 'middle', 'bottom'], true) ? $style['valign'] : 'top',
                    'fill' => isset($style['fill']) ? self::color($style['fill'], null) : null, 'lineHeight' => self::num($style['lineHeight'] ?? 1.25, 0.8, 3), 'padding' => self::num($style['padding'] ?? 8, 0, 100),
                ],
            ];
        }

        if ($type === 'image') {
            return $base + [
                'asset_id' => self::str($el['asset_id'] ?? null, 40), 'alt' => mb_substr((string) ($el['alt'] ?? ''), 0, 500), 'prompt' => mb_substr((string) ($el['prompt'] ?? ''), 0, 1000),
                'fit' => ($el['fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover', 'radius' => self::num($el['radius'] ?? 0, 0, 400),
                // Pasted / imported images arrive as data URIs and are converted to assets by ingestDataUris().
                'src' => isset($el['src']) && str_starts_with((string) $el['src'], 'data:image/') ? (string) $el['src'] : null,
            ];
        }

        if ($type === 'video' || $type === 'audio') {
            // A clip or a sound placed on the slide: the file is a kit asset; `src` is only added when the deck is served.
            return $base + [
                'asset_id' => self::str($el['asset_id'] ?? null, 40), 'alt' => mb_substr((string) ($el['alt'] ?? ''), 0, 500), 'prompt' => mb_substr((string) ($el['prompt'] ?? ''), 0, 1000),
                'autoplay' => (bool) ($el['autoplay'] ?? false), 'loop' => (bool) ($el['loop'] ?? false), 'radius' => self::num($el['radius'] ?? 0, 0, 400),
            ];
        }

        return $base + [
            'shape' => in_array($el['shape'] ?? null, ['rect', 'round', 'ellipse', 'line'], true) ? $el['shape'] : 'rect',
            'fill' => isset($el['fill']) ? self::color($el['fill'], null) : null, 'stroke' => isset($el['stroke']) ? self::color($el['stroke'], null) : null,
            'strokeWidth' => self::num($el['strokeWidth'] ?? 0, 0, 60), 'radius' => self::num($el['radius'] ?? 0, 0, 400), 'opacity' => self::num($el['opacity'] ?? 1, 0, 1),
        ];
    }

    /**
     * Converts data-URI images (from an imported PPTX or a paste) into stored assets and returns how many.
     */
    public static function ingestDataUris(array &$deck, TrainingKit $kit, ?User $user, FileStorage $storage, ?string $fileId = null): int
    {
        $count = 0;
        foreach ($deck['slides'] as &$slide) {
            foreach ($slide['elements'] as &$el) {
                if (($el['type'] ?? null) !== 'image' || empty($el['src'])) {
                    continue;
                }
                if ($asset = self::storeDataUri($el['src'], $kit, $user, $storage, $fileId)) {
                    $el['asset_id'] = $asset->id;
                    $count++;
                }
                unset($el['src']);
            }
            unset($el);
        }
        unset($slide);

        return $count;
    }

    public static function storeDataUri(string $uri, TrainingKit $kit, ?User $user, FileStorage $storage, ?string $fileId = null, string $source = 'imported'): ?KitAsset
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|jpg|gif|webp|svg\+xml));base64,(.+)$#s', $uri, $m)) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > 8 * 1024 * 1024) {
            return null;
        }
        $mime = $m[1] === 'image/jpg' ? 'image/jpeg' : $m[1];
        if ($mime === 'image/svg+xml') {
            $bytes = SvgSanitizer::clean($bytes);
        }
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$mime];
        $path = $storage->put('documents', "kits/{$kit->id}/assets/".Str::uuid().".{$ext}", $bytes, $mime);
        [$w, $h] = self::dimensions($bytes, $mime);

        return KitAsset::create(['kit_id' => $kit->id, 'file_id' => $fileId, 'mime' => $mime, 'size' => strlen($bytes), 'storage_path' => $path, 'source' => $source, 'width' => $w, 'height' => $h, 'created_by' => $user?->id]);
    }

    /** @return array{0: ?int, 1: ?int} */
    public static function dimensions(string $bytes, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            return preg_match('/viewBox="[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)"/', $bytes, $m) ? [(int) $m[1], (int) $m[2]] : [null, null];
        }
        $info = @getimagesizefromstring($bytes);

        return $info ? [$info[0], $info[1]] : [null, null];
    }

    /** Adds a short-lived `src` URL to every image element (assets are private). */
    public static function decorate(array $deck, FileStorage $storage): array
    {
        $ids = [];
        foreach ($deck['slides'] ?? [] as $slide) {
            if (! empty($slide['background']['asset_id'])) {
                $ids[] = $slide['background']['asset_id'];
            }
            foreach ($slide['elements'] as $el) {
                if (in_array($el['type'] ?? null, ['image', 'video', 'audio'], true) && ! empty($el['asset_id'])) {
                    $ids[] = $el['asset_id'];
                }
            }
        }
        $assets = KitAsset::whereIn('id', array_unique($ids))->get()->keyBy('id');
        $ttl = (int) config('tedc.kits.asset_url_ttl');
        $url = fn (?string $id) => $id && $assets->has($id) ? $storage->temporaryUrl($assets[$id]->bucket(), $assets[$id]->storage_path, $ttl) : null;

        foreach ($deck['slides'] as &$slide) {
            $slide['background']['src'] = $url($slide['background']['asset_id'] ?? null);
            foreach ($slide['elements'] as &$el) {
                if (in_array($el['type'] ?? null, ['image', 'video', 'audio'], true)) {
                    $el['src'] = $url($el['asset_id'] ?? null);
                }
            }
            unset($el);
        }
        unset($slide);

        return $deck;
    }

    /** Removes the decorated `src` again before storing. */
    public static function stripUrls(array $deck): array
    {
        foreach ($deck['slides'] ?? [] as $i => $slide) {
            unset($deck['slides'][$i]['background']['src']);
            foreach ($slide['elements'] as $j => $el) {
                if (in_array($el['type'] ?? null, ['image', 'video', 'audio'], true) && ! empty($el['asset_id'])) {
                    unset($deck['slides'][$i]['elements'][$j]['src']);
                }
            }
        }

        return $deck;
    }

    public static function slideText(array $slide): string
    {
        $parts = [];
        foreach ($slide['elements'] ?? [] as $el) {
            if (($el['type'] ?? null) === 'text') {
                foreach ($el['paragraphs'] ?? [] as $p) {
                    $parts[] = (string) ($p['text'] ?? '');
                }
            }
        }

        return trim(implode("\n", array_filter($parts, fn ($t) => $t !== '')));
    }

    public static function wordCount(string $text): int
    {
        return count(preg_split('/[\s\p{Z}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private static function num(mixed $value, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($value) ? (float) $value : $min)), 2);
    }

    private static function str(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr(preg_replace('/[^\w\-]/u', '', $value) ?: '', 0, $max) ?: null : null;
    }

    private static function color(mixed $value, ?string $fallback): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $fallback;
    }
}
