<?php

namespace Tests\Feature;

use App\Integrations\Contracts\IntegrationGuide;
use Tests\TestCase;

/** The go-live guide of each Ministry system is written from its shipped contract, so it cannot say anything the contract does not. */
class IntegrationDocsTest extends TestCase
{
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
            foreach (['ما تقدّمه الوزارة', 'What the Ministry provides', 'اختبارات الإطلاق مع الوزارة', 'Tests to run with the Ministry', 'خطة التراجع', 'Rollback plan'] as $heading) {
                $this->assertStringContainsString($heading, $text, "{$file}: {$heading}");
            }
            $this->assertGreaterThanOrEqual(8, substr_count($text, '- [ ] '), "{$file} lists what to ask for and what to confirm");
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
