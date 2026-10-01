<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\ErrorLog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Remedies for problems whose cure is known. Each remedy recognises its error by the message, is guarded against
 * running too often, and reports what it did. Anything it cannot cure stays open for the administrator.
 */
class ErrorAutoFixer
{
    /** @var array<string, array{label: string, match: string, sources: list<string>}> */
    private const RULES = [
        'schema' => ['label' => 'Pending database migrations', 'match' => '/(SQLSTATE\[(42P01|42703|42S02|42S22)\]|no such (table|column)|undefined (table|column)|relation ".*" does not exist|Base table or view not found|Unknown column)/i', 'sources' => ['server']],
        'cache' => ['label' => 'Stale framework caches', 'match' => '/(Target class \[.*\] does not exist|Class ".*" not found|View \[.*\] not found|Route \[.*\] not defined|bootstrap\/cache|Unable to (create|load) (compiled|cache)|Cannot redeclare)/i', 'sources' => ['server']],
        'push' => ['label' => 'Dead push tokens', 'match' => '/(UNREGISTERED|registration-token-not-registered|Requested entity was not found|InvalidRegistration|NotRegistered)/i', 'sources' => ['server']],
        'stale_client' => ['label' => 'Outdated website code', 'match' => '/(Failed to fetch dynamically imported module|Importing a module script failed|ChunkLoadError|Loading chunk .* failed|Unable to preload CSS)/i', 'sources' => ['web']],
        'transient' => ['label' => 'Temporary connection problem', 'match' => '/(could not connect|Connection (refused|timed out|reset)|server closed the connection|timeout|cURL error (6|7|28)|Network (is unreachable|Error)|Failed to fetch|SocketException)/i', 'sources' => ['server', 'web', 'app']],
    ];

    /** The remedy that applies to this entry, or null. */
    public function ruleFor(ErrorLog $log): ?string
    {
        foreach (self::RULES as $key => $rule) {
            if (in_array($log->source, $rule['sources'], true) && preg_match($rule['match'], $log->message.' '.$log->exception)) {
                return $key;
            }
        }

        return null;
    }

    public function label(string $rule): string
    {
        return self::RULES[$rule]['label'];
    }

    /** @return array{fixed: bool, rule: ?string, note: string} */
    public function fix(ErrorLog $log): array
    {
        $rule = $this->ruleFor($log);
        if (! $rule) {
            return ['fixed' => false, 'rule' => null, 'note' => 'No automatic remedy is known for this error.'];
        }

        try {
            $note = match ($rule) {
                'schema' => $this->guarded('fix:schema', 600, fn () => $this->artisan('migrate', ['--force' => true], 'Ran the pending database migrations.')),
                'cache' => $this->guarded('fix:cache', 600, fn () => $this->artisan('optimize:clear', [], 'Cleared the framework caches.')),
                'push' => $this->prunePushTokens(),
                'stale_client' => 'The website reloads itself onto the new version when this happens.',
                'transient' => 'A temporary connection problem: it is closed automatically if it does not come back.',
            };
        } catch (Throwable $e) {
            return ['fixed' => false, 'rule' => $rule, 'note' => 'The remedy failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // A "transient" error is only closed later, by the self-heal job, once it has stopped recurring.
        return ['fixed' => $rule !== 'transient', 'rule' => $rule, 'note' => $note];
    }

    private function guarded(string $key, int $seconds, callable $run): string
    {
        // One run per window, so a burst of identical errors does not run the remedy again and again.
        return Cache::add($key, true, $seconds) ? $run() : 'The remedy ran a moment ago.';
    }

    private function artisan(string $command, array $options, string $done): string
    {
        Artisan::call($command, $options);

        return $done;
    }

    private function prunePushTokens(): string
    {
        $removed = DeviceToken::where('last_seen_at', '<', now()->subDays(45))->delete();

        return "Removed {$removed} push tokens that were not seen for 45 days.";
    }
}
