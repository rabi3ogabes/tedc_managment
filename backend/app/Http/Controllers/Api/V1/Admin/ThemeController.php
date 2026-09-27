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
        ]);

        return response()->json(['data' => $this->themes->update($data, $this->user())]);
    }

    public function reset(): JsonResponse
    {
        return response()->json(['data' => $this->themes->reset($this->user())]);
    }

    /** Uploads a banner, hero or pattern image to the public assets bucket and returns its URL. */
    public function upload(Request $request, FileStorage $storage): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:6144'],
            'kind' => ['required', Rule::in(['hero', 'banner', 'pattern'])],
        ]);

        $path = $storage->uploadPublic($request->file('file'), 'theme/'.$request->input('kind'));

        return response()->json(['data' => ['url' => FileStorage::publicUrl($path)]], 201);
    }
}
