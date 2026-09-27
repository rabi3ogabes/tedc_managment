<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Vercel environment defaults (backend/scripts/vercel-env.php), run in a child process so no variable leaks. */
class VercelEnvTest extends TestCase
{
    private function run(array $env): array
    {
        $script = 'require '.var_export(realpath(__DIR__.'/../../scripts/vercel-env.php'), true).'; tedc_vercel_env(false);'
            .' echo json_encode(["DB_URL" => getenv("DB_URL"), "DB_CONNECTION" => getenv("DB_CONNECTION"), "APP_ENV" => getenv("APP_ENV")]);';
        $vars = '';
        foreach ($env as $key => $value) {
            $vars .= $key.'='.escapeshellarg($value).' ';
        }
        $output = shell_exec('env -i PATH='.escapeshellarg((string) getenv('PATH')).' '.$vars.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));

        return json_decode((string) $output, true) ?? [];
    }

    public function test_supabase_session_pooler_url_is_switched_to_the_transaction_pooler_with_ssl(): void
    {
        $result = $this->run(['DB_URL' => 'postgresql://postgres.ref:pw@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres']);

        $this->assertSame('postgresql://postgres.ref:pw@aws-0-ap-southeast-1.pooler.supabase.com:6543/postgres?sslmode=require', $result['DB_URL']);
        $this->assertSame('pgsql', $result['DB_CONNECTION']);
    }

    public function test_values_set_in_vercel_win_over_defaults(): void
    {
        $result = $this->run(['DB_URL' => 'postgresql://u:p@db.example.com:5432/app', 'DB_CONNECTION' => 'pgsql', 'APP_ENV' => 'staging']);

        $this->assertSame('postgresql://u:p@db.example.com:5432/app', $result['DB_URL']);
        $this->assertSame('staging', $result['APP_ENV']);
    }
}
