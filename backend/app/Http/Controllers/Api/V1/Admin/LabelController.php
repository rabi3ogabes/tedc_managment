<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\LabelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Labels: rename menus, buttons and other interface texts (Arabic and English). */
class LabelController extends Controller
{
    public function __construct(private readonly LabelSettings $labels) {}

    /** Public: the web app applies these on top of its built-in texts. */
    public function publicIndex(): JsonResponse
    {
        return response()->json(['data' => $this->labels->all(), 'version' => $this->labels->version()]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->labels->all(), 'version' => $this->labels->version()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ar' => ['sometimes', 'array', 'max:'.LabelSettings::MAX_ENTRIES], 'ar.*' => ['nullable', 'string', 'max:200'],
            'en' => ['sometimes', 'array', 'max:'.LabelSettings::MAX_ENTRIES], 'en.*' => ['nullable', 'string', 'max:200'],
            'replace' => ['sometimes', 'boolean'],
        ]);
        $this->labels->update($data, $request->user(), (bool) ($data['replace'] ?? false));

        return $this->show();
    }
}
