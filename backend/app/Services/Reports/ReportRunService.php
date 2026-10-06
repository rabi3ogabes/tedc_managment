<?php

namespace App\Services\Reports;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\Role;
use App\Models\User;
use App\Services\FileStorage;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Runs report definitions: on screen (paged), or as Excel / PDF / Word files — at once when small, from the minute job when large. */
class ReportRunService
{
    /** Up to this many rows are exported while the person waits. */
    public const INLINE_ROWS = 2000;

    public const FORMATS = ['xlsx', 'pdf', 'docx'];

    public function __construct(private readonly ReportRunner $runner, private readonly ReportExporter $exporter, private readonly FileStorage $storage, private readonly NotificationService $notifications, private readonly BuiltInReports $builtIn) {}

    /** Definitions the person may use: their own, shared ones, and system reports meant for one of their roles. @return Builder<ReportDefinition> */
    public function visible(User $user): Builder
    {
        $roles = $user->roles->pluck('slug')->all();
        $admin = array_intersect($roles, [Role::SUPER_ADMIN, Role::CENTER_ADMIN]) !== [];

        return ReportDefinition::query()->when(! $admin, function ($q) use ($user, $roles) {
            $q->where(function ($w) use ($user, $roles) {
                $w->where('owner_id', $user->id)->orWhere('visibility', 'everyone')
                    ->orWhere(function ($r) use ($roles) {
                        $r->where('visibility', 'role')->where(function ($x) use ($roles) {
                            $x->whereNull('roles')->orWhereJsonLength('roles', 0);
                            foreach ($roles as $slug) {
                                $x->orWhereJsonContains('roles', $slug);
                            }
                        });
                    });
            });
        });
    }

    public function authorize(ReportDefinition $def, User $user): void
    {
        abort_unless($this->visible($user)->whereKey($def->id)->exists(), 403);
    }

    /** One screenful of a report, with the chart. @param  array<string, mixed>  $params @return array<string, mixed> */
    public function preview(ReportDefinition $def, array $params, User $user, int $page, int $perPage): array
    {
        $options = $def->options ?? [];
        $arr = $def->toArray();
        if (($options['kind'] ?? null) === 'matrix') {
            return $this->runner->matrix($arr, $params, $user, $page, $perPage);
        }
        if (! empty($options['parts'])) {
            $first = $options['parts'][0] + ['filters' => []];

            return $this->runner->run($first, $params, $user, $page, $perPage) + ['parts' => count($options['parts'])];
        }

        return $this->runner->run($arr, $params, $user, $page, $perPage);
    }

    /** Creates a run; small ones are produced now, large ones wait for the minute job. */
    public function request(ReportDefinition $def, array $params, User $user, array $formats, string $lang, ?string $scheduleId = null): ReportRun
    {
        $formats = array_values(array_intersect($formats ?: ['xlsx'], self::FORMATS)) ?: ['xlsx'];
        $run = ReportRun::create(['definition_id' => $def->id, 'params' => $params, 'formats' => $formats, 'status' => 'queued', 'requested_by' => $user->id, 'schedule_id' => $scheduleId, 'lang' => $lang === 'en' ? 'en' : 'ar']);

        $count = $this->preview($def, $params, $user, 1, 1)['total'] ?? 0;
        if ($count <= (int) config('tedc.reports.inline_rows', self::INLINE_ROWS)) {
            $this->execute($run);
        }

        return $run->refresh();
    }

    /** Builds the files of a queued run. */
    public function execute(ReportRun $run): ReportRun
    {
        $run->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        try {
            $def = $run->definition;
            $user = User::with('roles')->findOrFail($run->requested_by);
            $lang = $run->lang;
            $doc = $this->document($def, (array) $run->params, $user, $lang, $rows, $personal);
            $paths = [];
            foreach ($run->formats ?? ['xlsx'] as $format) {
                $path = "reports/{$run->id}/report.{$format}";
                $this->storage->put('documents', $path, $this->exporter->render($doc, $format, $lang), ReportExporter::MIME[$format]);
                $paths[$format] = $path;
            }
            $run->update(['status' => 'ready', 'rows_count' => $rows, 'file_paths' => $paths, 'personal' => $personal, 'finished_at' => now(), 'expires_at' => now()->addDays(7)]);
            if ($run->schedule_id === null && $rows > (int) config('tedc.reports.inline_rows', self::INLINE_ROWS)) {
                $this->notifications->send($user, 'report.ready', ['ar' => 'التقرير جاهز', 'en' => 'Your report is ready'], ['ar' => $def->title_ar, 'en' => $def->title_en], ['route' => '/admin/reports?run='.$run->id], raw: true);
            }
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
        }

        return $run->refresh();
    }

    /** Processes waiting runs (the minute job). */
    public function processQueue(int $limit = 5): int
    {
        $n = 0;
        ReportRun::where('status', 'queued')->orderBy('created_at')->limit($limit)->get()->each(function (ReportRun $r) use (&$n) {
            $this->execute($r);
            $n++;
        });

        return $n;
    }

    /** The bytes of one format; downloads of reports holding personal data are written to the audit log. @return array{content: string, mime: string, filename: string} */
    public function file(ReportRun $run, string $format, ?User $by): array
    {
        if ($run->status !== 'ready' || empty($run->file_paths[$format] ?? null)) {
            throw new BusinessRuleException('The file is not ready.', 'not_ready');
        }
        if ($run->expires_at && $run->expires_at->isPast()) {
            throw new BusinessRuleException('This download has expired; run the report again.', 'expired');
        }
        if ($run->personal) {
            AuditLog::create(['user_id' => $by?->id, 'action' => 'report.export_personal', 'auditable_type' => ReportRun::class, 'auditable_id' => $run->id,
                'new_values' => ['definition' => $run->definition_id, 'format' => $format, 'rows' => $run->rows_count], 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 250), 'url' => mb_substr((string) request()->fullUrl(), 0, 250)]);
        }
        $name = ($run->definition->key ?: 'report').'-'.$run->created_at->format('Ymd');

        return ['content' => $this->storage->get('documents', $run->file_paths[$format]), 'mime' => ReportExporter::MIME[$format], 'filename' => "{$name}.{$format}"];
    }

    /**
     * The exportable document: a title, then a table per part (with totals and a chart), in the language chosen.
     *
     * @param  array<string, mixed>  $params
     */
    public function document(ReportDefinition $def, array $params, User $user, string $lang, ?int &$rows = 0, ?bool &$personal = false): array
    {
        $ar = $lang === 'ar';
        $options = $def->options ?? [];
        $parts = [];
        if (($options['kind'] ?? null) === 'matrix') {
            $parts[] = [['ar' => $def->title_ar, 'en' => $def->title_en], $this->runner->matrix($def->toArray(), $params, $user, all: true)];
        } elseif (! empty($options['parts'])) {
            foreach ($options['parts'] as $p) {
                $parts[] = [['ar' => $p['title'][0], 'en' => $p['title'][1]], $this->runner->run($p + ['filters' => [], 'chart' => ['type' => 'bar', 'x' => $p['columns'][0]['field'], 'y' => ($p['columns'][1]['field'] ?? '').(isset($p['columns'][1]['aggregate']) ? '__'.$p['columns'][1]['aggregate'] : '')]], $params, $user, all: true)];
            }
        } else {
            $parts[] = [null, $this->runner->run($def->toArray(), $params, $user, all: true)];
        }

        $sections = [];
        $rows = 0;
        $personal = false;
        foreach ($parts as [$title, $result]) {
            $rows += count($result['rows']);
            $personal = $personal || ! empty($result['personal']);
            $head = array_map(fn ($c) => $c['label'][$lang] ?? $c['label']['ar'], $result['columns']);
            $body = array_map(fn ($r) => array_map(fn ($c) => $this->cell($r[$c['key']] ?? null, $c, $ar), $result['columns']), $result['rows']);
            if (! empty($result['totals'])) {
                $body[] = array_map(fn ($c, $i) => $i === 0 ? ($ar ? 'الإجمالي' : 'Total') : (isset($result['totals'][$c['key']]) ? round($result['totals'][$c['key']], 1) : ''), $result['columns'], array_keys($result['columns']));
            }
            if (($options['kind'] ?? null) === 'sheet') {
                $blank = (int) ($options['blank_columns'] ?? 6);
                for ($i = 1; $i <= $blank; $i++) {
                    $head[] = ($ar ? 'يوم ' : 'Day ').$i;
                }
                $body = array_map(fn ($r) => array_merge($r, array_fill(0, $blank, '')), $body);
            }
            $section = ['heading' => $title ? ($title[$lang] ?? $title['ar']) : null, 'table' => ['head' => $head, 'rows' => $body]];
            if (! empty($result['chart']['points'])) {
                $max = max(1, ...array_map(fn ($p) => $p['value'], $result['chart']['points']));
                $section['bars'] = array_map(fn ($p) => ['label' => $p['label'], 'value' => round($p['value'], 1), 'max' => $max], $result['chart']['points']);
            }
            $sections[] = $section;
        }
        if ($rows === 0) {
            $sections[] = ['paragraphs' => [$ar ? 'لا توجد بيانات تطابق الاختيار.' : 'No data matches the selection.']];
        }

        return [
            'title' => $lang === 'ar' ? $def->title_ar : $def->title_en,
            'subtitle' => ($ar ? 'أُعدّ في ' : 'Generated ').now()->format('Y-m-d H:i').' · '.$user->displayName(),
            'sections' => $sections,
        ];
    }

    private function cell(mixed $v, array $col, bool $ar): mixed
    {
        if ($v === null) {
            return '';
        }
        if ($col['type'] === 'bool') {
            return $v ? '✔' : '—';
        }
        if ($col['type'] === 'date') {
            return substr((string) $v, 0, strlen((string) $v) > 10 ? 16 : 10);
        }

        return $v;
    }
}
