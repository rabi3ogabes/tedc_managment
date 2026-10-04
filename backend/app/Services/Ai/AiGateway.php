<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Talks to whichever model the administrator assigned to a kind of work. OpenRouter, OpenAI and any OpenAI-compatible address
 * share one protocol (chat completions, images, speech); Anthropic has its own messages protocol. Every method returns null
 * when nothing is assigned or the call fails, so callers keep their built-in fallback.
 */
class AiGateway
{
    public function __construct(private readonly AiModelSettings $settings) {}

    public function has(string $task): bool
    {
        return $this->settings->resolve($task) !== null;
    }

    /** Plain text answer for a prompt. */
    public function text(string $system, array|string $messages, ?int $maxTokens = null): ?string
    {
        $r = $this->settings->resolve('text');

        return $r ? $this->safely(fn () => $this->chat($r, $system, $messages, $maxTokens)) : null;
    }

    /** JSON answer matching a schema (the schema goes into the prompt, so any model can follow it). */
    public function json(string $system, string $prompt, array $schema, ?int $maxTokens = null): ?array
    {
        $r = $this->settings->resolve('text');
        if (! $r) {
            return null;
        }
        $system .= "\n\nReply with ONE JSON object only, no commentary and no code fences, matching this JSON Schema:\n".json_encode($schema, JSON_UNESCAPED_UNICODE);

        return $this->safely(fn () => self::decode($this->chat($r, $system, $prompt, $maxTokens)));
    }

    /** @return array{bytes: string, mime: string, ext: string}|null */
    public function image(string $prompt, string $aspect = '16:9'): ?array
    {
        $r = $this->settings->resolve('image');

        return $r ? $this->safely(fn () => $this->drawImage($r, $prompt, $aspect)) : null;
    }

    /** @return array{bytes: string, mime: string}|null */
    public function speech(string $text, ?string $voice = null): ?array
    {
        $r = $this->settings->resolve('audio');

        return $r ? $this->safely(fn () => $this->speak($r, $text, $voice)) : null;
    }

    /** The models a connection offers (for the picker). @return list<array<string, mixed>> */
    public function catalog(string $connectionId): array
    {
        $c = $this->settings->connection($connectionId);
        $key = $this->settings->key($connectionId);
        if (! $c || $c['driver'] === 'anthropic' && ! $key) {
            return [];
        }
        $response = $this->http($c, (string) $key, 20)->get($c['base_url_resolved'].'/models')->throw()->json('data') ?? [];

        return collect($response)->map(function ($m) {
            $out = (array) ($m['architecture']['output_modalities'] ?? []);
            $in = (array) ($m['architecture']['input_modalities'] ?? []);
            $id = (string) ($m['id'] ?? '');
            $tasks = array_values(array_filter([
                in_array('text', $out, true) || ! $out ? 'text' : null,
                in_array('image', $out, true) || preg_match('/image|dall|flux|imagen|diffusion/i', $id) ? 'image' : null,
                in_array('audio', $out, true) || preg_match('/tts|speech|audio/i', $id) ? 'audio' : null,
                in_array('video', $out, true) || preg_match('/video|veo|sora|kling|runway/i', $id) ? 'video' : null,
            ]));

            return ['id' => $id, 'name' => (string) ($m['name'] ?? $id), 'tasks' => $tasks ?: ['text'], 'context' => $m['context_length'] ?? null, 'free' => (float) ($m['pricing']['prompt'] ?? 1) === 0.0 && (float) ($m['pricing']['completion'] ?? 1) === 0.0, 'vision' => in_array('image', $in, true)];
        })->filter(fn ($m) => $m['id'] !== '')->values()->all();
    }

    /** Runs a tiny real request for the model's kinds of work and reports the outcome. @return array{ok: bool, ms: int, sample: ?string, error: ?string, task: string} */
    public function test(string $modelId, ?string $task = null): array
    {
        $model = $this->settings->model($modelId);
        $connection = $model ? $this->settings->connection($model['connection_id']) : null;
        $key = $connection ? $this->settings->key($connection['id']) : null;
        $task ??= $model['tasks'][0] ?? 'text';
        $start = microtime(true);
        $ms = fn () => (int) round((microtime(true) - $start) * 1000);
        if (! $model || ! $connection) {
            return ['ok' => false, 'ms' => 0, 'sample' => null, 'error' => 'model_not_found', 'task' => $task];
        }
        if (! $key) {
            return ['ok' => false, 'ms' => 0, 'sample' => null, 'error' => 'no_key', 'task' => $task];
        }
        $r = ['model' => $model, 'connection' => $connection, 'key' => $key];
        try {
            $sample = match ($task) {
                'image' => ($img = $this->drawImage($r, 'a simple maroon and gold geometric pattern', '1:1')) ? 'image · '.round(strlen($img['bytes']) / 1024).' KB' : throw new RuntimeException('no image returned'),
                'audio' => ($a = $this->speak($r, 'Hello', null)) ? 'audio · '.round(strlen($a['bytes']) / 1024).' KB' : throw new RuntimeException('no audio returned'),
                'video' => throw new RuntimeException('video_not_supported'),
                default => mb_substr(trim($this->chat($r, 'Answer in one short sentence.', 'Say hello to the Training and Development Center.', 60)), 0, 200),
            };
            $result = ['ok' => true, 'ms' => $ms(), 'sample' => $sample, 'error' => null, 'task' => $task];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'ms' => $ms(), 'sample' => null, 'error' => self::explain($e), 'task' => $task];
        }
        $this->settings->recordTest($modelId, $result);

        return $result;
    }

    // Protocols -------------------------------------------------------------------------------------------------

    private function http(array $c, string $key, int $timeout): PendingRequest
    {
        $req = Http::timeout($timeout)->acceptJson();

        return $c['driver'] === 'anthropic'
            ? $req->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
            : $req->withToken($key)->withHeaders($c['driver'] === 'openrouter' ? ['HTTP-Referer' => (string) config('app.url'), 'X-Title' => 'TEDC'] : []);
    }

    private function chat(array $r, string $system, array|string $messages, ?int $maxTokens): string
    {
        ['model' => $m, 'connection' => $c, 'key' => $key] = $r;
        $messages = is_string($messages) ? [['role' => 'user', 'content' => $messages]] : $messages;
        $max = $maxTokens ?? ($m['params']['max_tokens'] ?? 4096);
        $temperature = $m['params']['temperature'] ?? null;

        if ($c['driver'] === 'anthropic') {
            $body = ['model' => $m['model'], 'max_tokens' => $max, 'system' => $system, 'messages' => $messages] + ($temperature !== null ? ['temperature' => min(1, $temperature)] : []);
            $res = $this->http($c, $key, 120)->post($c['base_url_resolved'].'/v1/messages', $body)->throw()->json();

            return collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        }

        $body = ['model' => $m['model'], 'max_tokens' => $max, 'messages' => [['role' => 'system', 'content' => $system], ...$messages]] + ($temperature !== null ? ['temperature' => $temperature] : []);
        $text = $this->http($c, $key, 120)->post($c['base_url_resolved'].'/chat/completions', $body)->throw()->json('choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('empty_answer');
        }

        return $text;
    }

    private function drawImage(array $r, string $prompt, string $aspect): ?array
    {
        ['model' => $m, 'connection' => $c, 'key' => $key] = $r;
        $http = $this->http($c, $key, 150);

        // OpenAI proper (and compatible addresses that offer it) have a dedicated images endpoint.
        if ($c['driver'] === 'openai' || $c['driver'] === 'custom') {
            $size = ['16:9' => '1536x1024', '4:3' => '1536x1024', '1:1' => '1024x1024'][$aspect] ?? '1024x1024';
            $d = $http->post($c['base_url_resolved'].'/images/generations', ['model' => $m['model'], 'prompt' => $prompt, 'size' => $size, 'n' => 1])->throw()->json('data.0');
            $bytes = ! empty($d['b64_json']) ? base64_decode($d['b64_json'], true) : (! empty($d['url']) ? Http::timeout(60)->get($d['url'])->throw()->body() : null);

            return $bytes ? self::imageFromBytes($bytes) : null;
        }

        // OpenRouter: image models answer a chat request with the picture attached to the message.
        $body = ['model' => $m['model'], 'modalities' => ['image', 'text'], 'messages' => [['role' => 'user', 'content' => $prompt.". Aspect ratio {$aspect}."]]];
        $url = $http->post($c['base_url_resolved'].'/chat/completions', $body)->throw()->json('choices.0.message.images.0.image_url.url');
        if (! is_string($url) || $url === '') {
            return null;
        }
        if (preg_match('#^data:(image/[a-z+.-]+);base64,(.+)$#is', $url, $mm)) {
            $bytes = base64_decode($mm[2], true);

            return $bytes ? self::imageFromBytes($bytes) : null;
        }

        return self::imageFromBytes(Http::timeout(60)->get($url)->throw()->body());
    }

    private function speak(array $r, string $text, ?string $voice): ?array
    {
        ['model' => $m, 'connection' => $c, 'key' => $key] = $r;
        $res = $this->http($c, $key, 90)->post($c['base_url_resolved'].'/audio/speech', ['model' => $m['model'], 'voice' => $voice ?: ($m['params']['voice'] ?? 'nova'), 'input' => mb_substr($text, 0, 4000), 'response_format' => 'mp3'])->throw();

        return $res->body() !== '' ? ['bytes' => $res->body(), 'mime' => 'audio/mpeg'] : null;
    }

    // Helpers ---------------------------------------------------------------------------------------------------

    private static function imageFromBytes(string $bytes): ?array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;

        return $ext ? ['bytes' => $bytes, 'mime' => $mime, 'ext' => $ext] : null;
    }

    /** Models often wrap JSON in code fences or add a sentence around it. */
    public static function decode(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/mi', '', trim($text)) ?? '');
        $data = json_decode($text, true);
        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }

        return is_array($data) ? $data : null;
    }

    public static function explain(Throwable $e): string
    {
        if ($e instanceof RequestException) {
            $body = $e->response->json('error.message') ?? $e->response->json('error') ?? $e->response->json('message');

            return $e->response->status().': '.mb_substr(is_string($body) ? $body : $e->response->body(), 0, 220);
        }

        return mb_substr($e->getMessage(), 0, 220);
    }

    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            Log::warning('AI gateway call failed', ['error' => self::explain($e)]);

            return null;
        }
    }
}
