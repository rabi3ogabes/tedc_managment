<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Administrator-managed evaluation rules: when impact forms go out, when low satisfaction raises an alert, and what classifies a program. */
class EvaluationSettings
{
    public const KEY = 'evaluation';

    private const CACHE = 'site.evaluation';

    public static function defaults(): array
    {
        return [
            // Impact forms: days after the trainee completes the program. The trainee form goes out at least one and a half months later.
            'impact' => ['trainee_days' => 45, 'manager_days' => 60, 'optional_days' => null, 'reminder_after_days' => 7, 'expiry_days' => 30],
            // Satisfaction alert (the RFP rule): response rate at least 80 % and average satisfaction below 50 %.
            'alerts' => ['is_active' => true, 'min_response_rate' => 80, 'threshold' => 50, 'recipients' => ['center_leadership', 'program_coordinator']],
            // Program classification thresholds (satisfaction and impact on a 0–100 scale, knowledge gain in %).
            'classification' => ['satisfaction_good' => 80, 'satisfaction_low' => 60, 'gain_good' => 35, 'gain_low' => 20, 'impact_good' => 70, 'impact_low' => 50],
            // Anonymous satisfaction: results are shown only once this many people answered.
            'anonymous_min_responses' => 3,
        ];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace_recursive(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $path, $default);
    }

    public function update(array $input, ?User $by = null): array
    {
        $cur = $this->all();
        $impact = array_replace($cur['impact'], Arr::only($input['impact'] ?? [], array_keys($cur['impact'])));
        foreach (['trainee_days', 'manager_days', 'reminder_after_days', 'expiry_days'] as $k) {
            $impact[$k] = max(1, min(365, (int) $impact[$k]));
        }
        $impact['optional_days'] = ! empty($impact['optional_days']) ? max(1, min(365, (int) $impact['optional_days'])) : null;

        $alerts = array_replace($cur['alerts'], Arr::only($input['alerts'] ?? [], array_keys($cur['alerts'])));
        $alerts['is_active'] = (bool) $alerts['is_active'];
        $alerts['min_response_rate'] = max(1, min(100, (float) $alerts['min_response_rate']));
        $alerts['threshold'] = max(1, min(100, (float) $alerts['threshold']));
        $alerts['recipients'] = array_values(array_intersect((array) $alerts['recipients'], ['center_leadership', 'program_coordinator', 'planning_head', 'center_admin']));

        $class = array_replace($cur['classification'], array_map('floatval', Arr::only($input['classification'] ?? [], array_keys($cur['classification']))));

        $next = ['impact' => $impact, 'alerts' => $alerts, 'classification' => $class, 'anonymous_min_responses' => max(1, min(50, (int) ($input['anonymous_min_responses'] ?? $cur['anonymous_min_responses'])))];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }
}
