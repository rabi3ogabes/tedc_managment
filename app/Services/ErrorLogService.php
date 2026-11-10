<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ErrorLog;
use App\Support\Features;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The error log: server exceptions and the errors the website and the app report. Identical errors are grouped
 * (fingerprint) and counted; secrets are removed before anything is stored; known problems are fixed automatically.
 */
class ErrorLogService
{
    private const SECRET_KEYS = '/(password|passwd|secret|token|authorization|api[_-]?key|cookie|session|otp|cvv|national_id)/i';

    public function __construct(private readonly ErrorLogSettings $settings, private readonly ErrorAutoFixer $fixer) {}

    /** Server-side: called for every reported exception. Never throws. */
    public function recordThrowable(Throwable $e, ?Request $request = null): void
    {
        try {
            if (! $this->settings->all()['enabled'] || $this->ignored($e)) {
                return;
            }
            $request ??= app()->bound('request') ? request() : null;
            $user = $request?->user();

            $this->record([
                'source' => 'server', 'level' => $e instanceof \Error || $e instanceof \PDOException ? 'critical' : 'error',
                'message' => $e->getMessage() ?: class_basename($e), 'exception' => $e::class,
                'location' => str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(), 'stack' => $this->trace($e),
                'method' => $request?->method(), 'url' => $request ? $this->cleanUrl($request->fullUrl()) : 'console', 'status_code' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                'user_id' => $user?->id, 'user_email' => $user?->email, 'ip' => $request?->ip(), 'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
                'context' => $request ? ['route' => $request->route()?->uri(), 'input_keys' => array_keys($request->except(array_keys($request->all()))) ?: array_keys($request->all())] : null,
            ]);
        } catch (Throwable) {
            // Logging must never make a failing request worse.
        }
    }

    /**
     * Stores one error (from the server or reported by a client) and returns its group.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data): ErrorLog
    {
        $data['message'] = $this->scrub(mb_substr((string) $data['message'], 0, 2000));
        $data['stack'] = isset($data['stack']) ? $this->scrub(mb_substr((string) $data['stack'], 0, 12000)) : null;
        $data['context'] = isset($data['context']) ? $this->redact((array) $data['context']) : null;
        $fingerprint = $this->fingerprint($data);
        $now = now();

        $log = ErrorLog::where('fingerprint', $fingerprint)->first();
        if ($log) {
            $newUser = ! empty($data['user_id']) && $log->user_id !== ($data['user_id']);
            $log->fill([
                'occurrences' => $log->occurrences + 1, 'last_seen_at' => $now, 'users_count' => $log->users_count + ($newUser ? 1 : 0),
                'user_id' => $data['user_id'] ?? $log->user_id, 'user_email' => $data['user_email'] ?? $log->user_email, 'url' => $data['url'] ?? $log->url,
                'app_version' => $data['app_version'] ?? $log->app_version, 'device' => $data['device'] ?? $log->device,
            ]);
            // Something marked fixed that comes back was not really fixed.
            if ($log->status === 'fixed') {
                $log->fill(['status' => 'open', 'resolved_at' => null, 'auto_fixed' => false, 'note' => trim(($log->note ? $log->note."\n" : '').'Returned after being marked fixed ('.$now->toDateTimeString().').')]);
            }
            $log->save();
        } else {
            $log = ErrorLog::create($data + ['fingerprint' => $fingerprint, 'level' => 'error', 'occurrences' => 1, 'users_count' => empty($data['user_id']) ? 0 : 1, 'first_seen_at' => $now, 'last_seen_at' => $now, 'status' => 'open']);
        }

        if ($log->status === 'open' && $this->settings->all()['auto_fix'] && $log->fix_attempts < 3) {
            $this->tryFix($log);
        }

        return $log;
    }

    /** Runs the automatic remedy of the entry, if there is one. @return array{fixed: bool, rule: ?string, note: string} */
    public function tryFix(ErrorLog $log): array
    {
        // On a live system the remedy is only suggested: nothing is changed until Settings → Features switches self-healing on.
        if (! Features::enabled('self_heal')) {
            $rule = $this->fixer->ruleFor($log);

            return ['fixed' => false, 'suggested' => true, 'rule' => $rule, 'note' => $rule ? __('messages.features.fix_suggested', ['fix' => $this->fixer->label($rule)]) : __('messages.features.no_remedy')];
        }
        $result = $this->fixer->fix($log);
        $log->fill(['fix_attempts' => $log->fix_attempts + 1, 'last_fix_at' => now(), 'note' => $this->appendNote($log->note, $result['rule'] ? "[{$this->fixer->label($result['rule'])}] {$result['note']}" : null)]);
        if ($result['fixed']) {
            $log->fill(['status' => 'fixed', 'auto_fixed' => true, 'resolved_at' => now()]);
        }
        $log->save();

        return $result;
    }

    // -----------------------------------------------------------------------------------------------------------

    private function ignored(Throwable $e): bool
    {
        if ($e instanceof ValidationException || $e instanceof AuthenticationException || $e instanceof AuthorizationException || $e instanceof ModelNotFoundException || $e instanceof BusinessRuleException) {
            return true;
        }

        return $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
    }

    /** @param  array<string, mixed>  $d */
    private function fingerprint(array $d): string
    {
        // Numbers, ids and quoted values vary between occurrences of the same bug: they are left out of the identity.
        $message = preg_replace(['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\b\d+\b/', '/(["\'`]).*?\1/'], ['{id}', '{n}', '{v}'], (string) $d['message']);

        return sha1(implode('|', [$d['source'], $d['exception'] ?? '', mb_substr($message, 0, 240), $d['location'] ?? '']));
    }

    private function trace(Throwable $e): string
    {
        $lines = [get_class($e).': '.$e->getMessage(), '    at '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine()];
        foreach (array_slice($e->getTrace(), 0, 14) as $i => $f) {
            $lines[] = '#'.$i.' '.str_replace(base_path().'/', '', $f['file'] ?? '[internal]').(isset($f['line']) ? ':'.$f['line'] : '').' '.($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? '');
        }

        return implode("\n", $lines);
    }

    private function cleanUrl(string $url): string
    {
        return mb_substr(preg_replace('/([?&](token|password|secret|key|code|access_token|refresh_token)=)[^&]*/i', '$1***', $url), 0, 500);
    }

    /** Removes bearer tokens, JWTs and key-like values from free text. */
    private function scrub(string $text): string
    {
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***', $text);
        $text = preg_replace('/eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]*/', '***jwt***', $text);

        return preg_replace('/((password|secret|token|api[_-]?key)["\']?\s*[:=]\s*["\']?)[^\s"\',&]+/i', '$1***', $text);
    }

    /** @param  array<mixed>  $data  @return array<mixed> */
    private function redact(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k)) {
                $out[$k] = '***';
            } elseif (is_array($v)) {
                $out[$k] = $depth > 3 ? '[…]' : $this->redact($v, $depth + 1);
            } else {
                $out[$k] = is_string($v) ? $this->scrub(mb_substr($v, 0, 500)) : $v;
            }
        }

        return $out;
    }

    private function appendNote(?string $note, ?string $line): ?string
    {
        return $line ? trim(($note ? $note."\n" : '').now()->format('Y-m-d H:i').' '.$line) : $note;
    }
}
