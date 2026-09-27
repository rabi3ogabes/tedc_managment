<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\PushLog;
use App\Services\Push\FcmClient;
use App\Services\Push\PushDispatcher;
use App\Services\Push\PushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Settings → Notifications: Firebase credentials, pushed categories, connection check and test sends. */
class PushSettingsController extends Controller
{
    public function __construct(private readonly PushSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => $this->settings->forAdmin(),
            'categories' => PushSettings::CATEGORIES,
            'stats' => $this->stats(),
            'logs' => PushLog::latest()->limit(25)->get(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'service_account_json' => ['nullable', 'string', 'max:20000'],
            'remove_service_account' => ['sometimes', 'boolean'],
            'client' => ['sometimes', 'array'],
            'client.api_key' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'client.app_id' => ['nullable', 'string', 'max:100', 'regex:/^[0-9]+:[0-9]+:[a-z]+:[0-9a-f]+$/'],
            'client.messaging_sender_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'client.project_id' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            'client.storage_bucket' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9.\-_]+$/'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
            'android' => ['sometimes', 'array'],
            'android.channel_name_ar' => ['sometimes', 'string', 'max:60'],
            'android.channel_name_en' => ['sometimes', 'string', 'max:60'],
            'android.color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json(['data' => [
            'settings' => $this->settings->update($data, $request->user()),
            'categories' => PushSettings::CATEGORIES,
            'stats' => $this->stats(),
            'logs' => PushLog::latest()->limit(25)->get(),
        ]]);
    }

    /** Signs in to Google with the stored service account. */
    public function verify(FcmClient $fcm): JsonResponse
    {
        try {
            $fcm->accessToken(fresh: true);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['service_account_json' => $e->getMessage()]);
        }

        return response()->json(['data' => ['ok' => true, 'project_id' => $this->settings->serviceAccount()['project_id'] ?? null]]);
    }

    /** Sends a test notification to the signed-in administrator's own devices (or everyone with `audience=all`). */
    public function test(Request $request, PushDispatcher $push): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['sometimes', Rule::in(['me', 'all'])],
            'title_ar' => ['nullable', 'string', 'max:120'],
            'title_en' => ['nullable', 'string', 'max:120'],
            'body_ar' => ['nullable', 'string', 'max:300'],
            'body_en' => ['nullable', 'string', 'max:300'],
        ]);

        if (! $this->settings->isReady()) {
            throw ValidationException::withMessages(['enabled' => __('push.not_ready')]);
        }

        $userIds = ($data['audience'] ?? 'me') === 'all'
            ? DeviceToken::query()->distinct()->pluck('user_id')
            : collect([$request->user()->id]);

        if (DeviceToken::whereIn('user_id', $userIds)->doesntExist()) {
            throw ValidationException::withMessages(['audience' => __('push.no_devices')]);
        }

        $log = $push->dispatch(
            $userIds,
            'test',
            ['ar' => $data['title_ar'] ?? 'إشعار تجريبي', 'en' => $data['title_en'] ?? 'Test notification'],
            ['ar' => $data['body_ar'] ?? 'الإشعارات تعمل بنجاح ✓', 'en' => $data['body_en'] ?? 'Push notifications are working ✓'],
            ['route' => '/notifications'],
            force: true,
            triggeredBy: $request->user()->id,
        );

        return response()->json(['data' => $log]);
    }

    private function stats(): array
    {
        $since = now()->subDays(7);

        return [
            'devices' => DeviceToken::count(),
            'users' => DeviceToken::distinct()->count('user_id'),
            'by_platform' => DeviceToken::selectRaw('platform, count(*) as total')->groupBy('platform')->pluck('total', 'platform'),
            'delivered_7d' => (int) PushLog::where('created_at', '>=', $since)->sum('delivered'),
            'failed_7d' => (int) PushLog::where('created_at', '>=', $since)->sum('failed'),
        ];
    }
}
