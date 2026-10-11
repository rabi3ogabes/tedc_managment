<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\PushLog;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\DeliveryPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the push counterpart of in-app notifications to the recipients' registered devices, in each
 * device's language. Never throws: a push failure must not break the business action that notified.
 */
class PushDispatcher
{
    public function __construct(private readonly PushSettings $settings, private readonly FcmClient $fcm) {}

    /**
     * @param  Collection<int, string>|array<int, string>  $userIds
     * @param  array{ar: string, en: string}  $title
     * @param  array{ar: string, en: string}|null  $body
     */
    public function dispatch(Collection|array $userIds, string $type, array $title, ?array $body = null, array $data = [], bool $force = false, ?string $triggeredBy = null): ?PushLog
    {
        if (! $this->settings->isReady() || (! $force && ! $this->settings->allows($type))) {
            return null;
        }

        $userIds = collect($userIds)->filter()->unique()->values();
        if (! DeviceToken::query()->whereIn('user_id', $userIds)->exists()) {
            return null;
        }

        // People who switched the sound off get the silent channel; everyone else hears the TEDC chime (NTF-09).
        $quiet = UserNotificationPreference::whereIn('user_id', $userIds)->where(['event_group' => DeliveryPolicy::SOUND, 'channel' => DeliveryPolicy::SOUND, 'enabled' => false])->pluck('user_id')->flip();
        $devices = DeviceToken::query()->whereIn('user_id', $userIds)->get(['id', 'token', 'locale', 'user_id']);
        $messages = $devices->map(fn (DeviceToken $d) => [
            'token' => $d->token,
            'title' => $d->locale === 'en' ? $title['en'] : $title['ar'],
            'body' => $body ? ($d->locale === 'en' ? $body['en'] : $body['ar']) : null,
            'sound' => ! $quiet->has($d->user_id),
        ])->all();

        try {
            $result = $this->fcm->send($messages, ['type' => $type, 'route' => $data['route'] ?? '/notifications'] + $data);
        } catch (Throwable $e) {
            Log::warning('Push notification failed: '.$e->getMessage());
            $result = ['delivered' => 0, 'failed' => count($messages), 'invalid_tokens' => [], 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        if ($result['invalid_tokens']) {
            DeviceToken::whereIn('token', $result['invalid_tokens'])->delete();
        }

        return PushLog::create([
            'type' => $type,
            'title' => $title['ar'],
            'recipients' => $userIds->count(),
            'devices' => count($messages),
            'delivered' => $result['delivered'],
            'failed' => $result['failed'],
            'pruned' => count($result['invalid_tokens']),
            'error' => $result['error'],
            'triggered_by' => $triggeredBy,
        ]);
    }
}
