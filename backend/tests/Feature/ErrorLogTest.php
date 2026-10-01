<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\ErrorLog;
use App\Models\Role;
use App\Services\ErrorLogService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ErrorLogTest extends TestCase
{
    public function test_clients_report_errors_which_are_grouped_and_scrubbed(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE);
        $payload = fn (string $message) => ['errors' => [['source' => 'web', 'message' => $message, 'stack' => 'at x Bearer abc.def.ghi password=hunter2', 'route' => '/portal/training', 'context' => ['token' => 'secret', 'page' => 'x']]]];

        $this->postJson('/api/v1/client-errors', $payload('Cannot read property of undefined (row 12)'))->assertStatus(202);
        $this->asUser($user)->postJson('/api/v1/client-errors', $payload('Cannot read property of undefined (row 99)'))->assertStatus(202);

        $log = ErrorLog::firstOrFail();
        $this->assertSame(1, ErrorLog::count(), 'the same bug is one group');
        $this->assertSame(2, $log->occurrences);
        $this->assertSame($user->email, $log->user_email);
        $this->assertStringNotContainsString('hunter2', $log->stack);
        $this->assertStringNotContainsString('abc.def.ghi', $log->stack);
        $this->assertSame('***', $log->context['token']);
    }

    public function test_server_exceptions_are_logged_but_not_validation_or_not_found(): void
    {
        $service = app(ErrorLogService::class);
        $service->recordThrowable(new \RuntimeException('Boom in the kitchen'));
        $service->recordThrowable(new ValidationException(validator([], ['a' => 'required'])));
        $service->recordThrowable(new NotFoundHttpException);

        $this->assertSame(1, ErrorLog::count());
        $this->assertSame('server', ErrorLog::first()->source);
        $this->assertStringContainsString('RuntimeException', ErrorLog::first()->stack);
    }

    public function test_only_the_system_admin_can_see_manage_and_delete_the_log(): void
    {
        $log = app(ErrorLogService::class)->record(['source' => 'app', 'message' => 'Null check operator used on a null value', 'location' => '/training']);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/error-logs')->assertForbidden();
        $this->asUser($admin)->getJson('/api/v1/admin/error-logs?source=app')->assertOk()->assertJsonPath('data.0.id', $log->id)->assertJsonPath('stats.open', 1);
        $this->asUser($admin)->getJson("/api/v1/admin/error-logs/{$log->id}")->assertOk()->assertJsonPath('data.message', 'Null check operator used on a null value');

        $this->asUser($admin)->putJson("/api/v1/admin/error-logs/{$log->id}", ['status' => 'fixed', 'note' => 'Patched in 1.0.18'])->assertOk()->assertJsonPath('data.status', 'fixed');
        // It comes back: not fixed after all.
        app(ErrorLogService::class)->record(['source' => 'app', 'message' => 'Null check operator used on a null value', 'location' => '/training']);
        $this->assertSame('open', $log->fresh()->status);

        $this->asUser($admin)->postJson('/api/v1/admin/error-logs/bulk', ['action' => 'fix', 'ids' => [$log->id]])->assertOk()->assertJsonPath('data.affected', 1);
        $this->asUser($admin)->postJson('/api/v1/admin/error-logs/bulk', ['action' => 'delete', 'status' => 'fixed'])->assertOk();
        $this->assertSame(0, ErrorLog::count());
    }

    public function test_known_problems_are_fixed_automatically(): void
    {
        DeviceToken::create(['user_id' => $this->makeUser(Role::EMPLOYEE)->id, 'token' => 'old', 'platform' => 'android', 'last_seen_at' => now()->subDays(90)]);

        $log = app(ErrorLogService::class)->record(['source' => 'server', 'message' => 'FCM: UNREGISTERED token', 'exception' => 'RuntimeException', 'location' => 'app/Services/Push/FcmClient.php:80']);

        $this->assertSame('fixed', $log->fresh()->status);
        $this->assertTrue($log->fresh()->auto_fixed);
        $this->assertSame(0, DeviceToken::count());

        $unknown = app(ErrorLogService::class)->record(['source' => 'server', 'message' => 'Something odd', 'exception' => 'RuntimeException', 'location' => 'x:1']);
        $this->assertSame('open', $unknown->fresh()->status);
    }
}
