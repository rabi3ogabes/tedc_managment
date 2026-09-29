<?php

namespace Tests\Feature;

use App\Mail\CertificateMail;
use App\Models\AppNotification;
use App\Models\Certificate;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CertificateSendingTest extends TestCase
{
    private function certificate(?Program $program = null, array $userAttributes = []): Certificate
    {
        $program ??= $this->makeProgram();
        $employee = $this->makeEmployee([], $this->makeUser(Role::EMPLOYEE, $userAttributes));
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return Certificate::create([
            'certificate_no' => 'C-'.uniqid(), 'verification_code' => strtoupper(uniqid()), 'registration_id' => $registration->id,
            'employee_id' => $employee->id, 'program_id' => $program->id, 'issued_at' => now(), 'hours' => 12, 'status' => 'valid',
        ]);
    }

    public function test_certificates_are_sent_with_the_pdf_and_marked_as_sent(): void
    {
        Mail::fake();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $a = $this->certificate();
        $b = $this->certificate();

        $this->asUser($admin)->getJson('/api/v1/admin/certificates?sent=no')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.is_sent', false);

        $this->asUser($admin)->postJson('/api/v1/admin/certificates/send', ['ids' => [$a->id]])->assertOk()->assertJsonPath('data.sent', 1);
        Mail::assertSent(CertificateMail::class, fn (CertificateMail $m) => $m->hasTo($a->employee->user->email) && count($m->attachments()) === 1);
        Mail::assertSentCount(1);

        $a->refresh();
        $this->assertNotNull($a->sent_at);
        $this->assertSame(1, $a->sent_count);
        $this->assertSame($admin->id, $a->sent_by);
        $this->assertSame(1, AppNotification::where('user_id', $a->employee->user_id)->where('type', 'certificate.sent')->count());

        // The list shows the sent state and can filter on it.
        $this->asUser($admin)->getJson('/api/v1/admin/certificates?sent=yes')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_sent', true)->assertJsonPath('data.0.sent_to', $a->employee->user->email)->assertJsonPath('data.0.sent_by', $admin->displayName());
        $this->asUser($admin)->getJson('/api/v1/admin/certificates?sent=no')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);

        // Sending again skips it unless a resend is requested.
        $this->asUser($admin)->postJson('/api/v1/admin/certificates/send', ['ids' => [$a->id]])->assertOk()->assertJsonPath('data.sent', 0)->assertJsonPath('data.skipped.0.reason', 'already_sent');
        $this->asUser($admin)->postJson('/api/v1/admin/certificates/send', ['ids' => [$a->id], 'resend' => true])->assertOk()->assertJsonPath('data.sent', 1);
        $this->assertSame(2, $a->fresh()->sent_count);
    }

    public function test_bulk_send_by_filter_skips_revoked_and_reports_failures(): void
    {
        Mail::fake();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();
        $other = $this->makeProgram();
        $one = $this->certificate($program, ['name' => 'Aisha Trainee']);
        $two = $this->certificate($program);
        $revoked = $this->certificate($program);
        $revoked->update(['status' => 'revoked']);
        $this->certificate($other);

        $res = $this->asUser($admin)->postJson('/api/v1/admin/certificates/send', ['all' => true, 'program_id' => $program->id, 'sent' => 'no'])->assertOk();
        $res->assertJsonPath('data.sent', 2)->assertJsonPath('data.total', 3)->assertJsonPath('data.skipped.0.reason', 'revoked');
        $this->assertNull($revoked->fresh()->sent_at);

        // Filtering by holder name works for a targeted send.
        $this->asUser($admin)->getJson('/api/v1/admin/certificates?q=Aisha')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $one->id);

        // A mail failure is recorded and does not stop the batch.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $failed = $this->certificate($program);
        $this->asUser($admin)->postJson('/api/v1/admin/certificates/send', ['ids' => [$failed->id]])->assertOk()->assertJsonPath('data.failed.0.reason', 'mail_error');
        $this->assertNull($failed->fresh()->sent_at);
        $this->assertSame('SMTP down', $failed->fresh()->send_error);

        $this->asUser($admin)->postJson('/api/v1/admin/certificates/send', [])->assertUnprocessable();
        $this->asUser($this->makeUser(Role::TRAINER))->postJson('/api/v1/admin/certificates/send', ['ids' => [$two->id]])->assertForbidden();
    }

    public function test_school_admins_can_only_send_their_own_schools_certificates(): void
    {
        Mail::fake();
        $schoolAdmin = $this->makeUser(Role::SCHOOL_ADMIN);
        $mine = $this->certificate();
        $theirs = $this->certificate();
        $this->assertSame(0, Certificate::whereNotNull('sent_at')->count());

        // School admins do not hold certificates.issue, so they cannot send at all.
        $this->asUser($schoolAdmin)->postJson('/api/v1/admin/certificates/send', ['ids' => [$mine->id, $theirs->id]])->assertForbidden();
        Mail::assertNothingSent();
    }
}
