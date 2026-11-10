<?php

namespace App\Services;

use App\Models\ErrorLog;
use Illuminate\Support\Collection;

/**
 * Turns error log entries into one ready-to-paste request for Claude: what the project is, what to do, then each error with
 * everything needed to find it. No e-mail addresses or IP numbers are included, only how many people were affected.
 */
class ErrorPromptBuilder
{
    private const SOURCE = ['server' => 'Laravel API (backend/)', 'web' => 'React website (web/)', 'app' => 'Flutter mobile app (mobile/)'];

    /** @param  Collection<int, ErrorLog>  $logs */
    public function build(Collection $logs, int $total): string
    {
        $head = <<<'TXT'
You are fixing errors captured in production by the TEDC training-center platform. The code is in this repository:
- backend/  Laravel 13 (PHP) API, tests in backend/tests (run: php -d memory_limit=2G artisan test)
- web/      React + TypeScript + Vite website and admin dashboard (check: npm run build, npm run lint)
- mobile/   Flutter app

How to work:
1. For each error below, find the root cause in the code (do not just hide the message).
2. Fix it with the smallest correct change, in the style of the surrounding code. Do not refactor unrelated code.
3. Add or update a test that fails before the fix and passes after, where a test makes sense.
4. Errors that are one problem seen in several places can share one fix: say so.
5. If an error is caused by bad data, a missing setting or something outside the code, say what to change instead of guessing.
6. At the end give a short list: error number, cause, what you changed, how you verified it.

TXT;

        $shown = $logs->count();
        $lines = [$head, sprintf("There are %d error%s below%s.\n", $shown, $shown === 1 ? '' : 's', $total > $shown ? " (the {$shown} most important of {$total} matching)" : '')];

        foreach ($logs->values() as $i => $l) {
            $lines[] = $this->entry($i + 1, $l);
        }

        return trim(implode("\n", $lines))."\n";
    }

    private function entry(int $n, ErrorLog $l): string
    {
        $out = [sprintf('## %d. [%s] %s', $n, strtoupper($l->level), self::SOURCE[$l->source] ?? $l->source), 'Message: '.mb_substr(trim($l->message), 0, 600)];
        $facts = array_filter([
            'Exception' => $l->exception, 'Where' => $l->location, 'Request' => trim(($l->method ? $l->method.' ' : '').($l->url ?? '')) ?: null,
            'HTTP status' => $l->status_code, 'App version' => $l->app_version,
            'Seen' => sprintf('%d time%s by %d user%s, first %s, last %s', $l->occurrences, $l->occurrences === 1 ? '' : 's', $l->users_count, $l->users_count === 1 ? '' : 's', $l->first_seen_at?->toDateString(), $l->last_seen_at?->toDateTimeString()),
            'Notes from the administrator' => $l->note,
        ], fn ($v) => $v !== null && $v !== '');
        foreach ($facts as $k => $v) {
            $out[] = "$k: $v";
        }
        if (! empty($l->device)) {
            $out[] = 'Device: '.json_encode($l->device, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (! empty($l->context)) {
            $out[] = 'Context: '.mb_substr(json_encode($l->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 1200);
        }
        if (filled($l->stack)) {
            $stack = array_slice(preg_split('/\R/', trim($l->stack)) ?: [], 0, 18);
            $out[] = "Stack trace (first lines):\n```\n".implode("\n", $stack)."\n```";
        }

        return implode("\n", $out)."\n";
    }
}
