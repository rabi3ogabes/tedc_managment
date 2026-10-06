<?php

namespace App\Integrations\Ministry;

use App\Integrations\EventBus;
use App\Integrations\IntegrationManager;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Security\AuthSessions;
use Illuminate\Support\Facades\DB;

/**
 * Employees from the HR system (the current system or Mawared): a full sync the first time, then only what changed since the last one.
 * HR wins for master data (job title, school, department, manager, hire date…); leavers are deactivated and their training records kept.
 * Each row is applied on its own, so one bad record never stops the rest.
 */
class HrSync
{
    public function __construct(private readonly IntegrationManager $hub, private readonly EventBus $bus) {}

    /** The HR system in use: Mawared when it is on, else the current system. */
    public function active(): ?string
    {
        foreach (['mawared', 'hr'] as $k) {
            if ($this->hub->isReady($k)) {
                return $k;
            }
        }

        return null;
    }

    /** @return array{created: int, updated: int, deactivated: int, skipped: int, errors: int} */
    public function run(string $key = 'hr'): array
    {
        $since = $this->hub->get($key)->last_sync_at?->toIso8601String();
        $rows = $this->hub->call($key, 'fetch_employees', function (array $s, $i) use ($since) {
            if ($i->driver === 'fake') {
                return (array) (is_string($s['fake_employees'] ?? null) ? json_decode($s['fake_employees'], true) : ($s['fake_employees'] ?? []));
            }

            return (array) ((new MinistryHttp($s))->get((string) ($s['employees_path'] ?? '/employees'), array_filter(['since' => $since]))['data'] ?? []);
        }, ['delta' => (bool) $since]);

        $policy = (string) ($this->hub->settings($key)['conflict_policy'] ?? 'hr_wins');
        $out = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            try {
                $out[$this->applyEmployee((array) $row, $policy)]++;
            } catch (\Throwable) {
                $out['errors']++;
            }
        }
        $this->hub->markSynced($key);

        return $out;
    }

    /** @param  array<string, mixed>  $payload @return string processed | ignored */
    public function applyInbound(string $key, array $payload): string
    {
        $row = (array) ($payload['employee'] ?? $payload['data'] ?? []);
        if (! in_array($payload['type'] ?? '', ['employee.updated', 'employee.created', 'employee.left'], true) || empty($row['employee_no'])) {
            return 'ignored';
        }
        if (($payload['type'] ?? '') === 'employee.left') {
            $row['status'] = 'left';
        }
        $this->applyEmployee($row, (string) ($this->hub->settings($key)['conflict_policy'] ?? 'hr_wins'));

        return 'processed';
    }

    /** @param  array<string, mixed>  $r  @return string created | updated | deactivated | skipped */
    public function applyEmployee(array $r, string $policy = 'hr_wins'): string
    {
        $no = trim((string) ($r['employee_no'] ?? ''));
        if ($no === '') {
            return 'skipped';
        }
        $hrWins = $policy !== 'tedc_wins';

        return DB::transaction(function () use ($r, $no, $hrWins) {
            $employee = Employee::where('employee_no', $no)->first();
            $left = in_array(strtolower((string) ($r['status'] ?? 'active')), ['left', 'inactive', 'terminated', 'resigned', 'retired'], true);

            if (! $employee) {
                if ($left || empty($r['email'])) {
                    return 'skipped';   // a leaver we never had; or no way to make an account
                }
                $user = User::whereRaw('lower(email) = ?', [strtolower($r['email'])])->first()
                    ?? User::create(['name' => $r['name'] ?? $r['email'], 'name_ar' => $r['name_ar'] ?? null, 'email' => strtolower($r['email']), 'locale' => 'ar', 'status' => 'active']);
                if ($user->roles()->doesntExist() && ($role = Role::where('slug', Role::EMPLOYEE)->first())) {
                    $user->roles()->attach($role->id);
                }
                $employee = Employee::create(array_merge(['user_id' => $user->id, 'employee_no' => $no], $this->master($r, true)));
                $this->bus->emit('employee.synced', ['employee_id' => $employee->id, 'employee_no' => $no, 'change' => 'created']);

                return 'created';
            }

            $user = $employee->user;
            if ($left) {
                // Leavers cannot sign in any more; everything they trained on stays.
                $employee->forceFill(['status' => 'left'])->save();
                $user?->forceFill(['status' => 'inactive'])->save();
                if ($user) {
                    app(AuthSessions::class)->revokeAll($user, 'admin');
                }
                $this->bus->emit('employee.synced', ['employee_id' => $employee->id, 'employee_no' => $no, 'change' => 'left']);

                return 'deactivated';
            }

            $changes = $this->master($r, $hrWins, $employee);
            if ($employee->status === 'left') {
                $changes['status'] = 'active';
                $user?->forceFill(['status' => 'active'])->save();
            }
            $employee->fill($changes);
            $dirty = $employee->isDirty();
            $employee->save();
            if ($user) {
                foreach (['name' => $r['name'] ?? null, 'name_ar' => $r['name_ar'] ?? null] as $col => $value) {
                    if ($value && ($hrWins || ! $user->{$col})) {
                        $user->{$col} = $value;
                    }
                }
                $dirty = $dirty || $user->isDirty();
                $user->save();
            }
            if ($dirty) {
                $this->bus->emit('employee.synced', ['employee_id' => $employee->id, 'employee_no' => $no, 'change' => 'updated']);
            }

            return 'updated';
        });
    }

    /**
     * The master-data columns a row carries. When HR does not win, only blanks are filled.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function master(array $r, bool $hrWins, ?Employee $current = null): array
    {
        $map = [];
        if (! empty($r['job_title_code']) && ($j = JobTitle::where('code', $r['job_title_code'])->value('id'))) {
            $map['job_title_id'] = $j;
        }
        if (! empty($r['school_code']) && ($s = School::where('code', $r['school_code'])->orWhere('moe_no', $r['school_code'])->value('id'))) {
            $map['school_id'] = $s;
        }
        if (! empty($r['department_code']) && ($d = Department::where('code', $r['department_code'])->value('id'))) {
            $map['department_id'] = $d;
        }
        if (! empty($r['supervisor_employee_no']) && ($m = Employee::where('employee_no', $r['supervisor_employee_no'])->value('id'))) {
            $map['supervisor_id'] = $m;
        }
        foreach (['gender', 'nationality', 'hire_date', 'experience_years', 'qualification', 'specialization', 'grade_level', 'education_stage', 'birth_date', 'national_id'] as $col) {
            if (isset($r[$col]) && $r[$col] !== '') {
                $map[$col] = $r[$col];
            }
        }
        if ($current && ! $hrWins) {
            $map = array_filter($map, fn ($v, $k) => blank($current->{$k}), ARRAY_FILTER_USE_BOTH);
        }

        return $map;
    }
}
