<?php

namespace Database\Seeders\Samples;

use App\Models\Employee;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\User;

/** What the sample sections share: the demo accounts, programs and registrations that already exist, and the accounts the new roles still lack. */
class SampleContext
{
    public const PASSWORD = 'Tedc@2026!';

    /** email => [name_ar, name_en, role]. The roles added since the first release have no demo account until here. */
    public const ROLE_ACCOUNTS = [
        'head@tedc.qa' => ['رئيس قسم التدريب', 'Head of Training', Role::TRAINING_HEAD],
        'deputy@tedc.qa' => ['النائب الأكاديمي', 'Academic Deputy', Role::ACADEMIC_DEPUTY],
        'leadership@tedc.qa' => ['قيادة المركز', 'Centre Leadership', Role::CENTER_LEADERSHIP],
        'planning@tedc.qa' => ['رئيس التخطيط', 'Head of Planning', Role::PLANNING_HEAD],
        'planner@tedc.qa' => ['أخصائي التخطيط', 'Planning Specialist', Role::PLANNING_SPECIALIST],
        'logistics@tedc.qa' => ['مسؤول الدعم اللوجستي', 'Logistics Officer', Role::LOGISTICS_OFFICER],
        'finance@tedc.qa' => ['المسؤول المالي', 'Finance Officer', Role::FINANCE_OFFICER],
    ];

    /** @var array<string, User> */
    private array $users = [];

    public function user(string $email): ?User
    {
        return $this->users[$email] ??= User::where('email', $email)->first();
    }

    public function admin(): User
    {
        return $this->user('admin@tedc.qa') ?? User::query()->firstOrFail();
    }

    /** The accounts a section can act as: trainees, trainers and staff that exist in this database. @return list<User> */
    public function trainees(): array
    {
        return array_values(array_filter(array_map(fn ($n) => $this->user("trainee{$n}@tedc.qa"), [1, 2, 3, 4])));
    }

    public function program(string $code): ?Program
    {
        return Program::where('code', $code)->first();
    }

    /** The first few programs with groups, for sections that need a group to hang things on. @return list<TrainingGroup> */
    public function groups(int $limit = 4): array
    {
        return TrainingGroup::with('program')->orderBy('code')->limit($limit)->get()->all();
    }

    public function employee(?User $user): ?Employee
    {
        return $user?->employee;
    }

    public function registration(User $user, ?Program $program = null): ?Registration
    {
        $e = $user->employee;

        return $e ? Registration::where('employee_id', $e->id)->when($program, fn ($q) => $q->where('program_id', $program->id))->first() : null;
    }

    /** Creates the missing accounts for the newer roles. */
    public function roleAccounts(): void
    {
        foreach (self::ROLE_ACCOUNTS as $email => [$ar, $en, $role]) {
            $u = User::updateOrCreate(['email' => $email], ['name' => $en, 'name_ar' => $ar, 'password' => self::PASSWORD, 'locale' => 'ar', 'status' => 'active']);
            $u->roles()->syncWithoutDetaching(Role::where('slug', $role)->pluck('id'));
            unset($this->users[$email]);
        }
    }
}
