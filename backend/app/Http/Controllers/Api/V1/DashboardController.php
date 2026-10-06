<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\Dashboards\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Each role's dashboard: the layout (the role's preset with the person's own order and hidden widgets), the data of each widget, and the presets for administrators. */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards) {}

    public function layout(): JsonResponse
    {
        return response()->json(['data' => $this->dashboards->layout($this->user())]);
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $d = $request->validate(['order' => ['present', 'array', 'max:40'], 'order.*' => ['string', 'max:48'], 'hidden' => ['present', 'array', 'max:40'], 'hidden.*' => ['string', 'max:48']]);

        return response()->json(['data' => $this->dashboards->saveLayout($this->user(), $d['order'], $d['hidden'])]);
    }

    public function widget(Request $request, string $key): JsonResponse
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $data = $this->dashboards->widget($this->user(), $key, $d['from'] ?? null, $d['to'] ?? null);
        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    /** All presets (administrators), with the widgets that can be chosen. */
    public function presets(): JsonResponse
    {
        $roles = Role::orderByDesc('level')->get(['slug', 'name_ar', 'name_en']);

        return response()->json(['data' => $roles->map(fn ($r) => ['role' => $r->slug, 'name' => ['ar' => $r->name_ar, 'en' => $r->name_en], 'widgets' => $this->dashboards->presetFor($r->slug)])->values(),
            'meta' => ['widgets' => collect(DashboardService::WIDGETS)->map(fn ($w, $k) => ['key' => $k, 'title' => ['ar' => $w[0], 'en' => $w[1]], 'type' => $w[2]])->values()]]);
    }

    public function savePreset(Request $request, string $slug): JsonResponse
    {
        $role = $slug;
        abort_unless(Role::where('slug', $role)->exists(), 404);
        $d = $request->validate(['widgets' => ['required', 'array', 'max:40'], 'widgets.*' => ['string', Rule::in(array_keys(DashboardService::WIDGETS))]]);

        return response()->json(['data' => ['role' => $role, 'widgets' => $this->dashboards->savePreset($role, $d['widgets'])]]);
    }
}
