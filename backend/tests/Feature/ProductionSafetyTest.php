<?php

namespace Tests\Feature;

use App\Services\ErrorLogService;
use App\Services\FeatureSettings;
use App\Support\DemoGuard;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    private function production(): void
    {
        $this->app['env'] = 'production';
        app(FeatureSettings::class)->flush();
    }

    public function test_demo_data_is_never_seeded_in_production_without_an_explicit_switch(): void
    {
        $this->assertTrue(DemoGuard::allowed());

        $this->production();
        config(['tedc.demo.allow_in_production' => false]);
        $this->assertFalse(DemoGuard::allowed());

        config(['tedc.demo.allow_in_production' => true]);
        $this->assertTrue(DemoGuard::allowed(), 'the demo deployment can still opt in on purpose');
    }

    public function test_in_production_the_error_fixer_only_suggests_unless_self_heal_is_on(): void
    {
        $this->production();
        $log = app(ErrorLogService::class)->record(['source' => 'server', 'level' => 'error', 'message' => 'SQLSTATE[HY000]: General error: 1 no such table: demo', 'url' => '/api/v1/x']);
        $before = $log->fresh()->fix_attempts;

        $result = app(ErrorLogService::class)->tryFix($log);

        $this->assertFalse($result['fixed']);
        $this->assertTrue($result['suggested']);
        $this->assertSame($before, $log->fresh()->fix_attempts, 'nothing was attempted');
        $this->assertSame(0, $before);
    }

    public function test_the_scheduled_self_heal_and_scenario_commands_do_nothing_in_production(): void
    {
        $this->production();

        $this->assertSame(0, Artisan::call('tedc:scenario-advance'));
        $this->assertStringContainsString('switched off', Artisan::output());
        $this->assertSame(0, Artisan::call('tedc:self-heal'));
        $this->assertStringContainsString('switched off', Artisan::output());
    }

    public function test_the_sign_in_screens_are_told_to_hide_the_demo_accounts_on_a_live_system(): void
    {
        $this->getJson('/api/v1/public/mobile-config')->assertOk()->assertJsonPath('data.demo_accounts', true);

        $this->production();
        config(['tedc.demo.allow_in_production' => false]);
        $this->getJson('/api/v1/public/mobile-config')->assertOk()->assertJsonPath('data.demo_accounts', false);
    }
}
