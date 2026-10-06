<?php

namespace App\Services\Kpi;

use App\Models\Evaluation;
use App\Models\KpiSample;
use App\Models\KpiTarget;
use App\Models\PresenceSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The live KPI dashboard: response time, concurrent users, uptime, error rate, data integrity and completion, course completion,
 * monthly active users, satisfaction, knowledge gain and security. Each metric has a target; collecting stores a sample, a breach alerts administrators once a day.
 */
class KpiService
{
    /** metric => [unit, target, comparator, label ar, label en] */
    public const METRICS = [
        'response_time_p50' => ['ms', 1500, 'lte', 'متوسط زمن الاستجابة', 'Average response time'],
        'response_time_p95' => ['ms', 3000, 'lte', 'زمن الاستجابة (الشريحة 95)', 'Response time (95th percentile)'],
        'concurrent_users' => ['users', 10000, 'gte', 'المستخدمون المتزامنون (السعة)', 'Concurrent users (capacity)'],
        'uptime' => ['%', 99.9, 'gte', 'نسبة التوافر', 'Uptime'],
        'error_rate' => ['%', 1, 'lte', 'نسبة الأخطاء', 'Error rate'],
        'data_integrity' => ['%', 100, 'gte', 'سلامة البيانات', 'Data integrity'],
        'data_completion' => ['%', 100, 'gte', 'اكتمال البيانات', 'Data completion'],
        'course_completion' => ['%', 80, 'gte', 'معدل إتمام الدورات', 'Course completion'],
        'active_users_monthly' => ['%', 70, 'gte', 'المستخدمون النشطون شهريًا', 'Monthly active users'],
        'satisfaction' => ['/5', 4.5, 'gte', 'رضا المتعلمين', 'Learner satisfaction'],
        'knowledge_gain' => ['%', 35, 'gte', 'مكتسب المعرفة', 'Knowledge gain'],
        'unauthorized_attempts' => ['breaches', 0, 'lte', 'الاختراقات الأمنية (المحاولات المحجوبة في التفاصيل)', 'Security breaches (blocked attempts in detail)'],
    ];

    public function __construct(private readonly NotificationService $notifications, private readonly DataIntegrityChecker $integrity) {}

    /** Gives a new installation the RFP's targets (administrators can change them). */
    public function ensureTargets(): void
    {
        foreach (self::METRICS as $metric => [, $target, $cmp]) {
            KpiTarget::firstOrCreate(['metric' => $metric], ['target' => $target, 'comparator' => $cmp, 'editable' => true]);
        }
    }

    /** Computes every metric now. @return array<string, array{value: float, meta: array<string, mixed>}> */
    public function measure(): array
    {
        $out = [];
        $since = now()->subMinutes(5);
        $latency = DB::table('request_metrics')->where('created_at', '>=', $since)->orderBy('duration_ms')->pluck('duration_ms')->all();
        $out['response_time_p50'] = ['value' => $this->percentile($latency, 50), 'meta' => ['samples' => count($latency)]];
        $out['response_time_p95'] = ['value' => $this->percentile($latency, 95), 'meta' => ['samples' => count($latency)]];

        $onlineSince = now()->subSeconds(120);
        $current = PresenceSession::where('last_seen_at', '>=', $onlineSince)->distinct()->count('user_id');
        $peak = (float) KpiSample::where('metric', 'concurrent_users')->where('measured_at', '>=', now()->subDay())->max('value');
        $out['concurrent_users'] = ['value' => (float) $current, 'meta' => ['peak_24h' => max($peak, $current)]];

        $probes = KpiSample::where('metric', 'uptime_probe')->where('measured_at', '>=', now()->subDays(30));
        $n = (clone $probes)->count();
        $out['uptime'] = ['value' => $n ? round((clone $probes)->avg('value') * 100, 3) : 100.0, 'meta' => ['probes' => $n]];

        $total = DB::table('request_metrics')->where('created_at', '>=', $since)->count();
        $errors = DB::table('request_metrics')->where('created_at', '>=', $since)->where('status', '>=', 500)->count();
        $out['error_rate'] = ['value' => $total ? round($errors / $total * 100, 2) : 0.0, 'meta' => ['requests' => $total, 'errors' => $errors]];

        $out['data_integrity'] = $this->integrity->rate();
        $out['data_completion'] = $this->completion();

        $active = Registration::whereIn('status', ['approved', 'completed'])->count();
        $done = Registration::where('status', 'completed')->count();
        $out['course_completion'] = ['value' => $active ? round($done / $active * 100, 1) : 0.0, 'meta' => ['completed' => $done, 'enrolled' => $active]];

        $population = max(1, User::where('status', 'active')->count());
        $mau = User::where('status', 'active')->where('last_active_at', '>=', now()->subDays(30))->count();
        $out['active_users_monthly'] = ['value' => round($mau / $population * 100, 1), 'meta' => ['active' => $mau, 'population' => $population]];

        $sat = Evaluation::where('submitted_at', '>=', now()->subDays(90))->avg('satisfaction_score');
        $out['satisfaction'] = ['value' => $sat === null ? 0.0 : round((float) $sat / 20, 2), 'meta' => ['responses' => Evaluation::where('submitted_at', '>=', now()->subDays(90))->count()]];

        $gain = Evaluation::where('pre_test_score', '>', 0)->whereNotNull('post_test_score')->selectRaw('avg((post_test_score - pre_test_score) * 100.0 / pre_test_score) as g, count(*) as n')->first();
        $out['knowledge_gain'] = ['value' => $gain && $gain->n ? round((float) $gain->g, 1) : 0.0, 'meta' => ['pairs' => (int) ($gain->n ?? 0)]];

        $blocked = DB::table('request_metrics')->where('created_at', '>=', now()->subDays(30))->whereIn('status', [401, 403, 429])->count();
        $breaches = DB::table('audit_logs')->where('action', 'security.breach')->where('created_at', '>=', now()->subDays(30))->count();
        $out['unauthorized_attempts'] = ['value' => (float) $breaches, 'meta' => ['blocked_30d' => $blocked]];

        return $out;
    }

    /** Stores a sample of every metric (and a health probe), then warns about breaches. @return array<string, mixed> */
    public function collect(): array
    {
        KpiSample::create(['metric' => 'uptime_probe', 'value' => $this->probe() ? 1 : 0, 'window' => '5m', 'measured_at' => now()]);
        $this->ensureTargets();
        $values = $this->measure();
        foreach ($values as $metric => $m) {
            KpiSample::create(['metric' => $metric, 'value' => $m['value'], 'window' => '5m', 'meta' => $m['meta'], 'measured_at' => now()]);
        }
        $this->alertBreaches($values);
        // Old request rows and raw samples are not needed past a short horizon (daily averages live on in the samples).
        DB::table('request_metrics')->where('created_at', '<', now()->subDays(2))->delete();
        KpiSample::where('measured_at', '<', now()->subDays(45))->delete();

        return $values;
    }

    /** Current value, target, status and a 30-day daily trend for each metric. @return list<array<string, mixed>> */
    public function dashboard(): array
    {
        $this->ensureTargets();
        $targets = KpiTarget::all()->keyBy('metric');
        $live = null;
        $out = [];
        foreach (self::METRICS as $metric => [$unit, , , $ar, $en]) {
            $latest = KpiSample::where('metric', $metric)->orderByDesc('measured_at')->first();
            if (! $latest) {
                $live ??= $this->measure();
                $latest = new KpiSample(['metric' => $metric, 'value' => $live[$metric]['value'], 'meta' => $live[$metric]['meta'], 'measured_at' => now()]);
            }
            $t = $targets[$metric];
            $out[] = [
                'metric' => $metric, 'label' => ['ar' => $ar, 'en' => $en], 'unit' => $unit, 'value' => $latest->value, 'target' => $t->target, 'comparator' => $t->comparator, 'editable' => $t->editable,
                'status' => $this->status($latest->value, $t->target, $t->comparator), 'measured_at' => $latest->measured_at->toIso8601String(), 'meta' => $latest->meta, 'trend' => $this->trend($metric),
            ];
        }

        return $out;
    }

    /** @param  array<string, float|int|null>  $targets metric => value */
    public function updateTargets(array $targets): void
    {
        foreach ($targets as $metric => $value) {
            if (isset(self::METRICS[$metric]) && is_numeric($value)) {
                KpiTarget::where('metric', $metric)->update(['target' => (float) $value]);
            }
        }
    }

    public function status(float $value, float $target, string $comparator): string
    {
        return ($comparator === 'gte' ? $value >= $target : $value <= $target) ? 'ok' : 'breach';
    }

    /** The month in one table, for service-level reviews. @return array<string, mixed> */
    public function monthlyDocument(Carbon $month, string $lang): array
    {
        $ar = $lang === 'ar';
        $this->ensureTargets();
        $targets = KpiTarget::all()->keyBy('metric');
        $rows = [];
        foreach (self::METRICS as $metric => [$unit, , , $labelAr, $labelEn]) {
            $q = KpiSample::where('metric', $metric)->whereBetween('measured_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            $avg = (clone $q)->avg('value');
            $t = $targets[$metric];
            $worst = $t->comparator === 'gte' ? (clone $q)->min('value') : (clone $q)->max('value');
            $rows[] = [$ar ? $labelAr : $labelEn, $avg === null ? '—' : round((float) $avg, 2).' '.$unit, ($t->comparator === 'gte' ? '≥ ' : '≤ ').$t->target.' '.$unit, $worst === null ? '—' : round((float) $worst, 2),
                $avg === null ? '—' : ($this->status((float) $avg, $t->target, $t->comparator) === 'ok' ? ($ar ? 'محقق' : 'Met') : ($ar ? 'غير محقق' : 'Not met'))];
        }

        return [
            'title' => $ar ? 'تقرير مؤشرات الأداء الشهري' : 'Monthly KPI report',
            'subtitle' => $month->format('Y-m'),
            'sections' => [['heading' => $ar ? 'المؤشرات مقابل المستهدفات' : 'Indicators against targets', 'table' => [
                'head' => $ar ? ['المؤشر', 'المتوسط', 'المستهدف', 'أسوأ قيمة', 'الحالة'] : ['Indicator', 'Average', 'Target', 'Worst value', 'Status'], 'rows' => $rows,
            ]]],
        ];
    }

    private function trend(string $metric): array
    {
        $driver = DB::getDriverName();
        $day = $driver === 'sqlite' ? 'date(measured_at)' : "to_char(measured_at, 'YYYY-MM-DD')";

        return KpiSample::where('metric', $metric)->where('measured_at', '>=', now()->subDays(30))->selectRaw("{$day} as d, avg(value) as v")->groupByRaw($day)->orderByRaw($day)->get()
            ->map(fn ($r) => ['date' => $r->d, 'value' => round((float) $r->v, 2)])->all();
    }

    private function percentile(array $sorted, int $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        sort($sorted);

        return (float) $sorted[(int) max(0, min($n - 1, ceil($p / 100 * $n) - 1))];
    }

    /** One synthetic check: the database answers and the cache works. */
    private function probe(): bool
    {
        try {
            DB::select('select 1');
            Cache::put('kpi.probe', 1, 60);

            return Cache::get('kpi.probe') === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /** Share of mandatory profile fields that are filled in. @return array{value: float, meta: array<string, mixed>} */
    private function completion(): array
    {
        $employees = DB::table('employees')->count();
        if ($employees === 0) {
            return ['value' => 100.0, 'meta' => ['employees' => 0]];
        }
        $fields = ['employee_no', 'school_id', 'job_title_id', 'gender'];
        $filled = 0;
        foreach ($fields as $f) {
            $filled += DB::table('employees')->whereNotNull($f)->where($f, '<>', '')->count();
        }
        $users = DB::table('users')->join('employees', 'employees.user_id', '=', 'users.id');
        $userFilled = (clone $users)->whereNotNull('users.email')->count() + (clone $users)->whereNotNull('users.name')->count();
        $cells = $employees * count($fields) + $employees * 2;

        return ['value' => round(($filled + $userFilled) / $cells * 100, 2), 'meta' => ['employees' => $employees]];
    }

    /** @param  array<string, array{value: float}>  $values */
    private function alertBreaches(array $values): void
    {
        $targets = KpiTarget::all()->keyBy('metric');
        $admins = null;
        foreach ($values as $metric => $m) {
            $t = $targets[$metric] ?? null;
            if (! $t || $this->status($m['value'], $t->target, $t->comparator) === 'ok') {
                continue;
            }
            // Metrics with no data yet (a fresh installation) are not breaches.
            if (in_array($metric, ['response_time_p50', 'response_time_p95', 'error_rate'], true) && ($m['meta']['samples'] ?? $m['meta']['requests'] ?? 0) === 0) {
                continue;
            }
            if (! Cache::add("kpi.breach.{$metric}", true, now()->addDay())) {
                continue;
            }
            $admins ??= User::where('status', 'active')->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::SUPER_ADMIN, Role::CENTER_ADMIN]))->pluck('id');
            [$unit, , , $ar, $en] = self::METRICS[$metric];
            $this->notifications->broadcast($admins, 'kpi.breach', ['ar' => 'مؤشر خارج المستهدف: '.$ar, 'en' => 'KPI off target: '.$en],
                ['ar' => "القيمة {$m['value']} {$unit} مقابل المستهدف {$t->target}", 'en' => "Value {$m['value']} {$unit} against target {$t->target}"], ['route' => '/admin/kpi'], raw: true);
        }
    }
}
