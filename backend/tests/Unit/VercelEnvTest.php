<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Vercel environment defaults (backend/scripts/vercel-env.php). */
class VercelEnvTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['DB_URL', 'DB_CONNECTION'] as $key) {
            $this->saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $e, $s]) {
            $env === false ? putenv($key) : putenv("{$key}={$env}");
            $e === null ? $_ENV[$key] = null : $_ENV[$key] = $e;
            $s === null ? $_SERVER[$key] = null : $_SERVER[$key] = $s;
            if ($e === null) {
                unset($_ENV[$key]);
            }
            if ($s === null) {
                unset($_SERVER[$key]);
            }
        }
        parent::tearDown();
    }

    public function test_supabase_session_pooler_url_is_switched_to_the_transaction_pooler_with_ssl(): void
    {
        require_once __DIR__.'/../../scripts/vercel-env.php';
        putenv('DB_URL=postgresql://postgres.ref:pw@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres');

        tedc_vercel_env(runtime: false);

        $this->assertSame('postgresql://postgres.ref:pw@aws-0-ap-southeast-1.pooler.supabase.com:6543/postgres?sslmode=require', getenv('DB_URL'));
        $this->assertSame('pgsql', getenv('DB_CONNECTION'));
    }
}
