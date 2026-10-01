<?php

namespace App\Services\Notifications;

/**
 * Where the mobile app opens when a notification is tapped. Every notification carries a `route` in its data
 * (also delivered with the push), derived from its event and the ids it refers to, so a "mark your attendance"
 * notification opens the attendance page and a task notification opens that task.
 *
 * Routes that need the recipient's own registration (`/my-program/{id}`) are resolved by the app.
 */
class NotificationRoute
{
    /** @param  array<string, mixed>  $data */
    public static function for(string $type, array $data): string
    {
        $explicit = (string) ($data['route'] ?? '');
        if (str_starts_with($explicit, '/') && ! in_array($explicit, ['/training', '/notifications'], true)) {
            return $explicit;
        }

        $id = fn (string $key) => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        $group = strstr($type, '.', true) ?: $type;

        return match (true) {
            $group === 'session' && $id('session_id') !== null => '/sessions/'.$id('session_id'),
            $group === 'task' && $id('task_id') !== null => '/tasks/'.$id('task_id'),
            $type === 'impact.survey' && $id('survey_id') !== null => '/surveys/'.$id('survey_id'),
            $group === 'needs_survey' && $id('needs_survey_id') !== null => '/needs-surveys/'.$id('needs_survey_id'),
            $group === 'certificate' => '/certificates',
            $group === 'profile' => '/account',
            $type === 'program.invite' && $id('program_code') !== null => '/programs/'.$id('program_code'),
            $id('registration_id') !== null => '/registrations/'.$id('registration_id'),
            in_array($group, ['survey', 'program', 'registration'], true) && $id('program_id') !== null => '/my-program/'.$id('program_id'),
            default => $explicit !== '' ? $explicit : '/notifications',
        };
    }

    /** @param  array<string, mixed>  $data  @return array<string, mixed> */
    public static function withRoute(string $type, array $data): array
    {
        return ['route' => self::for($type, $data)] + $data;
    }
}
