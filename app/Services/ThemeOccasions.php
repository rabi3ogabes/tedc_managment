<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Ready-made occasion themes (Ramadan, the two Eids, National Day, Teachers' Day, Sports Day, graduation): each is a colour, pattern
 * and loading-page patch the administrator can switch on for a period. Hijri dates move every year, so those periods are listed per year
 * from the astronomical calendar and must be confirmed against the official announcement.
 */
class ThemeOccasions
{
    /** Approximate periods by Gregorian year: [start, end] — confirm with the official announcement of the moon sighting. */
    private const HIJRI = [
        2026 => ['ramadan' => ['02-18', '03-19'], 'eid_fitr' => ['03-20', '03-23'], 'eid_adha' => ['05-26', '05-30']],
        2027 => ['ramadan' => ['02-08', '03-09'], 'eid_fitr' => ['03-10', '03-13'], 'eid_adha' => ['05-16', '05-20']],
        2028 => ['ramadan' => ['01-28', '02-26'], 'eid_fitr' => ['02-27', '03-01'], 'eid_adha' => ['05-04', '05-08']],
    ];

    /** The look of each occasion. @return array<string, array<string, mixed>> */
    public static function catalog(): array
    {
        $ar = fn (string $text) => $text;

        return [
            'ramadan' => ['name_ar' => 'شهر رمضان المبارك', 'name_en' => 'Ramadan', 'patch' => [
                'colors' => ['primary' => '#1B1F4B', 'accent' => '#D9B35F', 'background' => '#F6F3EA', 'surface' => '#FFFFFF', 'text' => '#161738', 'link' => '#1B1F4B'],
                'buttons' => ['accent_text' => '#161738'],
                'banners' => ['overlay_color' => '#121438', 'overlay_opacity' => 78],
                'pattern' => ['type' => 'islamic_star', 'color' => '#D9B35F', 'opacity' => 20, 'size' => 56],
                'loading' => ['style' => 'crescent', 'message_ar' => 'رمضان كريم', 'message_en' => 'Ramadan Kareem', 'background' => '#161738', 'accent' => '#D9B35F'],
            ]],
            'eid_fitr' => ['name_ar' => 'عيد الفطر المبارك', 'name_en' => 'Eid al-Fitr', 'patch' => [
                'colors' => ['primary' => '#0F5C45', 'accent' => '#E0B84C', 'background' => '#F7F5EC', 'surface' => '#FFFFFF', 'text' => '#10261F', 'link' => '#0F5C45'],
                'buttons' => ['accent_text' => '#10261F'],
                'banners' => ['overlay_color' => '#07271D', 'overlay_opacity' => 74],
                'pattern' => ['type' => 'arabesque', 'color' => '#E0B84C', 'opacity' => 22, 'size' => 52],
                'loading' => ['style' => 'pulse', 'message_ar' => 'عيدكم مبارك', 'message_en' => 'Eid Mubarak', 'background' => '#0B3A2C', 'accent' => '#E0B84C'],
            ]],
            'eid_adha' => ['name_ar' => 'عيد الأضحى المبارك', 'name_en' => 'Eid al-Adha', 'patch' => [
                'colors' => ['primary' => '#0B5D5B', 'accent' => '#E8C170', 'background' => '#F6F4EE', 'surface' => '#FFFFFF', 'text' => '#0D2A2A', 'link' => '#0B5D5B'],
                'buttons' => ['accent_text' => '#0D2A2A'],
                'banners' => ['overlay_color' => '#062F2E', 'overlay_opacity' => 74],
                'pattern' => ['type' => 'islamic_star', 'color' => '#E8C170', 'opacity' => 18, 'size' => 48],
                'loading' => ['style' => 'pulse', 'message_ar' => 'عيد أضحى مبارك', 'message_en' => 'Eid al-Adha Mubarak', 'background' => '#083E3C', 'accent' => '#E8C170'],
            ]],
            'national_day' => ['name_ar' => 'اليوم الوطني لدولة قطر', 'name_en' => 'Qatar National Day', 'patch' => [
                'colors' => ['primary' => '#7A0F30', 'accent' => '#E7D7A1', 'background' => '#FBF7F4', 'surface' => '#FFFFFF', 'text' => '#2A0A14', 'link' => '#7A0F30'],
                'buttons' => ['accent_text' => '#2A0A14'],
                'banners' => ['overlay_color' => '#3A0918', 'overlay_opacity' => 76],
                'pattern' => ['type' => 'serrated', 'color' => '#E7D7A1', 'opacity' => 26, 'size' => 44],
                'loading' => ['style' => 'emblem', 'message_ar' => 'كل عام وقطر بخير — اليوم الوطني', 'message_en' => 'Happy Qatar National Day', 'background' => '#5A0B23', 'accent' => '#E7D7A1'],
            ]],
            'teachers_day' => ['name_ar' => 'يوم المعلم', 'name_en' => "Teachers' Day", 'patch' => [
                'colors' => ['primary' => '#0E4B6B', 'accent' => '#F2B84B', 'background' => '#F4F8FA', 'surface' => '#FFFFFF', 'text' => '#0A2230', 'link' => '#0E4B6B'],
                'buttons' => ['accent_text' => '#0A2230'],
                'banners' => ['overlay_color' => '#07293A', 'overlay_opacity' => 72],
                'pattern' => ['type' => 'dots', 'color' => '#F2B84B', 'opacity' => 18, 'size' => 34],
                'loading' => ['style' => 'dots', 'message_ar' => 'شكرًا لمن يصنع الأجيال — يوم المعلم', 'message_en' => "Thank you to those who shape generations — Teachers' Day", 'background' => '#0A3B55', 'accent' => '#F2B84B'],
            ]],
            'sports_day' => ['name_ar' => 'اليوم الرياضي للدولة', 'name_en' => 'National Sports Day', 'patch' => [
                'colors' => ['primary' => '#1B3A5C', 'accent' => '#F28C28', 'background' => '#F7F8FA', 'surface' => '#FFFFFF', 'text' => '#101D2E', 'link' => '#1B3A5C'],
                'buttons' => ['accent_text' => '#101D2E'],
                'banners' => ['overlay_color' => '#0F2236', 'overlay_opacity' => 70],
                'pattern' => ['type' => 'diagonal', 'color' => '#F28C28', 'opacity' => 16, 'size' => 30],
                'loading' => ['style' => 'bar', 'message_ar' => 'اليوم الرياضي للدولة — تحرّك!', 'message_en' => 'National Sports Day — get moving!', 'background' => '#142C45', 'accent' => '#F28C28'],
            ]],
            'graduation' => ['name_ar' => 'موسم التخرج', 'name_en' => 'Graduation season', 'patch' => [
                'colors' => ['primary' => '#0B1F3A', 'accent' => '#C8A24A', 'background' => '#F8F6F1', 'surface' => '#FFFFFF', 'text' => '#0B1620', 'link' => '#0B1F3A'],
                'buttons' => ['accent_text' => '#0B1620'],
                'banners' => ['overlay_color' => '#07142A', 'overlay_opacity' => 74],
                'pattern' => ['type' => 'grid', 'color' => '#C8A24A', 'opacity' => 14, 'size' => 40],
                'loading' => ['style' => 'emblem', 'message_ar' => 'مبارك التخرج', 'message_en' => 'Congratulations, graduates', 'background' => '#0B1F3A', 'accent' => '#C8A24A'],
            ]],
        ];
    }

    /** The standard occasions with their periods for a year. @return list<array<string, mixed>> */
    public static function standard(int $year): array
    {
        $hijri = self::HIJRI[$year] ?? self::HIJRI[2027];
        $secondTuesdayFeb = Carbon::create($year, 2, 1)->nthOfMonth(2, Carbon::TUESDAY);
        $periods = [
            'ramadan' => ['starts_on' => "{$year}-{$hijri['ramadan'][0]}", 'ends_on' => "{$year}-{$hijri['ramadan'][1]}", 'recurring' => false],
            'eid_fitr' => ['starts_on' => "{$year}-{$hijri['eid_fitr'][0]}", 'ends_on' => "{$year}-{$hijri['eid_fitr'][1]}", 'recurring' => false],
            'eid_adha' => ['starts_on' => "{$year}-{$hijri['eid_adha'][0]}", 'ends_on' => "{$year}-{$hijri['eid_adha'][1]}", 'recurring' => false],
            'national_day' => ['starts_on' => "{$year}-12-14", 'ends_on' => "{$year}-12-20", 'recurring' => true],
            'teachers_day' => ['starts_on' => "{$year}-10-03", 'ends_on' => "{$year}-10-09", 'recurring' => true],
            'sports_day' => ['starts_on' => $secondTuesdayFeb->copy()->subDays(2)->toDateString(), 'ends_on' => $secondTuesdayFeb->copy()->addDay()->toDateString(), 'recurring' => false],
            'graduation' => ['starts_on' => "{$year}-06-01", 'ends_on' => "{$year}-06-30", 'recurring' => true],
        ];
        $out = [];
        foreach (self::catalog() as $id => $o) {
            $id_year = in_array($id, ['national_day', 'teachers_day', 'graduation'], true) ? $id : "{$id}_{$year}";
            $out[] = ['id' => $id_year, 'occasion' => $id, 'name_ar' => $o['name_ar'], 'name_en' => $o['name_en'], 'enabled' => true, 'patch' => $o['patch']] + $periods[$id];
        }

        return $out;
    }

    /** Whether a date falls inside an occasion: an exact period, or the same days every year (wrapping over New Year when needed). */
    public static function covers(array $o, Carbon $day): bool
    {
        if (empty($o['enabled']) || empty($o['starts_on']) || empty($o['ends_on'])) {
            return false;
        }
        if (empty($o['recurring'])) {
            return $day->toDateString() >= $o['starts_on'] && $day->toDateString() <= $o['ends_on'];
        }
        $md = $day->format('m-d');
        $from = substr((string) $o['starts_on'], 5);
        $to = substr((string) $o['ends_on'], 5);

        return $from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to);
    }
}
