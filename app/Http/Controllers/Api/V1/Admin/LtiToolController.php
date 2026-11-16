<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CourseModule;
use App\Models\LtiTool;
use App\Services\Lti\LtiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The LTI tools registry and the platform details a tool needs to register TEDC. */
class LtiToolController extends Controller
{
    public function __construct(private readonly LtiService $lti) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => LtiTool::orderBy('name')->get()->makeHidden('consumer_secret')->all(), 'platform' => $this->platform()]);
    }

    public function save(Request $request, ?LtiTool $tool = null): JsonResponse
    {
        $r = $tool ? 'sometimes' : 'required';
        $d = $request->validate(['name' => [$r, 'string', 'max:200'], 'version' => [$r, Rule::in(['1.1', '1.3'])], 'client_id' => ['nullable', 'string', 'max:200'], 'deployment_id' => ['nullable', 'string', 'max:200'], 'login_url' => ['nullable', 'url', 'max:500'], 'launch_url' => [$r, 'url', 'max:500'],
            'jwks_url' => ['nullable', 'url', 'max:500'], 'public_key' => ['nullable', 'string', 'max:5000'], 'deep_link_url' => ['nullable', 'url', 'max:500'], 'consumer_key' => ['nullable', 'string', 'max:200'], 'consumer_secret' => ['nullable', 'string', 'max:300'],
            'custom' => ['nullable', 'array'], 'privacy' => ['nullable', 'array'], 'privacy.share_name' => ['boolean'], 'privacy.share_email' => ['boolean'], 'supports_ags' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean']]);
        if (($d['version'] ?? $tool?->version) === '1.3' && empty($d['client_id'] ?? $tool?->client_id)) {
            abort(422, 'An LTI 1.3 tool needs a client id.');
        }
        $row = $tool ? tap($tool)->update($d) : LtiTool::create($d + ['deployment_id' => $d['deployment_id'] ?? '1']);

        return response()->json(['data' => $row->fresh()->makeHidden('consumer_secret')], $tool ? 200 : 201);
    }

    public function destroy(LtiTool $tool): JsonResponse
    {
        $tool->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function rotate(): JsonResponse
    {
        $this->lti->rotate();

        return response()->json(['data' => $this->platform()]);
    }

    /** Starts content selection in a tool for a module (Deep Linking 2.0). */
    public function deepLink(Request $request, LtiTool $tool): JsonResponse
    {
        $module = CourseModule::findOrFail($request->validate(['module_id' => ['required', 'uuid', 'exists:course_modules,id']])['module_id']);

        return response()->json(['data' => $this->lti->loginInitiation($tool, $this->user(), null, 'deep_link', ['module_id' => $module->id, 'program_id' => $module->program_id])]);
    }

    private function platform(): array
    {
        return ['issuer' => $this->lti->issuer(), 'jwks_url' => url('/api/v1/lti/jwks'), 'auth_url' => url('/api/v1/lti/auth'), 'token_url' => url('/api/v1/lti/token'), 'deep_link_return_url' => url('/api/v1/lti/deep-link/return'), 'outcomes_url' => url('/api/v1/lti/outcomes'), 'key_id' => $this->lti->keys()['kid']];
    }
}
