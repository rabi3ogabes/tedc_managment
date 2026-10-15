<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\EmailSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Self-service password reset: a link by e-mail, a new password under the policy, other sessions ended. */
class PasswordResetTest extends TestCase
{
    /** @var list<array{to: string, heading: string, text: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $outbox = &$this->sent;
        $this->app->instance(EmailSender::class, new class(app(ChannelSettings::class), $outbox) extends EmailSender
        {
            public function __construct(ChannelSettings $s, private array &$outbox)
            {
                parent::__construct($s);
            }

            public function send(string $to, string $heading, ?string $text, string $locale = 'ar'): array
            {
                $this->outbox[] = ['to' => $to, 'heading' => $heading, 'text' => $text];

                return ['ok' => true, 'error' => null];
            }
        });
        $this->mailOn();
    }

    private function mailOn(bool $on = true): void
    {
        $settings = app(ChannelSettings::class)->all();
        $settings['email']['driver'] = $on ? 'log' : 'none';
        SiteSetting::updateOrCreate(['key' => ChannelSettings::KEY], ['value' => array_diff_key($settings, ['ready' => 1])]);
        Cache::flush();
    }

    private function lastLinkToken(string $email): string
    {
        $mail = collect($this->sent)->last(fn ($m) => $m['to'] === $email);
        $this->assertNotNull($mail, "no e-mail to {$email}");
        $this->assertStringContainsString('/reset?email=', (string) $mail['text']);
        preg_match('/token=([A-Za-z0-9]+)/', (string) $mail['text'], $m);

        return $m[1];
    }

    public function test_a_link_is_sent_and_the_answer_never_reveals_whether_the_account_exists(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['email' => 'mariam@example.com']);

        $this->postJson('/api/v1/auth/forgot', ['email' => 'nobody@example.com'])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertCount(0, $this->sent);

        $this->postJson('/api/v1/auth/forgot', ['email' => 'MARIAM@example.com'])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertCount(1, $this->sent);
        $this->assertSame('mariam@example.com', $this->sent[0]['to']);
        $this->assertStringContainsString('/reset?email=mariam%40example.com&token=', (string) $this->sent[0]['text']);
        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'password_reset_requested')->exists());
    }

    public function test_the_link_sets_a_new_password_under_the_policy_ends_other_sessions_and_works_once(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['email' => 'ali@example.com']);
        $user->forceFill(['failed_attempts' => 4, 'locked_until' => now()->addHour()])->saveQuietly();
        $this->asUser($user)->getJson('/api/v1/auth/me')->assertOk();
        AuthSession::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'method' => 'password', 'last_seen_at' => now(), 'expires_at' => now()->addDay()]);

        $this->postJson('/api/v1/auth/forgot', ['email' => 'ali@example.com'])->assertOk();
        $token = $this->lastLinkToken('ali@example.com');

        // Too weak for the policy: refused with the policy's own messages.
        $this->postJson('/api/v1/auth/reset', ['email' => 'ali@example.com', 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/auth/reset', ['email' => 'ali@example.com', 'token' => $token, 'password' => 'Fresh#Start2026', 'password_confirmation' => 'Fresh#Start2026'])
            ->assertOk()->assertJsonPath('data.reset', true);
        $user->refresh();
        $this->assertTrue(Hash::check('Fresh#Start2026', $user->password));
        $this->assertSame(0, (int) $user->failed_attempts);
        $this->assertNull($user->locked_until);
        $this->assertSame(0, AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'password_reset')->exists());

        // The same link cannot be used twice, and a made-up token is refused with a clear code.
        $this->postJson('/api/v1/auth/reset', ['email' => 'ali@example.com', 'token' => $token, 'password' => 'Another#Pass2026', 'password_confirmation' => 'Another#Pass2026'])
            ->assertStatus(422)->assertJsonPath('code', 'reset_invalid');
        $this->postJson('/api/v1/auth/reset', ['email' => 'ali@example.com', 'token' => 'nope', 'password' => 'Another#Pass2026', 'password_confirmation' => 'Another#Pass2026'])
            ->assertStatus(422)->assertJsonPath('code', 'reset_invalid');

        $this->postJson('/api/v1/auth/login', ['email' => 'ali@example.com', 'password' => 'Fresh#Start2026'])->assertOk();
    }

    public function test_no_link_without_a_mail_server_or_for_a_suspended_account(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['email' => 'off@example.com']);
        $user->forceFill(['status' => 'suspended'])->saveQuietly();
        $this->postJson('/api/v1/auth/forgot', ['email' => 'off@example.com'])->assertOk();
        $this->assertCount(0, $this->sent);

        $this->mailOn(false);
        $this->postJson('/api/v1/auth/forgot', ['email' => 'off@example.com'])->assertStatus(503)->assertJsonPath('code', 'mail_not_configured');
    }
}
