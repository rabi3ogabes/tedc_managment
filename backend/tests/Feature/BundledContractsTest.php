<?php

namespace Tests\Feature;

use App\Integrations\Contracts\Contract;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\ContractValidator;
use App\Integrations\IntegrationRegistry;
use Tests\TestCase;

/** The contracts, mappings, fixtures and test cases TEDC ships are data that has to be as correct as anything an administrator may save. */
class BundledContractsTest extends TestCase
{
    /** @return list<string> */
    private function systems(): array
    {
        return Contract::systems();
    }

    public function test_every_system_with_a_contract_is_known_and_has_the_expected_documents(): void
    {
        $this->assertEqualsCanonicalizing(['hr', 'licences', 'ministry_site', 'nsis', 'qneds', 'saaed', 'sijil'], $this->systems());
        foreach ($this->systems() as $system) {
            $this->assertArrayHasKey($system, IntegrationRegistry::all(), $system);
            $this->assertFileExists(resource_path("contracts/{$system}/mapping.json"));
            $this->assertDirectoryExists(resource_path("contracts/{$system}/fixtures"));
        }
        $this->assertArrayHasKey('mawared', ContractStore::ALIASES);
    }

    public function test_every_shipped_contract_and_mapping_passes_the_same_checks_as_an_administrator_s_override(): void
    {
        $store = app(ContractStore::class);
        foreach (array_merge($this->systems(), array_keys(ContractStore::ALIASES)) as $key) {
            $contract = $store->bundled($key);
            $this->assertNotNull($store->knownSettings($key), $key);
            $this->assertSame([], ContractValidator::problems($key, 'contract', $contract->document(), $store->secretSettings($key), $store->knownSettings($key)), "{$key} contract");
            $this->assertSame([], ContractValidator::problems($key, 'mapping', $contract->mappingDocument(), $store->secretSettings($key), $store->knownSettings($key)), "{$key} mapping");
        }
    }

    public function test_every_test_case_names_an_operation_and_a_fixture_that_exist_and_every_entity_has_a_mapping(): void
    {
        foreach ($this->systems() as $system) {
            $contract = Contract::bundled($system);
            $this->assertNotSame([], $contract->tests(), "{$system} has test cases");
            foreach ($contract->tests() as $case) {
                $this->assertArrayHasKey($case['operation'], $contract->operations(), "{$system}: {$case['name']}");
                $this->assertFileExists(resource_path("contracts/{$system}/fixtures/{$case['fixture']}.json"), "{$system}: {$case['name']}");
            }
            foreach ($contract->operations() as $name => $op) {
                if (isset($op['entity'])) {
                    $this->assertNotSame([], $contract->entity($op['entity'])['fields'] ?? [], "{$system}.{$name} maps to the entity {$op['entity']}");
                }
            }
            foreach (glob(resource_path("contracts/{$system}/fixtures/*.json")) as $file) {
                $fixture = json_decode((string) file_get_contents($file), true);
                $this->assertIsArray($fixture, $file);
                $this->assertArrayHasKey('status', $fixture, $file);
                $this->assertArrayHasKey('body', $fixture, $file);
            }
        }
    }

    public function test_every_checklist_has_the_same_steps_in_both_languages_and_unique_ids(): void
    {
        foreach ($this->systems() as $system) {
            $items = Contract::bundled($system)->checklist();
            $this->assertGreaterThanOrEqual(10, count($items), $system);
            $ids = array_column($items, 'id');
            $this->assertSame($ids, array_values(array_unique($ids)), "{$system}: ids are unique");
            foreach ($items as $item) {
                $this->assertNotSame('', trim((string) ($item['ar'] ?? '')), "{$system}.{$item['id']} Arabic");
                $this->assertNotSame('', trim((string) ($item['en'] ?? '')), "{$system}.{$item['id']} English");
                $this->assertContains($item['group'], ['ministry_provides', 'tedc_settings', 'joint_tests', 'rollback'], "{$system}.{$item['id']}");
            }
            $this->assertContains('rollback', array_column($items, 'group'), "{$system} has a rollback plan");
        }
    }

    public function test_no_shipped_document_holds_a_secret_or_a_real_looking_address(): void
    {
        foreach ($this->systems() as $system) {
            foreach (['contract.json', 'mapping.json'] as $file) {
                $text = (string) file_get_contents(resource_path("contracts/{$system}/{$file}"));
                $this->assertDoesNotMatchRegularExpression('/https?:\/\/(?!tedc\.example)[a-z0-9.-]+\.(qa|gov)/i', $text, "{$system}/{$file}");
                $this->assertDoesNotMatchRegularExpression('/Bearer\s+[A-Za-z0-9._-]{10,}/', $text, "{$system}/{$file}");
            }
        }
    }
}
