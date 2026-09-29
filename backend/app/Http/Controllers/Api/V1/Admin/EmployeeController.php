<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\PassportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $this->user();
        $schoolId = $this->schoolScope();
        $supervisorOnly = ! $schoolId && ! $this->isCenterStaff($user) && ! $user->hasRole(Role::EXECUTIVE) && $user->hasRole(Role::SUPERVISOR);

        $employees = Employee::with(['user', 'school', 'jobTitle', 'department'])
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->when($supervisorOnly, fn ($q) => $q->where('supervisor_id', $user->employee?->id ?? '00000000-0000-0000-0000-000000000000'))
            ->when($request->query('school_id'), fn ($q, $id) => $q->where('school_id', $id))
            ->when($request->query('job_title_id'), fn ($q, $id) => $q->where('job_title_id', $id))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('employee_no', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('name_ar', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))))
            ->orderBy('employee_no')
            ->paginate($this->perPage($request, 25));

        return EmployeeResource::collection($employees);
    }

    public function show(Employee $employee, PassportService $passport): JsonResponse
    {
        $this->authorizeView($employee);

        return response()->json(['data' => [
            'employee' => new EmployeeResource($employee->load(['user', 'school', 'jobTitle', 'department', 'skills'])),
            'passport' => $passport->build($employee),
            'registrations' => RegistrationResource::collection($employee->registrations()->with('program')->latest()->get()),
        ]]);
    }

    public function store(Request $request): EmployeeResource
    {
        $data = $this->validated($request);

        $employee = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? null,
                'locale' => 'ar',
            ]);
            $user->roles()->attach(Role::whereIn('slug', $data['roles'] ?? [Role::EMPLOYEE])->pluck('id'));

            return Employee::create(collect($data)->except(['name', 'name_ar', 'email', 'phone', 'password', 'roles'])->all() + ['user_id' => $user->id]);
        });

        return new EmployeeResource($employee->load(['user', 'school', 'jobTitle', 'department']));
    }

    public function update(Request $request, Employee $employee): EmployeeResource
    {
        $data = $this->validated($request, $employee);

        DB::transaction(function () use ($employee, $data) {
            $employee->user->update(collect($data)->only(['name', 'name_ar', 'email', 'phone'])->all());
            $employee->update(collect($data)->except(['name', 'name_ar', 'email', 'phone', 'password', 'roles'])->all());
        });

        return new EmployeeResource($employee->refresh()->load(['user', 'school', 'jobTitle', 'department']));
    }

    public function skills(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate([
            'skills' => ['required', 'array'],
            'skills.*.id' => ['required', 'uuid', 'exists:skills,id'],
            'skills.*.level' => ['required', 'integer', 'between:1,5'],
        ]);

        foreach ($data['skills'] as $skill) {
            $employee->skills()->syncWithoutDetaching([$skill['id'] => ['level' => $skill['level'], 'source' => 'supervisor', 'verified_at' => now()]]);
        }

        return new EmployeeResource($employee->load(['user', 'skills']));
    }

    private function authorizeView(Employee $employee): void
    {
        $user = $this->user();
        if ($this->isCenterStaff($user) || $user->hasRole(Role::EXECUTIVE)) {
            return;
        }
        $allowed = ($this->schoolScope() && $employee->school_id === $this->schoolScope())
            || ($user->employee && $employee->supervisor_id === $user->employee->id);
        abort_unless($allowed, 403, __('auth.forbidden'));
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $required = $employee ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
            'email' => [$required, 'email', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:10'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => [Rule::in([Role::EMPLOYEE, Role::SUPERVISOR, Role::SCHOOL_ADMIN, Role::TRAINER])],
            'employee_no' => [$required, 'string', 'max:32', Rule::unique('employees', 'employee_no')->ignore($employee?->id)],
            'national_id' => ['nullable', 'string', 'max:32'],
            'school_id' => ['nullable', 'uuid', 'exists:schools,id'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'job_title_id' => ['nullable', 'uuid', 'exists:job_titles,id'],
            'supervisor_id' => ['nullable', 'uuid', 'exists:employees,id'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'nationality' => ['nullable', 'string', 'max:64'],
            'birth_date' => ['nullable', 'date', 'before:-15 years', 'after:1930-01-01'],
            'hire_date' => ['nullable', 'date'],
            'experience_years' => ['sometimes', 'numeric', 'between:0,60'],
            'education_stage' => ['nullable', 'string', 'max:32'],
            'qualification' => ['nullable', 'string', 'max:64'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
