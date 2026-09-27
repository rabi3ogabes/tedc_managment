<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Registers the signed-in user's phone for push notifications. */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        // A token belongs to one device; if another account signed in on it, the token moves to this user.
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'last_seen_at' => now()] + $data,
        );

        return response()->json(['data' => ['id' => $device->id, 'platform' => $device->platform, 'locale' => $device->locale]], 201);
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['token' => ['required', 'string', 'max:512']]);
        DeviceToken::where('user_id', $request->user()->id)->where('token', $request->input('token'))->delete();

        return response()->noContent();
    }
}
