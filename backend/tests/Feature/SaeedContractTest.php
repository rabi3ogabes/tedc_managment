<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Integrations\Ministry\SaeedTickets;
use App\Models\AppNotification;
use App\Models\IntegrationLog;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\ChecksTheCircuit;
use Tests\TestCase;

/** Saaed on the contract kit: a report becomes a ticket with its number from the recorded answer, its status comes back, and a failing Saaed only delays it. */
class SaeedContractTest extends TestCase
{
    use ChecksTheCircuit;

    private const BASE = 'https://saaed.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['tedc.integrations.backoff_ms' => 0]);
        app(IntegrationManager::class)->update('saaed', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => self::BASE, 'api_key' => 'SAEED-KEY-1', 'category_map' => ['bug' => 'TECH']]]);
    }

    private function report(array $extra = []): SupportTicket
    {
        $user = User::where('email', 'sara.kuwari@example.test')->first() ?? $this->makeUser(Role::EMPLOYEE, ['name' => 'Sara Al-Kuwari', 'email' => 'sara.kuwari@example.test']);

        return app(SaeedTickets::class)->create($user, $extra + ['category' => 'bug', 'subject' => 'The certificate does not open', 'description' => 'The certificate page stays blank', 'page_url' => 'https://tedc.example/certificates', 'context' => ['platform' => 'web']]);
    }

    public function test_a_report_becomes_a_ticket_with_the_number_from_the_recorded_answer(): void
    {
        ContractFixtures::fake('saaed', self::BASE);

        $ticket = $this->report();

        $this->assertSame(['SAEED-0001', 'open', 'open'], [$ticket->saaed_ticket_no, $ticket->saaed_status, $ticket->status]);
        $this->assertSame(1, AppNotification::where('user_id', $ticket->user_id)->where('type', 'ticket.created')->count());
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/tickets' && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer SAEED-KEY-1')
            && $r['category'] === 'TECH' && $r['subject'] === 'The certificate does not open' && $r['reporter']['email'] === 'sara.kuwari@example.test' && $r['context']['platform'] === 'web' && $r['reference'] === $ticket->id);
    }

    public function test_a_retry_of_the_same_report_carries_the_same_idempotency_key(): void
    {
        Http::fake([self::BASE.'/tickets' => Http::sequence()->push('busy', 503)->push('busy', 503)->push(['ticket_no' => 'SAEED-0007', 'status' => 'open'], 201)]);

        $ticket = $this->report();              // the first send and its retry fail: the ticket waits
        $this->assertSame('queued', $ticket->status);
        app(SaeedTickets::class)->send($ticket);   // the next run

        $keys = collect(Http::recorded())->map(fn ($p) => $p[0]->header('Idempotency-Key')[0] ?? null)->unique();
        $this->assertCount(1, $keys, 'Saaed can tell that these are one report, not three');
        $this->assertSame('tedc-ticket-'.$ticket->id, $keys->first());
        $this->assertSame('SAEED-0007', $ticket->fresh()->saaed_ticket_no);
    }

    public function test_the_status_comes_back_from_the_status_address_and_the_person_is_told(): void
    {
        ContractFixtures::fake('saaed', self::BASE, ['ticket_status' => 'resolved']);
        $ticket = $this->report();
        $this->travel(1)->hours();   // a ticket is looked at again after half an hour

        $result = app(SaeedTickets::class)->tick();

        $this->assertSame(1, $result['updated']);
        $this->assertSame(['resolved', 'resolved'], [$ticket->fresh()->status, $ticket->fresh()->saaed_status]);
        $this->assertSame(1, AppNotification::where('user_id', $ticket->user_id)->where('type', 'ticket.updated')->count());
        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/tickets/SAEED-0001' && $r->method() === 'GET');
    }

    public function test_a_refusal_keeps_the_report_with_a_clear_reason_that_never_holds_the_key(): void
    {
        ContractFixtures::fake('saaed', self::BASE, ['create_ticket' => 'unauthorised']);

        $ticket = $this->report();

        $this->assertSame('queued', $ticket->status);
        $this->assertSame(1, $ticket->attempts);
        $this->assertStringContainsString('refused the credentials', (string) $ticket->last_error);
        $this->assertStringNotContainsString('SAEED-KEY-1', json_encode([$ticket->last_error, IntegrationLog::where('integration_key', 'saaed')->get()->toArray()]));
        Http::assertSentCount(1);
    }

    public function test_the_circuit_opens_after_five_failures_and_recovers(): void
    {
        $this->assertTheCircuitOpensAndRecovers('saaed', 'saaed', self::BASE, 'create_ticket', fn () => $this->report());
    }
}
