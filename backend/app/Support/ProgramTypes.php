<?php

namespace App\Support;

/**
 * The five programme types of the RFP's annual plan. `apprenticeship` was the first English slug for تمهين; plans
 * saved with it read it as `professionalisation`.
 */
final class ProgramTypes
{
    public const ALL = ['licensing', 'empowerment', 'professionalisation', 'specialisation', 'electives'];

    public const LEGACY = ['apprenticeship' => 'professionalisation'];

    /** @return list<array{id: string, name_ar: string, name_en: string, hint_ar: string, hint_en: string}> */
    public static function catalogue(): array
    {
        return [
            ['id' => 'licensing', 'name_ar' => 'ترخيص', 'name_en' => 'Licensing', 'hint_ar' => 'برامج تؤهل للرخصة المهنية أو تجديدها.', 'hint_en' => 'Prepares for the professional licence or its renewal.'],
            ['id' => 'empowerment', 'name_ar' => 'تمكين', 'name_en' => 'Empowerment', 'hint_ar' => 'برامج تمكّن من أداء المهام الأساسية للوظيفة.', 'hint_en' => 'Enables the core duties of the job.'],
            ['id' => 'professionalisation', 'name_ar' => 'تمهين', 'name_en' => 'Professionalisation', 'hint_ar' => 'برامج ترسّخ الممارسة المهنية وتطوّرها.', 'hint_en' => 'Deepens and develops professional practice.'],
            ['id' => 'specialisation', 'name_ar' => 'تخصيص', 'name_en' => 'Specialisation', 'hint_ar' => 'برامج في مادة التخصص أو دور محدد.', 'hint_en' => 'Focused on a subject or a specific role.'],
            ['id' => 'electives', 'name_ar' => 'تخيير', 'name_en' => 'Electives', 'hint_ar' => 'برامج اختيارية يختارها الموظف.', 'hint_en' => 'Optional programmes the employee chooses.'],
        ];
    }

    /** @return array<string, array{ar: string, en: string}> code => names, for reports */
    public static function labels(): array
    {
        return collect(self::catalogue())->mapWithKeys(fn ($t) => [$t['id'] => ['ar' => $t['name_ar'], 'en' => $t['name_en']]])->all();
    }

    public static function normalize(?string $type): ?string
    {
        return $type === null ? null : (self::LEGACY[$type] ?? $type);
    }

    /** @param  list<string>  $types @return list<string> */
    public static function normalizeList(array $types): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($t) => self::normalize((string) $t), $types), fn ($t) => in_array($t, self::ALL, true))));
    }

    public static function name(?string $type, string $lang = 'ar'): string
    {
        foreach (self::catalogue() as $t) {
            if ($t['id'] === $type) {
                return $lang === 'en' ? $t['name_en'] : $t['name_ar'];
            }
        }

        return $lang === 'en' ? 'Type missing' : 'النوع غير محدد';
    }
}
