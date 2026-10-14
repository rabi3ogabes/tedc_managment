<?php

namespace App\Integrations\Contracts;

/**
 * One system's contract (what can be asked of it and how) with its mapping (how its records become TEDC's). Both are plain data: the
 * bundled documents in resources/contracts/<system>/, or the administrator's versions of them.
 */
final class Contract
{
    /** @param  array<string, mixed>  $contract  @param  array<string, mixed>  $mapping */
    public function __construct(public readonly string $system, private readonly array $contract, private readonly array $mapping = []) {}

    /** The documents shipped with TEDC. */
    public static function bundled(string $system): self
    {
        $dir = resource_path('contracts/'.$system);
        $read = fn (string $file) => is_file("{$dir}/{$file}") ? (json_decode((string) file_get_contents("{$dir}/{$file}"), true) ?: []) : [];

        return new self($system, $read('contract.json'), $read('mapping.json'));
    }

    public static function exists(string $system): bool
    {
        return is_file(resource_path("contracts/{$system}/contract.json"));
    }

    /** @return list<string> the systems that have a bundled contract */
    public static function systems(): array
    {
        $dirs = glob(resource_path('contracts/*/contract.json')) ?: [];

        return array_values(array_map(fn ($f) => basename(dirname($f)), $dirs));
    }

    /** The same documents under another system's name (Mawared starts from the HR documents). */
    public function forSystem(string $system): self
    {
        return new self($system, ['system' => $system] + $this->contract, $this->mapping);
    }

    public function withDocuments(?array $contract, ?array $mapping): self
    {
        return new self($this->system, $contract ?? $this->contract, $mapping ?? $this->mapping);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->contract;
    }

    /** @return array<string, mixed> */
    public function mappingDocument(): array
    {
        return $this->mapping;
    }

    public function version(): string
    {
        return (string) ($this->contract['version'] ?? '1.0');
    }

    /** @return array<string, mixed> */
    public function auth(): array
    {
        return (array) ($this->contract['auth'] ?? ['type' => 'none']);
    }

    /** How the system signs and numbers what it sends us (signature, idempotency); empty: TEDC's own scheme. @return array<string, mixed> */
    public function inbound(): array
    {
        return (array) ($this->contract['inbound'] ?? []);
    }

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        return (array) ($this->contract['operations'] ?? []);
    }

    /** @return array<string, mixed> */
    public function operation(string $name): array
    {
        return $this->operations()[$name] ?? throw new ContractException('unknown_operation', 'This system\'s contract has no operation "'.mb_substr($name, 0, 40).'".');
    }

    /** @return array{fields: array<string, mixed>, tables?: array<string, mixed>} */
    public function entity(string $name): array
    {
        return (array) ($this->mapping['entities'][$name] ?? ['fields' => []]);
    }

    /** Maps one record of the system into TEDC's fields. @param  array<string, mixed>  $record @return array<string, mixed> */
    public function map(string $entity, array $record): array
    {
        return Mapper::apply($this->entity($entity), $record);
    }

    /** @return list<array<string, mixed>> */
    public function tests(): array
    {
        return array_values((array) ($this->contract['tests'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    public function checklist(): array
    {
        return array_values((array) ($this->contract['checklist'] ?? []));
    }

    /** @return array<string, mixed> the error table for a status (exact, then "4xx"/"5xx", then the contract's defaults) */
    public function errorFor(array $op, int $status): ?array
    {
        $tables = [$op['errors'] ?? [], $this->contract['errors'] ?? []];
        foreach ($tables as $table) {
            foreach ([(string) $status, intdiv($status, 100).'xx', 'default'] as $key) {
                if (isset($table[$key]) && is_array($table[$key])) {
                    return $table[$key];
                }
            }
        }

        return null;
    }
}
