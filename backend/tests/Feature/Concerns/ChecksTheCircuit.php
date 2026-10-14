<?php

namespace Tests\Feature\Concerns;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/** What every system on the contract kit must do when it fails: be paused after five failures in a row, not be called while paused, and work again once it is back. */
trait ChecksTheCircuit
{
    /**
     * @param  callable(int): mixed  $run  one run of the adapter's operation (given the number of this run); it may throw or swallow the failure
     */
    protected function assertTheCircuitOpensAndRecovers(string $key, string $system, string $baseUrl, string $operation, callable $run): void
    {
        $hub = app(IntegrationManager::class);
        ContractFixtures::fake($system, $baseUrl, [$operation => 'server_error']);
        for ($i = 0; $i < 5; $i++) {
            try {
                $run($i);
            } catch (Throwable) {
                // the failure is what is being tested
            }
        }
        $this->assertTrue($hub->present($key)['circuit_open'], "{$key}: five failures in a row pause the system");

        $sent = count(Http::recorded());
        try {
            $run(5);
        } catch (Throwable) {
        }
        $this->assertCount($sent, Http::recorded(), "{$key}: a paused system is not called");

        Carbon::setTestNow(now()->addMinutes(6));
        Http::swap(new HttpFactory);
        ContractFixtures::fake($system, $baseUrl);
        $run(6);
        $this->assertFalse($hub->present($key)['circuit_open'], "{$key}: the system is called again after the pause");
        $this->assertSame('ok', $hub->get($key)->health, "{$key}: and is healthy again once it answers");
        Carbon::setTestNow();
    }
}
