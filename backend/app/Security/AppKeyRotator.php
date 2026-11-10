<?php

namespace App\Security;

use App\Models\Employee;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Moves every value the platform keeps locked with APP_KEY to a new key, in place.
 *
 * Run it where the CURRENT key is configured (APP_KEY, plus APP_PREVIOUS_KEYS if any): each locked value is opened with
 * the current key and locked again with the new one. Nothing else about the data changes.
 *
 *  - Locked values are found by their shape (Laravel's payload: base64 of {"iv","value","mac","tag"}), in any text / json
 *    column of any table, including inside JSON documents (settings, integration secrets, MFA secrets, device keys...),
 *    so a column added later is covered too. The text is re-locked byte for byte (no PHP serialisation is touched).
 *  - The keyed fingerprints that depend on APP_KEY are recomputed: the national-ID blind index of employees and trainers,
 *    and the duplicate guard of registration requests.
 *  - It is safe to stop and run again: a value already under the new key is skipped, and each write only applies if the
 *    row still holds the value that was read. A value that neither key opens is left untouched and counted.
 *  - Modes: `dry` counts and changes nothing, `run` writes, `verify` re-reads everything with the new key only.
 *
 * Reports counts, never a value or a key.
 */
final class AppKeyRotator
{
    private const PAYLOAD_NEEDLE = 'eyJpdiI6';   // base64 of {"iv":"  — every Laravel payload starts with it

    private Encrypter $new;

    private float $deadline;

    private bool $stopped = false;

    /** @var array<string, array<string, int>> per "table.column": found / rotated / already_new / unreadable / blocked */
    private array $columns = [];

    /** @var array<string, int> */
    private array $fingerprints = [];

    /** @param 'dry'|'run'|'verify' $mode  `verify` is run with the NEW key as APP_KEY and checks that everything opens with it */
    public function __construct(private readonly string $newKey, private readonly string $mode = 'dry', int $seconds = 240)
    {
        if (! in_array($mode, ['dry', 'run', 'verify'], true)) {
            throw new InvalidArgumentException('Mode must be dry, run or verify.');
        }
        if ($newKey === '' || ($mode !== 'verify' && hash_equals((string) config('app.key'), $newKey))) {
            throw new InvalidArgumentException('The new key must be set and different from the current APP_KEY.');
        }
        $this->new = new Encrypter(self::decode($newKey), (string) config('app.cipher'));   // throws when it is not a valid AES-256 key
        $this->deadline = microtime(true) + max(5, $seconds);
    }

    public function handle(): array
    {
        $mode = $this->mode;
        $write = $mode === 'run';
        $started = microtime(true);
        foreach (Schema::getTables() as $table) {
            if ($this->stopped) {
                break;
            }
            $this->scanTable($table, $write);
        }
        if (! $this->stopped) {
            $this->fingerprints($write);
        }

        $sum = fn (string $k) => array_sum(array_column($this->columns, $k));
        $report = [
            'mode' => $mode,
            'finished' => ! $this->stopped,
            'seconds' => round(microtime(true) - $started, 1),
            'found' => $sum('found'),
            'rotated' => $sum('rotated'),
            'already_new' => $sum('already_new'),
            'unreadable' => $sum('unreadable'),
            'blocked' => $sum('blocked'),
            'fingerprints' => $this->fingerprints,
            'columns' => $this->columns,
        ];
        // Fingerprints still waiting to be recomputed (a run recomputes them as it goes).
        $stale = $write ? 0 : array_sum(array_filter($this->fingerprints, fn ($v, $k) => str_ends_with($k, '.stale'), ARRAY_FILTER_USE_BOTH));
        // "complete" = nothing is left under the old key, nothing is unreadable, no fingerprint is out of date. After a run, repeat
        // with `dry` (or `verify` once the new key is APP_KEY) to confirm it.
        $report['complete'] = $report['finished'] && $report['unreadable'] === 0 && $report['blocked'] === 0 && $stale === 0
            && ($write || $report['rotated'] === 0);

        Log::warning('APP_KEY rotation', ['mode' => $mode] + array_diff_key($report, ['columns' => 1, 'fingerprints' => 1]) + ['fingerprints' => $this->fingerprints]);

        return $report;
    }

    // ── the locked values ───────────────────────────────────────────────────────────────────────────────────────────

    private function scanTable(array $meta, bool $write): void
    {
        $table = (string) $meta['name'];
        // Only the application's own schema (Supabase keeps its auth / storage tables elsewhere).
        if ($table === 'migrations' || (isset($meta['schema']) && $meta['schema'] !== null && $meta['schema'] !== (string) (config('database.connections.'.config('database.default').'.search_path') ?: 'public') && DB::connection()->getDriverName() === 'pgsql')) {
            return;
        }
        $grammar = DB::connection()->getQueryGrammar();
        $names = [];
        foreach (Schema::getColumns($table) as $c) {
            if ($this->isTextual((string) ($c['type_name'] ?? ''))) {
                $names[] = (string) $c['name'];
            }
        }
        if ($names === []) {
            return;
        }
        $primary = $this->primaryKey($table);

        // One pass over the table: a column's text is selected only when it holds a locked value (otherwise NULL).
        $like = '%'.self::PAYLOAD_NEEDLE.'%';
        $selects = [];
        $selectBindings = [];
        $wheres = [];
        foreach ($names as $i => $name) {
            $w = $grammar->wrap($name);
            $selects[] = "CASE WHEN CAST({$w} AS TEXT) LIKE ? THEN CAST({$w} AS TEXT) END AS tedc_c{$i}";
            $selectBindings[] = $like;
            $wheres[] = "CAST({$w} AS TEXT) LIKE ?";
        }
        $stats = array_fill_keys($names, ['found' => 0, 'rotated' => 0, 'already_new' => 0, 'unreadable' => 0, 'blocked' => 0]);
        $base = fn (): Builder => DB::table($table)->whereRaw('('.implode(' OR ', $wheres).')', array_fill(0, count($names), $like));

        if ($primary === []) {
            $found = $base()->count();
            if ($found > 0) {
                $this->columns[$table.' (no primary key)'] = ['found' => $found, 'rotated' => 0, 'already_new' => 0, 'unreadable' => 0, 'blocked' => $found];
            }

            return;
        }

        $query = $base()->select([...$primary])->selectRaw(implode(', ', $selects), $selectBindings);
        foreach ($primary as $p) {
            $query->orderBy($p);
        }
        $query->chunk(300, function ($rows) use ($table, $names, $grammar, $primary, $write, &$stats) {
            DB::transaction(function () use ($rows, $table, $names, $grammar, $primary, $write, &$stats) {
                foreach ($rows as $row) {
                    $set = [];
                    $guards = [];
                    foreach ($names as $i => $name) {
                        $raw = $row->{'tedc_c'.$i};
                        if ($raw === null) {
                            continue;
                        }
                        [$next, $counts] = $this->rewrite((string) $raw);
                        foreach ($counts as $k => $v) {
                            $stats[$name][$k] += $v;
                        }
                        if ($write && $next !== null && $next !== $raw) {
                            $set[$name] = $next;
                            $guards[$name] = $raw;
                        }
                    }
                    if ($set !== []) {
                        $update = DB::table($table);
                        foreach ($primary as $p) {
                            $update->where($p, $row->{$p});
                        }
                        foreach ($guards as $name => $raw) {   // only if nobody changed the row since it was read
                            $update->whereRaw('CAST('.$grammar->wrap($name).' AS TEXT) = ?', [$raw]);
                        }
                        $update->update($set);
                    }
                }
            });
            if (microtime(true) > $this->deadline) {
                $this->stopped = true;

                return false;
            }

            return true;
        });
        foreach ($stats as $name => $c) {
            if ($c['found'] > 0) {
                $this->columns[$table.'.'.$name] = $c;
            }
        }
    }

    /**
     * @return array{0: ?string, 1: array<string, int>} the new stored text (null when nothing changes) and what was seen
     */
    private function rewrite(string $raw): array
    {
        $counts = ['found' => 0, 'rotated' => 0, 'already_new' => 0, 'unreadable' => 0, 'blocked' => 0];

        if ($this->isPayload($raw)) {
            $counts['found'] = 1;
            [$next, $state] = $this->relock($raw);
            $counts[$state]++;

            return [$next, $counts];
        }

        $json = json_decode($raw);   // objects stay objects, so {} and [] survive the round trip
        if (! is_array($json) && ! $json instanceof stdClass) {
            return [null, $counts];
        }
        $changed = false;
        $this->walk($json, $counts, $changed);

        return [$changed ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) : null, $counts];
    }

    private function walk(array|stdClass &$node, array &$counts, bool &$changed): void
    {
        foreach ($node as $k => &$value) {
            if (is_string($value) && $this->isPayload($value)) {
                $counts['found']++;
                [$next, $state] = $this->relock($value);
                $counts[$state]++;
                if ($next !== null) {
                    $value = $next;
                    $changed = true;
                }
            } elseif (is_array($value) || $value instanceof stdClass) {
                $this->walk($value, $counts, $changed);
            }
        }
    }

    /** @return array{0: ?string, 1: 'rotated'|'already_new'|'unreadable'} */
    private function relock(string $payload): array
    {
        try {
            $this->new->decrypt($payload, false);

            return [null, 'already_new'];
        } catch (DecryptException) {
        }
        try {
            $plain = app('encrypter')->decrypt($payload, false);
        } catch (DecryptException) {
            return [null, 'unreadable'];
        }

        return [$this->new->encrypt($plain, false), 'rotated'];
    }

    private function isPayload(string $value): bool
    {
        if (! str_starts_with($value, self::PAYLOAD_NEEDLE) || ! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value)) {
            return false;
        }
        $json = json_decode((string) base64_decode($value, true), true);

        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }

    // ── the keyed fingerprints ──────────────────────────────────────────────────────────────────────────────────────

    private function fingerprints(bool $write): void
    {
        foreach (['employees', 'trainers'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'national_id_hash')) {
                continue;
            }
            $stats = ['checked' => 0, 'stale' => 0, 'updated' => 0, 'unreadable' => 0];
            DB::table($table)->whereNotNull('national_id')->whereNotNull('national_id_hash')->orderBy('id')
                ->chunk(500, function ($rows) use ($table, $write, &$stats) {
                    DB::transaction(function () use ($rows, $table, $write, &$stats) {
                        foreach ($rows as $row) {
                            $stats['checked']++;
                            $plain = $this->open((string) $row->national_id);
                            if ($plain === null) {
                                $stats['unreadable']++;

                                continue;
                            }
                            $hash = Employee::nationalIdHash($plain, $this->newKey);
                            if (! hash_equals((string) $row->national_id_hash, $hash)) {
                                $stats['stale']++;
                                if ($write) {
                                    DB::table($table)->where('id', $row->id)->update(['national_id_hash' => $hash]);
                                    $stats['updated']++;
                                }
                            }
                        }
                    });

                    return microtime(true) <= $this->deadline ? true : ! ($this->stopped = true);
                });
            foreach ($stats as $k => $v) {
                $this->fingerprints["{$table}.{$k}"] = $v;
            }
            if ($this->stopped) {
                return;
            }
        }

        $this->registrationRequests($write);
    }

    /** The duplicate guard of external registrations: sha256(APP_KEY|digits of the national ID answer). */
    private function registrationRequests(bool $write): void
    {
        if (! Schema::hasTable('registration_requests') || ! Schema::hasColumn('registration_requests', 'national_id_hash')) {
            return;
        }
        $forms = [];
        $stats = ['checked' => 0, 'stale' => 0, 'updated' => 0, 'unknown' => 0];
        DB::table('registration_requests')->whereNotNull('national_id_hash')->orderBy('id')->chunk(500, function ($rows) use (&$forms, $write, &$stats) {
            DB::transaction(function () use ($rows, &$forms, $write, &$stats) {
                foreach ($rows as $row) {
                    $stats['checked']++;
                    $forms[$row->form_id] ??= collect(json_decode((string) DB::table('registration_forms')->where('id', $row->form_id)->value('fields'), true) ?: [])->firstWhere('type', 'national_id')['key'] ?? null;
                    $key = $forms[$row->form_id];
                    $digits = $key ? preg_replace('/\D+/', '', (string) (json_decode((string) $row->data, true)[$key] ?? '')) : '';
                    if ($digits === '' || $digits === null) {
                        $stats['unknown']++;

                        continue;
                    }
                    $hash = hash('sha256', $this->newKey.'|'.$digits);
                    if (! hash_equals((string) $row->national_id_hash, $hash)) {
                        $stats['stale']++;
                        if ($write) {
                            DB::table('registration_requests')->where('id', $row->id)->update(['national_id_hash' => $hash]);
                            $stats['updated']++;
                        }
                    }
                }
            });

            return true;
        });
        foreach ($stats as $k => $v) {
            $this->fingerprints["registration_requests.{$k}"] = $v;
        }
    }

    private function open(string $payload): ?string
    {
        foreach ([$this->new, app('encrypter')] as $encrypter) {
            try {
                return $encrypter->decrypt($payload, false);
            } catch (Throwable) {
            }
        }

        return null;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────────

    private function isTextual(string $type): bool
    {
        return in_array(strtolower($type), ['text', 'mediumtext', 'longtext', 'json', 'jsonb', 'varchar', 'character varying', 'string'], true);
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (! empty($index['primary'])) {
                return array_map('strval', $index['columns']);
            }
        }

        return [];
    }

    private static function decode(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }
}
