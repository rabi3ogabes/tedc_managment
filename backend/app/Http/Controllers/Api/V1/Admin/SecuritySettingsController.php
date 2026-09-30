<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Security: the idle lock of the administration team. */
class SecuritySettingsController extends Controller
{
    public function __construct(private readonly SecuritySettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['idle_lock_enabled' => ['sometimes', 'boolean'], 'idle_lock_minutes' => ['sometimes', 'integer', 'min:1', 'max:240']]);
        $this->settings->update($data, $request->user());

        return $this->show();
    }
}
