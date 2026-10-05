<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CaliperEvent;
use App\Services\Content\CaliperService;
use App\Services\Content\StandardsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings → Standards and integrations: LRS credentials and forwarding, Caliper, offline. */
class StandardsController extends Controller
{
    public function __construct(private readonly StandardsSettings $settings, private readonly CaliperService $caliper) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->masked() + ['lrs_endpoint' => url('/api/v1/xapi').'/', 'caliper_pending' => CaliperEvent::where('status', 'pending')->count(), 'caliper_failed' => CaliperEvent::where('status', 'failed')->count()]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate(['lrs_forward' => ['sometimes', 'array'], 'lrs_forward.enabled' => ['boolean'], 'lrs_forward.endpoint' => ['nullable', 'url', 'max:500'], 'lrs_forward.key' => ['nullable', 'string', 'max:200'], 'lrs_forward.secret' => ['nullable', 'string', 'max:300'],
            'caliper' => ['sometimes', 'array'], 'caliper.enabled' => ['boolean'], 'caliper.endpoint' => ['nullable', 'url', 'max:500'], 'caliper.api_key' => ['nullable', 'string', 'max:300'], 'caliper.sensor_id' => ['nullable', 'string', 'max:300'],
            'offline' => ['sometimes', 'array'], 'offline.enabled' => ['boolean'], 'offline.expiry_days' => ['integer', 'between:1,365']]);

        return response()->json(['data' => $this->settings->update($d, $this->user())]);
    }

    public function addCredential(Request $request): JsonResponse
    {
        $d = $request->validate(['label' => ['required', 'string', 'max:100'], 'scope' => ['required', Rule::in(['read', 'write', 'readwrite'])]]);

        return response()->json(['data' => $this->settings->addCredential($d['label'], $d['scope'], $this->user())], 201);
    }

    public function removeCredential(string $key): JsonResponse
    {
        $this->settings->removeCredential($key, $this->user());

        return response()->json(['data' => ['removed' => true]]);
    }

    public function flushCaliper(): JsonResponse
    {
        return response()->json(['data' => $this->caliper->flush()]);
    }
}
