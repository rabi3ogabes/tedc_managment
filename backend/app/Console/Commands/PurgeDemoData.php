<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Import\TraineeHistoryImport;
use App\Support\Supabase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Turns a demonstration database into a working one: every demo programme, registration, session, certificate, survey, kit, post,
 * notification, log and every demo person goes; what stays is the platform's setup (roles and permissions, settings and theme, reference
 * lists, help centre, report definitions, templates, holidays, public pages, training rooms), the official school list, and the people and
 * courses imported from the Ministry's extract — plus one administrator. Without --force it only counts. Afterwards no deployment adds the
 * demo data back (`demo_purged_at` in the settings).
 */
#[Signature('tedc:purge-demo
    {--force : Delete for real (without it: a dry run that only counts)}
    {--admin= : E-mail of the administrator to keep, created as Super Admin when missing (required with --force)}
    {--admin-name= : Name of that administrator when it is created}
    {--backup= : Before deleting, write every row that goes to this file (gzipped JSON lines), so it can be inspected or restored}')]
#[Description('Delete the demo data and keep only the platform setup, the official schools and the imported people (dry run unless --force)')]
class PurgeDemoData extends Command
{
    /** Platform setup and reference data: kept whole. */
    public const KEEP = [
        'migrations', 'migration_batches', 'roles', 'permissions', 'permission_role', 'site_settings',
        'job_titles', 'job_groups', 'skills', 'competency_domains', 'program_categories', 'pd_activity_types', 'pd_annual_targets', 'levels', 'badges',
        'gamification_rules', 'rewards', 'help_articles', 'help_article_versions', 'certificate_templates', 'integrations', 'webhook_subscriptions',
        'calendar_days', 'page_blocks', 'page_versions', 'training_places', 'buildings', 'training_rooms', 'withdrawal_reasons', 'kpi_targets',
        'sharing_policies', 'passing_policies', 'registration_priority_rules', 'needs_rules', 'career_paths', 'career_path_levels', 'school_groups',
        'partner_organizations', 'dashboard_presets', 'evaluation_forms', 'embeddings',
    ];

    /** Tables where some rows stay (the rest is demo). */
    public const PARTIAL = [
        'users', 'employees', 'role_user', 'schools', 'departments', 'pd_activities', 'employee_numbers', 'user_identities',
        'user_notification_preferences', 'school_group_school', 'report_definitions', 'notification_rules',
    ];

    private string $admin = '';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $this->admin = strtolower(trim((string) $this->option('admin')));
        if ($force && ! filter_var($this->admin, FILTER_VALIDATE_EMAIL)) {
            $this->error('--admin=<e-mail> is required with --force: the administrator who will still be able to sign in.');

            return self::FAILURE;
        }
        $imported = DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->count();
        if ($imported === 0) {
            $this->error('No imported employees (source '.TraineeHistoryImport::SOURCE.'): import the Ministry extract first, or there would be nobody left.');

            return self::FAILURE;
        }

        $tables = collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true) || str_starts_with($t, 'sqlite_'))->values();
        $plan = [];
        foreach ($tables as $t) {
            if (in_array($t, self::KEEP, true)) {
                continue;
            }
            $q = $this->doomed($t);
            $n = (clone $q)->count();
            if ($n > 0) {
                $plan[$t] = $n;
            }
        }
        arsort($plan);
        $this->info(($force ? 'Deleting' : 'Dry run — would delete').' '.number_format(array_sum($plan)).' rows in '.count($plan).' tables.');
        $this->table(['Table', 'Rows'], collect($plan)->map(fn ($n, $t) => [$t, number_format($n)])->values()->all());
        $this->line('Kept: '.number_format($imported).' imported employees and their courses, '.number_format($this->keptSchools()->count()).' schools, the platform setup ('.count(self::KEEP).' tables).');
        if (! $force) {
            $this->line('Nothing was changed. Run again with --force --admin=<e-mail> to delete.');

            return self::SUCCESS;
        }

        if ($path = (string) $this->option('backup')) {
            $this->backup($path, array_keys($plan));
        }
        $removedEmails = DB::table('users')->whereNotIn('id', $this->keptUsers())->pluck('email')->map(fn ($e) => strtolower((string) $e))->all();
        $this->ensureAdmin();
        $left = $this->purge(array_keys($plan));
        if ($left !== []) {
            $this->error('Could not empty: '.implode(', ', $left).'. Nothing else is affected; see the messages above.');

            return self::FAILURE;
        }
        DB::table('cache')->delete();
        SiteSetting::updateOrCreate(['key' => 'demo_purged_at'], ['value' => ['at' => now()->toIso8601String(), 'admin' => $this->admin]]);
        $this->removeFromSupabase($removedEmails);
        $this->info('Done. The demo data is gone; deployments will not add it back.');

        return self::SUCCESS;
    }

    /** @param list<string> $tables */
    private function backup(string $path, array $tables): void
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Cannot write the backup to {$path}.");
        }
        $rows = 0;
        foreach ($tables as $t) {
            foreach ($this->doomed($t)->orderBy(DB::raw('1'))->cursor() as $row) {
                gzwrite($gz, json_encode(['table' => $t, 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
                $rows++;
            }
        }
        gzclose($gz);
        $this->info('Backup: '.number_format($rows).' rows written to '.$path.'.');
    }

    /** The rows of a table that go. */
    private function doomed(string $t): Builder
    {
        $q = DB::table($t);

        return match ($t) {
            'users' => $q->whereNotIn('id', $this->keptUsers()),
            'employees' => $q->where(fn ($w) => $w->whereNull('source_system')->orWhere('source_system', '!=', TraineeHistoryImport::SOURCE)),
            'pd_activities' => $q->where(fn ($w) => $w->whereNull('source_system')->orWhere('source_system', '!=', TraineeHistoryImport::SOURCE)),
            'employee_numbers' => $q->whereNotIn('employee_id', $this->keptEmployees()),
            'role_user', 'user_identities', 'user_notification_preferences' => $q->whereNotIn('user_id', $this->keptUsers()),
            'schools' => $q->whereNotIn('id', $this->keptSchools()),
            'departments' => $q->whereNotIn('id', DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->whereNotNull('department_id')->select('department_id')),
            'school_group_school' => $q->whereNotIn('school_id', $this->keptSchools()),
            'report_definitions' => $q->whereNotNull('owner_id')->whereNotIn('owner_id', $this->keptUsers()),
            'notification_rules' => $q->whereNotNull('program_id'),
            default => $q,
        };
    }

    private function keptEmployees(): Builder
    {
        return DB::table('employees')->where('source_system', TraineeHistoryImport::SOURCE)->select('id');
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

    private function ensureAdmin(): void
    {
        $user = User::where('email', $this->admin)->first();
        if (! $user) {
            $name = trim((string) $this->option('admin-name')) ?: 'مدير النظام';
            $user = User::create(['name' => $name, 'name_ar' => $name, 'email' => $this->admin, 'locale' => 'ar', 'status' => 'active']);
            $this->info('Administrator '.$this->admin.' created (no password yet).');
        }
        $role = Role::where('slug', Role::SUPER_ADMIN)->value('id');
        if ($role && ! DB::table('role_user')->where('user_id', $user->id)->where('role_id', $role)->exists()) {
            $user->roles()->attach($role);
        }
    }

    /**
     * Deletes table by table. Links between demo tables decide the order, so a table that cannot be emptied yet (another demo table
     * still points at it) is tried again on the next pass; each attempt is its own savepoint, so a refused one changes nothing.
     *
     * @param  list<string>  $tables
     * @return list<string> the tables that could not be emptied
     */
    private function purge(array $tables): array
    {
        $pending = $tables;
        for ($pass = 1; $pending !== [] && $pass <= 12; $pass++) {
            $still = [];
            foreach ($pending as $t) {
                try {
                    DB::transaction(fn () => $this->doomed($t)->delete());
                } catch (Throwable $e) {
                    $still[] = $t;
                    if ($pass === 12) {
                        $this->warn("{$t}: ".mb_substr($e->getMessage(), 0, 300));
                    }
                }
            }
            if (count($still) === count($pending)) {
                $pending = $still;
                break;
            }
            $pending = $still;
        }

        return $pending;
    }

    /** The demo accounts in Supabase Auth (the live identity store) go too, so none of them can sign in again. */
    private function removeFromSupabase(array $emails): void
    {
        if (config('tedc.auth.driver') !== 'supabase' || ! config('tedc.supabase.url') || ! config('tedc.supabase.service_role_key') || $emails === []) {
            return;
        }
        $gone = array_flip($emails);
        unset($gone[$this->admin]);
        $admin = Supabase::admin(20)->baseUrl(rtrim((string) config('tedc.supabase.url'), '/').'/auth/v1');
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
        $removed = 0;
        foreach ($ids as $id) {
            $admin->delete('/admin/users/'.$id)->throw();
            $removed++;
        }
        $this->info("Supabase Auth: {$removed} demo account(s) removed.");
    }
}
