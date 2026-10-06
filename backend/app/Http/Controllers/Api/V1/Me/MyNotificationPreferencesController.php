<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\DeliveryPolicy;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Each person's choices for optional notifications: which channels per kind of event, and the arrival chime. Events marked mandatory cannot be switched off. */
class MyNotificationPreferencesController extends Controller
{
    private const GROUP_LABELS = [
        'announcements' => ['ar' => 'الإعلانات والفعاليات', 'en' => 'Announcements and events'],
        'system' => ['ar' => 'النظام', 'en' => 'System'],
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $this->user();
        $stored = UserNotificationPreference::where('user_id', $user->id)->get()->mapWithKeys(fn ($p) => [$p->event_group.'|'.$p->channel => $p->enabled]);
        $groups = collect(NotificationCatalog::events())->groupBy('group')->map(function ($events, $group) {
            return ['group' => $group, 'label' => self::GROUP_LABELS[$group] ?? null, 'events' => $events->count()];
        });
        foreach (array_keys(self::GROUP_LABELS) as $g) {
            $groups->put($g, ['group' => $g, 'label' => self::GROUP_LABELS[$g], 'events' => 0]);
        }
        $mandatory = collect(DeliveryPolicy::MANDATORY)->map(fn ($e) => ['event' => $e, 'group' => NotificationCatalog::events()[$e]['group'] ?? 'system'])->groupBy('group')->map->count();

        return response()->json(['data' => [
            'groups' => $groups->values()->map(fn ($g) => $g + [
                'mandatory_events' => (int) ($mandatory[$g['group']] ?? 0),
                'channels' => collect(['push', 'email', 'sms'])->mapWithKeys(fn ($c) => [$c => (bool) ($stored[$g['group'].'|'.$c] ?? true)]),
            ])->all(),
            'sound' => (bool) ($stored[DeliveryPolicy::SOUND.'|'.DeliveryPolicy::SOUND] ?? true),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'sound' => ['sometimes', 'boolean'],
            'groups' => ['sometimes', 'array'], 'groups.*.group' => ['required', 'string', 'max:32'],
            'groups.*.channels' => ['required', 'array'], 'groups.*.channels.*' => ['boolean'],
        ]);
        $user = $this->user();
        if (array_key_exists('sound', $d)) {
            UserNotificationPreference::updateOrCreate(['user_id' => $user->id, 'event_group' => DeliveryPolicy::SOUND, 'channel' => DeliveryPolicy::SOUND], ['enabled' => $d['sound']]);
        }
        foreach ($d['groups'] ?? [] as $g) {
            foreach ($g['channels'] as $channel => $on) {
                if (in_array($channel, ['push', 'email', 'sms'], true)) {
                    UserNotificationPreference::updateOrCreate(['user_id' => $user->id, 'event_group' => $g['group'], 'channel' => $channel], ['enabled' => (bool) $on]);
                }
            }
        }

        return $this->show($request);
    }
}
