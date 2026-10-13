<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Vercel production deployment always runs as production, whatever APP_ENV the project's dashboard holds.
 * Otherwise every "never in production" guard (demo mode and its shared password, fake payment and sign-in drivers,
 * risky tools off by default) is silently off on the live site, as it was until 12 Oct 2026.
 */
class VercelEnvTest extends TestCase
{
    /** Runs scripts/vercel-env.php in a clean PHP process and returns the APP_ENV it leaves behind. */
    private function appEnvAfterBoot(array $env): string
    {
        $script = base_path('scripts/vercel-env.php');
        $code = 'require '.var_export($script, true).'; tedc_vercel_env(runtime: false); echo getenv("APP_ENV");';
        // false removes a variable the test runner itself has (APP_ENV=testing), so only $env is seen.
        $process = new Process([PHP_BINARY, '-r', $code], null, $env + ['APP_ENV' => false, 'VERCEL_ENV' => false, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        $process->mustRun();

        return trim($process->getOutput());
    }

    public function test_the_production_deployment_runs_as_production_even_when_the_dashboard_says_otherwise(): void
    {
        $this->assertSame('production', $this->appEnvAfterBoot(['VERCEL_ENV' => 'production', 'APP_ENV' => 'staging']));
        $this->assertSame('production', $this->appEnvAfterBoot(['VERCEL_ENV' => 'production', 'APP_ENV' => 'local']));
    }

    public function test_preview_deployments_keep_the_dashboard_value(): void
    {
        $this->assertSame('staging', $this->appEnvAfterBoot(['VERCEL_ENV' => 'preview', 'APP_ENV' => 'staging']));
    }

    public function test_without_a_value_the_default_is_production(): void
    {
        $this->assertSame('production', $this->appEnvAfterBoot(['VERCEL_ENV' => 'production']));
        $this->assertSame('production', $this->appEnvAfterBoot([]));
    }
}
