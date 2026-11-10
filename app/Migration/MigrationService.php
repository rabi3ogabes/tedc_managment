<?php

namespace App\Migration;

use App\Exceptions\BusinessRuleException;
use App\Migration\Importers\Importer;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Brings legacy data in safely: upload (encrypted rows), map columns, cleanse and validate, rehearse in a dry run, import in transactional chunks,
 * reconcile against the source, and roll a whole batch back. Everything is audited and the uploaded data is deleted after a retention period.
 */
class MigrationService
{
    public const CHUNK = 200;

    public const MAX_ROWS = 50000;

    public const RETENTION_DAYS = 14;

    // ---- templates and upload ---------------------------------------------------------------------

    /** The header row of a template and one example row, in the language asked. @return array{head: list<string>, example: list<string>, keys: list<string>} */
    public function template(string $kind, string $lang = 'ar'): array
    {
        $fields = ImporterRegistry::get($kind)->fields();

        return ['keys' => array_keys($fields), 'head' => array_map(fn ($f) => $f['label'][$lang].(($f['required'] ?? false) ? ' *' : ''), $fields), 'example' => array_map(fn ($f) => (string) ($f['example'] ?? ''), $fields)];
    }

    public function upload(string $kind, UploadedFile $file, ?User $by): MigrationBatch
    {
        $importer = ImporterRegistry::get($kind);
        [$head, $rows] = $this->read($file);
        if (count($rows) > self::MAX_ROWS) {
            throw new BusinessRuleException('The file has too many rows; split it.', 'too_many_rows');
        }
        if ($rows === []) {
            throw new BusinessRuleException('The file has no data rows.', 'empty_file');
        }
        $batch = MigrationBatch::create([
            'kind' => $kind, 'filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'checksum' => hash_file('sha256', $file->getRealPath()), 'status' => 'uploaded', 'mapping' => $this->autoMap($importer, $head),
            'total_rows' => count($rows), 'created_by' => $by?->id, 'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ]);
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $insert = [];
            foreach ($chunk as $i => $row) {
                $insert[] = ['id' => (string) Str::uuid(), 'batch_id' => $batch->id, 'row_no' => $i + 2, 'data' => MigrationRow::seal(array_combine($head, array_pad($row, count($head), ''))), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
            }
            MigrationRow::insert($insert);
        }
        $this->audit('migration_uploaded', $batch, $by, ['kind' => $kind, 'rows' => $batch->total_rows, 'sha256' => $batch->checksum]);

        return $batch;
    }

    /** The columns found in the file (for the mapping screen). @return list<string> */
    public function columns(MigrationBatch $batch): array
    {
        $first = $batch->rows()->orderBy('row_no')->first();

        return $first ? array_keys($first->source()) : [];
    }

    /** @param  array<string, ?string>  $mapping  source column → target field @param  array<string, array<string, string>>  $valueMaps @param  array<string, string>  $defaults */
    public function setMapping(MigrationBatch $batch, array $mapping, array $valueMaps, array $defaults): MigrationBatch
    {
        $this->assertOpen($batch);
        $fields = array_keys(ImporterRegistry::get($batch->kind)->fields());
        $batch->update([
            'mapping' => array_filter($mapping, fn ($t) => in_array($t, $fields, true)), 'value_maps' => array_intersect_key($valueMaps, array_flip($fields)), 'defaults' => array_intersect_key($defaults, array_flip($fields)),
            'status' => 'uploaded', 'valid_rows' => 0, 'invalid_rows' => 0, 'duplicate_rows' => 0, 'report' => null,
        ]);
        MigrationRow::where('batch_id', $batch->id)->update(['status' => 'pending', 'mapped' => null, 'errors' => null, 'row_key' => null]);

        return $batch->refresh();
    }

    // ---- validation and dry run -------------------------------------------------------------------

    /** Cleanses and checks every row; marks repeats inside the file as duplicates. @return array<string, mixed> the report */
    public function validate(MigrationBatch $batch): array
    {
        $this->assertOpen($batch);
        $importer = ImporterRegistry::get($batch->kind);
        $seen = [];
        $stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0, 'will_create' => 0, 'will_update' => 0, 'problems' => []];

        $batch->rows()->orderBy('row_no')->chunkById(500, function ($rows) use ($importer, $batch, &$seen, &$stats) {
            foreach ($rows as $row) {
                $stats['total']++;
                [$clean, $errors] = $importer->clean($this->transform($batch, $row->source()));
                $key = $importer->key($clean);
                if ($key === null && ! array_filter($errors, fn ($e) => str_starts_with($e, 'missing:'))) {
                    $errors[] = 'missing:key';
                }
                $status = 'valid';
                if ($errors) {
                    $status = 'invalid';
                } elseif ($key !== null && isset($seen[$key])) {
                    $status = 'duplicate';
                    $errors = ["duplicate_of_row:{$seen[$key]}"];
                }
                if ($key !== null) {
                    $seen[$key] ??= $row->row_no;
                }
                $row->update(['mapped' => $clean, 'row_key' => $key ? mb_substr($key, 0, 190) : null, 'status' => $status, 'errors' => $errors ?: null]);
                $stats[$status]++;
                foreach ($errors as $e) {
                    $stats['problems'][$e] = ($stats['problems'][$e] ?? 0) + 1;
                }
                if ($status === 'valid') {
                    $importer->exists($clean) ? $stats['will_update']++ : $stats['will_create']++;
                }
            }
        }, 'id');

        arsort($stats['problems']);
        $batch->update(['valid_rows' => $stats['valid'], 'invalid_rows' => $stats['invalid'], 'duplicate_rows' => $stats['duplicate'], 'status' => 'validated', 'report' => ['validation' => $stats]]);

        return $stats;
    }

    /** Runs the import for real inside a transaction that is then rolled back: what would be created and updated, and what would fail. @return array<string, mixed> */
    public function dryRun(MigrationBatch $batch): array
    {
        $this->assertValidated($batch);
        $importer = ImporterRegistry::get($batch->kind);
        $out = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        DB::beginTransaction();
        try {
            foreach ($batch->rows()->where('status', 'valid')->orderBy('row_no')->get() as $row) {
                try {
                    DB::transaction(function () use ($importer, $row, &$out) {
                        $r = $importer->apply($row->mapped);
                        $out[$r['action']]++;
                    });
                } catch (Throwable $e) {
                    $out['failed']++;
                    if (count($out['errors']) < 20) {
                        $out['errors'][] = ['row' => $row->row_no, 'error' => mb_substr($e->getMessage(), 0, 160)];
                    }
                }
            }
        } finally {
            DB::rollBack();
        }
        $report = $batch->report ?? [];
        $report['dry_run'] = $out + ['at' => now()->toIso8601String()];
        $batch->update(['report' => $report]);

        return $out;
    }

    // ---- import, reconciliation, rollback ----------------------------------------------------------

    /** @return array<string, mixed> the reconciliation report */
    public function import(MigrationBatch $batch, ?User $by): array
    {
        $this->assertValidated($batch);
        if ($batch->status === 'imported') {
            throw new BusinessRuleException('This batch was already imported.', 'already_imported');
        }
        $importer = ImporterRegistry::get($batch->kind);
        $failed = [];
        $batch->rows()->where('status', 'valid')->orderBy('row_no')->chunkById(self::CHUNK, function ($rows) use ($importer, &$failed) {
            // A chunk is one transaction; a row that fails is isolated and reported without losing the rest of the chunk.
            DB::transaction(function () use ($rows, $importer, &$failed) {
                foreach ($rows as $row) {
                    try {
                        DB::transaction(function () use ($row, $importer) {
                            $r = $importer->apply($row->mapped);
                            $row->update(['status' => 'imported', 'action' => $r['action'], 'target_id' => $r['id'], 'before' => $r['before'], 'created_ids' => $r['created']]);
                        });
                    } catch (Throwable $e) {
                        $row->update(['status' => 'invalid', 'errors' => ['import_failed:'.mb_substr($e->getMessage(), 0, 120)]]);
                        $failed[] = $row->row_no;
                    }
                }
            });
        }, 'id');

        $report = $this->reconcile($batch);
        $batch->update(['status' => 'imported', 'imported_at' => now(), 'created_rows' => $report['imported_created'], 'updated_rows' => $report['imported_updated'], 'invalid_rows' => $report['invalid'],
            'report' => array_merge($batch->report ?? [], ['reconciliation' => $report])]);
        $this->audit('migration_imported', $batch, $by, ['created' => $report['imported_created'], 'updated' => $report['imported_updated'], 'failed' => count($failed)]);

        return $report;
    }

    /** Counts and checksum: every source row is accounted for, and the keys that went in match the keys that were meant to. @return array<string, mixed> */
    public function reconcile(MigrationBatch $batch): array
    {
        $count = fn (string $status) => $batch->rows()->where('status', $status)->count();
        $imported = $batch->rows()->where('status', 'imported');
        $keysIn = (clone $imported)->orderBy('row_key')->pluck('row_key')->all();
        $expected = $batch->rows()->whereIn('status', ['valid', 'imported'])->orderBy('row_key')->pluck('row_key')->all();

        return [
            'source_rows' => $batch->total_rows, 'valid' => count($expected), 'invalid' => $count('invalid'), 'duplicate' => $count('duplicate'), 'pending' => $count('pending'), 'imported' => count($keysIn),
            'imported_created' => (clone $imported)->where('action', 'created')->count(), 'imported_updated' => (clone $imported)->where('action', 'updated')->count(),
            'accounted_for' => $count('invalid') + $count('duplicate') + count($expected) === $batch->total_rows,
            'checksum_expected' => hash('sha256', implode("\n", $expected)), 'checksum_imported' => hash('sha256', implode("\n", $keysIn)), 'matches' => $expected === $keysIn,
            'target_rows' => $this->targetCount($batch->kind),
        ];
    }

    /** Puts everything a batch changed back: created records are removed, updated ones restored. @return array{removed: int, restored: int} */
    public function rollback(MigrationBatch $batch, ?User $by): array
    {
        if ($batch->status !== 'imported') {
            throw new BusinessRuleException('Only an imported batch can be rolled back.', 'not_imported');
        }
        $out = ['removed' => 0, 'restored' => 0];
        DB::transaction(function () use ($batch, &$out) {
            $batch->rows()->where('status', 'imported')->orderByDesc('row_no')->get()->each(function (MigrationRow $row) use (&$out) {
                foreach ((array) $row->created_ids as $c) {
                    // Records created by the import go, newest first; anything else that now points at them would stop the delete, which is the right outcome.
                    $out['removed'] += DB::table($c['table'])->where('id', $c['id'])->delete();
                }
                if ($row->action === 'updated' && $row->before) {
                    $this->restore($row);
                    $out['restored']++;
                }
                $row->update(['status' => 'skipped', 'errors' => ['rolled_back']]);
            });
        });
        $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
        $this->audit('migration_rolled_back', $batch, $by, $out);

        return $out;
    }

    /** Deletes the uploaded data of batches past their retention (the audit trail and counts stay). */
    public function purgeExpired(): int
    {
        $n = 0;
        MigrationBatch::where('expires_at', '<', now())->whereHas('rows')->get()->each(function (MigrationBatch $b) use (&$n) {
            MigrationRow::where('batch_id', $b->id)->delete();
            $n++;
        });

        return $n;
    }

    // ---- internals ---------------------------------------------------------------------------------

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
            $all = [];
            foreach ($sheet->toArray(null, true, true, false) as $r) {
                $all[] = array_map(fn ($c) => is_scalar($c) || $c === null ? trim((string) $c) : '', $r);
            }
        } else {
            $raw = (string) file_get_contents($file->getRealPath());
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
            if (! mb_check_encoding($raw, 'UTF-8')) {
                $raw = (string) (@iconv('CP1256', 'UTF-8//IGNORE', $raw) ?: $raw);   // Arabic exports from older Excel
            }
            $delimiter = substr_count((string) strtok($raw, "\n"), ';') > substr_count((string) strtok($raw, "\n"), ',') ? ';' : ',';
            $h = fopen('php://temp', 'r+');
            fwrite($h, $raw);
            rewind($h);
            $all = [];
            while (($line = fgetcsv($h, 0, $delimiter)) !== false) {
                $all[] = array_map(fn ($c) => trim((string) $c), $line);
            }
            fclose($h);
        }
        $all = array_values(array_filter($all, fn ($r) => array_filter($r, fn ($c) => $c !== '') !== []));
        $head = array_map(fn ($h, $i) => $h !== '' ? $h : 'column_'.($i + 1), $all[0] ?? [], array_keys($all[0] ?? []));
        // The example row of a downloaded template (and the asterisk on required columns) are ignored.
        $head = array_map(fn ($h) => trim(rtrim($h, '* ')), $head);

        return [$head, array_slice($all, 1)];
    }

    /** @param  list<string>  $head  @return array<string, string> */
    private function autoMap(Importer $importer, array $head): array
    {
        $norm = fn (string $s) => preg_replace('/[\s_\-.*]+/u', '', Cleanser::arabic(mb_strtolower($s))) ?? '';
        $index = [];
        foreach ($importer->fields() as $key => $f) {
            foreach (array_merge([$key, $f['label']['ar'], $f['label']['en']], $f['aliases'] ?? []) as $name) {
                $index[$norm($name)] ??= $key;
            }
        }
        $map = [];
        foreach ($head as $h) {
            if (isset($index[$norm($h)])) {
                $map[$h] = $index[$norm($h)];
            }
        }

        return $map;
    }

    /** Applies the mapping, the value maps and the defaults to a source row. @param  array<string, mixed>  $source @return array<string, mixed> */
    private function transform(MigrationBatch $batch, array $source): array
    {
        $out = [];
        foreach ((array) $batch->mapping as $col => $field) {
            $out[$field] = $source[$col] ?? null;
        }
        foreach ((array) $batch->value_maps as $field => $map) {
            $v = trim((string) ($out[$field] ?? ''));
            foreach ($map as $from => $to) {
                if (Cleanser::arabic((string) $from) === Cleanser::arabic($v) || strcasecmp(trim((string) $from), $v) === 0) {
                    $out[$field] = $to;
                    break;
                }
            }
        }
        foreach ((array) $batch->defaults as $field => $value) {
            if (! isset($out[$field]) || trim((string) $out[$field]) === '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    private function restore(MigrationRow $row): void
    {
        $before = (array) $row->before;
        $tables = ['employees' => 'employees', 'trainers' => 'trainers', 'programs' => 'programs', 'registrations' => 'registrations', 'attendance' => 'registrations', 'certificates' => 'certificates', 'pd' => 'pd_activities'];
        $kind = $row->batch->kind;
        if ($kind === 'employees') {
            $employee = Employee::find($row->target_id);
            $employee?->forceFill($before['employee'] ?? [])->save();
            if ($employee?->user && ! empty($before['user'])) {
                $employee->user->forceFill($before['user'])->save();
            }

            return;
        }
        $table = $tables[$kind] ?? null;
        if ($table) {
            DB::table($table)->where('id', $row->target_id)->update(array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $before));
        }
    }

    private function targetCount(string $kind): int
    {
        return (int) DB::table(['employees' => 'employees', 'trainers' => 'trainers', 'programs' => 'programs', 'registrations' => 'registrations', 'attendance' => 'registrations', 'certificates' => 'certificates', 'pd' => 'pd_activities'][$kind])->count();
    }

    private function assertOpen(MigrationBatch $batch): void
    {
        if (in_array($batch->status, ['imported', 'rolled_back'], true)) {
            throw new BusinessRuleException('This batch is closed.', 'batch_closed');
        }
        if (! $batch->rows()->exists()) {
            throw new BusinessRuleException('The uploaded data has been deleted after its retention period.', 'data_purged');
        }
    }

    private function assertValidated(MigrationBatch $batch): void
    {
        if (! in_array($batch->status, ['validated', 'imported'], true)) {
            throw new BusinessRuleException('Validate the batch first.', 'not_validated');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function audit(string $action, MigrationBatch $batch, ?User $by, array $data): void
    {
        AuditLog::create(['user_id' => $by?->id, 'action' => $action, 'auditable_type' => MigrationBatch::class, 'auditable_id' => $batch->id, 'new_values' => $data, 'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 250), 'url' => mb_substr((string) request()->fullUrl(), 0, 250)]);
    }
}
