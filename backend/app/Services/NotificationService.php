<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifications\NotificationRoute;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Push\PushDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes bilingual in-app notifications. Supabase Realtime streams each inserted
 * row to the owning user's web / mobile clients (RLS restricts rows to auth.uid()),
 * and, when Firebase is configured, each notification is also pushed to the users' phones.
 */
class NotificationService
{
    public function __construct(private readonly PushDispatcher $push, private readonly NotificationTemplates $templates) {}

    /**
     * @param  User|string  $user  user model or id
     * @param  array{ar: string, en: string}  $title
     * @param  array{ar: string, en: string}|null  $body
     */
    public function send(User|string $user, string $type, array $title, ?array $body = null, array $data = [], ?string $campaignId = null, bool $force = false, bool $raw = false): ?AppNotification
    {
        $userId = $user instanceof User ? $user->id : $user;

        // An administrator can switch an action off, or rewrite its wording (Settings → Notification templates).
        $composed = $raw ? ['title' => $title, 'body' => $body, 'push' => true, 'template' => null] : $this->templates->compose($type, $title, $body, $data, $userId, $force);
        if ($composed === null) {
            return null;
        }
        ['title' => $title, 'body' => $body] = $composed;
        $data = NotificationRoute::withRoute($type, $data);

        $notification = AppNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title_ar' => $title['ar'],
            'title_en' => $title['en'],
            'body_ar' => $body['ar'] ?? null,
            'body_en' => $body['en'] ?? null,
            'data' => $data,
            'campaign_id' => $campaignId,
        ]);

        if ($composed['push']) {
            $this->push->dispatch([$userId], $type, $title, $body, $data + ['notification_id' => $notification->id], force: $force);
        }

        return $notification;
    }

    /**
     * Efficient fan-out for announcements and reminders.
     *
     * @param  Collection<int, string>|array<int, string>  $userIds
     */
    public function broadcast(Collection|array $userIds, string $type, array $title, ?array $body = null, array $data = [], ?string $campaignId = null, bool $force = false, bool $raw = false): int
    {
        $composed = $raw ? ['title' => $title, 'body' => $body, 'push' => true, 'template' => null] : $this->templates->compose($type, $title, $body, $data, null, $force);
        if ($composed === null) {
            return 0;
        }
        ['title' => $title, 'body' => $body] = $composed;
        $data = NotificationRoute::withRoute($type, $data);
        // Wording that greets each person by name is rendered per recipient.
        $perUser = $composed['template'] && $this->templates->isCustomised($composed['template']) && str_contains(json_encode($composed['template']), '{{name');

        $now = now();
        $count = 0;

        collect($userIds)->unique()->chunk(500)->each(function (Collection $chunk) use ($type, $title, $body, $data, $now, $campaignId, $perUser, $force, &$count) {
            $rows = $chunk->map(function ($id) use ($type, $title, $body, $data, $now, $campaignId, $perUser, $force) {
                if ($perUser) {
                    ['title' => $title, 'body' => $body] = $this->templates->compose($type, $title, $body, $data, $id, $force) ?? ['title' => $title, 'body' => $body];
                }

                return [
                    'id' => (string) Str::uuid(),
                    'user_id' => $id,
                    'type' => $type,
                    'title_ar' => $title['ar'],
                    'title_en' => $title['en'],
                    'body_ar' => $body['ar'] ?? null,
                    'body_en' => $body['en'] ?? null,
                    'data' => json_encode($data),
                    'campaign_id' => $campaignId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('notifications')->insert($rows);
            $count += count($rows);
        });

        if ($composed['push']) {
            $this->push->dispatch($userIds, $type, $title, $body, $data, force: $force);
        }

        return $count;
    }
}
