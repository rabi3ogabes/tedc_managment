<?php

namespace App\Services\Library\Providers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * What a provider's records (already put into TEDC's field names by the contract's mapping) become in TEDC: a course with whole hours, a
 * completion with a date. Nothing here knows a provider; everything it needs the mapping has already said.
 */
final class ProviderNormaliser
{
    /** What one of a unit is, in hours (a provider states a duration in its own unit). */
    private const HOURS_PER = ['SECOND' => 1 / 3600, 'SECONDS' => 1 / 3600, 'MINUTE' => 1 / 60, 'MINUTES' => 1 / 60, 'HOUR' => 1, 'HOURS' => 1];

    /**
     * @param  array<string, mixed>  $row  a mapped course: id, title, url, hours | duration + duration_unit, description, level, language, image, status
     * @param  array<string, mixed>  $settings  the provider's settings (default_hours, base_url)
     * @return array<string, mixed>|null null when the record has no id or no title
     */
    public static function course(array $row, array $settings): ?array
    {
        $id = trim(self::text($row['id'] ?? null));
        $title = trim(self::text($row['title'] ?? null));
        if ($id === '' || $title === '') {
            return null;
        }
        $url = trim(self::text($row['url'] ?? null));
        if (str_starts_with($url, '/')) {
            $url = rtrim(trim(self::text($settings['base_url'] ?? null)), '/').$url;   // a mapped address that starts with a slash is on the provider's own address
        }
        $status = strtolower(self::text($row['status'] ?? null));

        return [
            'external_id' => mb_substr($id, 0, 255), 'title' => mb_substr($title, 0, 255), 'url' => mb_substr($url, 0, 500), 'hours' => self::hours($row, $settings),
            'description' => ($d = trim(self::text($row['description'] ?? null))) !== '' ? mb_substr($d, 0, 4000) : null,
            'level' => ($l = strtolower(trim(self::text($row['level'] ?? null)))) !== '' ? mb_substr($l, 0, 20) : null,
            'language' => ($g = strtolower(trim(self::text($row['language'] ?? null)))) !== '' ? mb_substr($g, 0, 10) : null,
            'image_url' => ($i = trim(self::text($row['image'] ?? null))) !== '' ? mb_substr($i, 0, 500) : null,
            'status' => in_array($status, ['available', 'retired'], true) ? $status : 'available',
            'meta' => $row,
        ];
    }

    /** Whole hours, rounded up: the stated hours, else the duration in its unit, else the default for a provider that states none. @param  array<string, mixed>  $row @param  array<string, mixed>  $settings */
    public static function hours(array $row, array $settings): int
    {
        $hours = null;
        if (isset($row['hours']) && is_numeric($row['hours']) && (float) $row['hours'] > 0) {
            $hours = (float) $row['hours'];
        } elseif (isset($row['duration']) && is_numeric($row['duration']) && (float) $row['duration'] > 0 && isset(self::HOURS_PER[strtoupper(self::text($row['duration_unit'] ?? null))])) {
            $hours = (float) $row['duration'] * self::HOURS_PER[strtoupper(self::text($row['duration_unit']))];
        } elseif (isset($settings['default_hours']) && is_numeric($settings['default_hours']) && (float) $settings['default_hours'] > 0) {
            $hours = (float) $settings['default_hours'];
        }

        return $hours === null ? 0 : (int) min(65535, ceil(round($hours, 4)));
    }

    /**
     * @param  array<string, mixed>  $row  a mapped completion
     * @return array{course_id: string, employee_no: ?string, email: ?string, username: ?string, completed: bool, completed_at: ?Carbon, progress: ?int}
     */
    public static function completion(array $row): array
    {
        $at = null;
        if (filled($row['completed_at'] ?? null)) {
            try {
                $at = Carbon::parse(self::text($row['completed_at']));
            } catch (Throwable) {
                $at = null;
            }
        }

        return [
            'course_id' => trim(self::text($row['course_id'] ?? null)),
            'employee_no' => ($n = trim(self::text($row['employee_no'] ?? null))) !== '' ? $n : null,
            'email' => ($e = strtolower(trim(self::text($row['email'] ?? null)))) !== '' ? $e : null,
            'username' => ($u = trim(self::text($row['username'] ?? null))) !== '' ? $u : null,
            // A provider that says so is believed; one that only gives a date has completed it when the date is there.
            'completed' => array_key_exists('completed', $row) && $row['completed'] !== null ? (bool) $row['completed'] : $at !== null,
            'completed_at' => $at,
            'progress' => isset($row['progress']) && is_numeric($row['progress']) ? (int) $row['progress'] : null,
        ];
    }

    private static function text(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
