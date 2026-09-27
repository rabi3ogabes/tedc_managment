<?php

namespace Tests;

use App\Auth\JwtVerifier;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.auth.driver' => 'local', 'tedc.storage_driver' => 'local', 'tedc.ai.api_key' => null]);
        $this->seed([RolePermissionSeeder::class, ReferenceDataSeeder::class]);
    }

    protected function makeUser(string $role = Role::EMPLOYEE, array $attributes = []): User
    {
        $user = User::create($attributes + [
            'name' => 'User '.Str::random(5),
            'name_ar' => 'مستخدم',
            'email' => Str::lower(Str::random(10)).'@test.qa',
            'password' => 'Secret#12345',
        ]);
        $user->roles()->attach(Role::where('slug', $role)->value('id'));

        return $user->load('roles');
    }

    protected function makeSchool(array $attributes = []): School
    {
        return School::create($attributes + [
            'code' => 'S-'.Str::random(6), 'name_ar' => 'مدرسة', 'name_en' => 'School',
            'type' => 'government', 'stage' => 'primary', 'region' => 'doha',
        ]);
    }

    protected function makeEmployee(array $attributes = [], ?User $user = null, string $jobCode = 'TEACHER'): Employee
    {
        $user ??= $this->makeUser();

        return Employee::create($attributes + [
            'user_id' => $user->id,
            'school_id' => $this->makeSchool()->id,
            'job_title_id' => JobTitle::where('code', $jobCode)->value('id'),
            'employee_no' => 'E-'.Str::random(8),
            'experience_years' => 5,
            'education_stage' => 'primary',
            'qualification' => 'bachelor',
        ])->load('user');
    }

    protected function makeProgram(array $attributes = []): Program
    {
        return Program::create($attributes + [
            'code' => 'P-'.Str::random(6),
            'title_ar' => 'برنامج', 'title_en' => 'Program',
            'total_hours' => 12, 'capacity' => 10,
            'status' => Program::STATUS_REGISTRATION_OPEN,
            'start_date' => today()->addDays(10), 'end_date' => today()->addDays(12),
            'registration_modes' => Program::MODES,
            'min_attendance_percent' => 80,
            'requires_tasks' => false, 'requires_evaluation' => false,
        ]);
    }

    protected function makeSession(Program $program, $startsAt, int $hours = 2): ProgramSession
    {
        return $program->sessions()->create([
            'title_ar' => 'جلسة', 'title_en' => 'Session',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHours($hours),
        ]);
    }

    protected function tokenFor(User $user): string
    {
        return app(JwtVerifier::class)->issue(['sub' => $user->id, 'email' => $user->email, 'typ' => 'access'], 3600);
    }

    protected function asUser(User $user): static
    {
        // Guards memoise the resolved user for the lifetime of the app instance.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user));
    }
}
