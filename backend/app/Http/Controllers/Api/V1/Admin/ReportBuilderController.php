<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Services\Reports\BuiltInReports;
use App\Services\Reports\ReportDatasetRegistry;
use App\Services\Reports\ReportRunner;
use App\Services\Reports\ReportRunService;
use App\Services\Reports\ReportScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The report hub and builder: datasets, definitions (built-in and custom), runs and downloads, favourites and schedules. */
class ReportBuilderController extends Controller
{
    public function __construct(private readonly ReportDatasetRegistry $datasets, private readonly ReportRunService $runs, private readonly BuiltInReports $builtIn) {}

    public function datasets(): JsonResponse
    {
        return response()->json(['data' => $this->datasets->describe($this->user()), 'meta' => ['aggregates' => ReportDatasetRegistry::AGGREGATES]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->builtIn->ensure();
        $user = $this->user();
        $favs = DB::table('report_favorites')->where('user_id', $user->id)->pluck('definition_id')->flip();
        $rows = $this->runs->visible($user)->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('q'), function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(title_ar) like ?', [$like])->orWhereRaw('lower(title_en) like ?', [$like]));
            })->orderBy('category')->orderBy('title_ar')->get();

        return response()->json(['data' => $rows->map(fn (ReportDefinition $d) => $this->present($d) + ['favorite' => $favs->has($d->id)])->values()]);
    }

    public function show(ReportDefinition $definition): JsonResponse
    {
        $this->runs->authorize($definition, $this->user());

        return response()->json(['data' => $this->present($definition, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $def = ReportDefinition::create($data + ['owner_id' => $this->user()->id, 'is_system' => false, 'key' => null]);

        return response()->json(['data' => $this->present($def, true)], 201);
    }

    public function update(Request $request, ReportDefinition $definition): JsonResponse
    {
        $this->assertOwn($definition);
        $definition->update($this->validated($request, true));

        return response()->json(['data' => $this->present($definition, true)]);
    }

    public function destroy(ReportDefinition $definition): JsonResponse
    {
        $this->assertOwn($definition);
        $definition->delete();

        return response()->json(null, 204);
    }

    /** A copy a person can change (also how a built-in report is customised). */
    public function copy(ReportDefinition $definition): JsonResponse
    {
        $this->runs->authorize($definition, $this->user());
        $copy = $definition->replicate(['key', 'is_system', 'owner_id']);
        $copy->forceFill(['key' => null, 'is_system' => false, 'owner_id' => $this->user()->id, 'visibility' => 'private', 'title_ar' => $definition->title_ar.' (نسخة)', 'title_en' => $definition->title_en.' (copy)'])->save();

        return response()->json(['data' => $this->present($copy, true)], 201);
    }

    public function favorite(ReportDefinition $definition): JsonResponse
    {
        $this->runs->authorize($definition, $this->user());
        $row = ['user_id' => $this->user()->id, 'definition_id' => $definition->id];
        $on = ! DB::table('report_favorites')->where($row)->exists();
        $on ? DB::table('report_favorites')->insert($row) : DB::table('report_favorites')->where($row)->delete();

        return response()->json(['data' => ['favorite' => $on]]);
    }

    /** On-screen result, a page at a time. Also used for the builder's preview of an unsaved definition. */
    public function preview(Request $request, ReportDefinition $definition): JsonResponse
    {
        $this->runs->authorize($definition, $this->user());
        $p = $request->validate(['params' => ['sometimes', 'array'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:5,200']]);

        return response()->json(['data' => $this->runs->preview($definition, $p['params'] ?? [], $this->user(), $p['page'] ?? 1, $p['per_page'] ?? 50)]);
    }

    public function previewDraft(Request $request, ReportRunner $runner): JsonResponse
    {
        $data = $this->validated($request);
        $params = $request->validate(['params' => ['sometimes', 'array']])['params'] ?? [];

        return response()->json(['data' => $runner->run($data, $params, $this->user(), 1, 25)]);
    }

    /** Starts a run that produces files (xlsx, pdf, docx). */
    public function run(Request $request, ReportDefinition $definition): JsonResponse
    {
        $this->runs->authorize($definition, $this->user());
        $d = $request->validate(['params' => ['sometimes', 'array'], 'formats' => ['sometimes', 'array'], 'formats.*' => [Rule::in(ReportRunService::FORMATS)], 'lang' => ['sometimes', Rule::in(['ar', 'en'])]]);
        $run = $this->runs->request($definition, $d['params'] ?? [], $this->user(), $d['formats'] ?? ['xlsx'], $d['lang'] ?? 'ar');

        return response()->json(['data' => $this->presentRun($run)], 201);
    }

    public function runs(Request $request): JsonResponse
    {
        return response()->json(ReportRun::with('definition:id,key,title_ar,title_en')->where('requested_by', $this->user()->id)->latest()->paginate($this->perPage($request)));
    }

    public function showRun(ReportRun $run): JsonResponse
    {
        $this->ownRun($run);

        return response()->json(['data' => $this->presentRun($run->load('definition:id,key,title_ar,title_en'))]);
    }

    public function download(Request $request, ReportRun $run)
    {
        $this->ownRun($run);
        $format = $request->validate(['format' => ['required', Rule::in(ReportRunService::FORMATS)]])['format'];
        $f = $this->runs->file($run, $format, $this->user());

        return response($f['content'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => "attachment; filename=\"{$f['filename']}\""]);
    }

    // ---- schedules ---------------------------------------------------------------------------------------

    public function schedules(): JsonResponse
    {
        return response()->json(['data' => ReportSchedule::with('definition:id,key,title_ar,title_en')->where('created_by', $this->user()->id)->orderBy('next_run_at')->get()]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $d = $this->validatedSchedule($request);
        $def = ReportDefinition::findOrFail($d['definition_id']);
        $this->runs->authorize($def, $this->user());
        $s = ReportSchedule::create($d + ['created_by' => $this->user()->id, 'next_run_at' => ReportScheduleService::nextRun($d['frequency']), 'is_active' => true]);

        return response()->json(['data' => $s->load('definition:id,key,title_ar,title_en')], 201);
    }

    public function updateSchedule(Request $request, ReportSchedule $schedule): JsonResponse
    {
        abort_unless($schedule->created_by === $this->user()->id, 403);
        $d = $this->validatedSchedule($request, true);
        $schedule->update($d + (isset($d['frequency']) ? ['next_run_at' => ReportScheduleService::nextRun($d['frequency'])] : []));

        return response()->json(['data' => $schedule]);
    }

    public function destroySchedule(ReportSchedule $schedule): JsonResponse
    {
        abort_unless($schedule->created_by === $this->user()->id, 403);
        $schedule->delete();

        return response()->json(null, 204);
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    private function assertOwn(ReportDefinition $d): void
    {
        if ($d->is_system || $d->owner_id !== $this->user()->id) {
            throw new BusinessRuleException('Built-in reports cannot be changed; make a copy.', 'read_only');
        }
    }

    private function ownRun(ReportRun $run): void
    {
        abort_unless($run->requested_by === $this->user()->id, 403);
    }

    private function present(ReportDefinition $d, bool $full = false): array
    {
        $base = ['id' => $d->id, 'key' => $d->key, 'category' => $d->category, 'title' => ['ar' => $d->title_ar, 'en' => $d->title_en], 'dataset' => $d->dataset, 'is_system' => $d->is_system, 'visibility' => $d->visibility, 'owner_id' => $d->owner_id,
            'kind' => $d->options['kind'] ?? (isset($d->options['parts']) ? 'composite' : 'table'), 'adjustable' => collect($d->filters ?? [])->where('adjustable', true)->values()->all()];

        return $full ? $base + ['columns' => $d->columns, 'filters' => $d->filters, 'group_by' => $d->group_by, 'sort' => $d->sort, 'chart' => $d->chart, 'options' => $d->options, 'roles' => $d->roles, 'description' => ['ar' => $d->description_ar, 'en' => $d->description_en]] : $base;
    }

    private function presentRun(ReportRun $r): array
    {
        return ['id' => $r->id, 'definition' => $r->definition ? ['id' => $r->definition->id, 'key' => $r->definition->key, 'title' => ['ar' => $r->definition->title_ar, 'en' => $r->definition->title_en]] : null, 'status' => $r->status, 'rows' => $r->rows_count,
            'formats' => array_keys($r->file_paths ?? []), 'requested_formats' => $r->formats, 'error' => $r->error, 'created_at' => $r->created_at?->toIso8601String(), 'expires_at' => $r->expires_at?->toIso8601String()];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title_ar' => [$req, 'string', 'max:255'], 'title_en' => [$req, 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:1000'], 'description_en' => ['nullable', 'string', 'max:1000'],
            'category' => ['sometimes', Rule::in(['admin', 'supervisor', 'trainer', 'manager', 'trainee', 'qa', 'kit', 'general'])],
            'dataset' => [$req, Rule::in(array_keys($this->datasets->all()))],
            'columns' => [$req, 'array', 'min:1', 'max:30'], 'columns.*.field' => ['required', 'string', 'max:64'], 'columns.*.aggregate' => ['nullable', Rule::in(ReportDatasetRegistry::AGGREGATES)],
            'columns.*.label' => ['nullable'], 'columns.*.format' => ['nullable', 'string', 'max:20'],
            'filters' => ['nullable', 'array', 'max:20'], 'filters.*.field' => ['required', 'string', 'max:64'], 'filters.*.operator' => ['required', 'string', 'max:20'], 'filters.*.value' => ['nullable'], 'filters.*.adjustable' => ['sometimes', 'boolean'],
            'group_by' => ['nullable', 'array', 'max:6'], 'group_by.*' => ['string', 'max:64'],
            'sort' => ['nullable', 'array', 'max:4'], 'sort.*.field' => ['required', 'string', 'max:80'], 'sort.*.dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            'chart' => ['nullable', 'array'], 'chart.type' => ['sometimes', Rule::in(['bar', 'line', 'donut'])], 'chart.x' => ['sometimes', 'string', 'max:80'], 'chart.y' => ['sometimes', 'string', 'max:80'],
            'visibility' => ['sometimes', Rule::in(['private', 'role', 'everyone'])], 'roles' => ['nullable', 'array'], 'roles.*' => ['string', 'max:48'],
        ]);
    }

    private function validatedSchedule(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'definition_id' => [$req, 'uuid', 'exists:report_definitions,id'], 'params' => ['nullable', 'array'], 'frequency' => [$req, Rule::in(['daily', 'weekly', 'monthly'])],
            'formats' => [$req, 'array', 'min:1'], 'formats.*' => [Rule::in(ReportRunService::FORMATS)], 'lang' => ['sometimes', Rule::in(['ar', 'en'])], 'is_active' => ['sometimes', 'boolean'],
            'recipients' => [$req, 'array'], 'recipients.users' => ['sometimes', 'array'], 'recipients.users.*' => ['uuid'], 'recipients.roles' => ['sometimes', 'array'], 'recipients.roles.*' => ['string', 'max:48'],
            'recipients.emails' => ['sometimes', 'array', 'max:20'], 'recipients.emails.*' => ['email'],
        ]);
    }
}
