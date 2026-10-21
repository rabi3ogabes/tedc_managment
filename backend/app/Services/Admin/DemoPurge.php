<?php

namespace App\Services\Admin;

use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use App\Security\PasswordService;
use App\Services\FeatureSettings;
use App\Services\Import\TraineeHistoryImport;
use App\Support\Supabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns a demonstration database into a working one: every demo programme, registration, session, certificate, survey, kit, post,
 * notification, log and every demo person goes; what stays is the platform's setup (roles and permissions, settings and theme, reference
 * lists, help centre, report definitions, templates, holidays, public pages, training rooms), the official school list, the people and
 * courses imported from the Ministry's extract — and one administrator. Used by `tedc:purge-demo` and by Settings → Data.
 */
class DemoPurge
{
    /** Platform setup and reference data: kept whole. */
    public const KEEP = [
        'migrations', 'migration_batches', 'roles', 'permissions', 'permission_role', 'site_settings', 'import_runs',
        'job_titles', 'job_groups', 'skills', 'competency_domains', 'program_categories', 'pd_activity_types', 'pd_annual_targets', 'levels', 'badges',
        'gamification_rules', 'rewards', 'help_articles', 'help_article_versions', 'certificate_templates', 'integrations', 'webhook_subscriptions',
        'calendar_days', 'page_blocks', 'page_versions', 'training_places', 'buildings', 'training_rooms', 'withdrawal_reasons', 'kpi_targets',
        'sharing_policies', 'passing_policies', 'registration_priority_rules', 'needs_rules', 'career_paths', 'career_path_levels', 'school_groups',
        'partner_organizations', 'dashboard_presets', 'evaluation_forms', 'embeddings',
    ];

    /** Framework tables that are never touched. */
    private const SKIP = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private string $admin = '';

    public function forAdmin(string $email): static
    {
        $this->admin = strtolower(trim($email));

        return $this;
    }

    public function importedPeople(): int
    {
        return DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->count();
    }

    public function done(): ?array
    {
        return SiteSetting::find('demo_purged_at')?->value;
    }

    /** What would go, table by table (largest first). @return array<string, int> */
    public function plan(): array
    {
        $plan = [];
        foreach ($this->tables() as $t) {
            if (in_array($t, self::KEEP, true)) {
                continue;
            }
            $n = $this->doomed($t)->count();
            if ($n > 0) {
                $plan[$t] = $n;
            }
        }
        arsort($plan);

        return $plan;
    }

    /** @return array{people: int, courses: int, schools: int, setup_tables: int} */
    public function kept(): array
    {
        return [
            'people' => $this->importedPeople(),
            'courses' => DB::table('pd_activities')->where('source_system', TraineeHistoryImport::SOURCE)->count(),
            'schools' => $this->keptSchools()->count(),
            'setup_tables' => count(self::KEEP),
        ];
    }

    /** Every row that will go, one JSON line each, gzipped, to $path (a local file). Returns the number of rows. @param list<string> $tables */
    public function backup(string $path, array $tables): int
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Cannot write the backup to {$path}.");
        }
        $rows = 0;
        foreach ($tables as $t) {
            foreach ($this->doomed($t)->orderBy(DB::raw('1'))->cursor() as $row) {
                gzwrite($gz, json_encode(['table' => $t, 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
                $rows++;
            }
        }
        gzclose($gz);

        return $rows;
    }

    /**
     * Deletes. Links between demo tables decide the order, so a table that cannot be emptied yet (another demo table still points at it)
     * is tried again on the next pass; each attempt is its own transaction, so a refused one changes nothing.
     *
     * @param  list<string>  $tables
     * @return array{left: list<string>, removed_emails: list<string>, messages: list<string>}
     */
    public function purge(array $tables): array
    {
        if ($this->admin === '') {
            throw new RuntimeException('The administrator to keep is required.');
        }
        $removedEmails = DB::table('users')->whereNotIn('id', $this->keptUsers())->pluck('email')->map(fn ($e) => strtolower((string) $e))->all();
        $pending = $tables;
        $messages = [];
        for ($pass = 1; $pending !== [] && $pass <= 12; $pass++) {
            $still = [];
            foreach ($pending as $t) {
                try {
                    DB::transaction(fn () => $this->doomed($t)->delete());
                } catch (Throwable $e) {
                    $still[] = $t;
                    if ($pass === 12) {
                        $messages[] = "{$t}: ".mb_substr($e->getMessage(), 0, 300);
                    }
                }
            }
            if (count($still) === count($pending)) {
                $pending = $still;
                break;
            }
            $pending = $still;
        }
        if ($pending === []) {
            DB::table('cache')->delete();
            // Written directly: the person who ran the clean-up may be a demo account that no longer exists (an audit entry would point at it).
            DB::table('site_settings')->updateOrInsert(['key' => 'demo_purged_at'], ['value' => json_encode(['at' => now()->toIso8601String(), 'admin' => $this->admin]), 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['left' => $pending, 'removed_emails' => array_values(array_diff($removedEmails, [$this->admin])), 'messages' => $messages];
    }

    /**
     * The administrator who stays: found or created as Super Admin. With a password (the screen), it is set through the password policy —
     * and in Supabase Auth when that is the identity store — so they can sign in at once; without one (the command) it is left unset.
     */
    public function ensureAdmin(string $name, ?string $password = null): User
    {
        $user = User::where('email', $this->admin)->first() ?? User::create(['name' => $name, 'name_ar' => $name, 'email' => $this->admin, 'locale' => 'ar', 'status' => 'active']);
        $user->forceFill(['status' => 'active'])->saveQuietly();
        $role = Role::where('slug', Role::SUPER_ADMIN)->value('id');
        if ($role && ! DB::table('role_user')->where('user_id', $user->id)->where('role_id', $role)->exists()) {
            $user->roles()->attach($role);
        }
        if ($password !== null) {
            if ($this->usesSupabase() && ! $user->auth_id) {
                $admin = $this->supabaseAdmin();
                $res = $admin->post('/admin/users', ['email' => $user->email, 'password' => Str::password(32), 'email_confirm' => true]);
                $authId = $res->successful() ? $res->json('id') : null;
                if (! $authId) {   // already in Supabase Auth (an earlier attempt): find it
                    for ($page = 1; ! $authId; $page++) {
                        $users = $admin->get('/admin/users', ['page' => $page, 'per_page' => 1000])->throw()->json('users') ?? [];
                        $authId = collect($users)->first(fn ($u) => strtolower($u['email'] ?? '') === $user->email)['id'] ?? null;
                        if (count($users) < 1000) {
                            break;
                        }
                    }
                }
                $user->forceFill(['auth_id' => $authId ?? throw new RuntimeException('The administrator could not be created in Supabase Auth.')])->saveQuietly();
            }
            app(PasswordService::class)->set($user, $password);
        }

        return $user;
    }

    /** After the clean-up the demo switches are off: no demo accounts on the sign-in screen, no demo scenario. */
    public function switchOffDemoFeatures(User $by): void
    {
        $features = app(FeatureSettings::class);
        foreach (['test_accounts', 'demo_scenarios'] as $key) {
            if ($features->exists($key) && $features->enabled($key)) {
                $features->set($key, false, 'The demo data was removed', $by);
            }
        }
    }

    /** The demo accounts in Supabase Auth go too, so none of them can sign in again. @param list<string> $emails */
    public function removeFromSupabase(array $emails): int
    {
        if (! $this->usesSupabase() || $emails === []) {
            return 0;
        }
        $gone = array_flip($emails);
        unset($gone[$this->admin]);
        $admin = $this->supabaseAdmin();
        // Collect first, then delete: deleting while paging would shift the pages.
        $ids = [];
        for ($page = 1; ; $page++) {
            $users = $admin->get('/admin/users', ['page' => $page, 'per_page' => 1000])->throw()->json('users') ?? [];
            foreach ($users as $u) {
                if (isset($gone[strtolower($u['email'] ?? '')])) {
                    $ids[] = $u['id'];
                }
            }
            if (count($users) < 1000) {
                break;
            }
        }
        foreach ($ids as $id) {
            $admin->delete('/admin/users/'.$id)->throw();
        }

        return count($ids);
    }

    /** @return list<string> */
    private function tables(): array
    {
        return collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::SKIP, true) || str_starts_with($t, 'sqlite_'))->values()->all();
    }

    /** The rows of a table that go. */
    public function doomed(string $t): Builder
    {
        $q = DB::table($t);

        return match ($t) {
            'users' => $q->whereNotIn('id', $this->keptUsers()),
            'employees' => $q->where(fn ($w) => $w->whereNull('source_system')->orWhere('source_system', '!=', TraineeHistoryImport::SOURCE)),
            'pd_activities' => $q->where(fn ($w) => $w->whereNull('source_system')->orWhere('source_system', '!=', TraineeHistoryImport::SOURCE)),
            'employee_numbers' => $q->whereNotIn('employee_id', DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->select('id')),
            'role_user', 'user_identities', 'user_notification_preferences' => $q->whereNotIn('user_id', $this->keptUsers()),
            'schools' => $q->whereNotIn('id', $this->keptSchools()),
            'departments' => $q->whereNotIn('id', DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->whereNotNull('department_id')->select('department_id')),
            'school_group_school' => $q->whereNotIn('school_id', $this->keptSchools()),
            'report_definitions' => $q->whereNotNull('owner_id')->whereNotIn('owner_id', $this->keptUsers()),
            'notification_rules' => $q->whereNotNull('program_id'),
            default => $q,
        };
    }

    private function keptUsers(): Builder
    {
        return DB::table('users')->where(fn ($w) => $w->whereIn('id', DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->select('user_id'))
            ->when($this->admin !== '', fn ($x) => $x->orWhere('email', $this->admin)))->select('id');
    }

    /** The official school list, and any school an imported person works in. */
    private function keptSchools(): Builder
    {
        return DB::table('schools')->where(fn ($w) => $w->where('source', 'like', 'moe_%')
            ->orWhereIn('id', DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->whereNotNull('school_id')->select('school_id')))->select('id');
    }

    private function usesSupabase(): bool
    {
        return config('tedc.auth.driver') === 'supabase' && config('tedc.supabase.url') && config('tedc.supabase.service_role_key');
    }

    private function supabaseAdmin(): PendingRequest
    {
        return Supabase::admin(20)->baseUrl(rtrim((string) config('tedc.supabase.url'), '/').'/auth/v1');
    }
}
