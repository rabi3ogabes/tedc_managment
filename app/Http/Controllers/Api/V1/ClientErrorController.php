<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ErrorLogService;
use App\Services\ErrorLogSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Receives the errors the website and the mobile app report about themselves (works signed in or not). */
class ClientErrorController extends Controller
{
    public function store(Request $request, ErrorLogService $logs, ErrorLogSettings $settings): JsonResponse
    {
        if (! $settings->all()['enabled'] || ! $settings->all()['capture_clients']) {
            return response()->json(['message' => 'ok']);
        }
        $data = $request->validate([
            'errors' => ['required', 'array', 'min:1', 'max:10'],
            'errors.*.source' => ['required', Rule::in(['web', 'app'])],
            'errors.*.level' => ['nullable', Rule::in(['warning', 'error', 'critical'])],
            'errors.*.message' => ['required', 'string', 'max:3000'],
            'errors.*.stack' => ['nullable', 'string', 'max:20000'],
            'errors.*.route' => ['nullable', 'string', 'max:300'],
            'errors.*.url' => ['nullable', 'string', 'max:500'],
            'errors.*.app_version' => ['nullable', 'string', 'max:40'],
            'errors.*.status_code' => ['nullable', 'integer', 'between:0,599'],
            'errors.*.device' => ['nullable', 'array'],
            'errors.*.context' => ['nullable', 'array'],
        ]);

        // The reporter may be signed in: the error is then tied to the user.
        $user = null;
        try {
            $user = Auth::guard('api')->user();
        } catch (\Throwable) {
        }

        foreach ($data['errors'] as $e) {
            $logs->record([
                'source' => $e['source'], 'level' => $e['level'] ?? 'error', 'message' => $e['message'], 'stack' => $e['stack'] ?? null, 'location' => $e['route'] ?? null,
                'url' => $e['url'] ?? null, 'status_code' => $e['status_code'] ?? null, 'app_version' => $e['app_version'] ?? null, 'device' => $e['device'] ?? null, 'context' => $e['context'] ?? null,
                'exception' => $e['source'] === 'web' ? 'JavaScript' : 'Flutter', 'user_id' => $user?->id, 'user_email' => $user?->email, 'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            ]);
        }

        return response()->json(['message' => 'ok'], 202);
    }
}
