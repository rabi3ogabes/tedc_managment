<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\FileStorage;
use App\Services\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    public function __construct(private readonly ThemeService $themes) {}

    /** Public: the active theme, consumed by the website, dashboard and portal. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->themes->get()]);
    }

    public function update(Request $request): JsonResponse
    {
        $asset = ['nullable', 'string', 'max:500'];

        $data = $request->validate([
            'preset' => ['nullable', 'string', 'max:40'],
            'colors' => ['required', 'array'],
            'colors.primary' => ['required', self::HEX],
            'colors.accent' => ['required', self::HEX],
            'colors.background' => ['required', self::HEX],
            'colors.surface' => ['required', self::HEX],
            'colors.text' => ['required', self::HEX],
            'colors.link' => ['required', self::HEX],
            'buttons' => ['required', 'array'],
            'buttons.style' => ['required', Rule::in(['gradient', 'solid', 'outline'])],
            'buttons.radius' => ['required', 'integer', 'between:0,32'],
            'buttons.accent_text' => ['required', self::HEX],
            'buttons.uppercase' => ['boolean'],
            'banners' => ['required', 'array'],
            'banners.overlay_color' => ['required', self::HEX],
            'banners.overlay_opacity' => ['required', 'integer', 'between:0,95'],
            'banners.hero_images' => ['present', 'array', 'max:4'],
            'banners.hero_images.*' => $asset,
            'banners.page_banner_image' => $asset,
            'banners.cta_style' => ['required', Rule::in(['gradient', 'accent', 'image'])],
            'pattern' => ['required', 'array'],
            'pattern.type' => ['required', Rule::in(ThemeService::PATTERNS)],
            'pattern.color' => ['required', self::HEX],
            'pattern.opacity' => ['required', 'integer', 'between:0,100'],
            'pattern.size' => ['required', 'integer', 'between:8,120'],
            'pattern.image' => $asset,
            'shape' => ['required', 'array'],
            'shape.card_radius' => ['required', 'integer', 'between:0,40'],
            'shape.glass_blur' => ['required', 'integer', 'between:0,40'],
            'identity' => ['sometimes', 'array'],
            'identity.logo_ar' => $asset,
            'identity.logo_en' => $asset,
            'identity.logo_ar_light' => $asset,
            'identity.logo_en_light' => $asset,
            'identity.show_center_name' => ['boolean'],
            'typography' => ['sometimes', 'array'],
            'typography.arabic_family' => ['required_with:typography', 'string', 'max:60', 'regex:/^[\pL\pN \-]+$/u'],
            'typography.latin_family' => ['required_with:typography', 'string', 'max:60', 'regex:/^[\pL\pN \-]+$/u'],
            'typography.arabic_font_url' => $asset,
            'typography.latin_font_url' => $asset,
            'typography.heading_weight' => ['sometimes', 'integer', Rule::in([500, 600, 700, 800, 900])],
        ]);

        return response()->json(['data' => $this->themes->update($data, $this->user())]);
    }

    public function reset(): JsonResponse
    {
        return response()->json(['data' => $this->themes->reset($this->user())]);
    }

    /** Uploads a banner / hero / pattern / logo image or a web font to the public assets bucket. */
    public function upload(Request $request, FileStorage $storage): JsonResponse
    {
        $request->validate(['kind' => ['required', Rule::in(['hero', 'banner', 'pattern', 'logo', 'font'])]]);
        $request->validate([
            'file' => $request->input('kind') === 'font'
                ? ['required', 'file', 'extensions:woff2,woff,ttf,otf', 'max:4096']
                : ['required', 'file', 'extensions:jpg,jpeg,png,webp', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        $path = $storage->uploadPublic($request->file('file'), 'theme/'.$request->input('kind'));

        return response()->json(['data' => ['url' => FileStorage::publicUrl($path)]], 201);
    }
}
