<?php

namespace App\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Masks production data for the rare investigation that needs a copy: Restricted columns are dropped, Confidential text is replaced by stable pseudonyms
 * (the same e-mail becomes the same pseudonym in every table, so joins still work), everything else is kept. Nothing leaves the machine without the approval being recorded.
 */
class Anonymiser
{
    /** Tables whose contents are never copied, masked or not. */
    public const SKIP = ['sessions', 'personal_access_tokens', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'siem_outbox', 'integrations', 'migrations'];

    private string $key;

    public function __construct(private readonly DataClassification $classification)
    {
        $this->key = random_bytes(32);   // lives only for this export: the pseudonyms cannot be reversed or linked to another export
    }

    /** @param  list<string>|null  $only @return array<string, array{rows: int, file: string, sha256: string}> */
    public function export(string $dir, ?array $only = null): array
    {
        @mkdir($dir, 0775, true);
        $map = $this->classification->map();
        $manifest = [];
        foreach ($map as $table => $columns) {
            if (in_array($table, self::SKIP, true) || ($only && ! in_array($table, $only, true))) {
                continue;
            }
            $file = "{$dir}/{$table}.ndjson";
            $h = fopen($file, 'wb');
            $rows = 0;
            $order = Schema::hasColumn($table, 'id') ? 'id' : array_key_first($columns);
            DB::table($table)->orderBy($order)->chunk(500, function ($chunk) use ($h, $columns, $table, &$rows) {
                foreach ($chunk as $row) {
                    fwrite($h, json_encode($this->mask($table, (array) $row, $columns), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                    $rows++;
                }
            });
            fclose($h);
            $manifest[$table] = ['rows' => $rows, 'file' => basename($file), 'sha256' => hash_file('sha256', $file)];
        }

        return $manifest;
    }

    /** @param  array<string, mixed>  $row  @param  array<string, string>  $columns @return array<string, mixed> */
    public function mask(string $table, array $row, array $columns): array
    {
        $out = [];
        foreach ($row as $col => $value) {
            $class = $columns[$col] ?? 'confidential';
            $out[$col] = match (true) {
                $class === 'restricted' => null,
                $class === 'confidential' && $value !== null && ! $this->isStructural($col) => $this->pseudonym($col, $value),
                default => $value,
            };
        }

        return $out;
    }

    /** Identifiers, dates and numbers keep joins and statistics meaningful. */
    private function isStructural(string $col): bool
    {
        return $col === 'id' || str_ends_with($col, '_id') || str_ends_with($col, '_at') || in_array($col, ['created_at', 'updated_at'], true);
    }

    private function pseudonym(string $col, mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        $v = is_string($value) ? $value : json_encode($value);
        $h = substr(hash_hmac('sha256', mb_strtolower($v), $this->key), 0, 12);

        return match (true) {
            str_contains($col, 'email') => "user-{$h}@example.invalid",
            str_contains($col, 'phone') || str_contains($col, 'mobile') => '+974 5'.substr((string) hexdec(substr($h, 0, 7)), 0, 7),
            $col === 'name_ar' => 'شخص '.substr($h, 0, 6),
            $col === 'name' || str_starts_with($col, 'name_') || str_contains($col, 'full_name') || str_contains($col, 'first_name') || str_contains($col, 'last_name') => 'Person '.substr($h, 0, 6),
            $col === 'ip' || str_contains($col, 'ip_address') => '0.0.0.0',
            in_array($col, ['latitude', 'longitude'], true) => null,
            default => '[masked '.substr($h, 0, 6).']',
        };
    }
}
