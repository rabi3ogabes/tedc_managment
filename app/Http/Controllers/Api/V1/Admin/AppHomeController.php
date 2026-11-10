<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AppHomeLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppHomeController extends Controller
{
    public function __construct(private readonly AppHomeLayout $layout) {}

    /** Settings → App home screen: every role's view (cards and action tiles, in order, on or off). */
    public function show(): JsonResponse
    {
        $names = Role::whereIn('slug', AppHomeLayout::ROLES)->get()->keyBy('slug');

        return response()->json(['data' => collect(AppHomeLayout::ROLES)->map(fn ($slug) => [
            'role' => $slug, 'name' => ['ar' => $names[$slug]->name_ar ?? $slug, 'en' => $names[$slug]->name_en ?? $slug],
            'sections' => $this->layout->get($slug), 'customised' => $this->layout->customised($slug),
        ])->values(), 'meta' => ['cards' => AppHomeLayout::CARDS, 'actions' => AppHomeLayout::ACTIONS]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'role' => ['required', 'string', Rule::in(AppHomeLayout::ROLES)],
            'sections' => ['required', 'array', 'max:40'],
            'sections.*.key' => ['required', 'string', Rule::in(AppHomeLayout::universe()), 'distinct'],
            'sections.*.enabled' => ['required', 'boolean'],
        ]);
        $this->layout->save($d['role'], $d['sections'], $this->user()->id);

        return $this->show();
    }

    public function reset(Request $request): JsonResponse
    {
        $role = $request->query('role');
        abort_if($role !== null && ! in_array($role, AppHomeLayout::ROLES, true), 422, 'Unknown role.');
        $this->layout->reset($role);

        return $this->show();
    }
}
