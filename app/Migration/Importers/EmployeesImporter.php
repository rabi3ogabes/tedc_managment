<?php

namespace App\Migration\Importers;

use App\Migration\Cleanser;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Str;

class EmployeesImporter extends Importer
{
    public function kind(): string
    {
        return 'employees';
    }

    public function fields(): array
    {
        return [
            'employee_no' => $this->f('الرقم الوظيفي', 'Employee no.', true, ['personal no', 'رقم الموظف', 'الرقم الشخصي'], 'E-1001'),
            'name' => $this->f('الاسم', 'Name', true, ['full name', 'name en', 'الاسم بالانجليزية'], 'Hamad Al-Thani'),
            'name_ar' => $this->f('الاسم بالعربية', 'Name (Arabic)', false, ['الاسم العربي'], 'حمد آل ثاني'),
            'email' => $this->f('البريد الإلكتروني', 'E-mail', false, ['mail', 'البريد'], 'hamad@moe.gov.qa'),
            'phone' => $this->f('الجوال', 'Phone', false, ['mobile', 'الهاتف'], '55512345'),
            'school_code' => $this->f('رمز المدرسة', 'School code', false, ['school', 'المدرسة', 'رقم المدرسة'], 'SCH-001'),
            'job_title_code' => $this->f('رمز المسمى الوظيفي', 'Job title code', false, ['job title', 'المسمى الوظيفي'], 'TEACHER'),
            'gender' => $this->f('الجنس', 'Gender', false, ['sex'], 'male'),
            'nationality' => $this->f('الجنسية', 'Nationality', false, [], 'QA'),
            'hire_date' => $this->f('تاريخ التعيين', 'Hire date', false, ['joining date'], '2018-09-01'),
            'national_id' => $this->f('رقم الهوية', 'National ID', false, ['qid', 'id number'], ''),
            'qualification' => $this->f('المؤهل', 'Qualification', false, [], 'bachelor'),
        ];
    }

    public function key(array $m): ?string
    {
        $k = Cleanser::code($m['employee_no'] ?? '');

        return $k !== '' ? $k : null;
    }

    public function clean(array $m): array
    {
        $c = [
            'employee_no' => Cleanser::code($m['employee_no'] ?? ''), 'name' => Cleanser::name($m['name'] ?? ''), 'name_ar' => Cleanser::name($m['name_ar'] ?? '') ?: null, 'email' => Cleanser::email($m['email'] ?? ''),
            'phone' => Cleanser::phone($m['phone'] ?? ''), 'school_code' => Cleanser::code($m['school_code'] ?? '') ?: null, 'job_title_code' => Cleanser::code($m['job_title_code'] ?? '') ?: null, 'gender' => Cleanser::gender($m['gender'] ?? ''),
            'nationality' => Cleanser::name($m['nationality'] ?? '') ?: null, 'hire_date' => Cleanser::date($m['hire_date'] ?? null), 'national_id' => Cleanser::digits(trim((string) ($m['national_id'] ?? ''))) ?: null, 'qualification' => Cleanser::name($m['qualification'] ?? '') ?: null,
        ];
        $e = $this->missing($c, ['employee_no', 'name']);
        if (filled($m['email'] ?? null) && $c['email'] === null) {
            $e[] = 'invalid:email';
        }
        if (filled($m['hire_date'] ?? null) && $c['hire_date'] === null) {
            $e[] = 'invalid:hire_date';
        }
        if (filled($m['gender'] ?? null) && $c['gender'] === null) {
            $e[] = 'invalid:gender';
        }
        if ($c['school_code'] && ! School::where('code', $c['school_code'])->orWhere('moe_no', $c['school_code'])->exists()) {
            $e[] = 'unknown:school_code';
        }
        if ($c['job_title_code'] && ! JobTitle::where('code', $c['job_title_code'])->exists()) {
            $e[] = 'unknown:job_title_code';
        }
        if (! Employee::where('employee_no', $c['employee_no'])->exists() && $c['email'] === null && ! in_array('invalid:email', $e, true)) {
            $e[] = 'missing:email';   // a new person needs an address to sign in
        }

        return [$c, $e];
    }

    public function exists(array $c): bool
    {
        return Employee::where('employee_no', $c['employee_no'])->exists();
    }

    public function apply(array $c): array
    {
        $school = $c['school_code'] ? School::where('code', $c['school_code'])->orWhere('moe_no', $c['school_code'])->value('id') : null;
        $title = $c['job_title_code'] ? JobTitle::where('code', $c['job_title_code'])->value('id') : null;
        $fields = array_filter(['school_id' => $school, 'job_title_id' => $title, 'gender' => $c['gender'], 'nationality' => $c['nationality'], 'hire_date' => $c['hire_date'], 'national_id' => $c['national_id'], 'qualification' => $c['qualification']], fn ($v) => $v !== null);

        $employee = Employee::where('employee_no', $c['employee_no'])->first();
        if ($employee) {
            $user = $employee->user;
            $before = ['employee' => $employee->only(array_keys($fields)), 'user' => $user?->only(['name', 'name_ar', 'phone'])];
            $employee->fill($fields)->save();
            $user?->forceFill(array_filter(['name' => $c['name'], 'name_ar' => $c['name_ar'], 'phone' => $c['phone']], fn ($v) => $v !== null))->save();

            return ['action' => 'updated', 'id' => $employee->id, 'before' => $before, 'created' => []];
        }
        $created = [];
        $user = User::whereRaw('lower(email) = ?', [$c['email']])->first();
        if (! $user) {
            $user = User::create(['name' => $c['name'], 'name_ar' => $c['name_ar'], 'email' => $c['email'], 'phone' => $c['phone'], 'locale' => 'ar', 'status' => 'active']);
            $created[] = ['table' => 'users', 'id' => $user->id];
            if ($role = Role::where('slug', Role::EMPLOYEE)->first()) {
                $user->roles()->attach($role->id);
            }
        }
        $employee = Employee::create(['user_id' => $user->id, 'employee_no' => $c['employee_no'], 'school_id' => $school] + $fields);
        array_unshift($created, ['table' => 'employees', 'id' => $employee->id]);

        return ['action' => 'created', 'id' => $employee->id, 'before' => null, 'created' => $created];
    }

    /** Used by the other importers: the employee a row is about. */
    public static function employeeId(string $no): ?string
    {
        return Employee::where('employee_no', Cleanser::code($no))->value('id');
    }

    public static function uuid(): string
    {
        return (string) Str::uuid();
    }
}
