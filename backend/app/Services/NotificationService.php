<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes bilingual in-app notifications. Supabase Realtime streams each inserted
 * row to the owning user's web / mobile clients (RLS restricts rows to auth.uid()).
 */
class NotificationService
{
    /**
     * @param  User|string  $user  user model or id
     * @param  array{ar: string, en: string}  $title
     * @param  array{ar: string, en: string}|null  $body
     */
    public function send(User|string $user, string $type, array $title, ?array $body = null, array $data = []): AppNotification
    {
        return AppNotification::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'title_ar' => $title['ar'],
            'title_en' => $title['en'],
            'body_ar' => $body['ar'] ?? null,
            'body_en' => $body['en'] ?? null,
            'data' => $data,
        ]);
    }

    /**
     * Efficient fan-out for announcements and reminders.
     *
     * @param  Collection<int, string>|array<int, string>  $userIds
     */
    public function broadcast(Collection|array $userIds, string $type, array $title, ?array $body = null, array $data = []): int
    {
        $now = now();
        $count = 0;

        collect($userIds)->unique()->chunk(500)->each(function (Collection $chunk) use ($type, $title, $body, $data, $now, &$count) {
            $rows = $chunk->map(fn ($id) => [
                'id' => (string) Str::uuid(),
                'user_id' => $id,
                'type' => $type,
                'title_ar' => $title['ar'],
                'title_en' => $title['en'],
                'body_ar' => $body['ar'] ?? null,
                'body_en' => $body['en'] ?? null,
                'data' => json_encode($data),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('notifications')->insert($rows);
            $count += count($rows);
        });

        return $count;
    }
}
