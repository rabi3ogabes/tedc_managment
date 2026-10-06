<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a report definition into rows. The definition says which dataset, which columns (with an optional aggregate), which filters, grouping and
 * order — all by field key. Keys, operators and aggregates are checked against the dataset; values are bound. Filters can use the tokens
 *
 * @me, @my_employee, @my_trainer and @today so a built-in report can mean "mine".
 */
class ReportRunner
{
    public const MAX_ROWS = 50000;

    public function __construct(private readonly ReportDatasetRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $def  dataset, columns, filters, group_by, sort, chart, options
     * @param  array<string, mixed>  $params  adjustable filters: [{field, operator, value}], date_from, date_to
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, total: int, totals: array<string, float>, chart: ?array<string, mixed>, personal: bool}
     */
    public function run(array $def, array $params, User $user, int $page = 1, int $perPage = 50, bool $all = false): array
    {
        $dataset = $this->registry->get((string) ($def['dataset'] ?? '')) ?? throw new BusinessRuleException('Unknown dataset.', 'unknown_dataset');
        $scope = AccessScope::current($user);
        $columns = $this->columns($dataset, $def['columns'] ?? [], $user);
        $query = $this->base($dataset, $scope);

        $this->optionScope($query, $def['options']['scope'] ?? null, $user);
        $filters = $this->filters($def, $params, $dataset, $user);
        foreach ($filters as $flt) {
            $this->applyFilter($query, $dataset['fields'][$flt['field']], $flt);
        }

        $aggregated = collect($columns)->contains(fn ($c) => $c['aggregate'] !== null);
        $select = [];
        $group = [];
        foreach ($columns as $c) {
            $expr = $dataset['fields'][$c['field']]['expr'];
            $select[] = DB::raw($this->selectExpr($expr, $c['aggregate']).' as "'.$c['key'].'"');
            if ($aggregated && $c['aggregate'] === null) {
                $group[] = $expr;
            }
        }
        // Extra grouping fields that are not shown are allowed too.
        foreach ((array) ($def['group_by'] ?? []) as $g) {
            if (isset($dataset['fields'][$g]) && ! in_array($dataset['fields'][$g]['expr'], $group, true) && $aggregated) {
                $group[] = $dataset['fields'][$g]['expr'];
            }
        }
        $query->select($select);
        foreach (array_unique($group) as $g) {
            $query->groupByRaw($g);
        }

        $sorts = $this->sorts($def['sort'] ?? [], $columns);
        foreach ($sorts as [$alias, $dir]) {
            $query->orderBy($alias, $dir);
        }
        if ($sorts === [] && $columns !== []) {
            $query->orderBy($columns[0]['key']);
        }

        $total = $aggregated ? (int) DB::query()->fromSub((clone $query)->reorder(), 'x')->count() : (int) DB::query()->fromSub((clone $query)->reorder(), 'x')->count();
        $rows = $all ? $query->limit(self::MAX_ROWS)->get() : $query->forPage($page, $perPage)->get();
        $rows = $rows->map(fn ($r) => (array) $r)->all();

        $totals = [];
        foreach ($columns as $c) {
            if ($c['type'] === 'number' && in_array($c['aggregate'], ['sum', 'count', 'count_distinct'], true)) {
                $totals[$c['key']] = array_sum(array_map(fn ($r) => (float) ($r[$c['key']] ?? 0), $rows));
            }
        }

        return [
            'columns' => array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['type'], 'format' => $c['format']], $columns),
            'rows' => $rows, 'total' => $total, 'totals' => $totals, 'chart' => $this->chart($def['chart'] ?? null, $columns, $rows),
            'personal' => collect($columns)->contains(fn ($c) => ! empty($dataset['fields'][$c['field']]['personal'])),
        ];
    }

    /**
     * Employees × programs (or licences): who has completed what. A matrix report; rows are limited by the person's scope.
     *
     * @param  array<string, mixed>  $params  programs: list of program ids (default: the programs most completed)
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, total: int, totals: array<string, float>, chart: null, personal: bool}
     */
    public function matrix(array $def, array $params, User $user, int $page = 1, int $perPage = 50, bool $all = false): array
    {
        $scope = AccessScope::current($user);
        $kind = ($params['matrix'] ?? $def['options']['matrix'] ?? 'programs') === 'licences' ? 'licences' : 'programs';
        $employees = $scope->constrainEmployees(Employee::query()->with('user:id,name,name_ar', 'school:id,name_ar,name_en', 'jobTitle:id,name_ar,name_en'));
        if (! empty($params['school_id'])) {
            $employees->where('school_id', $params['school_id']);
        }
        $total = (clone $employees)->count();
        $people = $all ? $employees->orderBy('employee_no')->limit(self::MAX_ROWS)->get() : $employees->orderBy('employee_no')->forPage($page, $perPage)->get();

        $cols = [];
        $cells = [];
        if ($kind === 'programs') {
            $ids = (array) ($params['programs'] ?? []);
            if ($ids === []) {
                $ids = DB::table('registrations')->where('status', 'completed')->select('program_id', DB::raw('count(*) as n'))->groupBy('program_id')->orderByDesc('n')->limit(8)->pluck('program_id')->all();
            }
            $programs = DB::table('programs')->whereIn('id', $ids)->get(['id', 'code', 'title_ar', 'title_en']);
            $cols = $programs->map(fn ($p) => ['key' => 'p_'.substr(str_replace('-', '', $p->id), 0, 12), 'label' => ['ar' => $p->title_ar, 'en' => $p->title_en], 'id' => $p->id])->all();
            $rows = DB::table('registrations')->whereIn('employee_id', $people->pluck('id'))->whereIn('program_id', $programs->pluck('id'))->whereIn('status', ['approved', 'completed', 'pending'])->get(['employee_id', 'program_id', 'status']);
            foreach ($rows as $r) {
                $cells[$r->employee_id][$r->program_id] = $r->status === 'completed' ? '✔' : '…';
            }
        } else {
            $paths = DB::table('career_paths')->where('is_active', true)->orderBy('title_ar')->limit(10)->get(['id', 'title_ar', 'title_en']);
            $cols = $paths->map(fn ($p) => ['key' => 'l_'.substr(str_replace('-', '', $p->id), 0, 12), 'label' => ['ar' => $p->title_ar, 'en' => $p->title_en], 'id' => $p->id])->all();
            $rows = DB::table('professional_licences')->whereIn('employee_id', $people->pluck('id'))->get(['employee_id', 'path_id', 'level_no', 'status']);
            foreach ($rows as $r) {
                $cells[$r->employee_id][$r->path_id] = ($r->status === 'active' ? '✔ ' : '✖ ').$r->level_no;
            }
        }

        $out = [];
        foreach ($people as $e) {
            $row = ['employee_no' => $e->employee_no, 'name' => $e->user?->displayName(), 'school' => $e->school?->name_ar, 'job_title' => $e->jobTitle?->name_ar];
            foreach ($cols as $c) {
                $row[$c['key']] = $cells[$e->id][$c['id']] ?? '';
            }
            $out[] = $row;
        }
        $columns = array_merge([
            ['key' => 'employee_no', 'label' => ['ar' => 'الرقم الوظيفي', 'en' => 'Employee no.'], 'type' => 'string', 'format' => null],
            ['key' => 'name', 'label' => ['ar' => 'الاسم', 'en' => 'Name'], 'type' => 'string', 'format' => null],
            ['key' => 'school', 'label' => ['ar' => 'المدرسة', 'en' => 'School'], 'type' => 'string', 'format' => null],
            ['key' => 'job_title', 'label' => ['ar' => 'المسمى الوظيفي', 'en' => 'Job title'], 'type' => 'string', 'format' => null],
        ], array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'type' => 'string', 'format' => null], $cols));

        return ['columns' => $columns, 'rows' => $out, 'total' => $total, 'totals' => [], 'chart' => null, 'personal' => false];
    }

    /** @return list<array{key: string, field: string, aggregate: ?string, label: array<string, string>, type: string, format: ?string}> */
    private function columns(array $dataset, array $requested, User $user): array
    {
        if ($requested === []) {
            throw new BusinessRuleException('Choose at least one column.', 'no_columns');
        }
        $personalOk = $user->hasPermission('reports.export_personal');
        $out = [];
        $used = [];
        foreach ($requested as $c) {
            $field = (string) ($c['field'] ?? '');
            $fd = $dataset['fields'][$field] ?? null;
            if (! $fd || ! empty($fd['hidden'])) {
                throw new BusinessRuleException("Field {$field} is not available.", 'field_not_allowed');
            }
            if (! empty($fd['personal']) && ! $personalOk) {
                throw new BusinessRuleException("Field {$field} holds personal data.", 'personal_not_allowed');
            }
            $agg = $c['aggregate'] ?? null;
            if ($agg !== null && (! in_array($agg, ReportDatasetRegistry::AGGREGATES, true) || ! in_array($agg, $fd['aggregates'] ?? [], true))) {
                throw new BusinessRuleException("Aggregate {$agg} is not allowed on {$field}.", 'aggregate_not_allowed');
            }
            if ($agg === null && ! empty($fd['computed_only'])) {
                throw new BusinessRuleException("{$field} can only be counted or summed.", 'aggregate_required');
            }
            $key = $agg ? "{$field}__{$agg}" : $field;
            if (isset($used[$key])) {
                continue;
            }
            $used[$key] = true;
            $label = $c['label'] ?? null;
            if (is_string($label) && $label !== '') {
                $label = ['ar' => $label, 'en' => $label];
            }
            $out[] = ['key' => $key, 'field' => $field, 'aggregate' => $agg, 'label' => is_array($label) ? $label : $fd['label'], 'type' => $agg && in_array($agg, ['count', 'count_distinct', 'sum', 'avg'], true) ? 'number' : $fd['type'], 'format' => $c['format'] ?? null];
        }

        return $out;
    }

    /** "Mine" scopes some built-in reports need beyond the person's organisational scope. */
    private function optionScope(Builder $q, ?string $kind, User $user): void
    {
        $employeeId = $user->employee?->id ?? '00000000-0000-0000-0000-000000000000';
        $roles = $user->roles->pluck('slug')->all();
        match ($kind) {
            // A direct manager sees their own staff; school administrators and deputies see their whole school (their scope already says which).
            'staff' => array_intersect($roles, [Role::SCHOOL_ADMIN, Role::ACADEMIC_DEPUTY, Role::SUPER_ADMIN, Role::CENTER_ADMIN, Role::TRAINING_HEAD, Role::COORDINATOR]) === [] ? $q->whereRaw('e.supervisor_id = ?', [$employeeId]) : null,
            'my_registrations' => $q->whereExists(fn ($w) => $w->select(DB::raw(1))->from('registrations as rr')->whereColumn('rr.program_id', 'ps.program_id')->where('rr.employee_id', $employeeId)->whereIn('rr.status', ['approved', 'completed'])),
            'open' => $q->whereNotNull('g.published_at'),
            default => null,
        };
    }

    private function base(array $dataset, AccessScope $scope): Builder
    {
        $q = DB::table($dataset['table']);
        foreach ($dataset['joins'] ?? [] as [$table, $a, $b, $type]) {
            $type === 'left' ? $q->leftJoin($table, $a, '=', $b) : $q->join($table, $a, '=', $b);
        }
        ($dataset['scope'])($q, $scope);

        return $q;
    }

    private function selectExpr(string $expr, ?string $agg): string
    {
        return match ($agg) {
            null => $expr,
            'count' => "count({$expr})",
            'count_distinct' => "count(distinct {$expr})",
            'sum' => "coalesce(sum({$expr}), 0)",
            'avg' => "round(cast(avg({$expr}) as numeric), 1)",
            'min' => "min({$expr})",
            'max' => "max({$expr})",
        };
    }

    /** The definition's own filters, with adjustable ones replaced or added from the run's parameters. @return list<array{field: string, operator: string, value: mixed}> */
    private function filters(array $def, array $params, array $dataset, User $user): array
    {
        $out = [];
        $adjusted = collect($params['filters'] ?? [])->keyBy(fn ($f) => ($f['field'] ?? '').'|'.($f['operator'] ?? ''));
        foreach ((array) ($def['filters'] ?? []) as $f) {
            $key = ($f['field'] ?? '').'|'.($f['operator'] ?? '');
            if (! empty($f['adjustable']) && $adjusted->has($key)) {
                $f['value'] = $adjusted[$key]['value'] ?? $f['value'] ?? null;
                $adjusted->forget($key);
            }
            $out[] = $f;
        }
        // Anything else a person adds at run time must be a field they may use.
        foreach ($adjusted as $f) {
            $out[] = $f;
        }
        if (! empty($params['date_from']) || ! empty($params['date_to'])) {
            $df = $def['options']['date_field'] ?? $dataset['date_field'] ?? null;
            if ($df && isset($dataset['fields'][$df])) {
                if (! empty($params['date_from'])) {
                    $out[] = ['field' => $df, 'operator' => 'gte', 'value' => $params['date_from']];
                }
                if (! empty($params['date_to'])) {
                    $out[] = ['field' => $df, 'operator' => 'lte', 'value' => $params['date_to']];
                }
            }
        }

        $personalOk = $user->hasPermission('reports.export_personal');
        $clean = [];
        foreach ($out as $f) {
            // A filter a person has not filled in (an adjustable one left blank) does not narrow anything.
            $needsValue = ! in_array($f['operator'] ?? '', ['empty', 'not_empty', 'is_true', 'is_false'], true);
            if ($needsValue && (($f['value'] ?? null) === null || $f['value'] === '' || $f['value'] === [])) {
                continue;
            }
            $field = (string) ($f['field'] ?? '');
            $fd = $dataset['fields'][$field] ?? null;
            if (! $fd) {
                throw new BusinessRuleException("Field {$field} is not available.", 'field_not_allowed');
            }
            if (! empty($fd['personal']) && ! $personalOk) {
                throw new BusinessRuleException("Field {$field} holds personal data.", 'personal_not_allowed');
            }
            $op = (string) ($f['operator'] ?? '');
            if (! in_array($op, ReportDatasetRegistry::OPERATORS[$fd['type']], true)) {
                throw new BusinessRuleException("Operator {$op} is not allowed on {$field}.", 'operator_not_allowed');
            }
            $clean[] = ['field' => $field, 'operator' => $op, 'value' => $this->token($f['value'] ?? null, $user)];
        }

        return $clean;
    }

    private function token(mixed $value, User $user): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->token($v, $user), $value);
        }

        return match ($value) {
            '@me' => $user->id,
            '@my_employee' => $user->employee?->id ?? '00000000-0000-0000-0000-000000000000',
            '@my_trainer' => Trainer::where('user_id', $user->id)->value('id') ?? '00000000-0000-0000-0000-000000000000',
            '@today' => now()->toDateString(),
            default => $value,
        };
    }

    private function applyFilter(Builder $q, array $fd, array $f): void
    {
        $expr = $fd['expr'];
        $v = $f['value'];
        $type = $fd['type'];
        $like = fn ($s) => str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $s));
        $date = function ($s, bool $end = false) {
            $s = (string) $s;

            return strlen($s) === 10 ? $s.($end ? ' 23:59:59' : ' 00:00:00') : $s;
        };

        match ($f['operator']) {
            'eq' => $type === 'date' ? $q->where(fn ($w) => $w->whereRaw("{$expr} >= ?", [$date($v)])->whereRaw("{$expr} <= ?", [$date($v, true)])) : $q->whereRaw("{$expr} = ?", [$type === 'number' ? (float) $v : (string) $v]),
            'ne' => $q->whereRaw("{$expr} <> ?", [$type === 'number' ? (float) $v : (string) $v]),
            'contains' => $q->whereRaw("lower({$expr}) like ? escape '\\'", ['%'.$like($v).'%']),
            'starts' => $q->whereRaw("lower({$expr}) like ? escape '\\'", [$like($v).'%']),
            'in' => $q->whereRaw("{$expr} in (".implode(',', array_fill(0, max(1, count((array) $v)), '?')).')', array_slice(array_map('strval', (array) $v) ?: [''], 0, 100)),
            'empty' => $q->where(fn ($w) => $w->whereRaw("{$expr} is null")->orWhereRaw("{$expr} = ''")),
            'not_empty' => $q->whereRaw("{$expr} is not null")->whereRaw("{$expr} <> ''"),
            'gt' => $q->whereRaw("{$expr} > ?", [$type === 'date' ? $date($v, true) : (float) $v]),
            'gte' => $q->whereRaw("{$expr} >= ?", [$type === 'date' ? $date($v) : (float) $v]),
            'lt' => $q->whereRaw("{$expr} < ?", [$type === 'date' ? $date($v) : (float) $v]),
            'lte' => $q->whereRaw("{$expr} <= ?", [$type === 'date' ? $date($v, true) : (float) $v]),
            'between' => $q->whereRaw("{$expr} >= ?", [$type === 'date' ? $date($v[0] ?? '') : (float) ($v[0] ?? 0)])->whereRaw("{$expr} <= ?", [$type === 'date' ? $date($v[1] ?? '', true) : (float) ($v[1] ?? 0)]),
            'is_true' => $q->whereRaw("({$expr}) = ?", [true]),
            'is_false' => $q->where(fn ($w) => $w->whereRaw("({$expr}) = ?", [false])->orWhereRaw("{$expr} is null")),
        };
    }

    /** @return list<array{0: string, 1: string}> */
    private function sorts(array $sort, array $columns): array
    {
        $keys = array_column($columns, 'key');
        $out = [];
        foreach ($sort as $s) {
            $key = (string) ($s['field'] ?? '');
            if (in_array($key, $keys, true)) {
                $out[] = [$key, ($s['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
            }
        }

        return $out;
    }

    private function chart(?array $chart, array $columns, array $rows): ?array
    {
        if (! $chart || empty($chart['x']) || empty($chart['y'])) {
            return null;
        }
        $keys = array_column($columns, 'key');
        if (! in_array($chart['x'], $keys, true) || ! in_array($chart['y'], $keys, true)) {
            return null;
        }

        return ['type' => in_array($chart['type'] ?? 'bar', ['bar', 'line', 'donut'], true) ? $chart['type'] : 'bar', 'x' => $chart['x'], 'y' => $chart['y'],
            'points' => array_map(fn ($r) => ['label' => (string) ($r[$chart['x']] ?? ''), 'value' => (float) ($r[$chart['y']] ?? 0)], array_slice($rows, 0, 30))];
    }
}
