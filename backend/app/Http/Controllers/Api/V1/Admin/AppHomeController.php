<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AppHomeLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppHomeController extends Controller
{
    public function __construct(private readonly AppHomeLayout $layout) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->layout->get(), 'meta' => ['default' => AppHomeLayout::SECTIONS]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sections' => ['required', 'array', 'max:20'],
            'sections.*.key' => ['required', 'string', Rule::in(AppHomeLayout::SECTIONS), 'distinct'],
            'sections.*.enabled' => ['required', 'boolean'],
        ]);

        return response()->json(['data' => $this->layout->save($d['sections'], $this->user()->id)]);
    }

    public function reset(): JsonResponse
    {
        return response()->json(['data' => $this->layout->reset()]);
    }
}
