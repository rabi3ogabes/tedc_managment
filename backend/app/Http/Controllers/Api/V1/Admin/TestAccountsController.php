<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use Database\Seeders\DemoOnlineCoursesSeeder;
use Database\Seeders\DemoTestAccountsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/** Settings → Test accounts: the demo trainees / trainers for trying the mobile app, and which programs they belong to. */
class TestAccountsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /** Creates (or refreshes) the test accounts, programs and assignments. */
    public function seed(): JsonResponse
    {
        app(DemoTestAccountsSeeder::class)->run();
        app(DemoOnlineCoursesSeeder::class)->run();
        // With Supabase Auth the new accounts also need a sign-in; harmless otherwise.
        try {
            if (config('tedc.auth.driver') === 'supabase') {
                Artisan::call('tedc:supabase-sync-users', ['--password' => DemoTestAccountsSeeder::PASSWORD, '--email' => DemoTestAccountsSeeder::emails()]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['data' => $this->payload()]);
    }

    private function payload(): array
    {
        $programs = Program::whereIn('code', array_map(fn ($n) => "TEST-P{$n}", range(1, 4)))->with('trainers:id,name_ar,name_en,user_id')->get()->keyBy('code');
        $users = User::whereIn('email', DemoTestAccountsSeeder::emails())->with('employee')->get()->keyBy('email');
        if ($programs->isEmpty() || $users->isEmpty()) {
            return ['seeded' => false, 'password' => DemoTestAccountsSeeder::PASSWORD, 'programs' => [], 'accounts' => []];
        }

        $number = fn (Program $p) => (int) substr($p->code, -1);
        $byNumber = $programs->mapWithKeys(fn (Program $p) => [$number($p) => $p]);
        $enrolled = Registration::whereIn('program_id', $programs->pluck('id'))->with('employee:id,user_id')->get()->groupBy('program_id');

        $accounts = [];
        foreach (array_keys(DemoTestAccountsSeeder::TRAINEE_PROGRAMS) as $n) {
            $u = $users->get("trainee{$n}@tedc.qa");
            $mine = $u?->employee ? $byNumber->sortKeys()->filter(fn (Program $p) => $enrolled->get($p->id, collect())->contains(fn ($r) => $r->employee_id === $u->employee->id))->keys()->values() : collect();
            $accounts[] = ['kind' => 'trainee', 'n' => $n, 'name' => $u?->displayName(), 'email' => "trainee{$n}@tedc.qa", 'programs' => $mine->all()];
        }
        foreach (array_keys(DemoTestAccountsSeeder::TRAINER_PROGRAMS) as $n) {
            $u = $users->get("trainer{$n}@tedc.qa");
            $mine = $byNumber->sortKeys()->filter(fn (Program $p) => $p->trainers->contains(fn ($t) => $t->user_id === $u?->id))->keys()->values();
            $accounts[] = ['kind' => 'trainer', 'n' => $n, 'name' => $u?->displayName(), 'email' => "trainer{$n}@tedc.qa", 'programs' => $mine->all()];
        }

        $courses = Program::where('code', 'like', 'TEST-OL%')->orderBy('code')->withCount(['courseLessons as lessons' => fn ($q) => $q->where('status', 'published')])->get();

        return [
            'seeded' => true, 'password' => DemoTestAccountsSeeder::PASSWORD, 'accounts' => $accounts,
            'courses' => $courses->map(fn (Program $p) => [
                'id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title'), 'lessons' => $p->lessons,
                'trainees' => Registration::where('program_id', $p->id)->count(),
            ])->values(),
            'programs' => $byNumber->sortKeys()->map(fn (Program $p, $n) => [
                'n' => $n, 'id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title'),
                'trainers' => $p->trainers->map(fn ($t) => $t->translate('name'))->values(),
                'trainees' => $enrolled->get($p->id, collect())->count(),
            ])->values(),
        ];
    }
}
