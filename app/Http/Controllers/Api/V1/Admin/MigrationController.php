<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Migration\ImporterRegistry;
use App\Migration\MigrationService;
use App\Models\MigrationBatch;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The data-migration toolkit: templates, upload, mapping, validation, dry run, import, reconciliation, rollback. Restricted to `migration.run`. */
class MigrationController extends Controller
{
    public function __construct(private readonly MigrationService $svc) {}

    public function kinds(): JsonResponse
    {
        return response()->json(['data' => collect(ImporterRegistry::ORDER)->map(fn ($k) => ['kind' => $k, 'fields' => collect(ImporterRegistry::get($k)->fields())->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'required' => (bool) ($f['required'] ?? false)])->values()])->values()]);
    }

    public function template(Request $request, string $kind, ReportExporter $exporter): Response
    {
        $d = $request->validate(['format' => ['nullable', Rule::in(['csv', 'xlsx'])], 'lang' => ['nullable', Rule::in(['ar', 'en'])]]);
        $t = $this->svc->template($kind, $d['lang'] ?? 'ar');
        if (($d['format'] ?? 'csv') === 'xlsx') {
            $doc = ['title' => $kind, 'sections' => [['heading' => $kind, 'table' => ['head' => $t['head'], 'rows' => [$t['example']]]]]];

            return response($exporter->render($doc, 'xlsx', $d['lang'] ?? 'ar'), 200, ['Content-Type' => ReportExporter::MIME['xlsx'], 'Content-Disposition' => "attachment; filename=\"template-{$kind}.xlsx\""]);
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $t['head']);
        fputcsv($out, $t['example']);
        rewind($out);

        return response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"template-{$kind}.csv\""]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(MigrationBatch::latest()->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(ImporterRegistry::ORDER)], 'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls']]);
        $batch = $this->svc->upload($d['kind'], $request->file('file'), $this->user());

        return response()->json(['data' => $this->present($batch) + ['columns' => $this->svc->columns($batch)]], 201);
    }

    public function show(MigrationBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->present($batch) + ['columns' => $this->svc->columns($batch)]]);
    }

    public function mapping(Request $request, MigrationBatch $batch): JsonResponse
    {
        $d = $request->validate(['mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'string', 'max:40'], 'value_maps' => ['sometimes', 'array'], 'defaults' => ['sometimes', 'array']]);

        return response()->json(['data' => $this->present($this->svc->setMapping($batch, $d['mapping'], $d['value_maps'] ?? [], $d['defaults'] ?? []))]);
    }

    public function validateBatch(MigrationBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->svc->validate($batch), 'batch' => $this->present($batch->refresh())]);
    }

    public function dryRun(MigrationBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->svc->dryRun($batch)]);
    }

    public function import(MigrationBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->svc->import($batch, $this->user()), 'batch' => $this->present($batch->refresh())]);
    }

    public function rollback(MigrationBatch $batch): JsonResponse
    {
        return response()->json(['data' => $this->svc->rollback($batch, $this->user()), 'batch' => $this->present($batch->refresh())]);
    }

    public function rows(Request $request, MigrationBatch $batch): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['pending', 'valid', 'invalid', 'duplicate', 'imported', 'skipped'])]]);
        $page = $batch->rows()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderBy('row_no')->paginate($this->perPage($request, 50));
        $page->setCollection($page->getCollection()->map(fn ($r) => ['row_no' => $r->row_no, 'status' => $r->status, 'errors' => $r->errors, 'action' => $r->action, 'key' => $r->row_key, 'source' => $r->source(), 'mapped' => $r->mapped]));

        return response()->json($page);
    }

    /** The rows that failed, as a CSV to correct and upload again. */
    public function errors(MigrationBatch $batch): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $head = null;
        foreach ($batch->rows()->whereIn('status', ['invalid', 'duplicate'])->orderBy('row_no')->get() as $r) {
            $src = $r->source();
            if ($head === null) {
                $head = array_keys($src);
                fputcsv($out, array_merge(['row', 'problems'], $head));
            }
            fputcsv($out, array_merge([$r->row_no, implode('; ', (array) $r->errors)], array_map(fn ($h) => $src[$h] ?? '', $head)));
        }
        rewind($out);

        return response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="migration-errors.csv"']);
    }

    private function present(MigrationBatch $b): array
    {
        return $b->only(['id', 'kind', 'filename', 'checksum', 'status', 'mapping', 'value_maps', 'defaults', 'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'created_rows', 'updated_rows', 'report', 'imported_at', 'rolled_back_at', 'expires_at', 'created_at']);
    }
}
