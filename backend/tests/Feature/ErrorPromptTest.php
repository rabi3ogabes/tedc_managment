<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Role;
use Tests\TestCase;

class ErrorPromptTest extends TestCase
{
    private function log(array $a = []): ErrorLog
    {
        return ErrorLog::create($a + [
            'fingerprint' => md5(uniqid()), 'source' => 'server', 'level' => 'error', 'message' => 'Division by zero', 'exception' => 'DivisionByZeroError', 'location' => 'app/Services/X.php:12',
            'occurrences' => 3, 'users_count' => 2, 'user_email' => 'secret.person@example.com', 'ip' => '203.0.113.9', 'stack' => "#0 a\n#1 b", 'status' => 'open',
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);
    }

    public function test_the_prompt_lists_the_errors_worst_first_and_never_includes_personal_details(): void
    {
        $this->log(['message' => 'Minor warning', 'level' => 'warning', 'occurrences' => 99]);
        $this->log(['message' => 'Payment crashed', 'level' => 'critical', 'occurrences' => 1]);
        $this->log(['message' => 'Closed one', 'status' => 'fixed']);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $res = $this->asUser($admin)->getJson('/api/v1/admin/error-logs/prompt?status=open')->assertOk();
        $text = $res->json('data.text');

        $this->assertSame(2, $res->json('data.count'));
        $this->assertLessThan(strpos($text, 'Minor warning'), strpos($text, 'Payment crashed'), 'critical comes before a frequent warning');
        $this->assertStringNotContainsString('Closed one', $text);
        $this->assertStringContainsString('Laravel 13', $text);
        $this->assertStringContainsString('app/Services/X.php:12', $text);
        $this->assertStringNotContainsString('secret.person@example.com', $text);
        $this->assertStringNotContainsString('203.0.113.9', $text);
    }

    public function test_chosen_errors_only_and_a_limit_with_a_note_about_the_rest(): void
    {
        $a = $this->log(['message' => 'Only this']);
        $this->log(['message' => 'Not this']);
        $this->log(['message' => 'Nor this']);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $one = $this->asUser($admin)->getJson('/api/v1/admin/error-logs/prompt?ids='.$a->id)->assertOk();
        $this->assertSame(1, $one->json('data.count'));
        $this->assertStringNotContainsString('Not this', $one->json('data.text'));

        $limited = $this->asUser($admin)->getJson('/api/v1/admin/error-logs/prompt?limit=2')->assertOk();
        $this->assertTrue($limited->json('data.truncated'));
        $this->assertStringContainsString('most important of 3', $limited->json('data.text'));
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/error-logs/prompt')->assertForbidden();
    }
}
