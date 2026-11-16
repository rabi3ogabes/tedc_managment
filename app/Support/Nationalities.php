<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/** Nationality names (as stored on employees) with their country code, Arabic / English name and flag emoji. */
class Nationalities
{
    /** code => [english, arabic, other spellings] */
    private const LIST = [
        'QA' => ['Qatar', 'قطر', ['Qatari', 'قطري', 'قطرية']], 'SA' => ['Saudi Arabia', 'السعودية', ['Saudi', 'سعودي']], 'AE' => ['United Arab Emirates', 'الإمارات', ['UAE', 'Emirati', 'إماراتي']],
        'KW' => ['Kuwait', 'الكويت', ['Kuwaiti', 'كويتي']], 'BH' => ['Bahrain', 'البحرين', ['Bahraini', 'بحريني']], 'OM' => ['Oman', 'عُمان', ['Omani', 'عماني', 'عمان']],
        'JO' => ['Jordan', 'الأردن', ['Jordanian', 'أردني']], 'EG' => ['Egypt', 'مصر', ['Egyptian', 'مصري']], 'SD' => ['Sudan', 'السودان', ['Sudanese', 'سوداني']],
        'SY' => ['Syria', 'سوريا', ['Syrian', 'سوري', 'سورية']], 'LB' => ['Lebanon', 'لبنان', ['Lebanese', 'لبناني']], 'PS' => ['Palestine', 'فلسطين', ['Palestinian', 'فلسطيني']],
        'IQ' => ['Iraq', 'العراق', ['Iraqi', 'عراقي']], 'YE' => ['Yemen', 'اليمن', ['Yemeni', 'يمني']], 'TN' => ['Tunisia', 'تونس', ['Tunisian', 'تونسي']],
        'MA' => ['Morocco', 'المغرب', ['Moroccan', 'مغربي']], 'DZ' => ['Algeria', 'الجزائر', ['Algerian', 'جزائري']], 'LY' => ['Libya', 'ليبيا', ['Libyan', 'ليبي']],
        'MR' => ['Mauritania', 'موريتانيا', ['Mauritanian']], 'SO' => ['Somalia', 'الصومال', ['Somali']], 'IN' => ['India', 'الهند', ['Indian', 'هندي']],
        'PK' => ['Pakistan', 'باكستان', ['Pakistani', 'باكستاني']], 'PH' => ['Philippines', 'الفلبين', ['Filipino', 'فلبيني']], 'GB' => ['United Kingdom', 'المملكة المتحدة', ['UK', 'British']],
        'US' => ['United States', 'الولايات المتحدة', ['USA', 'American']], 'CA' => ['Canada', 'كندا', ['Canadian']], 'TR' => ['Turkey', 'تركيا', ['Turkish']],
    ];

    /** @return array{code: string, name: string, flag: string}|null */
    public static function resolve(?string $value, string $locale = 'ar'): ?array
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        foreach (self::LIST as $code => [$en, $ar, $alt]) {
            if (strcasecmp($v, $code) === 0 || strcasecmp($v, $en) === 0 || $v === $ar || in_array(mb_strtolower($v), array_map('mb_strtolower', $alt), true)) {
                return ['code' => $code, 'name' => $locale === 'en' ? $en : $ar, 'flag' => self::flag($code)];
            }
        }

        return null;
    }

    /** Regional-indicator pair for a two-letter country code (renders as a flag on phones and browsers). */
    public static function flag(string $code): string
    {
        return implode('', array_map(fn ($c) => mb_chr(0x1F1E6 + ord($c) - 65), str_split(strtoupper($code))));
    }

    /** What the home / account headers show about a person. */
    public static function identity(User $user): array
    {
        $user->loadMissing(['employee.jobTitle', 'employee.school', 'trainer', 'roles']);
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $e = $user->employee;
        $nationality = self::resolve($e?->nationality, $locale);

        return [
            'name' => $user->displayName(),
            'position' => $e?->jobTitle?->translate('name'),
            'school' => $e?->school?->translate('name'),
            'nationality' => $e?->nationality ? ['name' => $nationality['name'] ?? $e->nationality, 'code' => $nationality['code'] ?? null, 'flag' => $nationality['flag'] ?? null] : null,
            // A person can be both: a trainee (has an employee profile and attends programs) and a trainer.
            'is_trainee' => $e !== null,
            'is_trainer' => $user->trainer !== null || $user->roles->contains('slug', Role::TRAINER),
        ];
    }
}
