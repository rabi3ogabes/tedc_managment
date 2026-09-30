<?php

namespace App\Services\Kits;

use App\Models\KitAsset;
use App\Models\KitGeneration;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates an illustration from a prompt.
 *   openai      - a raster image from the OpenAI Images API (needs OPENAI_API_KEY)
 *   svg         - a vector illustration drawn by Claude (needs ANTHROPIC_API_KEY)
 *   placeholder - an abstract on-brand composition seeded by the prompt (always available)
 */
class ImageGenerator
{
    public function __construct(private readonly KitAi $ai, private readonly FileStorage $storage) {}

    public function provider(): string
    {
        $configured = config('tedc.kits.image_provider');
        $order = $configured === 'auto' ? ['openai', 'svg', 'placeholder'] : [$configured, 'svg', 'placeholder'];
        foreach ($order as $candidate) {
            if ($candidate === 'openai' && filled(config('tedc.kits.openai_key'))) {
                return 'openai';
            }
            if ($candidate === 'svg' && $this->ai->enabled()) {
                return 'svg';
            }
            if ($candidate === 'placeholder') {
                return 'placeholder';
            }
        }

        return 'placeholder';
    }

    /** @param  array{aspect?: string, style?: ?string}  $opts */
    public function generate(string $prompt, TrainingKit $kit, ?User $user, array $opts = []): KitAsset
    {
        $aspect = in_array($opts['aspect'] ?? null, ['16:9', '1:1', '4:3'], true) ? $opts['aspect'] : '16:9';
        $full = trim($prompt.(! empty($opts['style']) ? '. Style: '.$opts['style'] : ''));
        $provider = $this->provider();
        $result = null;

        if ($provider === 'openai') {
            $result = $this->openai($full, $aspect);
            $provider = $result ? 'openai' : ($this->ai->enabled() ? 'svg' : 'placeholder');
        }
        if (! $result && $provider === 'svg') {
            $svg = $this->safely(fn () => $this->claudeSvg($full, $aspect));
            $result = $svg ? ['bytes' => $svg, 'mime' => 'image/svg+xml', 'ext' => 'svg'] : null;
            $provider = $result ? 'svg' : 'placeholder';
        }
        if (! $result) {
            $result = ['bytes' => $this->placeholder($prompt, $aspect), 'mime' => 'image/svg+xml', 'ext' => 'svg'];
            $provider = 'placeholder';
        }

        $path = $this->storage->put('documents', "kits/{$kit->id}/assets/".Str::uuid().".{$result['ext']}", $result['bytes'], $result['mime']);
        [$w, $h] = DeckModel::dimensions($result['bytes'], $result['mime']);
        $asset = KitAsset::create([
            'kit_id' => $kit->id, 'name' => Str::limit($prompt, 80, ''), 'mime' => $result['mime'], 'size' => strlen($result['bytes']), 'storage_path' => $path,
            'prompt' => $prompt, 'source' => 'generated', 'width' => $w, 'height' => $h, 'meta' => ['provider' => $provider, 'aspect' => $aspect], 'created_by' => $user?->id,
        ]);

        KitGeneration::create(['kit_id' => $kit->id, 'user_id' => $user?->id, 'type' => 'image', 'prompt' => $prompt, 'params' => $opts, 'result' => ['asset_id' => $asset->id], 'provider' => $provider]);

        return $asset;
    }

    /** Any provider failure must degrade to the built-in illustration, never to a server error. */
    private function safely(callable $draw): ?string
    {
        try {
            return $draw();
        } catch (Throwable $e) {
            Log::warning('SVG image generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array{bytes: string, mime: string, ext: string}|null */
    private function openai(string $prompt, string $aspect): ?array
    {
        try {
            $size = ['16:9' => '1536x1024', '4:3' => '1536x1024', '1:1' => '1024x1024'][$aspect];
            $response = Http::withToken((string) config('tedc.kits.openai_key'))->timeout(150)
                ->post('https://api.openai.com/v1/images/generations', ['model' => config('tedc.kits.openai_image_model'), 'prompt' => $prompt, 'size' => $size, 'n' => 1])
                ->throw()->json('data.0');
            $bytes = ! empty($response['b64_json']) ? base64_decode($response['b64_json'], true) : (! empty($response['url']) ? Http::timeout(60)->get($response['url'])->throw()->body() : null);

            return $bytes ? ['bytes' => $bytes, 'mime' => 'image/png', 'ext' => 'png'] : null;
        } catch (Throwable $e) {
            Log::warning('OpenAI image generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function claudeSvg(string $prompt, string $aspect): ?string
    {
        [$w, $h] = ['16:9' => [1280, 720], '4:3' => [1200, 900], '1:1' => [1000, 1000]][$aspect];
        $system = <<<'PROMPT'
You are a vector illustrator for a government training center in Qatar. You draw ONE self-contained SVG per request.
Style: modern flat illustration, generous shapes, smooth curves, harmonious palette led by maroon #8A1538, sand #A29475, cream #F7F3EA, deep navy #1F2A44 with small accents.
Hard rules: use only svg drawing elements (rect, circle, ellipse, path, polygon, line, g, defs, linearGradient, radialGradient, stop, clipPath); no text or letters at all; no images, no external references, no scripts, no filters; keep it under 6000 characters; cultural sensitivity - no depictions of prophets or sacred imagery, modest clothing for any people.
PROMPT;
        $data = $this->ai->json($system, "Canvas: viewBox \"0 0 {$w} {$h}\". Draw: {$prompt}", ['type' => 'object', 'properties' => ['svg' => ['type' => 'string']], 'required' => ['svg'], 'additionalProperties' => false], 8000);

        if (empty($data['svg']) || ! str_contains($data['svg'], '<svg')) {
            return null;
        }
        $svg = SvgSanitizer::clean($data['svg']);
        if (! preg_match('/viewBox=/', $svg)) {
            $svg = preg_replace('/<svg\b/', "<svg viewBox=\"0 0 {$w} {$h}\"", $svg, 1) ?? $svg;
        }

        return $svg;
    }

    /** An abstract composition, deterministic per prompt, in the platform palette. */
    public function placeholder(string $prompt, string $aspect = '16:9'): string
    {
        [$w, $h] = ['16:9' => [1280, 720], '4:3' => [1200, 900], '1:1' => [1000, 1000]][$aspect];
        $seed = crc32($prompt ?: 'kit');
        $rand = function () use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;

            return $seed / 0x7FFFFFFF;
        };
        $palette = [['#8A1538', '#A29475', '#F7F3EA'], ['#1F2A44', '#A29475', '#F3E9D2'], ['#5E0E26', '#C9B98D', '#FFFFFF'], ['#0F5C63', '#E9C46A', '#F7F3EA']][$seed % 4];
        $shapes = '';
        for ($i = 0; $i < 5; $i++) {
            $shapes .= sprintf('<circle cx="%d" cy="%d" r="%d" fill="%s" opacity="%.2f"/>', $rand() * $w, $rand() * $h, 80 + $rand() * ($h / 2.2), $palette[$i % 3], 0.14 + $rand() * 0.28);
        }
        $y1 = $h * (0.55 + $rand() * 0.2);
        $y2 = $h * (0.7 + $rand() * 0.15);
        $wave = sprintf('<path d="M0 %d C %d %d, %d %d, %d %d S %d %d, %d %d L %d %d L 0 %d Z" fill="%s" opacity="0.55"/>', $y1, $w * .25, $y1 - 90, $w * .5, $y1 + 90, $w * .6, $y1, $w * .85, $y1 - 60, $w, $y1 - 10, $w, $h, $h, $palette[1]);
        $wave2 = sprintf('<path d="M0 %d C %d %d, %d %d, %d %d L %d %d L 0 %d Z" fill="%s" opacity="0.8"/>', $y2, $w * .3, $y2 - 70, $w * .65, $y2 + 60, $w, $y2 - 20, $w, $h, $h, $palette[0]);
        $dots = '';
        for ($i = 0; $i < 18; $i++) {
            $dots .= sprintf('<circle cx="%d" cy="%d" r="%d" fill="#FFFFFF" opacity="%.2f"/>', $rand() * $w, $rand() * $h * .6, 3 + $rand() * 6, 0.2 + $rand() * 0.4);
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$w.' '.$h.'"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="'.$palette[2].'"/><stop offset="1" stop-color="'.$palette[1].'" stop-opacity=".5"/></linearGradient></defs>'
            .'<rect width="'.$w.'" height="'.$h.'" fill="url(#g)"/>'.$shapes.$wave.$wave2.$dots.'</svg>';
    }
}
