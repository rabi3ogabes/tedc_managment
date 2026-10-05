<?php

namespace App\Services\Content;

use App\Models\CourseLesson;
use App\Models\Registration;
use App\Models\XapiDocument;
use App\Models\XapiStatement;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** An xAPI 1.0.3 learning record store, and the recording of TEDC's own learning activity as xAPI statements. */
class XapiService
{
    public const VERSION = '1.0.3';

    public const VOIDED = 'http://adlnet.gov/expapi/verbs/voided';

    public function __construct(private readonly StandardsSettings $settings) {}

    // ───────────────────────────── validation

    /** @throws InvalidArgumentException */
    public function validate(array $s): void
    {
        $fail = fn (string $m) => throw new InvalidArgumentException($m);
        if (isset($s['id']) && ! Str::isUuid((string) $s['id'])) {
            $fail('Statement id must be a UUID.');
        }
        $actor = $s['actor'] ?? null;
        if (! is_array($actor)) {
            $fail('actor is required.');
        }
        if (($actor['objectType'] ?? 'Agent') === 'Agent' && $this->actorKey($actor) === null) {
            $fail('An Agent needs exactly one of mbox, mbox_sha1sum, openid or account.');
        }
        $verb = $s['verb']['id'] ?? null;
        if (! is_string($verb) || ! preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:\S+$#', $verb)) {
            $fail('verb.id must be an IRI.');
        }
        $obj = $s['object'] ?? null;
        if (! is_array($obj)) {
            $fail('object is required.');
        }
        $type = $obj['objectType'] ?? 'Activity';
        if ($type === 'Activity' && (! is_string($obj['id'] ?? null) || ! preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:\S+$#', $obj['id']))) {
            $fail('object.id must be an IRI.');
        }
        if ($type === 'StatementRef' && ! Str::isUuid((string) ($obj['id'] ?? ''))) {
            $fail('A StatementRef needs a statement id.');
        }
        if ($verb === self::VOIDED && $type !== 'StatementRef') {
            $fail('A voiding statement must reference a statement.');
        }
        $scaled = $s['result']['score']['scaled'] ?? null;
        if ($scaled !== null && (! is_numeric($scaled) || $scaled < -1 || $scaled > 1)) {
            $fail('result.score.scaled must be between -1 and 1.');
        }
        if (isset($s['timestamp']) && strtotime((string) $s['timestamp']) === false) {
            $fail('timestamp is not valid.');
        }
    }

    /** A stable key for the agent (null when there is not exactly one identifier). */
    public function actorKey(array $actor): ?string
    {
        $ids = array_filter(['mbox' => $actor['mbox'] ?? null, 'mbox_sha1sum' => $actor['mbox_sha1sum'] ?? null, 'openid' => $actor['openid'] ?? null, 'account' => isset($actor['account']['name'], $actor['account']['homePage']) ? $actor['account']['homePage'].'#'.$actor['account']['name'] : null]);

        return count($ids) === 1 ? array_key_first($ids).':'.reset($ids) : null;
    }

    // ───────────────────────────── storing

    /**
     * Stores one statement. Storing the same id with the same content is a no-op; with other content it is a conflict.
     *
     * @return array{id: string, created: bool}
     */
    public function store(array $s, ?string $registrationId = null, ?string $lessonId = null): array
    {
        $this->validate($s);
        $s['id'] = $s['id'] ?? (string) Str::uuid();
        $s['timestamp'] = $s['timestamp'] ?? now()->toIso8601String();
        $s['stored'] = now()->toIso8601String();
        $s['version'] = self::VERSION;

        if ($existing = XapiStatement::find($s['id'])) {
            $a = $existing->statement;
            $b = $s;
            foreach (['stored', 'authority', 'version'] as $k) {
                unset($a[$k], $b[$k]);
            }
            if ($a != $b) {
                throw new RuntimeException('A different statement with this id already exists.');
            }

            return ['id' => $s['id'], 'created' => false];
        }
        $isVoid = ($s['verb']['id'] ?? '') === self::VOIDED;
        if ($isVoid) {
            $target = XapiStatement::find($s['object']['id']);
            if (! $target) {
                throw new InvalidArgumentException('The statement to void does not exist.');
            }
            if ($target->verb === self::VOIDED) {
                throw new InvalidArgumentException('A voiding statement cannot be voided.');
            }
            $target->update(['voided' => true]);
        }
        XapiStatement::create(['id' => $s['id'], 'statement' => $s, 'actor_key' => $this->actorKey($s['actor']) ?? ($s['actor']['objectType'] ?? 'Group'), 'verb' => $s['verb']['id'], 'object_id' => (string) ($s['object']['id'] ?? ''), 'registration_id' => $registrationId, 'lesson_id' => $lessonId, 'voided' => false, 'stored' => now()]);

        return ['id' => $s['id'], 'created' => true];
    }

    /** @return list<string> ids in the order given */
    public function storeMany(array $statements, ?string $registrationId = null, ?string $lessonId = null): array
    {
        $ids = [];
        foreach ($statements as $s) {
            $ids[] = $this->store($s, $registrationId, $lessonId)['id'];
        }

        return $ids;
    }

    // ───────────────────────────── querying

    /** @return array{statements: list<array<string, mixed>>, more: string} */
    public function query(array $q): array
    {
        $limit = max(1, min(500, (int) ($q['limit'] ?? 50) ?: 50));
        $offset = max(0, (int) ($q['cursor'] ?? 0));
        $builder = XapiStatement::query()->where('voided', false)->where('verb', '!=', self::VOIDED);
        if (! empty($q['agent'])) {
            $agent = is_string($q['agent']) ? (json_decode($q['agent'], true) ?: []) : $q['agent'];
            $builder->where('actor_key', $this->actorKey($agent) ?? '-');
        }
        $builder->when($q['verb'] ?? null, fn ($w, $v) => $w->where('verb', $v))->when($q['activity'] ?? null, fn ($w, $v) => $w->where('object_id', $v))->when($q['registration'] ?? null, fn ($w, $v) => $w->where('statement->context->registration', $v))
            ->when($q['since'] ?? null, fn ($w, $v) => $w->where('stored', '>', $v))->when($q['until'] ?? null, fn ($w, $v) => $w->where('stored', '<=', $v));
        $rows = $builder->orderBy('stored', ($q['ascending'] ?? false) ? 'asc' : 'desc')->orderBy('id')->offset($offset)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);
        $format = $q['format'] ?? 'exact';

        return ['statements' => $page->map(fn ($r) => $format === 'ids' ? ['actor' => ['objectType' => 'Agent'] + $r->statement['actor'], 'verb' => ['id' => $r->statement['verb']['id']], 'object' => ['id' => $r->statement['object']['id'] ?? null]] + collect($r->statement)->only(['id'])->all() : $r->statement)->values()->all(), 'more' => $more ? '?'.http_build_query(array_merge($q, ['cursor' => $offset + $limit])) : ''];
    }

    public function find(string $id, bool $voided = false): ?array
    {
        $r = XapiStatement::where('id', $id)->where('voided', $voided)->first();

        return $r?->statement;
    }

    // ───────────────────────────── documents (state and profiles)

    public function document(string $kind, array $scope, string $docId): ?XapiDocument
    {
        return XapiDocument::where('kind', $kind)->where('doc_id', $docId)->where('activity_id', $scope['activity_id'] ?? null)->where('agent_key', $scope['agent_key'] ?? null)->where('registration', $scope['registration'] ?? null)->first();
    }

    /** @return list<string> */
    public function documentIds(string $kind, array $scope): array
    {
        return XapiDocument::where('kind', $kind)->where('activity_id', $scope['activity_id'] ?? null)->where('agent_key', $scope['agent_key'] ?? null)->where('registration', $scope['registration'] ?? null)->pluck('doc_id')->all();
    }

    public function saveDocument(string $kind, array $scope, string $docId, string $content, string $contentType, bool $merge = false): XapiDocument
    {
        $doc = $this->document($kind, $scope, $docId);
        if ($doc && $merge && str_contains($doc->content_type, 'json') && str_contains($contentType, 'json')) {
            $content = json_encode(array_replace(json_decode($doc->content, true) ?: [], json_decode($content, true) ?: []), JSON_UNESCAPED_UNICODE);
        }

        return $doc ? tap($doc)->update(['content' => $content, 'content_type' => $contentType]) : XapiDocument::create(['kind' => $kind, 'activity_id' => $scope['activity_id'] ?? null, 'agent_key' => $scope['agent_key'] ?? null, 'registration' => $scope['registration'] ?? null, 'doc_id' => $docId, 'content' => $content, 'content_type' => $contentType]);
    }

    public function deleteDocument(string $kind, array $scope, ?string $docId): void
    {
        XapiDocument::where('kind', $kind)->where('activity_id', $scope['activity_id'] ?? null)->where('agent_key', $scope['agent_key'] ?? null)->where('registration', $scope['registration'] ?? null)->when($docId, fn ($q, $d) => $q->where('doc_id', $d))->delete();
    }

    // ───────────────────────────── TEDC's own activity as xAPI

    /** Records what a trainee did inside TEDC in the standard record, and forwards it when an external LRS is configured. */
    public function native(Registration $r, CourseLesson|null $lesson, string $verb, string $verbName, array $extra = []): void
    {
        $r->loadMissing('employee.user', 'program');
        $user = $r->employee->user;
        $home = rtrim((string) config('tedc.web_url'), '/');
        $statement = [
            'actor' => ['objectType' => 'Agent', 'name' => $user?->name, 'account' => ['homePage' => $home ?: 'https://tedc.local', 'name' => (string) $user?->id]],
            'verb' => ['id' => $verb, 'display' => ['en-US' => $verbName]],
            'object' => ['objectType' => 'Activity', 'id' => ($home ?: 'https://tedc.local').'/activities/'.($lesson ? 'lessons/'.$lesson->id : 'programs/'.$r->program_id), 'definition' => ['name' => ['en-US' => $lesson?->title_en ?? $r->program->title_en], 'type' => 'http://adlnet.gov/expapi/activities/'.($lesson ? 'lesson' : 'course')]],
            'context' => ['registration' => $r->id] + ($lesson ? ['contextActivities' => ['parent' => [['id' => ($home ?: 'https://tedc.local').'/activities/programs/'.$r->program_id]]]] : []),
        ] + $extra;
        try {
            $this->store($statement, $r->id, $lesson?->id);
            $this->forward($statement);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function forward(array $statement): void
    {
        $f = $this->settings->all()['lrs_forward'];
        if (! $f['enabled'] || $f['endpoint'] === '') {
            return;
        }
        \Illuminate\Support\Facades\Http::withBasicAuth($f['key'], $f['secret'])->withHeaders(['X-Experience-API-Version' => self::VERSION])->timeout(5)->post(rtrim($f['endpoint'], '/').'/statements', $statement);
    }
}
