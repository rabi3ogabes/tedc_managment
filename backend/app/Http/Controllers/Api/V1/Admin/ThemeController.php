<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\FileStorage;
use App\Services\ThemeOccasions;
use App\Services\ThemeService;
use App\Support\SvgGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    public function __construct(private readonly ThemeService $themes) {}

    /** Public: the active theme, consumed by the website, dashboard and portal. */
    public function show(Request $request): JsonResponse
    {
        // `base` is the saved theme without an occasion laid over it: the Brand Studio edits that one.
        return response()->json(['data' => $this->themes->get(), 'meta' => ['base' => $request->boolean('base') ? $this->themes->base() : null]]);
    }

    /** The ready-made occasion looks, for the Brand Studio's occasion list. */
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => collect(ThemeOccasions::catalog())->map(fn ($o, $id) => ['id' => $id] + $o)->values()]);
    }

    /** The standard occasions of a year as a list (nothing is saved): the Brand Studio adds the missing ones to its draft. */
    public function standardList(Request $request): JsonResponse
    {
        $d = $request->validate(['year' => ['nullable', 'integer', 'between:2024,2040']]);

        return response()->json(['data' => ThemeOccasions::standard((int) ($d['year'] ?? now()->year))]);
    }

    public function standardOccasions(Request $request): JsonResponse
    {
        $d = $request->validate(['year' => ['nullable', 'integer', 'between:2024,2040']]);

        return response()->json(['data' => $this->themes->addStandardOccasions((int) ($d['year'] ?? now()->year), $this->user())]);
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
            'pattern.size' => ['required', 'integer', 'between:8,400'],
            'pattern.image' => $asset,
            'pattern.tint' => ['nullable', self::HEX],
            'pattern.repeat' => ['nullable', Rule::in(['tile', 'cover'])],
            'shape' => ['required', 'array'],
            'shape.card_radius' => ['required', 'integer', 'between:0,40'],
            'shape.glass_blur' => ['required', 'integer', 'between:0,40'],
            'identity' => ['sometimes', 'array'],
            'identity.name_ar' => ['nullable', 'string', 'max:120'],
            'identity.name_en' => ['nullable', 'string', 'max:120'],
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
            'loading' => ['sometimes', 'array'],
            'loading.style' => ['required_with:loading', Rule::in(['emblem', 'bar', 'dots', 'pulse', 'crescent'])],
            'loading.message_ar' => ['nullable', 'string', 'max:120'],
            'loading.message_en' => ['nullable', 'string', 'max:120'],
            'loading.background' => ['nullable', self::HEX],
            'loading.accent' => ['nullable', self::HEX],
            'loading.show_name' => ['boolean'],
            'occasions' => ['sometimes', 'array', 'max:60'],
            'occasions.*.id' => ['required', 'string', 'max:60'],
            'occasions.*.name_ar' => ['required', 'string', 'max:120'],
            'occasions.*.name_en' => ['required', 'string', 'max:120'],
            'occasions.*.enabled' => ['boolean'],
            'occasions.*.recurring' => ['boolean'],
            'occasions.*.starts_on' => ['required', 'date_format:Y-m-d'],
            'occasions.*.ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:occasions.*.starts_on'],
            'occasions.*.patch' => ['sometimes', 'array'],
            'occasions.*.patch.colors.*' => ['nullable', self::HEX],
            'occasions.*.patch.loading.style' => ['nullable', Rule::in(['emblem', 'bar', 'dots', 'pulse', 'crescent'])],
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
        $pattern = $request->input('kind') === 'pattern';
        $request->validate([
            'file' => $request->input('kind') === 'font'
                ? ['required', 'file', 'extensions:woff2,woff,ttf,otf', 'max:4096']
                // A background pattern can also be an SVG (it stays sharp at any size and can be recoloured).
                : ['required', 'file', 'extensions:'.($pattern ? 'jpg,jpeg,png,webp,svg' : 'jpg,jpeg,png,webp'), 'mimes:'.($pattern ? 'jpg,jpeg,png,webp,svg' : 'jpg,jpeg,png,webp'), 'max:5120'],
        ]);
        if ($pattern && strtolower($request->file('file')->getClientOriginalExtension()) === 'svg' && ! SvgGuard::isSafe((string) file_get_contents($request->file('file')->getRealPath()))) {
            throw new BusinessRuleException(__('messages.theme.unsafe_svg'), 'unsafe_svg');
        }

        $path = $storage->uploadPublic($request->file('file'), 'theme/'.$request->input('kind'));

        return response()->json(['data' => ['url' => FileStorage::publicUrl($path)]], 201);
    }
}
