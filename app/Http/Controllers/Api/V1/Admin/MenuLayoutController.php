<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard's side menu as the administrator arranged it: the order of the sections, which section each button sits
 * in, the section names and the icon of any button. Only the arrangement is stored; the buttons themselves, their
 * names and who may see them stay in the application, so a new feature still appears and permissions still apply.
 */
class MenuLayoutController extends Controller
{
    public const KEY = 'admin_menu';

    private static function empty(): array
    {
        return ['sections' => [], 'icons' => (object) []];
    }

    /** Everyone signed in reads it (the menu is drawn from it). */
    public function show(): JsonResponse
    {
        $stored = Cache::remember('site.admin_menu', 60, fn () => SiteSetting::find(self::KEY)?->value);

        return response()->json(['data' => $stored ?: self::empty()]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sections' => ['present', 'array', 'max:30'],
            'sections.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/', 'distinct'],
            'sections.*.title_ar' => ['nullable', 'string', 'max:60'],
            'sections.*.title_en' => ['nullable', 'string', 'max:60'],
            'sections.*.items' => ['present', 'array', 'max:200'],
            'sections.*.items.*' => ['string', 'regex:/^\/admin[A-Za-z0-9\/_-]{0,80}$/'],
            'icons' => ['present', 'array', 'max:300'],
            'icons.*' => ['string', 'regex:/^[A-Za-z][A-Za-z0-9]{1,40}$/'],
        ]);

        $seen = [];
        $sections = [];
        foreach ($d['sections'] as $s) {
            // A button sits in one section only: the first one that lists it wins.
            $items = array_values(array_filter(array_unique($s['items']), function ($path) use (&$seen) {
                return ! isset($seen[$path]) && ($seen[$path] = true);
            }));
            $sections[] = [
                'id' => $s['id'],
                'title_ar' => isset($s['title_ar']) ? trim(strip_tags($s['title_ar'])) ?: null : null,
                'title_en' => isset($s['title_en']) ? trim(strip_tags($s['title_en'])) ?: null : null,
                'items' => $items,
            ];
        }
        $value = ['sections' => $sections, 'icons' => (object) $d['icons']];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $value, 'updated_by' => $this->user()->id]);
        Cache::forget('site.admin_menu');

        return response()->json(['data' => $value]);
    }

    public function reset(): JsonResponse
    {
        SiteSetting::where('key', self::KEY)->delete();
        Cache::forget('site.admin_menu');

        return response()->json(['data' => self::empty()]);
    }
}
