<?php

namespace App\Services\Plc;

use App\Models\PlcWorkshop;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * The Ministry's PLC policy (Settings → PLC): school compatibility, proximity, approval paths per scenario,
 * execution and attendance rules, retention. Defaults follow the decisions approved on 2026-10-15.
 */
class PlcSettings
{
    public const KEY = 'plc';

    private const CACHE = 'site.plc';

    public const GENDER_POLICIES = ['strict', 'allow_with_exception', 'open'];

    public static function defaults(): array
    {
        return [
            'gender_policy' => 'strict',
            'max_distance_km' => 25,
            'require_same_region' => false,
            'allow_unapproved_execution' => false,
            'approval_paths' => [
                'internal' => [['key' => 'school', 'permission' => 'plc.approve.school'], ['key' => 'centre', 'permission' => 'plc.approve']],
                'shared' => [['key' => 'centre', 'permission' => 'plc.approve']],
            ],
            'late_minutes' => 15,
            'early_leave_minutes' => 15,
            'geofence_default' => false,
            'attachment_retention_months' => 36,
            'rased_integration' => false, // there is no electronic integration; needs are entered by hand with a reference
            'evaluation_rules' => self::evaluationDefaults(),
        ];
    }

    public const INSTRUMENTS = ['workshop', 'trainer', 'session', 'community'];

    /**
     * The suggestion rules (design §7): which instrument goes out, whether it is mandatory before the workshop
     * closes, and how many days the respondent gets. Impact follow-ups reuse the evaluation settings' 45/90 days
     * for the trainee and 60 days for the manager; `impact_sources` says which workshop sources get them.
     */
    public static function evaluationDefaults(): array
    {
        return [
            'workshop' => ['enabled' => true, 'mandatory' => true, 'due_days' => 7],     // satisfaction, end of the last session, trainees
            'trainer' => ['enabled' => true, 'mandatory' => false, 'due_days' => 7],     // one per trainer, end of the trainer's last session, trainees
            'session' => ['enabled' => false, 'mandatory' => false, 'due_days' => 3],    // multi-session workshops only, end of each session
            'community' => ['enabled' => true, 'mandatory' => false, 'due_days' => 14],  // workshops inside a community, trainees and coordinators
            'impact_sources' => ['rased'],                                                 // +45 (+90 optional) trainee, +60 manager
            'auto_send' => true,                                                           // send by the clock, or only suggest for the coordinator to send
            'close_response_rate' => 60,                                                   // a mandatory instrument counts as complete at this response rate
        ];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** The approval steps a workshop of this scenario must pass. @return list<array{key: string, permission: string}> */
    public function approvalPath(string $scenario): array
    {
        $paths = $this->get('approval_paths');
        $steps = $paths[$scenario] ?? self::defaults()['approval_paths'][$scenario] ?? [];

        return array_values(array_filter(array_map(fn ($s) => isset($s['key'], $s['permission']) ? ['key' => (string) $s['key'], 'permission' => (string) $s['permission']] : null, $steps)));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        $next['gender_policy'] = in_array($next['gender_policy'], self::GENDER_POLICIES, true) ? $next['gender_policy'] : 'strict';
        $next['max_distance_km'] = max(1, min(500, (int) $next['max_distance_km']));
        foreach (['require_same_region', 'allow_unapproved_execution', 'geofence_default'] as $k) {
            $next[$k] = (bool) $next[$k];
        }
        $next['rased_integration'] = false; // only an importer shipped with the code may switch this on
        $next['late_minutes'] = max(0, min(180, (int) $next['late_minutes']));
        $next['early_leave_minutes'] = max(0, min(180, (int) $next['early_leave_minutes']));
        $next['attachment_retention_months'] = max(1, min(240, (int) $next['attachment_retention_months']));
        $next['evaluation_rules'] = self::cleanEvaluationRules((array) ($next['evaluation_rules'] ?? []));
        $paths = [];
        foreach (['internal', 'shared'] as $scenario) {
            $steps = [];
            foreach ((array) ($next['approval_paths'][$scenario] ?? []) as $s) {
                if (! empty($s['key']) && ! empty($s['permission']) && preg_match('/^[a-z_.]{3,64}$/', (string) $s['permission'])) {
                    $steps[] = ['key' => mb_substr((string) $s['key'], 0, 32), 'permission' => (string) $s['permission']];
                }
            }
            $paths[$scenario] = $steps ?: self::defaults()['approval_paths'][$scenario];
        }
        $next['approval_paths'] = $paths;

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }

    /** @return array<string, mixed> */
    public function evaluationRules(): array
    {
        return self::cleanEvaluationRules((array) ($this->get('evaluation_rules') ?? []));
    }

    private static function cleanEvaluationRules(array $input): array
    {
        $d = self::evaluationDefaults();
        $out = [];
        foreach (self::INSTRUMENTS as $key) {
            $rule = array_replace($d[$key], Arr::only((array) ($input[$key] ?? []), ['enabled', 'mandatory', 'due_days']));
            $out[$key] = ['enabled' => (bool) $rule['enabled'], 'mandatory' => (bool) $rule['enabled'] && (bool) $rule['mandatory'], 'due_days' => max(1, min(90, (int) $rule['due_days']))];
        }
        $out['impact_sources'] = array_values(array_intersect(array_map('strval', (array) ($input['impact_sources'] ?? $d['impact_sources'])), PlcWorkshop::SOURCES));
        $out['auto_send'] = (bool) ($input['auto_send'] ?? $d['auto_send']);
        $out['close_response_rate'] = max(0, min(100, (int) ($input['close_response_rate'] ?? $d['close_response_rate'])));

        return $out;
    }
}
