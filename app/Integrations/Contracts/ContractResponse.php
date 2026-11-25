<?php

namespace App\Integrations\Contracts;

use JsonSchema\Validator;
use Throwable;

/** What a system answered, read through the operation's response map. */
final class ContractResponse
{
    /** @param  array<string, mixed>|null  $json  @param  array<string, mixed>  $op  the operation of the contract */
    public function __construct(public readonly int $status, public readonly ?array $json, public readonly string $body, private readonly array $op = []) {}

    /** The records the operation returns (the list at response.records, or the whole answer when it is a list). @return list<array<string, mixed>> */
    public function records(): array
    {
        $path = $this->op['response']['records'] ?? null;
        $found = $path ? JsonPath::get($this->json, $path) : $this->json;

        return is_array($found) ? array_values(array_filter($found, 'is_array')) : [];
    }

    /** A named value of the answer (response.values.<name>), such as a ticket number. A list of paths is tried in order: the first that has a value wins. */
    public function value(string $name): mixed
    {
        $paths = $this->op['response']['values'][$name] ?? null;
        foreach (is_array($paths) ? $paths : [$paths] as $path) {
            $found = is_string($path) && $path !== '' ? JsonPath::get($this->json, $path) : null;
            if ($found !== null && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    /** next_page, next_cursor or total, when the operation names where to find them. */
    public function marker(string $name): mixed
    {
        $path = $this->op['response'][$name] ?? null;

        return $path ? JsonPath::get($this->json, $path) : null;
    }

    /** What is wrong with the answer against the operation's response schema (empty: it fits, or there is no schema). @return list<string> */
    public function schemaProblems(): array
    {
        return self::validate($this->json, (array) ($this->op['response_schema'] ?? []));
    }

    /** @param  array<string, mixed>  $schema @return list<string> */
    public static function validate(mixed $data, array $schema): array
    {
        if ($schema === []) {
            return [];
        }
        try {
            $validator = new Validator;
            $value = json_decode((string) json_encode($data));
            $validator->validate($value, json_decode((string) json_encode($schema)));

            return array_map(fn ($e) => ($e['property'] !== '' ? $e['property'].': ' : '').$e['message'], $validator->getErrors());
        } catch (Throwable $e) {
            return ['the schema could not be applied: '.mb_substr($e->getMessage(), 0, 120)];
        }
    }
}
