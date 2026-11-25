<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\PlcWorkshop;
use App\Models\PlcWorkshopSchool;
use App\Models\Registration;
use App\Models\School;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Host and participating schools of a workshop: manual search with filters, the compatibility policy (gender from the
 * official school record, never the name), proximity from official coordinates with an honest fallback to district
 * and region, eligible staff and clashes, seats per school, and the excluded list with reasons. Suggestions are
 * advisory: nothing is selected or invited until the officer confirms.
 */
class SchoolSelector
{
    public const REASONS = ['gender_match', 'gender_unknown', 'distance_km', 'same_district', 'same_region', 'other_region', 'staff', 'clashes', 'community_member'];

    public const EXCLUSIONS = ['gender_mismatch', 'beyond_max_distance', 'outside_region', 'no_staff', 'manual'];

    public function __construct(private readonly PlcSettings $settings, private readonly WorkshopWorkflow $workflow) {}

    /** Whether two gender categories may share a workshop under the policy. null = unknown. */
    public static function genderCompatible(?string $host, ?string $candidate): ?bool
    {
        $norm = fn (?string $g) => match ($g) {
            'boys', 'male' => 'boys', 'girls', 'female' => 'girls', 'mixed' => 'mixed', default => null
        };
        $h = $norm($host);
        $c = $norm($candidate);
        if ($h === null || $c === null) {
            return null;
        }

        return $h === 'mixed' || $c === 'mixed' || $h === $c;
    }

    /** Great-circle distance in kilometres, or null when either school has no official position. */
    public static function distanceKm(School $a, School $b): ?float
    {
        if ($a->latitude === null || $a->longitude === null || $b->latitude === null || $b->longitude === null) {
            return null;
        }
        $r = 6371.0;
        $dLat = deg2rad($b->latitude - $a->latitude);
        $dLng = deg2rad($b->longitude - $a->longitude);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($a->latitude)) * cos(deg2rad($b->latitude)) * sin($dLng / 2) ** 2;

        return round(2 * $r * asin(min(1, sqrt($x))), 2);
    }

    /** The host and participating schools as stored, with the school facts. */
    public function current(PlcWorkshop $w): Collection
    {
        return $w->schools()->with('school:id,code,name_ar,name_en,type,gender,stage,region,district,latitude,longitude')->get()
            ->sortBy(fn ($r) => $r->role === 'host' ? 0 : ($r->status === 'excluded' ? 2 : 1))->values()
            ->map(fn (PlcWorkshopSchool $r) => $this->row($r->school, ['role' => $r->role, 'status' => $r->status, 'seats' => $r->seats, 'reasons' => $r->suggestion_reasons, 'distance_km' => $r->distance_km, 'excluded_reason' => $r->excluded_reason]));
    }

    /**
     * Manual search, inside the person's scope, every row annotated with its compatibility with the host.
     *
     * @param  array{q?: string, region?: string, type?: string, gender?: string, stage?: string}  $filters
     */
    public function search(PlcWorkshop $w, User $user, array $filters, int $limit = 40): array
    {
        $host = $w->hostSchool;
        $scope = AccessScope::current($user);
        $selected = $w->schools()->pluck('status', 'school_id');
        $rows = School::query()->where('status', 'active')
            ->when(! $scope->isMinistryWide(), fn ($b) => $b->whereIn('id', $scope->schoolIds() ?? []))
            ->when($filters['q'] ?? null, fn ($b, $q) => $b->where(fn ($x) => $x->where('name_ar', 'like', "%$q%")->orWhere('name_en', 'like', "%$q%")->orWhere('code', 'like', "%$q%")->orWhere('moe_no', 'like', "%$q%")))
            ->when($filters['region'] ?? null, fn ($b, $v) => $b->where('region', $v))
            ->when($filters['type'] ?? null, fn ($b, $v) => $b->where('type', $v))
            ->when($filters['gender'] ?? null, fn ($b, $v) => $b->where('gender', $v))
            ->when($filters['stage'] ?? null, fn ($b, $v) => $b->where('stage', $v))
            ->orderBy('name_ar')->limit($limit)->get();
        $staff = $this->staffCounts($rows->pluck('id')->all());

        return $rows->map(fn (School $s) => $this->row($s, [
            'status' => $selected[$s->id] ?? null, 'role' => $s->id === $w->host_school_id ? 'host' : 'participant',
            'gender_ok' => $host ? self::genderCompatible($host->gender, $s->gender) : null,
            'distance_km' => $host ? self::distanceKm($host, $s) : null, 'staff' => $staff[$s->id] ?? 0,
        ]))->values()->all();
    }

    /**
     * Ranked suggestions for the participating schools, each with its reasons, and the excluded ones with why.
     *
     * @return array{suggested: list<array>, excluded: list<array>, facts: array}
     */
    public function suggest(PlcWorkshop $w, User $user, ?int $maxKm = null, int $limit = 20): array
    {
        $host = $w->hostSchool;
        if (! $host) {
            throw new BusinessRuleException(__('messages.plc.host_required'), 'plc_host_required');
        }
        $policy = $this->settings->get('gender_policy');
        $maxKm ??= (int) $this->settings->get('max_distance_km');
        $sameRegionOnly = (bool) $this->settings->get('require_same_region');
        $scope = AccessScope::current($user);
        $already = $w->schools()->pluck('status', 'school_id');
        $community = $w->community_id ? DB::table('plc_community_schools')->where('community_id', $w->community_id)->pluck('school_id')->flip() : collect();

        $candidates = School::query()->where('status', 'active')->where('id', '!=', $host->id)
            ->when(! $scope->isMinistryWide(), fn ($b) => $b->whereIn('id', $scope->schoolIds() ?? []))
            ->when($sameRegionOnly && $host->region, fn ($b) => $b->where('region', $host->region))
            ->get();
        $staff = $this->staffCounts($candidates->pluck('id')->all());
        $clashes = $this->clashCounts($w, $candidates->pluck('id')->all());

        $suggested = [];
        $excluded = [];
        foreach ($candidates as $s) {
            $reasons = [];
            $genderOk = self::genderCompatible($host->gender, $s->gender);
            $distance = self::distanceKm($host, $s);
            if ($genderOk === false && $policy === 'strict') {
                $excluded[] = $this->row($s, ['excluded_reason' => 'gender_mismatch', 'distance_km' => $distance]);

                continue;
            }
            if ($distance !== null && $distance > $maxKm) {
                $excluded[] = $this->row($s, ['excluded_reason' => 'beyond_max_distance', 'distance_km' => $distance]);

                continue;
            }
            if ($sameRegionOnly && $host->region && $s->region !== $host->region) {
                $excluded[] = $this->row($s, ['excluded_reason' => 'outside_region']);

                continue;
            }
            $n = $staff[$s->id] ?? 0;
            if ($n === 0) {
                $excluded[] = $this->row($s, ['excluded_reason' => 'no_staff', 'distance_km' => $distance]);

                continue;
            }
            $reasons[] = $genderOk === null ? ['key' => 'gender_unknown'] : ['key' => 'gender_match'];
            if ($genderOk === false) {
                $reasons[] = ['key' => 'gender_exception_needed'];
            }
            if ($distance !== null) {
                $reasons[] = ['key' => 'distance_km', 'value' => $distance];
            } elseif ($host->district && $s->district === $host->district) {
                $reasons[] = ['key' => 'same_district'];
            } elseif ($host->region && $s->region === $host->region) {
                $reasons[] = ['key' => 'same_region'];
            } else {
                $reasons[] = ['key' => 'other_region'];
            }
            $reasons[] = ['key' => 'staff', 'value' => $n];
            if ($clashes[$s->id] ?? 0) {
                $reasons[] = ['key' => 'clashes', 'value' => $clashes[$s->id]];
            }
            if ($community->has($s->id)) {
                $reasons[] = ['key' => 'community_member'];
            }
            // Rank: proven distance first, then district, region, elsewhere; more eligible staff first; clashes last.
            $tier = $distance !== null ? 0 : (($host->district && $s->district === $host->district) ? 1 : (($host->region && $s->region === $host->region) ? 2 : 3));
            $suggested[] = $this->row($s, ['reasons' => $reasons, 'distance_km' => $distance, 'staff' => $n, 'clashes' => $clashes[$s->id] ?? 0, 'status' => $already[$s->id] ?? null,
                'gender_ok' => $genderOk, 'score' => [$tier, $distance ?? 0, -$n, $clashes[$s->id] ?? 0, $community->has($s->id) ? 0 : 1]]);
        }
        usort($suggested, fn ($a, $b) => [$a['score'][0], $a['score'][4], $a['score'][1], $a['score'][2], $a['score'][3]] <=> [$b['score'][0], $b['score'][4], $b['score'][1], $b['score'][2], $b['score'][3]]);
        $suggested = array_map(function ($r) {
            unset($r['score']);

            return $r;
        }, array_slice($suggested, 0, $limit));

        return [
            'suggested' => $suggested,
            'excluded' => $excluded,
            'facts' => [
                'host' => $this->row($host, ['role' => 'host']),
                'host_has_position' => $host->latitude !== null && $host->longitude !== null,
                'gender_policy' => $policy, 'max_distance_km' => $maxKm, 'require_same_region' => $sameRegionOnly,
                'candidates' => $candidates->count(),
            ],
        ];
    }

    /** Sets or changes the host school of a shared workshop; the internal scenario's host never changes here. */
    public function setHost(PlcWorkshop $w, User $user, string $schoolId): PlcWorkshop
    {
        $this->assertEditable($w, $user);
        if ($w->isInternal()) {
            throw new BusinessRuleException(__('messages.plc.internal_host_fixed'), 'plc_internal_host_fixed');
        }
        $school = School::where('status', 'active')->findOrFail($schoolId);
        $this->assertInScope($user, $school);

        return DB::transaction(function () use ($w, $user, $school) {
            PlcWorkshopSchool::where('program_id', $w->program_id)->where('role', 'host')->delete();
            PlcWorkshopSchool::updateOrCreate(['program_id' => $w->program_id, 'school_id' => $school->id], ['role' => 'host', 'status' => 'selected', 'excluded_reason' => null, 'decided_by' => $user->id]);
            $w->update(['host_school_id' => $school->id]);
            $this->workflow->decision($w, 'school_choice', 'host_set', null, ['school_id' => $school->id, 'school' => $school->name_ar], $user);

            return $w->refresh();
        });
    }

    /**
     * Adds participating schools (with optional seats). Compatibility follows the policy: strict refuses, allow_with_exception
     * needs `plc.exceptions` and a reason (recorded), open accepts. Widening an internal workshop needs `plc.manage`.
     *
     * @param  list<array{id: string, seats?: int|null, from_suggestion?: bool}>  $schools
     */
    public function add(PlcWorkshop $w, User $user, array $schools, ?string $exceptionReason = null): Collection
    {
        $this->assertEditable($w, $user);
        $host = $w->hostSchool ?: throw new BusinessRuleException(__('messages.plc.host_required'), 'plc_host_required');
        $policy = $this->settings->get('gender_policy');
        $ids = array_values(array_unique(array_filter(array_map(fn ($s) => (string) ($s['id'] ?? ''), $schools))));
        $rows = School::where('status', 'active')->whereIn('id', $ids)->get()->keyBy('id');
        $seatsBy = collect($schools)->keyBy('id');

        if ($w->isInternal() && array_diff($ids, [$host->id]) && ! $user->hasPermission('plc.manage')) {
            throw new BusinessRuleException(__('messages.plc.internal_scope'), 'plc_internal_scope');
        }
        $incompatible = [];
        foreach ($ids as $id) {
            $s = $rows->get($id) ?: throw new BusinessRuleException(__('messages.plc.school_unknown'), 'plc_school_unknown');
            $this->assertInScope($user, $s);
            if ($s->id === $host->id) {
                continue;
            }
            if (self::genderCompatible($host->gender, $s->gender) === false) {
                $incompatible[] = $s;
            }
        }
        if ($incompatible) {
            if ($policy === 'strict') {
                throw new BusinessRuleException(__('messages.plc.gender_mismatch'), 'plc_gender_mismatch', ['schools' => array_map(fn ($s) => $s->name_ar, $incompatible)]);
            }
            if ($policy === 'allow_with_exception' && (! $user->hasPermission('plc.exceptions') || ! filled($exceptionReason))) {
                throw new BusinessRuleException(__('messages.plc.gender_exception'), 'plc_gender_exception', ['schools' => array_map(fn ($s) => $s->name_ar, $incompatible)]);
            }
        }

        return DB::transaction(function () use ($w, $user, $ids, $rows, $seatsBy, $host, $incompatible, $exceptionReason) {
            $widened = false;
            foreach ($ids as $id) {
                if ($id === $host->id) {
                    continue;
                }
                $s = $rows->get($id);
                $seats = isset($seatsBy[$id]['seats']) && $seatsBy[$id]['seats'] !== null ? max(1, min(5000, (int) $seatsBy[$id]['seats'])) : null;
                PlcWorkshopSchool::updateOrCreate(['program_id' => $w->program_id, 'school_id' => $id], [
                    'role' => 'participant', 'status' => 'selected', 'seats' => $seats, 'excluded_reason' => null,
                    'distance_km' => self::distanceKm($host, $s), 'decided_by' => $user->id,
                    'suggestion_reasons' => ! empty($seatsBy[$id]['from_suggestion']) ? ['from_suggestion' => true] : null,
                ]);
                $widened = $widened || $w->isInternal();
            }
            foreach ($incompatible as $s) {
                $this->workflow->decision($w, 'exception', 'gender_policy', $exceptionReason, ['school_id' => $s->id, 'school' => $s->name_ar, 'host_gender' => $host->gender, 'school_gender' => $s->gender], $user);
            }
            if ($widened) {
                $this->workflow->decision($w, 'exception', 'scope_widened', $exceptionReason, ['schools' => array_values(array_diff($ids, [$host->id]))], $user);
            }
            $this->workflow->decision($w, 'school_choice', 'participants_added', null, ['count' => count(array_diff($ids, [$host->id]))], $user);

            return $this->current($w);
        });
    }

    public function seats(PlcWorkshop $w, User $user, string $schoolId, ?int $seats): Collection
    {
        $this->assertEditable($w, $user);
        $row = PlcWorkshopSchool::where('program_id', $w->program_id)->where('school_id', $schoolId)->firstOrFail();
        $row->update(['seats' => $seats !== null ? max(1, min(5000, $seats)) : null]);

        return $this->current($w);
    }

    public function remove(PlcWorkshop $w, User $user, string $schoolId): Collection
    {
        $this->assertEditable($w, $user);
        $row = PlcWorkshopSchool::where('program_id', $w->program_id)->where('school_id', $schoolId)->firstOrFail();
        if ($row->role === 'host') {
            throw new BusinessRuleException(__('messages.plc.host_remove'), 'plc_host_remove');
        }
        $row->delete();
        $this->workflow->decision($w, 'school_choice', 'participant_removed', null, ['school_id' => $schoolId], $user);

        return $this->current($w);
    }

    /** Keeps the school on the record as excluded, with why (shown to the officer, never silently dropped). */
    public function exclude(PlcWorkshop $w, User $user, string $schoolId, string $reason, ?string $note = null): Collection
    {
        $this->assertEditable($w, $user);
        $reason = in_array($reason, self::EXCLUSIONS, true) ? $reason : 'manual';
        $school = School::findOrFail($schoolId);
        PlcWorkshopSchool::updateOrCreate(['program_id' => $w->program_id, 'school_id' => $schoolId], ['role' => 'participant', 'status' => 'excluded', 'excluded_reason' => $reason, 'seats' => null, 'decided_by' => $user->id]);
        $this->workflow->decision($w, 'school_choice', 'excluded', $note ?: $reason, ['school_id' => $schoolId, 'school' => $school->name_ar, 'reason' => $reason], $user);

        return $this->current($w);
    }

    private function row(School $s, array $extra = []): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name_ar, 'name_en' => $s->name_en, 'type' => $s->type, 'gender' => $s->gender, 'stage' => $s->stage, 'region' => $s->region, 'district' => $s->district,
            'has_position' => $s->latitude !== null && $s->longitude !== null] + $extra;
    }

    /** Active staff with an account per school. @return array<string,int> */
    private function staffCounts(array $schoolIds): array
    {
        if (! $schoolIds) {
            return [];
        }

        return Employee::query()->whereIn('school_id', $schoolIds)->where('status', 'active')->whereNotNull('user_id')
            ->groupBy('school_id')->selectRaw('school_id, count(*) as c')->pluck('c', 'school_id')->map(fn ($c) => (int) $c)->all();
    }

    /** Staff of each school already registered in another programme whose dates overlap this workshop's. @return array<string,int> */
    private function clashCounts(PlcWorkshop $w, array $schoolIds): array
    {
        $p = $w->program;
        if (! $schoolIds || ! $p?->start_date) {
            return [];
        }
        $end = $p->end_date ?? $p->start_date;

        return Registration::query()->join('employees', 'employees.id', '=', 'registrations.employee_id')->join('programs', 'programs.id', '=', 'registrations.program_id')
            ->whereIn('employees.school_id', $schoolIds)->where('registrations.program_id', '!=', $p->id)
            ->whereIn('registrations.status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_PENDING_MANAGER])
            ->whereNotNull('programs.start_date')->whereDate('programs.start_date', '<=', $end)->whereDate(DB::raw('coalesce(programs.end_date, programs.start_date)'), '>=', $p->start_date)
            ->groupBy('employees.school_id')->selectRaw('employees.school_id, count(distinct registrations.employee_id) as c')->pluck('c', 'employees.school_id')->map(fn ($c) => (int) $c)->all();
    }

    private function assertEditable(PlcWorkshop $w, User $user): void
    {
        $ok = in_array($w->workflow_state, WorkshopWorkflow::EDITABLE, true) || (in_array($w->workflow_state, ['approved', 'scheduled'], true) && $user->hasPermission('plc.manage'));
        if (! $ok) {
            throw new BusinessRuleException(__('messages.plc.locked', ['state' => $w->workflow_state]), 'plc_locked');
        }
        if (! $user->hasPermission('plc.manage') && ! $user->hasPermission('plc.internal.submit')) {
            abort(403);
        }
        if ($w->workflow_state === 'submitted') {
            // Changing the schools of a submitted workshop restarts its review, like any other edit.
            $w->forceFill(['workflow_state' => 'draft', 'approval_path' => null, 'state_changed_at' => now()])->save();
            $this->workflow->decision($w, 'transition', 'draft', __('messages.plc.edited_after_submit'), ['from' => 'submitted'], $user);
        }
    }

    private function assertInScope(User $user, School $school): void
    {
        $scope = AccessScope::current($user);
        if (! $scope->isMinistryWide() && ! $scope->allowsSchool($school->id) && ! $user->hasPermission('plc.manage')) {
            abort(403, __('messages.plc.outside_scope'));
        }
    }
}
