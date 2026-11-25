<?php

namespace App\Integrations\Contracts;

use App\Integrations\IntegrationAudit;
use App\Integrations\IntegrationRegistry;
use App\Models\IntegrationContractVersion;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Where a system's contract and mapping come from: the documents shipped with TEDC, or the administrator's versions of them. Every save
 * is checked first (the same checks the shipped documents pass), becomes a new numbered version, and is audited; a rollback never
 * rewrites history, it adds a version equal to an older one. Version 0 is always the shipped document.
 */
final class ContractStore
{
    /** Systems that start from another system's documents (Mawared is the government HR system: same shape as the current one). */
    public const ALIASES = ['mawared' => 'hr'];

    public const KINDS = ['contract', 'mapping'];

    /** Systems whose settings do not live on their integration card, with the settings that are secrets. */
    private const OWN_SECRETS = ['ministry_site' => ['api_key'], 'hudhud' => ['api_key', 'password', 'receipt_secret']];

    /** What the settings that live outside the integration card (Communication → Ministry website, Settings → Notification channels) offer a contract. */
    private const OWN_SETTINGS = [
        'ministry_site' => ['base_url', 'publish_path', 'publish_query', 'auth_header', 'api_key', 'site_name'],
        'hudhud' => ['base_url', 'send_path', 'api_key', 'username', 'password', 'sender', 'receipt_url', 'receipt_secret'],
    ];

    public function has(string $key): bool
    {
        return Contract::exists(self::ALIASES[$key] ?? $key);
    }

    /** The names of the system's settings that hold secrets (a contract may never read them in a template). @return list<string> */
    public function secretSettings(string $key): array
    {
        return self::OWN_SECRETS[$key] ?? (isset(IntegrationRegistry::all()[$key]) ? IntegrationRegistry::secretFields($key) : []);
    }

    /** Every setting the system has (a contract may only name those); null: not known. @return list<string>|null */
    public function knownSettings(string $key): ?array
    {
        return self::OWN_SETTINGS[$key] ?? (isset(IntegrationRegistry::all()[$key]) ? array_column(IntegrationRegistry::all()[$key]['fields'], 'k') : null);
    }

    /** The documents shipped with TEDC for a system. */
    public function bundled(string $key): Contract
    {
        $source = self::ALIASES[$key] ?? $key;

        return Contract::bundled($source)->forSystem($key);
    }

    /** The contract in force: the shipped one with the administrator's latest versions applied. */
    public function active(string $key): Contract
    {
        $this->known($key);

        return $this->bundled($key)->withDocuments($this->latestDocument($key, 'contract'), $this->latestDocument($key, 'mapping'));
    }

    /** The version in force (0: the document shipped with TEDC). */
    public function activeVersion(string $key, string $kind): int
    {
        return (int) IntegrationContractVersion::where('integration_key', $key)->where('kind', $kind)->max('version');
    }

    /**
     * Every version, newest first, the shipped document last as version 0.
     *
     * @return list<array{version: int, source: string, restored_from: ?int, note: ?string, created_by: ?string, created_by_name: ?string, created_at: ?string, active: bool}>
     */
    public function versions(string $key, string $kind): array
    {
        $this->known($key);
        $this->kind($kind);
        $active = $this->activeVersion($key, $kind);
        $names = User::whereIn('id', IntegrationContractVersion::where('integration_key', $key)->where('kind', $kind)->whereNotNull('created_by')->pluck('created_by'))->get()->keyBy('id');
        $rows = IntegrationContractVersion::where('integration_key', $key)->where('kind', $kind)->orderByDesc('version')->get()->map(fn (IntegrationContractVersion $v) => [
            'version' => $v->version, 'source' => $v->source, 'restored_from' => $v->restored_from, 'note' => $v->note, 'created_by' => $v->created_by,
            'created_by_name' => $v->created_by ? $names->get($v->created_by)?->displayName() : null, 'created_at' => $v->created_at?->toIso8601String(), 'active' => $v->version === $active,
        ])->all();
        $rows[] = ['version' => 0, 'source' => 'bundled', 'restored_from' => null, 'note' => null, 'created_by' => null, 'created_by_name' => null, 'created_at' => null, 'active' => $active === 0];

        return $rows;
    }

    /** The document of a version (0, or a version that went back to the shipped one: the shipped document). @return array<string, mixed> */
    public function document(string $key, string $kind, int $version): array
    {
        $this->known($key);
        $this->kind($kind);
        if ($version === 0) {
            return $this->shipped($key, $kind);
        }
        $row = IntegrationContractVersion::where('integration_key', $key)->where('kind', $kind)->where('version', $version)->first()
            ?? throw new InvalidContract(['there is no version '.$version.' of this '.$kind]);

        return $row->document ?? $this->shipped($key, $kind);
    }

    /** @return list<array{path: string, change: string, before: mixed, after: mixed}> */
    public function diff(string $key, string $kind, int $from, int $to): array
    {
        return ContractDiff::between($this->document($key, $kind, $from), $this->document($key, $kind, $to));
    }

    /** Saves the administrator's version of a contract or a mapping. @param  array<string, mixed>  $document @throws InvalidContract */
    public function save(string $key, string $kind, array $document, ?string $userId, ?string $note = null, ?string $ip = null): IntegrationContractVersion
    {
        $this->known($key);
        $this->kind($kind);
        $problems = ContractValidator::problems($key, $kind, $document, $this->secretSettings($key), $this->knownSettings($key));
        if ($problems !== []) {
            throw new InvalidContract($problems);
        }
        $before = $this->document($key, $kind, $this->activeVersion($key, $kind));
        $row = $this->append($key, $kind, $document, 'override', null, $userId, $note);
        $this->audit($key, 'integration.contract.saved', $kind, $row, $userId, $ip, ContractDiff::between($before, $document));

        return $row;
    }

    /** Makes an older version (0: the shipped document) the one in force again, as a new version. @throws InvalidContract */
    public function rollback(string $key, string $kind, int $toVersion, ?string $userId, ?string $ip = null): IntegrationContractVersion
    {
        $document = $this->document($key, $kind, $toVersion);   // a version that does not exist is refused here
        $target = $toVersion === 0 ? null : IntegrationContractVersion::where('integration_key', $key)->where('kind', $kind)->where('version', $toVersion)->value('document');
        if ($target !== null) {
            $problems = ContractValidator::problems($key, $kind, $document, $this->secretSettings($key), $this->knownSettings($key));   // the rules may have grown since it was saved
            if ($problems !== []) {
                throw new InvalidContract($problems);
            }
        }
        $before = $this->document($key, $kind, $this->activeVersion($key, $kind));
        $row = $this->append($key, $kind, $target, 'rollback', $toVersion, $userId, null);
        $this->audit($key, 'integration.contract.rolled_back', $kind, $row, $userId, $ip, ContractDiff::between($before, $document));

        return $row;
    }

    /** Back to the document shipped with TEDC, as a new version (the administrator's versions stay in the history). */
    public function reset(string $key, string $kind, ?string $userId, ?string $ip = null): IntegrationContractVersion
    {
        $this->known($key);
        $this->kind($kind);
        $before = $this->document($key, $kind, $this->activeVersion($key, $kind));
        $row = $this->append($key, $kind, null, 'reset', 0, $userId, null);
        $this->audit($key, 'integration.contract.reset', $kind, $row, $userId, $ip, ContractDiff::between($before, $this->shipped($key, $kind)));

        return $row;
    }

    // ---- internals ------------------------------------------------------------------------------------

    private function known(string $key): void
    {
        if (! $this->has($key)) {
            throw new InvalidContract(['there is no contract for "'.mb_substr($key, 0, 30).'"']);
        }
    }

    private function kind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidContract(['unknown kind "'.mb_substr($kind, 0, 20).'"']);
        }
    }

    /** @return array<string, mixed> */
    private function shipped(string $key, string $kind): array
    {
        $bundled = $this->bundled($key);

        return $kind === 'contract' ? $bundled->document() : $bundled->mappingDocument();
    }

    /** @return array<string, mixed>|null null: nothing overrides the shipped document */
    private function latestDocument(string $key, string $kind): ?array
    {
        return IntegrationContractVersion::where('integration_key', $key)->where('kind', $kind)->orderByDesc('version')->first()?->document;
    }

    /** @param  array<string, mixed>|null  $document */
    private function append(string $key, string $kind, ?array $document, string $source, ?int $restoredFrom, ?string $userId, ?string $note): IntegrationContractVersion
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(fn () => IntegrationContractVersion::create([
                    'integration_key' => $key, 'kind' => $kind, 'version' => $this->activeVersion($key, $kind) + 1, 'document' => $document, 'source' => $source,
                    'restored_from' => $restoredFrom, 'note' => $note !== null ? mb_substr(trim($note), 0, 200) : null, 'created_by' => $userId,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;   // two administrators saved at the very same moment, three times in a row
                }
            }
        }
    }

    /** @param  list<array{path: string}>  $changes */
    private function audit(string $key, string $action, string $kind, IntegrationContractVersion $row, ?string $userId, ?string $ip, array $changes): void
    {
        IntegrationAudit::record($key, $action, $userId, [
            'kind' => $kind, 'version' => $row->version, 'note' => $row->note, 'restored_from' => $row->restored_from,
            'changed_paths' => array_slice(array_column($changes, 'path'), 0, 30), 'changes' => count($changes),
        ], null, $ip);
    }
}
