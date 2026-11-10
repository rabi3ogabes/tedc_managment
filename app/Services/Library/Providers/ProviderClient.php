<?php

namespace App\Services\Library\Providers;

use App\Integrations\Contracts\ContractGateway;
use App\Integrations\IntegrationManager;
use App\Models\Integration;
use Illuminate\Support\Carbon;

/**
 * One learning provider as TEDC sees it: its card in the integration hub (switched on or off, training mode or the provider's own API,
 * settings, health, log, circuit breaker) and the operations of its contract. In the training mode nothing leaves TEDC.
 */
final class ProviderClient
{
    public function __construct(public readonly string $provider, private readonly IntegrationManager $hub, private readonly ContractGateway $contracts) {}

    public function integration(): Integration
    {
        return $this->hub->get($this->provider);
    }

    public function enabled(): bool
    {
        return $this->integration()->enabled;
    }

    public function training(): bool
    {
        return $this->integration()->driver === 'fake';
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->hub->settings($this->provider);
    }

    /** Whether the provider reports completions for everyone at once (otherwise they are asked for person by person). */
    public function reportsForEveryone(): bool
    {
        return isset($this->contracts->contract($this->provider)->operations()['list_completions']);
    }

    public function reportsPerPerson(): bool
    {
        return isset($this->contracts->contract($this->provider)->operations()['learner_completions']);
    }

    /** The longest stretch of time one call of list_completions can ask for, in days (null: no limit). */
    public function windowDays(): ?int
    {
        $days = $this->contracts->contract($this->provider)->operations()['list_completions']['windows']['max_days'] ?? null;

        return is_int($days) ? $days : null;
    }

    /** @return list<array<string, mixed>> the provider's courses, in TEDC's field names (since: only what changed after then, when the provider can say) */
    public function courses(?Carbon $since = null): array
    {
        if ($this->training()) {
            return $this->sample('fake_catalogue') ?: $this->sampleCatalogue();
        }

        return $this->hub->call($this->provider, 'list_courses', fn (array $s) => $this->contracts->records($this->provider, 'list_courses', $this->since($since), $s), ['since' => $since?->toDateString()]);
    }

    /** @return list<array<string, mixed>> completions reported for a stretch of time, in TEDC's field names */
    public function completions(Carbon $from, Carbon $to): array
    {
        if ($this->training()) {
            return $this->sample('fake_completions');
        }
        $step = $this->windowDays();
        $rows = [];
        for ($start = $from->copy()->utc(); $start->lt($to); $start = $start->copy()->addDays($step ?? 3650)) {
            $end = $step === null ? $to->copy()->utc() : $start->copy()->addDays($step)->min($to->copy()->utc());
            $params = $this->since($from) + [
                'window_from_ms' => $start->getTimestamp() * 1000, 'window_to_ms' => $end->getTimestamp() * 1000, 'window_from' => $start->format('Y-m-d'), 'window_to' => $end->format('Y-m-d'),
                'window_days' => max(1, (int) ceil($start->diffInSeconds($end) / 86400)),
            ];
            $rows = array_merge($rows, $this->hub->call($this->provider, 'list_completions', fn (array $s) => $this->contracts->records($this->provider, 'list_completions', $params, $s), ['from' => $start->toDateString(), 'to' => $end->toDateString()]));
            if ($step === null) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> the completions of one person (a provider that cannot report for everyone) */
    public function learnerCompletions(string $username): array
    {
        if ($this->training()) {
            return [];
        }

        return $this->hub->call($this->provider, 'learner_completions', fn (array $s) => $this->contracts->records($this->provider, 'learner_completions', ['username' => $username], $s), ['person' => 'one']);
    }

    // ---- internals ------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function since(?Carbon $since): array
    {
        return $since === null ? [] : ['since_ms' => $since->getTimestamp() * 1000, 'since_s' => $since->getTimestamp(), 'since' => $since->copy()->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    /** @return list<array<string, mixed>> the rows a training-mode setting holds (a list, or JSON text) */
    private function sample(string $setting): array
    {
        $value = $this->settings()[$setting] ?? [];
        $rows = is_string($value) ? json_decode($value, true) : $value;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    private function sampleCatalogue(): array
    {
        $name = ucfirst(str_replace('_', ' ', $this->provider));

        return [
            ['id' => $this->provider.'-101', 'title' => $name.' course 101', 'url' => "https://{$this->provider}.example/c/101", 'hours' => 6],
            ['id' => $this->provider.'-102', 'title' => $name.' course 102', 'url' => "https://{$this->provider}.example/c/102", 'hours' => 10],
        ];
    }
}
