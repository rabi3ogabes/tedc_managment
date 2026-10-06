<?php

namespace App\Services\Cms;

use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\Program;
use App\Models\PublicStat;
use App\Models\Registration;
use App\Models\School;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** The numbers on the public homepage: built-in sources computed from the data, plus values or named queries the centre defines. Cached for ten minutes. */
class PublicStatsService
{
    private const CACHE = 'public.stats.defined';

    /** Sources an administrator can choose, with the label shown in the editor. */
    public const SOURCES = [
        'users_total' => ['ar' => 'المستخدمون النشطون', 'en' => 'Active users'],
        'programs_total' => ['ar' => 'البرامج المنشورة', 'en' => 'Published programs'],
        'groups_held' => ['ar' => 'المجموعات المنفذة', 'en' => 'Groups held'],
        'certificates_issued' => ['ar' => 'الشهادات الصادرة', 'en' => 'Certificates issued'],
        'hours_delivered' => ['ar' => 'ساعات التدريب المقدمة', 'en' => 'Training hours delivered'],
        'schools_covered' => ['ar' => 'المدارس المشمولة', 'en' => 'Schools covered'],
        'custom_value' => ['ar' => 'قيمة يحددها المركز', 'en' => 'A value the centre sets'],
        'custom_query_key' => ['ar' => 'مؤشر مسمّى', 'en' => 'Named indicator'],
    ];

    /** Named indicators for `custom_query_key` (the value column holds the key). */
    public const QUERY_KEYS = ['trainers_total', 'employees_total', 'participants_total', 'satisfaction_avg'];

    /** Gives a new installation the usual set so the homepage is never empty. */
    public function ensure(): void
    {
        if (PublicStat::query()->exists()) {
            return;
        }
        $defaults = [
            ['participants_total', 'متدرب', 'Trainees', 'custom_query_key', 'participants_total', 'users'],
            ['programs_total', 'برنامج تدريبي', 'Programs', 'programs_total', null, 'book'],
            ['certificates_issued', 'شهادة صادرة', 'Certificates', 'certificates_issued', null, 'award'],
            ['schools_covered', 'مدرسة', 'Schools', 'schools_covered', null, 'school'],
            ['hours_delivered', 'ساعة تدريب', 'Training hours', 'hours_delivered', null, 'clock'],
        ];
        foreach ($defaults as $i => [$key, $ar, $en, $source, $value, $icon]) {
            PublicStat::create(['key' => $key, 'label_ar' => $ar, 'label_en' => $en, 'source' => $source, 'value' => $value, 'icon' => $icon, 'sort_order' => $i, 'is_visible' => true]);
        }
    }

    /** @return list<array{key: string, label: array{ar: string, en: string}, value: int|float|string, icon: ?string}> */
    public function visible(): array
    {
        return Cache::remember(self::CACHE, 600, function () {
            $this->ensure();

            return PublicStat::where('is_visible', true)->orderBy('sort_order')->get()->map(fn (PublicStat $s) => [
                'key' => $s->key, 'label' => ['ar' => $s->label_ar, 'en' => $s->label_en], 'value' => $this->compute($s), 'icon' => $s->icon,
            ])->all();
        });
    }

    public function refresh(): void
    {
        Cache::forget(self::CACHE);
        $this->visible();
    }

    public function compute(PublicStat $s): int|float|string
    {
        return match ($s->source) {
            'users_total' => User::where('status', 'active')->count(),
            'programs_total' => Program::visible()->count(),
            'groups_held' => TrainingGroup::where('status', TrainingGroup::COMPLETED)->count(),
            'certificates_issued' => Certificate::where('status', 'valid')->count(),
            'hours_delivered' => (int) round(Certificate::where('status', 'valid')->sum('hours')),
            'schools_covered' => School::where('status', 'active')->count(),
            'custom_query_key' => match ($s->value) {
                'trainers_total' => Trainer::where('status', 'active')->count(),
                'employees_total' => Employee::count(),
                'participants_total' => Registration::whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->distinct()->count('employee_id'),
                'satisfaction_avg' => round((float) Evaluation::avg('satisfaction_score'), 1),
                default => 0,
            },
            default => (string) ($s->value ?? ''),
        };
    }
}
