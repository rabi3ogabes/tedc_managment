<?php

namespace App\Services\Programs;

use App\Models\JobTitle;

/**
 * Suggested names for a programme's groups, from its target audience: the plural of the job title (feminine when the audience is
 * women only) and the group's number — "معلمون 1"، "منسقات 2"، "قادة 3". With several job titles the groups take them in turn; with one
 * they are numbered after it. A suggestion is only a suggestion: the administrator can rename a group at any time.
 */
class GroupNamer
{
    /** code => [masculine plural ar, feminine plural ar, plural en] */
    private const PLURALS = [
        'TEACHER' => ['معلمون', 'معلمات', 'Teachers'],
        'SENIOR_TEACHER' => ['معلمون أوائل', 'معلمات أوائل', 'Senior teachers'],
        'SUBJECT_COORD' => ['منسقو المواد', 'منسقات المواد', 'Subject coordinators'],
        'VICE_PRINCIPAL' => ['نواب المديرين', 'نائبات المديرات', 'Vice principals'],
        'PRINCIPAL' => ['مديرو المدارس', 'مديرات المدارس', 'Principals'],
        'ACADEMIC_ADVISOR' => ['مرشدون أكاديميون', 'مرشدات أكاديميات', 'Academic advisors'],
        'SOCIAL_SPECIALIST' => ['أخصائيون اجتماعيون', 'أخصائيات اجتماعيات', 'Social specialists'],
        'PSYCH_SPECIALIST' => ['أخصائيون نفسيون', 'أخصائيات نفسيات', 'Psychological specialists'],
        'LEARNING_RESOURCES' => ['أخصائيو مصادر التعلم', 'أخصائيات مصادر التعلم', 'Learning-resources specialists'],
        'IT_SPECIALIST' => ['أخصائيو تقنية المعلومات', 'أخصائيات تقنية المعلومات', 'IT specialists'],
        'ADMIN_OFFICER' => ['موظفون إداريون', 'موظفات إداريات', 'Administrative staff'],
    ];

    /** For job titles that are not in the list: what their category is called. */
    private const CATEGORIES = [
        'teaching' => ['معلمون', 'معلمات', 'Teachers'],
        'leadership' => ['قادة', 'قائدات', 'Leaders'],
        'administrative' => ['إداريون', 'إداريات', 'Administrative staff'],
        'support' => ['فنيون', 'فنيات', 'Support staff'],
    ];

    /**
     * @param  list<string>  $jobTitleIds
     * @param  list<string>  $genders  'male' / 'female'; women only gives the feminine plural
     * @return list<array{sequence: int, title_ar: string, title_en: string}>
     */
    public function suggest(array $jobTitleIds, array $genders, int $from = 1, int $count = 1): array
    {
        $feminine = $genders !== [] && array_diff($genders, ['female']) === [];
        $titles = JobTitle::whereIn('id', $jobTitleIds)->get()->keyBy('id');
        $bases = [];
        foreach ($jobTitleIds as $id) {
            if ($t = $titles->get($id)) {
                $bases[] = $this->plural($t, $feminine);
            }
        }
        $bases = array_values(array_unique($bases, SORT_REGULAR));
        if ($bases === []) {
            $bases = [['ar' => 'المجموعة', 'en' => 'Group']];
        }

        $out = [];
        for ($s = $from; $s < $from + max(1, $count); $s++) {
            $b = $bases[($s - 1) % count($bases)];
            $out[] = ['sequence' => $s, 'title_ar' => "{$b['ar']} {$s}", 'title_en' => "{$b['en']} {$s}"];
        }

        return $out;
    }

    /** @return array{ar: string, en: string} */
    private function plural(JobTitle $t, bool $feminine): array
    {
        $p = self::PLURALS[$t->code] ?? self::CATEGORIES[$t->category] ?? null;
        if ($p) {
            return ['ar' => $feminine ? $p[1] : $p[0], 'en' => $p[2]];
        }

        return ['ar' => $t->name_ar, 'en' => $t->name_en];
    }
}
