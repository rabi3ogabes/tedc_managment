<?php

namespace App\Integrations\Contracts;

use App\Integrations\IntegrationManager;
use App\Services\Channels\ChannelSettings;
use App\Services\Communication\MinistrySiteSettings;

/**
 * The adapters' way into the contract kit: the contract in force for a system (shipped or the administrator's), the system's settings,
 * and a driver that carries an operation out. Circuit breaker, retries and the call log stay with IntegrationManager::call(), which
 * wraps these calls; nothing here changes how a system is switched on or off.
 */
final class ContractGateway
{
    public function __construct(private readonly ContractStore $store, private readonly IntegrationManager $hub) {}

    public function contract(string $key): Contract
    {
        return $this->store->active($key);
    }

    /** The system's settings as the driver reads them (the Ministry website and the SMS gateway keep their own settings). @return array<string, mixed> */
    public function settings(string $key): array
    {
        return match ($key) {
            'ministry_site' => app(MinistrySiteSettings::class)->contractSettings(),
            'hudhud' => app(ChannelSettings::class)->contractSettings(),
            default => $this->hub->settings($key),
        };
    }

    /** @param  array<string, mixed>|null  $settings */
    public function driver(string $key, ?array $settings = null): ContractDriver
    {
        return new ContractDriver($this->store->active($key), $settings ?? $this->settings($key), $this->store->secretSettings($key), $key);
    }

    /**
     * Every record of an operation, page after page, mapped into TEDC's fields when the operation names an entity.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $settings
     * @return list<array<string, mixed>>
     *
     * @throws ContractException
     */
    public function records(string $key, string $operation, array $params = [], ?array $settings = null): array
    {
        $driver = $this->driver($key, $settings);
        $entity = $driver->contract()->operation($operation)['entity'] ?? null;
        $rows = [];
        foreach ($driver->each($operation, $params) as $record) {
            $rows[] = $entity ? $driver->contract()->map((string) $entity, $record) : $record;
        }

        return $rows;
    }

    /**
     * One call of an operation.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $settings
     * @param  array{idempotency_key?: string, query?: array<string, mixed>}  $options
     *
     * @throws ContractException
     */
    public function call(string $key, string $operation, array $params = [], ?array $settings = null, array $options = []): ContractResponse
    {
        return $this->driver($key, $settings)->call($operation, $params, $options);
    }

    /** Whether the system's contract has a connection check of its own. */
    public function canPing(string $key): bool
    {
        return $this->store->has($key) && isset($this->store->active($key)->operations()['health']);
    }

    /**
     * The health of a system through its contract: ok, degraded (it answered, but not as it should: refused the credentials, no such address)
     * or down (it did not answer, or failed). @param  array<string, mixed>  $settings
     *
     * @throws ContractException when the system cannot be reached at all
     */
    public function ping(string $key, array $settings): string
    {
        try {
            $this->driver($key, $settings)->call('health');

            return 'ok';
        } catch (ContractException $e) {
            if ($e->status >= 400 && $e->status < 500) {
                return 'degraded';
            }
            if ($e->status >= 500) {
                return 'down';
            }

            throw $e;
        }
    }
}
