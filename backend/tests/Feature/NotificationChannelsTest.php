<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\NotificationChannels;
use App\Services\Channels\SmsGateway;
use App\Services\Notifications\NotificationTemplates;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    private function configure(array $email = ['driver' => 'log'], array $sms = ['driver' => 'log']): void
    {
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/notification-channels', ['email' => $email, 'sms' => $sms])->assertOk();
    }

    private function notify(User $user, string $type = 'session.reminder'): void
    {
        app(NotificationService::class)->send($user, $type, ['ar' => 'تذكير', 'en' => 'Reminder'], ['ar' => 'جلستك غداً', 'en' => 'Your session is tomorrow']);
    }

    private function person(array $attributes = []): User
    {
        return $this->makeUser(Role::EMPLOYEE, $attributes + ['phone' => '55123456']);
    }

    public function test_nothing_is_sent_or_recorded_until_a_provider_is_set_up(): void
    {
        Mail::fake();
        $this->notify($this->person());

        $this->assertSame(0, NotificationDelivery::count());
        Mail::assertNothingSent();
        $status = $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/notification-channels/status')->assertOk()->json('data');
        $this->assertFalse($status['email']['ready']);
        $this->assertFalse($status['sms']['ready']);
    }

    public function test_every_channel_is_used_by_default_once_configured(): void
    {
        Mail::fake();
        $user = $this->person();
        $this->configure();
        $this->notify($user);

        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo($user->email) && $m->heading === 'تذكير');
        $this->assertSame(['email' => 'sent', 'sms' => 'sent'], NotificationDelivery::pluck('status', 'channel')->all());
        $this->assertStringContainsString('•••', (string) NotificationDelivery::where('channel', 'sms')->value('to'), 'numbers are masked in the log');
    }

    public function test_people_without_an_address_or_number_are_skipped_with_the_reason(): void
    {
        Mail::fake();
        $this->configure();
        $this->notify($this->person(['phone' => null]));

        $this->assertSame('no_phone', NotificationDelivery::where('channel', 'sms')->value('reason'));
        $this->assertSame('sent', NotificationDelivery::where('channel', 'email')->value('status'));
    }

    public function test_the_task_choice_the_event_setting_and_the_master_switch_each_narrow_the_channels(): void
    {
        Mail::fake();
        $this->configure();
        $user = $this->person();

        // The administrator picks SMS only for this task.
        app(NotificationChannels::class)->choose(['sms']);
        $this->notify($user);
        $this->assertSame(['sms'], NotificationDelivery::pluck('channel')->unique()->values()->all());
        app(NotificationChannels::class)->choose(null);

        // The event itself is set not to send e-mail.
        NotificationDelivery::query()->delete();
        app(NotificationTemplates::class)->ensure();
        NotificationTemplate::where('event', 'session.reminder')->update(['email' => false]);
        app(NotificationTemplates::class)->flush();
        $this->notify($user);
        $this->assertSame(['sms'], NotificationDelivery::pluck('channel')->unique()->values()->all());

        // The SMS channel is switched off altogether.
        NotificationDelivery::query()->delete();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/notification-channels', ['sms' => ['enabled' => false]])->assertOk();
        $this->notify($user);
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_the_request_can_carry_the_choice_for_the_task(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->configure();
        $trainee = $this->makeEmployee()->user;
        $trainee->update(['phone' => '55123456']);
        $program = $this->makeProgram();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $trainee->employee->id, 'source' => 'self', 'status' => 'pending']);

        Mail::fake();
        $this->asUser($admin)->patchJson("/api/v1/admin/registrations/{$registration->id}/status", ['status' => 'approved', 'notify_channels' => ['push']])->assertOk();

        $this->assertSame(0, NotificationDelivery::where('user_id', $trainee->id)->count(), 'push only: no e-mail, no SMS');
        $this->assertDatabaseHas('notifications', ['user_id' => $trainee->id, 'type' => 'registration.approved']);
    }

    public function test_settings_keep_secrets_hidden_and_a_custom_sms_provider_fills_its_request(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $res = $this->asUser($admin)->putJson('/api/v1/admin/notification-channels', ['sms' => [
            'driver' => 'http', 'http_url' => 'https://sms.example.com/send', 'http_format' => 'json', 'http_auth' => 'Bearer top-secret', 'sender' => 'TEDC',
            'http_body' => '{"to":"{{to}}","text":"{{message}}","from":"{{sender}}"}',
        ]])->assertOk();
        $this->assertStringNotContainsString('top-secret', $res->getContent());
        $this->assertTrue($res->json('data.settings.sms.secrets_set.http_auth'));
        $this->assertTrue($res->json('data.settings.ready.sms'));

        // A blank field keeps the stored secret.
        $this->asUser($admin)->putJson('/api/v1/admin/notification-channels', ['sms' => ['sender' => 'TEDC2', 'http_auth' => '']])->assertOk();
        $this->assertSame('Bearer top-secret', app(ChannelSettings::class)->secrets('sms')['http_auth']);

        Http::fake(['sms.example.com/*' => Http::sequence()->push(['ok' => true], 200)->push('nope', 500)]);
        $gateway = app(SmsGateway::class);
        $this->assertSame('97455123456', $gateway->normalise('55123456'));
        $this->assertSame('97455123456', $gateway->normalise('+974 5512 3456'));
        $this->assertSame('966501234567', $gateway->normalise('00966501234567'));
        $this->assertNull($gateway->normalise('12'));

        $this->assertTrue($gateway->send('97455123456', 'مرحباً "ضيف"')['ok']);
        Http::assertSent(function ($r) {
            $body = json_decode($r->body(), true);

            return $r->url() === 'https://sms.example.com/send' && $r->hasHeader('Authorization', 'Bearer top-secret')
                && ($body['to'] ?? null) === '97455123456' && ($body['from'] ?? null) === 'TEDC2' && str_contains($body['text'], 'ضيف');
        });

        $this->assertFalse($gateway->send('97455123456', 'x')['ok']);
    }

    public function test_the_test_endpoint_and_permissions(): void
    {
        Mail::fake();
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->configure();

        $this->asUser($admin)->postJson('/api/v1/admin/notification-channels/test', ['channel' => 'email', 'to' => 'me@example.com'])->assertOk()->assertJsonPath('data.ok', true);
        Mail::assertSent(NotificationMail::class, fn ($m) => $m->hasTo('me@example.com'));
        $this->asUser($admin)->postJson('/api/v1/admin/notification-channels/test', ['channel' => 'sms', 'to' => 'abc'])->assertUnprocessable();

        $member = $this->makeEmployee()->user;
        $this->asUser($member)->getJson('/api/v1/admin/notification-channels')->assertForbidden();
        $this->asUser($member)->getJson('/api/v1/admin/notification-channels/status')->assertForbidden();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/notification-channels/status')->assertOk();
    }
}
