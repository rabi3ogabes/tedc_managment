<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractStore;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Role;
use App\Payments\MoeEpayCertification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PAY-02: the certification plan for the Ministry's payment gateway, run end to end against the mock: a successful payment, a decline, a
 * cancellation, a timeout, a repeated callback, an amount that does not match, a refund, a partial refund and the import of the
 * gateway's statement. It works on pretend data that is rolled back, never reaches a real gateway and never runs in production.
 */
class MoeEpayCertificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->putJson('/api/v1/admin/features/payments', ['enabled' => true, 'reason' => 'test'])->assertOk();
    }

    public function test_the_whole_plan_passes_against_the_mock_and_leaves_nothing_behind(): void
    {
        $result = app(MoeEpayCertification::class)->run();

        $failed = collect($result['cases'])->where('ok', false)->map(fn ($c) => $c['id'].': '.collect($c['steps'])->where('ok', false)->pluck('detail')->implode(' | '))->all();
        $this->assertSame([], $failed);
        $this->assertTrue($result['ok']);
        $this->assertSame(['success', 'decline', 'cancel', 'timeout', 'duplicate_callback', 'amount_mismatch', 'refund', 'partial_refund', 'reconciliation_import'], array_column($result['cases'], 'id'));
        foreach ($result['cases'] as $case) {
            $this->assertNotEmpty($case['steps'], $case['id']);
            $this->assertNotEmpty($case['title']['ar'].$case['title']['en']);
        }
        $this->assertSame([0, 0, 0], [Order::count(), Payment::count(), PaymentEvent::count()], 'pretend data is rolled back');
        $this->assertStringNotContainsString('certification-secret', json_encode($result), 'the pretend secret is not shown');
    }

    public function test_it_follows_the_contract_in_force_so_a_wrong_edit_is_caught_and_named(): void
    {
        $store = app(ContractStore::class);
        $mapping = $store->active('moe_epay')->mappingDocument();
        $mapping['entities']['callback']['tables']['status']['SUCCESS'] = 'failed';   // the success word of the gateway read wrongly
        $store->save('moe_epay', 'mapping', $mapping, null);

        $result = app(MoeEpayCertification::class)->run();

        $this->assertFalse($result['ok']);
        $failed = collect($result['cases'])->where('ok', false)->pluck('id')->all();
        $this->assertContains('success', $failed);
        $this->assertContains('duplicate_callback', $failed);
        $this->assertNotContains('decline', $failed, 'what the edit did not touch still passes');
        $detail = collect(collect($result['cases'])->firstWhere('id', 'success')['steps'])->where('ok', false)->pluck('detail')->implode(' ');
        $this->assertStringContainsString('paid', $detail);
    }

    public function test_a_broken_response_path_stops_the_payment_at_the_start(): void
    {
        $store = app(ContractStore::class);
        $doc = $store->active('moe_epay')->document();
        $doc['operations']['initiate']['response']['values']['redirect_url'] = ['$.nowhere'];
        $store->save('moe_epay', 'contract', $doc, null);

        $result = app(MoeEpayCertification::class)->run();

        $this->assertFalse(collect($result['cases'])->firstWhere('id', 'success')['ok']);
        $this->assertStringContainsString('redirect', collect(collect($result['cases'])->firstWhere('id', 'success')['steps'])->where('ok', false)->pluck('detail')->implode(' '));
    }

    public function test_the_command_prints_the_plan_and_fails_when_a_case_fails(): void
    {
        $this->artisan('tedc:payments-certify')->expectsOutputToContain('9 of 9 cases passed.')->assertSuccessful();

        $store = app(ContractStore::class);
        $mapping = $store->active('moe_epay')->mappingDocument();
        $mapping['entities']['callback']['tables']['status']['DECLINED'] = 'captured';   // a decline read as a payment: the worst mistake
        $store->save('moe_epay', 'mapping', $mapping, null);
        $this->artisan('tedc:payments-certify')->expectsOutputToContain('FAIL')->assertFailed();
    }

    public function test_it_never_runs_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        app(MoeEpayCertification::class)->run();
    }

    public function test_the_payment_settings_button_runs_it_for_whoever_manages_integrations(): void
    {
        Http::fake();
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $r = $this->asUser($admin)->postJson('/api/v1/admin/payments/certification')->assertOk();

        $this->assertTrue($r->json('data.ok'), $r->getContent());
        $this->assertCount(9, $r->json('data.cases'));
        $this->assertSame(9, AuditLog::where('action', 'payments.certification.run')->firstOrFail()->new_values['passed']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/payments/certification')->assertForbidden();
        $this->assertSame(0, Order::count());

        $this->app->detectEnvironment(fn () => 'production');
        $this->asUser($admin)->postJson('/api/v1/admin/payments/certification')->assertStatus(403);
    }

    public function test_the_gateways_statement_can_be_imported_from_a_file(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $user = $this->makeUser(Role::EMPLOYEE);
        $order = Order::create(['number' => 'ORD-2610-LOST01', 'buyer_type' => 'user', 'user_id' => $user->id, 'items' => [], 'subtotal' => 100, 'discount' => 0, 'vat' => 0, 'total' => 100, 'currency' => 'QAR', 'status' => 'pending_payment', 'expires_at' => now()->addHour()]);
        Payment::create(['order_id' => $order->id, 'gateway' => 'moe_epay', 'gateway_ref' => 'P-77', 'amount' => 100, 'status' => 'initiated']);
        $csv = "\xEF\xBB\xBFpayment_id,status,amount\nP-77,SUCCESS,100.00\nP-99,SUCCESS,5.00\n";

        $r = $this->asUser($admin)->post('/api/v1/admin/payment-reconciliations/import', ['day' => '2026-10-13', 'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame([0, 1], [$r->json('data.matched'), $r->json('data.fixed')], 'the payment whose callback never arrived is completed from the file');
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('unknown_at_platform', $r->json('data.mismatches.0.type'));
        $this->assertSame('P-99', $r->json('data.mismatches.0.ref'));
        $again = $this->asUser($admin)->post('/api/v1/admin/payment-reconciliations/import', ['day' => '2026-10-13', 'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, $again->json('data.matched'), 'a second import of the same file changes nothing more');
    }

    public function test_a_semicolon_file_and_a_file_that_is_not_a_statement_are_handled(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $semi = "payment_id;status;amount\nP-5;FAILED;10.00\n";
        $this->asUser($admin)->post('/api/v1/admin/payment-reconciliations/import', ['file' => UploadedFile::fake()->createWithContent('s.csv', $semi)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.mismatches.0.ref', 'P-5');

        $this->asUser($admin)->post('/api/v1/admin/payment-reconciliations/import', ['file' => UploadedFile::fake()->createWithContent('s.csv', "name,age\nx,1\n")], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asUser($admin)->post('/api/v1/admin/payment-reconciliations/import', ['file' => UploadedFile::fake()->createWithContent('s.csv', '')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->post('/api/v1/admin/payment-reconciliations/import', ['file' => UploadedFile::fake()->createWithContent('s.csv', $semi)], ['Accept' => 'application/json'])->assertForbidden();
    }
}
