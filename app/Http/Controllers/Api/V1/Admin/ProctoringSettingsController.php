<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Assessment\ProctoringSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Security: how long exam-integrity data is kept (audited through the settings record). */
class ProctoringSettingsController extends Controller
{
    public function __construct(private readonly ProctoringSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['integrity_retention_days' => ['sometimes', 'integer', 'min:7', 'max:3650'], 'consent_version' => ['sometimes', 'string', 'max:20']]);
        $this->settings->update($data, $request->user());

        return $this->show();
    }
}
