<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppHomeLayout;
use App\Services\AppThemes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppThemesController extends Controller
{
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    public function __construct(private readonly AppThemes $themes) {}

    /** Settings → App themes: every look (with the administrator's colours), and who has which. */
    public function show(): JsonResponse
    {
        $settings = $this->themes->settings();

        return response()->json(['data' => [
            'presets' => collect(array_keys(AppThemes::presets()))->map(fn ($id) => $this->themes->effective($id) + ['default_colors' => AppThemes::presets()[$id]['colors']])->values(),
            'groups' => collect(AppThemes::GROUPS)->map(fn ($roles, $key) => ['key' => $key, 'roles' => $roles, 'preset' => $settings['assignments'][$key] ?? 'classic'])->values(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $ids = array_keys(AppThemes::presets());
        $d = $request->validate([
            'assignments' => ['present', 'array'],
            'assignments.*' => ['nullable', 'string', Rule::in($ids)],
            'colors' => ['present', 'array'],
            'colors.*' => ['nullable', 'array'],
            'colors.*.primary' => ['sometimes', self::HEX],
            'colors.*.accent' => ['sometimes', self::HEX],
            'colors.*.background' => ['sometimes', self::HEX],
        ]);
        abort_if(array_diff(array_keys($d['assignments']), array_keys(AppThemes::GROUPS)) !== [] || array_diff(array_keys($d['colors']), $ids) !== [], 422, 'Unknown group or look.');
        $this->themes->save($d['assignments'], $d['colors'], $this->user()->id);

        return $this->show();
    }

    public function reset(): JsonResponse
    {
        $this->themes->reset();

        return $this->show();
    }

    /** The signed-in person's look, or null to keep the brand look. */
    public function mine(AppHomeLayout $home): JsonResponse
    {
        return response()->json(['data' => $this->themes->forUser($this->user(), $home)]);
    }
}
