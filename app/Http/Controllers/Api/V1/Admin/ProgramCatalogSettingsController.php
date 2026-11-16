<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProgramCatalogSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Programme catalogue: type names and code prefixes, the code format, and the delivery-mode names. */
class ProgramCatalogSettingsController extends Controller
{
    public function __construct(private readonly ProgramCatalogSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->overview()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'types' => ['sometimes', 'array'], 'types.*.name_ar' => ['sometimes', 'string', 'max:60'], 'types.*.name_en' => ['sometimes', 'string', 'max:60'],
            'types.*.hint_ar' => ['sometimes', 'nullable', 'string', 'max:200'], 'types.*.hint_en' => ['sometimes', 'nullable', 'string', 'max:200'],
            'types.*.prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z]{2,5}$/'], 'types.*.enabled' => ['sometimes', 'boolean'],
            'modes' => ['sometimes', 'array'], 'modes.*.name_ar' => ['sometimes', 'string', 'max:60'], 'modes.*.name_en' => ['sometimes', 'string', 'max:60'],
            'code' => ['sometimes', 'array'], 'code.default_prefix' => ['sometimes', 'string', 'regex:/^[A-Za-z]{2,5}$/'], 'code.digits' => ['sometimes', 'integer', 'min:3', 'max:6'], 'code.separator' => ['sometimes', 'string', 'in:-,,/,.'],
        ]);
        $this->settings->update($data, $this->user());

        return $this->show();
    }
}
