<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FeatureSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Which features are on: readable by every signed-in user (web, app), changed by settings managers. */
class FeaturesController extends Controller
{
    public function __construct(private readonly FeatureSettings $features) {}

    public function map(): JsonResponse
    {
        return response()->json(['data' => ['flags' => $this->features->map(), 'unsafe_active' => $this->features->unsafeActive(), 'environment' => app()->environment()]]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->features->describe()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless($this->features->exists($key), 404);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $this->features->set($key, (bool) $data['enabled'], $data['reason'] ?? null, $request->user(), $request);

        return response()->json(['data' => collect($this->features->describe())->firstWhere('key', $key)]);
    }
}
