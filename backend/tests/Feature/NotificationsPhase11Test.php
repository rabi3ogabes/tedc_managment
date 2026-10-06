<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\JobTitle;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\ProgramCategory;
use App\Models\Role;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\NotificationChannels;
use App\Services\Channels\SmsGateway;
use App\Services\Notifications\AudienceResolver;
use App\Services\Notifications\DeliveryPolicy;
use App\Services\Notifications\NotificationScheduler;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationsPhase11Test extends TestCase
{
    private function hudhud(array $extra = []): void
    {
        app(ChannelSettings::class)->update(['sms' => ['enabled' => true, 'driver' => 'hudhud', 'hudhud_base_url' => 'https://hudhud.test', 'hudhud_api_key' => 'key-1', 'hudhud_receipt_secret' => 'shared-secret', 'sender' => 'TEDC'] + $extra]);
    }

    private function smsOnly(User $user, string $type = 'announcement', array $data = [], ?string $campaign = null): ?AppNotification
    {
        app(NotificationChannels::class)->choose(['sms']);
        $n = app(NotificationService::class)->send($user, $type, ['ar' => 'مرحبا', 'en' => 'Hello'], ['ar' => 'نص', 'en' => 'Body'], $data, $campaign, raw: true);
        app(NotificationChannels::class)->choose(null);

        return $n;
    }

    public function test_hudhud_sends_arabic_as_ucs2_and_a_signed_receipt_updates_the_delivery(): void
    {
        $this->hudhud();
        Http::fake(['hudhud.test/*' => Http::response(['message_id' => 'MSG-1', 'status' => 'queued'])]);
        $user = $this->makeUser(Role::EMPLOYEE, ['phone' => '55512345', 'locale' => 'ar']);

        $this->smsOnly($user);

        Http::assertSent(function ($r) {
            $text = (string) mb_convert_encoding((string) hex2bin($r['message']), 'UTF-8', 'UTF-16BE');

            return str_contains($r->url(), 'hudhud.test/api/v1/messages') && $r['encoding'] === 'UCS2' && $r['recipients'] === ['97455512345']
                && str_contains($text, 'مرحبا') && $r->hasHeader('Authorization');
        });
        $row = NotificationDelivery::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('sent', $row->status);
        $this->assertSame('MSG-1', $row->provider_message_id);

        $body = json_encode(['message_id' => 'MSG-1', 'status' => 'delivered']);
        $this->call('POST', '/api/v1/integrations/sms/hudhud/receipt', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUDHUD_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $sig = hash_hmac('sha256', $body, 'shared-secret');
        $this->call('POST', '/api/v1/integrations/sms/hudhud/receipt', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUDHUD_SIGNATURE' => $sig], $body)->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('delivered', $row->fresh()->status);

        $fail = json_encode(['receipts' => [['message_id' => 'MSG-1', 'status' => 'failed', 'reason' => 'handset off']]]);
        $this->call('POST', '/api/v1/integrations/sms/hudhud/receipt', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUDHUD_SIGNATURE' => hash_hmac('sha256', $fail, 'shared-secret')], $fail)->assertOk();
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame('handset off', $row->fresh()->failed_reason);
    }

    public function test_latin_text_goes_as_plain_text_and_an_unconfigured_provider_stays_silent(): void
    {
        $this->hudhud();
        $p = app(SmsGateway::class)->hudhudPayload(['sender' => 'TEDC'], '97455512345', 'Hello', 'ref');
        $this->assertSame('GSM', $p['encoding']);
        $this->assertSame('Hello', $p['message']);

        app(ChannelSettings::class)->update(['sms' => ['driver' => 'none']]);
        $user = $this->makeUser(Role::EMPLOYEE, ['phone' => '55512345']);
        $n = $this->smsOnly($user);
        $this->assertNotNull($n);   // the in-app copy always exists
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_audience_by_school_job_title_role_and_the_senders_scope(): void
    {
        $s1 = $this->makeSchool();
        $s2 = $this->makeSchool();
        $a = $this->makeEmployee(['school_id' => $s1->id], null, 'TEACHER');
        $b = $this->makeEmployee(['school_id' => $s2->id], null, 'TEACHER');
        $c = $this->makeEmployee(['school_id' => $s1->id], null, 'PRINCIPAL');
        $resolver = app(AudienceResolver::class);

        $this->assertEqualsCanonicalizing([$a->user_id, $c->user_id], $resolver->resolve(['schools' => [$s1->id]])->all());
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');
        $this->assertEqualsCanonicalizing([$a->user_id], $resolver->resolve(['schools' => [$s1->id], 'job_titles' => [$teacher]])->all());
        $this->assertContains($b->user_id, $resolver->resolve(['roles' => [Role::EMPLOYEE]])->all());
        $this->assertEqualsCanonicalizing([$a->user_id, $b->user_id, $c->user_id], array_values(array_intersect($resolver->resolve(['user_ids' => [$a->user_id, $b->user_id, $c->user_id]])->all(), [$a->user_id, $b->user_id, $c->user_id])));

        // A school administrator reaches only the people of their own school.
        $admin = $this->makeEmployee(['school_id' => $s1->id], $this->makeUser(Role::SCHOOL_ADMIN));
        $reached = $resolver->resolve(['roles' => [Role::EMPLOYEE]], $admin->user)->all();
        $this->assertContains($a->user_id, $reached);
        $this->assertNotContains($b->user_id, $reached);
        $preview = $this->asUser($this->makeUser(Role::TRAINING_HEAD))->postJson('/api/v1/admin/notifications/audience/preview', ['audience' => ['roles' => [Role::EMPLOYEE]]]);
        $preview->assertOk();
        $this->assertGreaterThanOrEqual(1, $preview->json('data.count'));
    }

    public function test_a_scheduled_notification_goes_out_when_due_and_a_repeating_one_is_rescheduled(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $target = $this->makeEmployee();
        $payload = ['title_ar' => 'تذكير', 'title_en' => 'Reminder', 'body_ar' => 'ن', 'body_en' => 'b', 'channels' => ['push'], 'audience' => ['user_ids' => [$target->user_id]], 'send_at' => now()->addHour()->toIso8601String()];

        $this->asUser($head)->postJson('/api/v1/admin/scheduled-notifications', ['send_at' => now()->subDay()->toIso8601String()] + $payload)->assertStatus(422)->assertJsonPath('code', 'send_at_past');
        $id = $this->asUser($head)->postJson('/api/v1/admin/scheduled-notifications', $payload + ['repeat' => 'daily'])->assertCreated()->json('data.id');

        $this->assertSame(0, app(NotificationScheduler::class)->runDue());   // not due yet
        ScheduledNotification::whereKey($id)->update(['send_at' => now()->subMinute()]);
        $this->assertSame(1, app(NotificationScheduler::class)->runDue());

        $this->assertSame(1, AppNotification::where('user_id', $target->user_id)->where('title_en', 'Reminder')->count());
        $s = ScheduledNotification::find($id);
        $this->assertSame('scheduled', $s->status);       // daily: waits for the next day
        $this->assertTrue($s->send_at->isFuture());
        $this->assertSame(1, $s->runs);
        $this->assertSame(0, app(NotificationScheduler::class)->runDue());   // never twice

        $this->asUser($head)->deleteJson("/api/v1/admin/scheduled-notifications/{$id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        ScheduledNotification::whereKey($id)->update(['send_at' => now()->subMinute(), 'status' => 'cancelled']);
        $this->assertSame(0, app(NotificationScheduler::class)->runDue());   // a cancelled one never goes
    }

    public function test_quiet_hours_move_an_sms_to_the_next_allowed_window(): void
    {
        $this->hudhud();
        Http::fake(['hudhud.test/*' => Http::response(['message_id' => 'Q-1'])]);
        $user = $this->makeUser(Role::EMPLOYEE, ['phone' => '55512345']);
        // SMS only between 08:00 and 20:00 Qatar time, every day.
        NotificationRule::create(['event' => '*', 'quiet_hours' => ['days' => [0, 1, 2, 3, 4, 5, 6], 'from' => '08:00', 'to' => '20:00', 'timezone' => 'Asia/Qatar', 'channels' => ['sms']]]);

        Carbon::setTestNow(Carbon::parse('2026-10-06 22:30:00', 'Asia/Qatar'));   // night
        $this->smsOnly($user);
        $row = NotificationDelivery::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('queued', $row->status);
        $this->assertTrue($row->not_before->equalTo(Carbon::parse('2026-10-07 08:00:00', 'Asia/Qatar')));
        Http::assertNothingSent();

        app(NotificationChannels::class)->process();
        $this->assertSame('queued', $row->fresh()->status);   // still night

        Carbon::setTestNow(Carbon::parse('2026-10-07 08:05:00', 'Asia/Qatar'));
        app(NotificationChannels::class)->process();
        $this->assertSame('sent', $row->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_the_policy_computes_the_next_allowed_moment_across_days_and_overnight_windows(): void
    {
        $policy = app(DeliveryPolicy::class);
        $from = Carbon::parse('2026-10-09 10:00:00', 'Asia/Qatar');   // a Friday
        // Sunday to Thursday only (0-4): from Friday the next slot is Sunday 08:00.
        $next = $policy->nextAllowed($from, ['days' => [0, 1, 2, 3, 4], 'from' => '08:00', 'to' => '16:00', 'timezone' => 'Asia/Qatar']);
        $this->assertTrue($next->equalTo(Carbon::parse('2026-10-11 08:00:00', 'Asia/Qatar')));
        // Overnight window 20:00-06:00: at 23:00 we are already inside it.
        $inside = $policy->nextAllowed(Carbon::parse('2026-10-06 23:00:00', 'Asia/Qatar'), ['from' => '20:00', 'to' => '06:00', 'timezone' => 'Asia/Qatar']);
        $this->assertTrue($inside->equalTo(Carbon::parse('2026-10-06 23:00:00', 'Asia/Qatar')));
        $this->assertNull($policy->nextAllowed($from, ['days' => []]));
    }

    public function test_rules_per_category_and_program_override_each_other_and_can_switch_an_event_off(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE);
        $category = ProgramCategory::first();
        $program = $this->makeProgram(['category_id' => $category->id]);
        $other = $this->makeProgram(['category_id' => $category->id]);
        $svc = app(NotificationService::class);

        // Category rule: no notifications of this event for the category...
        $rule = NotificationRule::create(['event' => 'registration.approved', 'category_id' => $category->id, 'enabled' => false]);
        $this->assertNull($svc->send($user, 'registration.approved', ['ar' => 'ا', 'en' => 'a'], null, ['program_id' => $program->id], raw: true));
        $this->assertNull($svc->send($user, 'registration.approved', ['ar' => 'ا', 'en' => 'a'], null, ['program_id' => $other->id], raw: true));

        // ...but a program rule is more specific and wins for that program.
        NotificationRule::create(['event' => 'registration.approved', 'program_id' => $program->id, 'enabled' => true, 'channels' => ['push']]);
        $this->assertNotNull($svc->send($user, 'registration.approved', ['ar' => 'ا', 'en' => 'a'], null, ['program_id' => $program->id], raw: true));
        $this->assertNull($svc->send($user, 'registration.approved', ['ar' => 'ا', 'en' => 'a'], null, ['program_id' => $other->id], raw: true));

        // A rule for another audience does not touch this user.
        $rule->update(['audience_filter' => ['roles' => [Role::TRAINER]]]);
        $this->assertNotNull($svc->send($user, 'registration.approved', ['ar' => 'ا', 'en' => 'a'], null, ['program_id' => $other->id], raw: true));
    }

    public function test_user_preferences_switch_optional_channels_but_mandatory_events_ignore_them(): void
    {
        $this->hudhud();
        Http::fake(['hudhud.test/*' => Http::response(['message_id' => 'P-1'])]);
        $user = $this->makeUser(Role::EMPLOYEE, ['phone' => '55512345']);
        $this->asUser($user)->putJson('/api/v1/me/notification-preferences', ['groups' => [['group' => 'announcements', 'channels' => ['sms' => false]], ['group' => 'registration', 'channels' => ['sms' => false]]], 'sound' => false])->assertOk()
            ->assertJsonPath('data.sound', false);
        $this->assertSame(2, UserNotificationPreference::where('user_id', $user->id)->where('enabled', false)->where('channel', 'sms')->count());

        // Optional (an announcement): no SMS row. Mandatory (registration approved): the SMS still goes.
        $this->smsOnly($user, 'announcement');
        $this->assertSame(0, NotificationDelivery::where('user_id', $user->id)->count());
        $this->smsOnly($user, 'registration.approved');
        $this->assertSame(1, NotificationDelivery::where('user_id', $user->id)->where('channel', 'sms')->count());
        $this->assertFalse(app(DeliveryPolicy::class)->soundOn($user->id));

        $this->asUser($user)->getJson('/api/v1/me/notification-preferences')->assertOk()->assertJsonPath('data.groups.0.channels.push', true);
    }

    public function test_delivery_tracking_lists_filters_and_exports(): void
    {
        $this->hudhud();
        Http::fake(['hudhud.test/*' => Http::sequence()->push(['message_id' => 'T-1'])->push(['message_id' => 'T-2'], 500)]);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $a = $this->makeUser(Role::EMPLOYEE, ['phone' => '55512345']);
        $b = $this->makeUser(Role::EMPLOYEE, ['phone' => '55512346']);
        $n1 = $this->smsOnly($a);
        $n1->update(['read_at' => now()]);
        $this->smsOnly($b);
        NotificationDelivery::where('user_id', $b->id)->update(['status' => 'failed', 'reason' => 'HTTP 500']);

        $all = $this->asUser($head)->getJson('/api/v1/admin/notifications/deliveries')->assertOk();
        $this->assertGreaterThanOrEqual(4, $all->json('summary.total'));          // two in-app + two SMS
        $this->assertSame(1, $all->json('summary.by_channel.sms.read'));          // the SMS whose notification was opened counts as read
        $this->assertSame(1, $all->json('summary.by_channel.sms.failed'));
        $this->asUser($head)->getJson('/api/v1/admin/notifications/deliveries?channel=sms&status=failed')->assertOk()->assertJsonCount(1, 'data.data');

        $xlsx = $this->asUser($head)->get('/api/v1/admin/notifications/deliveries/export?format=xlsx');
        $xlsx->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->getContent());
        $pdf = $this->asUser($head)->get('/api/v1/admin/notifications/deliveries/export?format=pdf&lang=en');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/notifications/deliveries')->assertForbidden();
    }

    public function test_rules_api_validates_and_needs_the_permission(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($head)->postJson('/api/v1/admin/notification-rules', ['event' => 'not.an.event'])->assertStatus(422);
        $id = $this->asUser($head)->postJson('/api/v1/admin/notification-rules', ['event' => 'session.reminder', 'channels' => ['push', 'email'], 'delay_minutes' => 30, 'quiet_hours' => ['days' => [0, 1], 'from' => '08:00', 'to' => '15:00']])->assertCreated()->json('data.id');
        $this->asUser($head)->putJson("/api/v1/admin/notification-rules/{$id}", ['enabled' => false])->assertOk();
        $this->asUser($head)->getJson('/api/v1/admin/notification-rules')->assertOk()->assertJsonPath('data.0.enabled', false);
        $this->asUser($head)->deleteJson("/api/v1/admin/notification-rules/{$id}")->assertNoContent();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/notification-rules')->assertForbidden();
    }
}
