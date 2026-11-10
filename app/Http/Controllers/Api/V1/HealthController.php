<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Throwable;

/**
 * Deployment diagnostics without secrets: which database driver is configured, whether it answers, whether
 * the tables exist, and — on failure — only the SQLSTATE code with a hint (never hosts, users or passwords).
 */
class HealthController extends Controller
{
    private const HINTS = [
        '42P05' => 'Prepared statements collide on the Supabase transaction pooler: set DB_EMULATE_PREPARES=true.',
        '26000' => 'Prepared statements are lost on the Supabase transaction pooler: set DB_EMULATE_PREPARES=true.',
        '42P01' => 'Tables are missing: run the one-time setup (POST /api/v1/system/setup) or php artisan migrate --seed.',
        '08006' => 'Cannot connect to the database: check DB_URL (Supabase pooler host, port and password).',
        '08001' => 'Cannot connect to the database: check DB_URL (Supabase pooler host, port and password).',
        '28P01' => 'The database password in DB_URL is wrong.',
        '28000' => 'The database user in DB_URL is not allowed; use the pooler user postgres.<project-ref>.',
        '53300' => 'Too many database connections: use the Supabase transaction pooler (port 6543).',
        'XX000' => 'Supabase pooler error (often "Tenant or user not found"): check the user and region in DB_URL.',
    ];

    public function __invoke(): JsonResponse
    {
        $driver = (string) config('database.default');
        $report = ['app' => 'ok', 'database' => ['driver' => $driver]];

        try {
            DB::select('select 1');
            $report['database']['connection'] = 'ok';
            $report['database']['tables'] = Schema::hasTable('users') && Schema::hasTable('programs') ? 'ok' : 'missing';
            if ($report['database']['tables'] === 'missing') {
                $report['database']['hint'] = self::HINTS['42P01'];
            }
        } catch (Throwable $e) {
            $report['database'] = ['driver' => $driver, 'connection' => 'error'] + self::describe($e, $driver);
        }

        $report['database']['emulate_prepares'] = (bool) env('DB_EMULATE_PREPARES', false);
        $report['app_key'] = filled(config('app.key')) ? 'set' : 'missing';
        // Names only (never values) of deployment variables that are still missing.
        $report['missing_env'] = array_values(array_filter(
            ['APP_KEY', 'DB_URL', 'SUPABASE_SECRET_KEY', 'CRON_SECRET'],
            fn (string $name) => blank(env($name)),
        ));
        $healthy = ($report['database']['connection'] ?? null) === 'ok' && ($report['database']['tables'] ?? null) === 'ok' && $report['app_key'] === 'set';

        return response()->json(['data' => $report], $healthy ? 200 : 503);
    }

    /** Known connection failures: [message fragment, reason, hint]. Only the reason (never the message) is shown. */
    private const REASONS = [
        ['Tenant or user not found', 'tenant_not_found', 'The pooler host or user does not match your project. In Supabase → Connect → Transaction pooler, copy the exact URI (the host may be aws-0-… or aws-1-…, and the user is postgres.<project-ref>) into DB_URL.'],
        ['password authentication failed', 'wrong_password', 'The database password in DB_URL is wrong. Reset it in Supabase → Project Settings → Database and update DB_URL.'],
        ['could not translate host name', 'unknown_host', 'The host in DB_URL does not exist: copy the URI from Supabase → Connect.'],
        ['timeout expired', 'timeout', 'The database did not answer in time: check the host and port (6543) in DB_URL.'],
        ['timed out', 'timeout', 'The database did not answer in time: check the host and port (6543) in DB_URL.'],
        ['Connection refused', 'refused', 'The port in DB_URL is closed: use 6543 (transaction pooler).'],
        ['SSL', 'ssl', 'SSL negotiation failed: keep sslmode=require for Supabase.'],
        ['Max client connections', 'too_many_connections', 'Too many connections: use the transaction pooler (port 6543).'],
    ];

    /** @return array{sqlstate: ?string, reason?: string, hint: string} safe to show publicly */
    public static function describe(Throwable $e, string $driver): array
    {
        $state = self::sqlState($e);
        for ($x = $e; $x; $x = $x->getPrevious()) {
            foreach (self::REASONS as [$fragment, $reason, $hint]) {
                if (stripos($x->getMessage(), $fragment) !== false) {
                    return ['sqlstate' => $state, 'reason' => $reason, 'hint' => $hint];
                }
            }
        }

        return ['sqlstate' => $state, 'hint' => self::HINTS[$state] ?? ($driver === 'sqlite'
            ? 'DB_CONNECTION is sqlite: set DB_CONNECTION=pgsql and DB_URL in the deployment environment.'
            : 'Check DB_URL and the database logs.')];
    }

    private static function sqlState(Throwable $e): ?string
    {
        for ($x = $e; $x; $x = $x->getPrevious()) {
            if ($x instanceof PDOException && is_string($x->errorInfo[0] ?? null)) {
                return $x->errorInfo[0];
            }
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $x->getMessage(), $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
