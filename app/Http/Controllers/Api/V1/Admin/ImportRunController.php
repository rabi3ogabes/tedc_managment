<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ImportRun;
use App\Models\Role;
use App\Services\FileStorage;
use App\Services\Import\TraineeHistoryImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Settings → Data: importing the Ministry's trainee extract from the browser. The workbooks go straight to private storage (signed
 * uploads), then the run advances one step per request — each small enough for serverless hosting, each repeatable — and ends with
 * the reconciled report. The uploaded files are deleted when the run ends. Super administrators only.
 */
class ImportRunController extends Controller
{
    private const BUCKET = 'documents';

    /** A step that has said nothing for this long was cut off (the request timed out): another request may take over. */
    private const STALE_SECONDS = 330;

    public function __construct(private readonly FileStorage $storage, private readonly TraineeHistoryImport $import) {}

    public function index(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => ImportRun::with('creator:id,name,name_ar')->latest()->limit(20)->get()->map(fn (ImportRun $r) => $this->present($r))]);
    }

    public function show(ImportRun $importRun): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => $this->present($importRun->load('creator:id,name,name_ar'))]);
    }

    /** A new run: where each workbook goes (signed uploads straight to storage). */
    public function store(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*.name' => ['required', 'string', 'max:255', 'regex:/\.xlsx$/i'],
            'files.*.size' => ['required', 'integer', 'min:1', 'max:'.(50 * 1024 * 1024)],
        ]);
        abort_if(ImportRun::whereIn('status', ['ready', 'running'])->where('updated_at', '>', now()->subMinutes(30))->exists(), 409, __('messages.imports.busy'));
        $run = ImportRun::create(['kind' => 'trainee_history', 'status' => 'uploading', 'files' => [], 'created_by' => $this->user()->id]);
        $files = [];
        $uploads = [];
        foreach ($data['files'] as $i => $f) {
            $path = "imports/{$run->id}/".($i + 1).'.xlsx';
            $files[] = ['name' => basename((string) $f['name']), 'size' => (int) $f['size'], 'path' => $path];
            $uploads[] = $this->storage->signedUpload(self::BUCKET, $path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $run->update(['files' => $files]);

        return response()->json(['data' => $this->present($run) + ['uploads' => $uploads]], 201);
    }

    /** The browser has sent the files: check they are all there and lay out the steps. */
    public function uploaded(ImportRun $importRun): JsonResponse
    {
        $this->guard();
        abort_unless($importRun->status === 'uploading', 409);
        foreach ($importRun->files as $f) {
            abort_unless($this->storage->exists(self::BUCKET, $f['path']), 422, __('messages.imports.missing_file', ['name' => $f['name']]));
        }
        $plan = array_map(fn ($s) => $s + ['done' => false, 'seconds' => null], TraineeHistoryImport::plan(count($importRun->files)));
        $importRun->update(['status' => 'ready', 'plan' => $plan, 'stats' => [], 'issues' => [], 'error' => null]);

        return response()->json(['data' => $this->present($importRun)]);
    }

    /** Runs the next step. A step that failed is tried again by calling this once more. */
    public function step(ImportRun $importRun): JsonResponse
    {
        $this->guard();
        @set_time_limit(320);
        abort_if(in_array($importRun->status, ['uploading', 'done'], true), 409);
        // One step at a time: claim the run (a step cut off by a timeout is taken over after a while).
        $claimed = ImportRun::whereKey($importRun->id)->where(fn ($q) => $q->where('status', '!=', 'running')->orWhere('updated_at', '<', now()->subSeconds(self::STALE_SECONDS)))
            ->update(['status' => 'running', 'updated_at' => now(), 'started_at' => $importRun->started_at ?? now()]);
        abort_if($claimed === 0, 409, __('messages.imports.step_running'));
        $run = $importRun->fresh();
        $plan = $run->plan;
        $index = collect($plan)->search(fn ($s) => ! $s['done']);
        if ($index === false) {
            return response()->json(['data' => $this->present($this->finish($run))]);
        }
        $s = $plan[$index];
        $t = microtime(true);
        try {
            $result = $this->import->step($this->localFiles($run), $s['step'], $s['part'], $s['parts']);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            return response()->json(['data' => $this->present($run)], 422);
        }
        $stats = $run->stats ?? [];
        foreach ($result['stats'] as $k => $v) {
            $stats[$k] = ($stats[$k] ?? 0) + $v;
        }
        if ($s['step'] === 'setup') {
            $stats['unmapped_nationalities'] = $result['unmapped_nationalities'];
        }
        $plan[$index] = ['done' => true, 'seconds' => round(microtime(true) - $t, 1)] + $s;
        $run->update(['plan' => $plan, 'stats' => $stats, 'issues' => array_slice(array_merge($run->issues ?? [], $result['issues']), 0, 100), 'status' => 'ready', 'error' => null]);
        if (collect($plan)->every(fn ($p) => $p['done'])) {
            $run = $this->finish($run);
        }

        return response()->json(['data' => $this->present($run)]);
    }

    /** The reconciled report; the uploaded workbooks (personal data) are deleted. */
    private function finish(ImportRun $run): ImportRun
    {
        $st = $run->stats ?? [];
        $handled = ($st['rejected_rows'] ?? 0) + ($st['activities_imported'] ?? 0) + ($st['activities_existing'] ?? 0) + ($st['activities_rejected'] ?? 0);
        $report = $st + [
            'files' => count($run->files),
            'seconds' => round(collect($run->plan)->sum('seconds'), 1),
            'reconciled' => ($st['source_rows'] ?? 0) === $handled,
            'hours_match' => abs(($st['source_hours'] ?? 0) - ($st['imported_hours'] ?? 0)) < 0.01 || ($st['activities_existing'] ?? 0) > 0,
            'issues' => count($run->issues ?? []),
        ];
        foreach ($run->files as $f) {
            try {
                $this->storage->delete(self::BUCKET, $f['path']);
            } catch (Throwable) {
                // a file already gone is fine
            }
        }
        $this->cleanLocal($run);
        $run->update(['status' => 'done', 'report' => $report, 'finished_at' => now()]);
        AuditLog::create(['user_id' => $run->created_by, 'action' => 'trainee_history_imported', 'auditable_type' => Employee::class, 'auditable_id' => null,
            'new_values' => array_diff_key($report, ['issues' => 1]) + ['run' => $run->id]]);

        return $run;
    }

    /** The workbooks on this server's disk (serverless instances keep /tmp only for a while: fetched again when missing). @return list<string> */
    private function localFiles(ImportRun $run): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tedc-import-'.$run->id;
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $paths = [];
        foreach ($run->files as $i => $f) {
            $local = $dir.DIRECTORY_SEPARATOR.($i + 1).'.xlsx';
            if (! is_file($local) || filesize($local) !== (int) $f['size']) {
                file_put_contents($local, $this->storage->get(self::BUCKET, $f['path']));
            }
            $paths[$i] = $local;
        }

        return $paths;
    }

    private function cleanLocal(ImportRun $run): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tedc-import-'.$run->id;
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function present(ImportRun $r): array
    {
        $plan = $r->plan ?? [];

        return [
            'id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'files' => array_map(fn ($f) => ['name' => $f['name'], 'size' => $f['size']], $r->files ?? []),
            'steps' => array_map(fn ($s) => ['step' => $s['step'], 'part' => $s['part'], 'parts' => $s['parts'], 'done' => $s['done'], 'seconds' => $s['seconds']], $plan),
            'done_steps' => count(array_filter($plan, fn ($s) => $s['done'])), 'total_steps' => count($plan),
            'stats' => $r->stats ?? [], 'issues' => $r->issues ?? [], 'report' => $r->report, 'error' => $r->error,
            'by' => $r->creator ? ($r->creator->name_ar ?: $r->creator->name) : null,
            'created_at' => $r->created_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }

    private function guard(): void
    {
        abort_unless($this->user()->hasRole(Role::SUPER_ADMIN), 403, __('messages.imports.super_admin_only'));
    }
}
