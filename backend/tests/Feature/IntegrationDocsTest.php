<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\IntegrationGuide;
use Tests\TestCase;

/** The go-live guide of each Ministry system is written from its shipped contract, so it cannot say anything the contract does not. */
class IntegrationDocsTest extends TestCase
{
    private function store(): ContractStore
    {
        return app(ContractStore::class);
    }

    public function test_the_committed_guides_are_current(): void
    {
        $this->artisan('tedc:integration-docs', ['--check' => true])->assertSuccessful();
    }

    public function test_each_guide_is_complete_in_both_languages(): void
    {
        $guide = app(IntegrationGuide::class);
        foreach (IntegrationGuide::GUIDES as $file => $key) {
            $text = $guide->render($key);
            $this->assertStringContainsString('## العربية', $text, $file);
            $this->assertStringContainsString('## English', $text, $file);
            $headings = IntegrationGuide::headings($this->store()->bundled($key));
            foreach ([$headings['ministry_provides']['ar'], $headings['ministry_provides']['en'], $headings['joint_tests']['ar'], $headings['joint_tests']['en'], 'خطة التراجع', 'Rollback plan'] as $heading) {
                $this->assertStringContainsString($heading, $text, "{$file}: {$heading}");
            }
            $this->assertGreaterThanOrEqual(8, substr_count($text, '- [ ] '), "{$file} lists what to ask for and what to confirm");
        }
    }

    public function test_a_learning_provider_s_guide_speaks_of_the_provider_and_names_where_the_contract_comes_from(): void
    {
        foreach (['coursera', 'udemy', 'linkedin_learning', 'edx'] as $key) {
            $text = app(IntegrationGuide::class)->render($key);
            foreach (['What the provider gives you', 'ما يقدّمه المزوّد', 'Tests to run with the provider', 'Sources the contract was written from', 'المصادر التي كُتب منها العقد'] as $heading) {
                $this->assertStringContainsString($heading, $text, "{$key}: {$heading}");
            }
            $this->assertStringNotContainsString('the Ministry', $text, $key);
            $this->assertStringNotContainsString('الوزارة', $text, $key);
            $this->assertGreaterThanOrEqual(2, substr_count($text, 'https://'), "{$key} lists its sources");
        }
    }

    public function test_a_guide_never_prints_a_secret_or_a_real_looking_address(): void
    {
        $guide = app(IntegrationGuide::class);
        foreach (IntegrationGuide::GUIDES as $file => $key) {
            $text = $guide->render($key);
            $this->assertDoesNotMatchRegularExpression('/Bearer\s+[A-Za-z0-9._-]{12,}/', $text, $file);
            $this->assertDoesNotMatchRegularExpression('/https?:\/\/[a-z0-9.-]+\.(gov\.qa|edu\.qa)/i', $text, $file);
        }
    }
}
