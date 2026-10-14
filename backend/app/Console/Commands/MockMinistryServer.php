<?php

namespace App\Console\Commands;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\MockMinistry;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MockMinistryServer extends Command
{
    protected $signature = 'tedc:mock-ministry
        {system : hr, mawared, licences, nsis, qneds, saaed, sijil, ministry_site, hudhud, moe_epay, or a learning provider (coursera, udemy, linkedin_learning, edx)}
        {--port=8099 : The port to listen on}
        {--host=127.0.0.1 : The address to listen on}
        {--scenario=ok : What it answers by default: ok, unauthorised, server_error, slow… (a request can choose with X-Mock-Scenario)}
        {--key= : Only this key is accepted (any key is accepted when it is not given)}
        {--setting=* : A setting that decides an address, such as publish_path=/ingest or org_id=ORG-1}
        {--routes : Only list what the mock answers}';

    protected $description = 'Starts a local mock of a Ministry system that answers its contract from the recorded fixtures (for UAT and demos; never in production)';

    public function handle(): int
    {
        $system = (string) $this->argument('system');
        $known = array_merge(Contract::systems(), array_keys(ContractStore::ALIASES));
        if (! in_array($system, $known, true)) {
            $this->error('There is no mock for "'.$system.'". The systems with a contract: '.implode(', ', $known).'.');

            return self::FAILURE;
        }
        if (app()->isProduction()) {
            $this->error('The mock of a Ministry system does not run in production.');

            return self::FAILURE;
        }
        $settings = [];
        foreach ((array) $this->option('setting') as $pair) {
            [$name, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $settings[trim($name)] = $value;
        }
        $mock = MockMinistry::fromDirectory(ContractFixtures::dir($system), $system, (string) $this->option('scenario'), $this->option('key') ?: null)->withSettings($settings);

        if ($this->option('routes')) {
            $this->line('The mock of "'.$system.'" answers:');
            foreach ($mock->routes() as $route) {
                $this->line(sprintf('  %-5s %s   (%s)', $route['method'], $route['path'], $route['operation']));
            }

            return self::SUCCESS;
        }

        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->warn('The mock listens on '.$host.': anyone who can reach it can read the fixtures. Use it on a private network only.');
        }
        $this->info("The mock of \"{$system}\" listens on http://{$host}:{$port}");
        $this->line('Point the integration card at it: driver http, address http://'.$host.':'.$port.', any key'.($this->option('key') ? ' (the one you gave)' : '').'.');
        $auth = $mock->tokenUrl("http://{$host}:{$port}");
        if ($auth !== null) {
            $path = (string) parse_url($auth, PHP_URL_PATH);
            $this->line('This system signs in with a token: the mock issues one at http://'.$host.':'.$port.$path.' — put that address in the integration\'s "token_url" setting (any client id and secret).');
        }
        $this->line('Ask for a failure with the header X-Mock-Scenario: unauthorised | server_error | slow (or start it with --scenario). Stop with Ctrl+C.');

        $server = new Process([PHP_BINARY, '-S', "{$host}:{$port}", base_path('scripts/mock-ministry-router.php')], base_path(), [
            'TEDC_MOCK_SYSTEM' => $system, 'TEDC_MOCK_DIR' => ContractFixtures::dir($system), 'TEDC_MOCK_SCENARIO' => (string) $this->option('scenario'),
            'TEDC_MOCK_KEY' => (string) $this->option('key'), 'TEDC_MOCK_SETTINGS' => (string) json_encode($settings),
        ]);
        $server->setTimeout(null);
        $server->run(fn ($type, $buffer) => $this->output->write($buffer));

        return self::SUCCESS;
    }
}
