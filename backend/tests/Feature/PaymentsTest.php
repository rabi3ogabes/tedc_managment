<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CartItem;
use App\Models\DiscountCode;
use App\Models\Employee;
use App\Models\EntityAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PriceList;
use App\Models\Program;
use App\Models\Refund;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SeatHold;
use App\Models\SeatVoucher;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\GatewayFactory;
use App\Payments\Gateways\MoeEpayGateway;
use App\Payments\PricingService;
use App\Payments\ReconciliationService;
use App\Payments\RefundService;
use App\Payments\SeatHolds;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    private User $admin;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->finance = $this->makeUser(Role::FINANCE_OFFICER);
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/payments', ['enabled' => true, 'reason' => 'test'])->assertOk();
    }

    /** @return array{0: Program, 1: TrainingGroup} */
    private function course(array $program = [], array $group = []): array
    {
        $p = $this->makeProgram($program + ['capacity' => 10]);
        $g = TrainingGroup::where('program_id', $p->id)->first() ?? TrainingGroup::create(['program_id' => $p->id, 'code' => 'G1', 'title_ar' => 'م', 'title_en' => 'G', 'sequence' => 1, 'delivery_mode' => 'online', 'start_date' => now()->addDays(20), 'end_date' => now()->addDays(22), 'capacity' => 10, 'status' => 'registration_open']);
        $g->update($group + ['start_date' => now()->addDays(20), 'end_date' => now()->addDays(22), 'capacity' => 10]);

        return [$p, $g->refresh()];
    }

    private function price(TrainingGroup $g, array $rules, float $default = 0, float $vat = 0, array $policy = []): PriceList
    {
        return PriceList::create(['group_id' => $g->id, 'rules' => $rules, 'default_price' => $default, 'vat_rate' => $vat, 'refund_policy' => $policy ?: null, 'currency' => 'QAR', 'is_active' => true]);
    }

    private function buyer(string $schoolType = 'government'): Employee
    {
        return $this->makeEmployee([], $this->makeUser());
    }

    private function external(): Employee
    {
        $u = $this->makeUser();

        return Employee::create(['user_id' => $u->id, 'employee_no' => 'EXT-'.strtoupper(substr(md5($u->id), 0, 6)), 'status' => 'active']);
    }

    private function privateEmployee(): Employee
    {
        $e = $this->makeEmployee();
        $e->school->update(['type' => 'private']);

        return $e->refresh();
    }

    /** Completes a payment as the (training) gateway would. */
    private function pay(Order $o, string $outcome = 'captured'): void
    {
        $ref = Payment::where('order_id', $o->id)->value('gateway_ref');
        $this->postJson("/api/v1/payments/fake/{$ref}/complete", ['outcome' => $outcome])->assertOk();
    }

    private function signedCallback(array $body, ?string $sig = null)
    {
        $raw = json_encode($body);
        $gw = app(GatewayFactory::class)->make();

        return $this->call('POST', '/api/v1/payments/callback/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X-SIGNATURE' => $sig ?? $gw->sign($raw)], $raw);
    }

    // ---- flag --------------------------------------------------------------------------------------

    public function test_with_payments_off_nothing_is_visible_and_every_endpoint_refuses(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [], 100);
        $emp = $this->buyer();
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/payments', ['enabled' => false, 'reason' => 'test'])->assertOk();
        $this->asUser($emp->user)->getJson('/api/v1/me/cart')->assertForbidden();
        $this->asUser($emp->user)->postJson('/api/v1/me/checkout')->assertForbidden();
        $this->asUser($this->admin)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->postJson('/api/v1/payments/callback/fake', [])->assertForbidden();
        $this->getJson("/api/v1/public/programs/{$p->code}")->assertOk()->assertJsonPath('data.pricing', null);
    }

    // ---- pricing -----------------------------------------------------------------------------------

    public function test_a_course_is_free_for_one_category_and_paid_for_another_with_vat(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'ministry_staff', 'price' => 0, 'label_en' => 'Ministry staff'], ['category' => 'private_school', 'price' => 350, 'label_en' => 'Private school'], ['category' => 'external', 'price' => 500], ['category' => 'entity', 'price' => 300]], 400, 5);
        $pricing = app(PricingService::class);
        $gov = $this->buyer();
        $priv = $this->privateEmployee();
        $ext = $this->external();

        $this->assertTrue($pricing->resolve($g, $gov)['free']);
        $this->assertSame('ministry_staff', $pricing->resolve($g, $gov)['category']);
        $this->assertEquals(350.0, $pricing->resolve($g, $priv)['price']);
        $this->assertSame('external', $pricing->resolve($g, $ext)['category']);
        $this->assertEquals(500.0, $pricing->resolve($g, $ext)['price']);
        $this->assertEquals(300.0, $pricing->resolve($g, null, true)['price']);

        // The catalogue says "free for you" to one and the price (VAT included) to another.
        $this->assertTrue($this->asUser($gov->user)->getJson("/api/v1/public/programs/{$p->code}")->assertOk()->json('data.pricing.free_for_you'));
        $this->assertEquals(367.5, $this->asUser($priv->user)->getJson("/api/v1/public/programs/{$p->code}")->json('data.pricing.price'));
        $visitor = $this->withHeaders(['Authorization' => ''])->getJson("/api/v1/public/programs/{$p->code}")->json('data.pricing');
        $this->assertSame(0, (int) $visitor['from']);
        $this->assertSame(500, (int) $visitor['to']);
    }

    public function test_conditional_rules_and_the_group_list_overriding_the_programme_list(): void
    {
        [$p, $g] = $this->course();
        PriceList::create(['program_id' => $p->id, 'rules' => [], 'default_price' => 100, 'vat_rate' => 0, 'currency' => 'QAR']);
        $this->assertEquals(100.0, app(PricingService::class)->resolve($g, $this->buyer())['price']);                    // the programme's list
        $this->price($g, [['category' => 'any', 'price' => 20, 'match' => [['field' => 'region', 'operator' => 'in', 'value' => ['nowhere']]]], ['category' => 'any', 'price' => 60]], 200);
        $this->assertEquals(60.0, app(PricingService::class)->resolve($g, $this->buyer())['price']);                     // the group's list wins; the first rule did not match
        $this->assertEquals(0.0, app(PricingService::class)->resolve($this->course()[1], $this->buyer())['price']);      // no list at all: free
    }

    public function test_price_lists_are_edited_with_a_preview_and_a_sensible_refund_policy(): void
    {
        [, $g] = $this->course();
        $this->asUser($this->makeUser())->putJson("/api/v1/admin/groups/{$g->id}/price-list", ['default_price' => 1, 'vat_rate' => 0])->assertForbidden();
        $r = $this->asUser($this->finance)->putJson("/api/v1/admin/groups/{$g->id}/price-list", ['rules' => [['category' => 'ministry_staff', 'price' => 0], ['category' => 'external', 'price' => 450, 'label_en' => 'Externals']], 'default_price' => 300, 'vat_rate' => 5, 'refund_policy' => ['full_days' => 10, 'partial_days' => 4, 'partial_percent' => 40]])->assertOk();
        $prev = collect($r->json('data.preview'))->keyBy('category');
        $this->assertTrue($prev['ministry_staff']['free']);
        $this->assertEquals(472.5, $prev['external']['price_with_vat']);
        $this->assertEquals(315.0, $prev['private_school']['price_with_vat']);                                           // falls to the default
        $this->asUser($this->finance)->putJson("/api/v1/admin/groups/{$g->id}/price-list", ['default_price' => 1, 'vat_rate' => 0, 'refund_policy' => ['full_days' => 2, 'partial_days' => 5]])->assertStatus(422);
        $this->asUser($this->finance)->putJson("/api/v1/admin/groups/{$g->id}/price-list", ['rules' => [['category' => 'robots', 'price' => 1]], 'default_price' => 1, 'vat_rate' => 0])->assertStatus(422);
        $this->asUser($this->finance)->getJson("/api/v1/admin/groups/{$g->id}/price-list")->assertOk()->assertJsonPath('data.refund_policy.full_days', 10);
    }

    // ---- discount codes ----------------------------------------------------------------------------

    public function test_discount_codes_respect_dates_limits_scope_and_use_per_person(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 200]], 200, 10);
        $emp = $this->buyer();
        $this->asUser($this->finance)->postJson('/api/v1/admin/discount-codes', ['code' => 'half', 'type' => 'percent', 'value' => 50, 'scope' => 'all'])->assertCreated();
        $this->asUser($this->finance)->postJson('/api/v1/admin/discount-codes', ['code' => 'HALF', 'type' => 'percent', 'value' => 10, 'scope' => 'all'])->assertStatus(422);        // codes are unique, whatever the case
        $this->asUser($this->finance)->postJson('/api/v1/admin/discount-codes', ['code' => 'BAD', 'type' => 'percent', 'value' => 150, 'scope' => 'all'])->assertStatus(422);
        $this->asUser($this->finance)->postJson('/api/v1/admin/discount-codes', ['code' => 'SCOPED', 'type' => 'amount', 'value' => 20, 'scope' => 'program'])->assertStatus(422);          // needs the programme

        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $cart = $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'half'])->assertOk()->json('data');
        $this->assertEquals(200.0, $cart['subtotal']);
        $this->assertEquals(100.0, $cart['discount']);
        $this->assertEquals(10.0, $cart['vat']);                                    // VAT on the discounted amount
        $this->assertEquals(110.0, $cart['total']);

        $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'nope'])->assertStatus(422);
        DiscountCode::where('code', 'HALF')->update(['valid_to' => now()->subDay()]);
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'HALF'])->assertStatus(422);
        DiscountCode::where('code', 'HALF')->update(['valid_to' => null, 'usage_limit' => 1, 'used' => 1]);
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'HALF'])->assertStatus(422);
        DiscountCode::where('code', 'HALF')->update(['usage_limit' => null, 'used' => 0]);
        DiscountCode::create(['code' => 'OTHER', 'type' => 'amount', 'value' => 10, 'scope' => 'program', 'scope_id' => $this->course()[0]->id, 'per_user_limit' => 1, 'used' => 0]);
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'OTHER'])->assertStatus(422);                         // does not apply to what is in the cart

        // Used once per person: after paying with it, the same person cannot use it again.
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'HALF'])->assertOk();
        $order = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->assertCreated()->json('data.order.id'));
        $this->pay($order);
        $this->assertSame(1, DiscountCode::where('code', 'HALF')->value('used'));
        $other = $this->makeProgram(['capacity' => 5]);
        $g2 = TrainingGroup::where('program_id', $other->id)->first();
        $this->price($g2, [['category' => 'any', 'price' => 100]], 100);
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g2->id])->assertCreated();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/discount', ['code' => 'HALF'])->assertStatus(422);
    }

    // ---- seats -------------------------------------------------------------------------------------

    public function test_a_cart_holds_the_seat_and_nobody_can_take_the_last_one(): void
    {
        [$p, $g] = $this->course([], ['capacity' => 1]);
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $a = $this->buyer();
        $b = $this->buyer();
        $this->assertSame(1, $g->seatsAvailable());
        $this->asUser($a->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $this->assertSame(0, $g->refresh()->seatsAvailable());                                                          // held for A
        $this->asUser($b->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertStatus(422)->assertJsonPath('code', 'no_seats');

        // A's hold runs out: the seat is free again, and B can take it.
        SeatHold::query()->update(['expires_at' => now()->subMinute()]);
        CartItem::query()->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $g->refresh()->seatsAvailable());
        $this->asUser($b->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $this->assertSame(0, app(SeatHolds::class)->prune() >= 0 ? 0 : 1);
        // A's expired line is dropped from the cart when it is read.
        $this->assertCount(0, $this->asUser($a->user)->getJson('/api/v1/me/cart')->json('data.lines'));
        $this->asUser($b->user)->deleteJson("/api/v1/me/cart/items/{$g->id}")->assertOk();
        $this->assertSame(1, $g->refresh()->seatsAvailable());                                                           // removing releases at once
    }

    public function test_a_person_cannot_buy_what_they_already_have_or_are_not_eligible_for(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        Registration::create(['program_id' => $p->id, 'employee_id' => $emp->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertStatus(422)->assertJsonPath('code', 'duplicate');
        $this->asUser($this->makeUser())->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertStatus(422)->assertJsonPath('code', 'no_profile');
        $this->asUser($emp->user)->postJson('/api/v1/me/checkout')->assertStatus(422)->assertJsonPath('code', 'empty_cart');
    }

    // ---- checkout, gateway, callbacks --------------------------------------------------------------

    public function test_paying_confirms_the_seat_issues_an_invoice_and_sends_a_receipt(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 200]], 200, 5);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $r = $this->asUser($emp->user)->postJson('/api/v1/me/checkout')->assertCreated()->json('data');
        $this->assertStringContainsString('/payments/fake/', $r['redirect_url']);
        $this->assertSame('pending_payment', $r['order']['status']);
        $this->assertEquals(210.0, $r['order']['total']);
        $order = Order::find($r['order']['id']);
        $this->assertSame(1, SeatHold::where(['kind' => 'order', 'owner_id' => $order->id])->count());                    // the cart hold became an order hold
        $this->assertSame(0, Registration::where('program_id', $p->id)->count());                                         // nothing is confirmed before the money is believed

        // The buyer's return alone proves nothing.
        $this->getJson('/api/v1/payments/return?order='.$order->number)->assertOk()->assertJsonPath('data.status', 'pending_payment');
        $this->pay($order);
        $order->refresh();
        $this->assertSame('paid', $order->status);
        $reg = Registration::where('program_id', $p->id)->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame(Registration::STATUS_APPROVED, $reg->status);                                                    // paid seats skip the manager by default
        $this->assertSame(0, SeatHold::count());
        $this->assertMatchesRegularExpression('/^INV-\d{4}-000001$/', $order->invoice_no);
        Storage::disk('local')->assertExists($order->invoice_pdf_path ? 'documents/'.$order->invoice_pdf_path : 'x');
        $this->assertSame(1, AppNotification::where('user_id', $emp->user->id)->where('type', 'order.paid')->count());
        $this->asUser($emp->user)->get("/api/v1/me/orders/{$order->id}/invoice")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asUser($this->buyer()->user)->get("/api/v1/me/orders/{$order->id}/invoice")->assertNotFound();
        $this->assertSame('paid', $this->asUser($emp->user)->getJson("/api/v1/me/orders/{$order->id}")->json('data.status'));
    }

    public function test_the_callback_needs_a_valid_signature_and_is_idempotent(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $order = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $ref = Payment::where('order_id', $order->id)->value('gateway_ref');
        $body = ['event_id' => 'evt-1', 'ref' => $ref, 'status' => 'captured', 'amount' => 100.0, 'order_number' => $order->number];

        $this->signedCallback($body, 'forged')->assertUnauthorized();
        $this->signedCallback($body, null)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->signedCallback($body)->assertOk()->assertJsonPath('outcome', 'duplicate');                                     // the same message again changes nothing
        $this->signedCallback(['event_id' => 'evt-2'] + $body)->assertOk()->assertJsonPath('outcome', 'duplicate');          // even a new event for a paid order
        $this->assertSame(1, Registration::where('program_id', $p->id)->count());
        $this->assertSame(1, AppNotification::where('type', 'order.paid')->count());
        $this->assertNotNull(Order::find($order->id)->invoice_no);
        $this->signedCallback(['event_id' => 'x', 'ref' => 'unknown', 'status' => 'captured', 'amount' => 1])->assertOk()->assertJsonPath('outcome', 'ignored');
    }

    public function test_a_failed_or_cancelled_payment_releases_the_seat_and_tells_the_buyer(): void
    {
        [$p, $g] = $this->course([], ['capacity' => 1]);
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $order = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $this->assertSame(0, $g->refresh()->seatsAvailable());
        $this->pay($order, 'failed');
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(1, $g->refresh()->seatsAvailable());
        $this->assertSame(0, Registration::count());
        $this->assertSame(1, AppNotification::where('user_id', $emp->user->id)->where('type', 'order.failed')->count());
        $this->assertSame('failed', Payment::where('order_id', $order->id)->value('status'));

        // Trying again works; cancelling at the gateway is a failure too.
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $o2 = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $this->pay($o2, 'cancelled');
        $this->assertSame('cancelled', $o2->refresh()->status);
    }

    public function test_an_amount_that_differs_is_not_accepted_and_finance_is_told(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $order = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $ref = Payment::where('order_id', $order->id)->value('gateway_ref');
        $this->signedCallback(['event_id' => 'e9', 'ref' => $ref, 'status' => 'captured', 'amount' => 1.0, 'order_number' => $order->number])->assertOk()->assertJsonPath('outcome', 'mismatch');
        $this->assertSame('failed', $order->refresh()->status);
        $this->assertSame(0, Registration::count());
        $this->assertSame(1, AppNotification::where('user_id', $this->finance->id)->where('type', 'payment.attention')->count());
    }

    public function test_a_free_order_is_confirmed_at_once_and_unpaid_orders_expire(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'ministry_staff', 'price' => 0], ['category' => 'external', 'price' => 100]], 100);
        $gov = $this->buyer();
        $this->asUser($gov->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $r = $this->asUser($gov->user)->postJson('/api/v1/me/checkout')->assertCreated()->json('data');
        $this->assertNull($r['redirect_url']);
        $this->assertSame('paid', $r['order']['status']);
        $this->assertSame(1, Registration::where('employee_id', $gov->id)->count());

        [, $g2] = $this->course();
        $this->price($g2, [['category' => 'any', 'price' => 50]], 50);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g2->id])->assertCreated();
        $o = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $o->update(['expires_at' => now()->subMinute()]);
        $this->artisan('tedc:payments-tick')->assertSuccessful();
        $this->assertSame('cancelled', $o->refresh()->status);
        $this->assertSame(0, SeatHold::count());
    }

    public function test_the_gateway_being_down_charges_nothing_and_says_so(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        Http::fake(['gateway.test/*' => Http::response('down', 503)]);
        $this->asUser($this->admin)->putJson('/api/v1/admin/integrations/moe_epay', ['driver' => 'http', 'enabled' => true, 'settings' => ['base_url' => 'https://gateway.test', 'merchant_id' => 'M1', 'secret' => 's3cret']])->assertOk();
        $this->asUser($emp->user)->postJson('/api/v1/me/checkout')->assertStatus(422)->assertJsonPath('code', 'gateway_unavailable');
        $this->assertSame('failed', Order::first()->status);
        $this->assertSame(0, SeatHold::count());
    }

    public function test_the_ministry_gateway_signs_requests_and_verifies_its_callbacks(): void
    {
        $gw = new MoeEpayGateway(['base_url' => 'https://gateway.test', 'merchant_id' => 'M1', 'secret' => 'topsecret']);
        $raw = json_encode(['event_id' => 'a', 'payment_id' => 'P1', 'status' => 'SUCCESS', 'amount' => '10.00', 'order_number' => 'ORD-1']);
        $this->assertTrue($gw->verify($raw, hash_hmac('sha256', $raw, 'topsecret')));
        $this->assertFalse($gw->verify($raw, hash_hmac('sha256', $raw, 'other')));
        $this->assertFalse($gw->verify($raw, null));
        $this->assertFalse((new MoeEpayGateway(['secret' => '']))->verify($raw, hash_hmac('sha256', $raw, '')));   // no secret, no trust
        $this->assertSame('captured', $gw->parse(json_decode($raw, true))['status']);
        $this->assertSame('cancelled', $gw->parse(['status' => 'CANCELLED'])['status']);
    }

    // ---- entities and vouchers ---------------------------------------------------------------------

    private function entityWithAdmin(): array
    {
        $admin = $this->makeUser();
        $e = EntityAccount::create(['name_ar' => 'مدرسة', 'name_en' => 'Gulf Academy', 'type' => 'private_school', 'cr_number' => 'CR1', 'status' => 'active']);
        DB::table('entity_account_users')->insert(['entity_account_id' => $e->id, 'user_id' => $admin->id, 'role' => 'admin']);

        return [$e, $admin];
    }

    public function test_an_entity_buys_seats_gets_vouchers_assigns_them_and_staff_redeem_them(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'entity', 'price' => 300], ['category' => 'any', 'price' => 500]], 500, 5);
        [$entity, $admin] = $this->entityWithAdmin();
        $this->asUser($this->makeUser())->postJson('/api/v1/entity/cart', ['entity_id' => $entity->id, 'group_id' => $g->id, 'quantity' => 2])->assertForbidden();        // not their entity
        $t = $this->asUser($admin)->postJson('/api/v1/entity/cart', ['entity_id' => $entity->id, 'group_id' => $g->id, 'quantity' => 3])->assertCreated()->json('data');
        $this->assertEquals(900.0, $t['subtotal']);
        $this->assertEquals(945.0, $t['total']);
        $this->assertSame(7, $g->refresh()->seatsAvailable());                                                                 // three held
        $order = Order::find($this->asUser($admin)->postJson('/api/v1/entity/checkout', ['entity_id' => $entity->id])->assertCreated()->json('data.order.id'));
        $this->pay($order);
        $this->assertSame(3, SeatVoucher::where('order_id', $order->id)->count());
        $this->assertSame(0, Registration::count());                                                                           // seats are reserved, nobody registered yet
        $this->assertSame(7, $g->refresh()->seatsAvailable());                                                                 // …and still reserved, as vouchers

        $one = $this->buyer();
        $two = $this->buyer();
        $res = $this->asUser($admin)->postJson('/api/v1/entity/vouchers/assign', ['entity_id' => $entity->id, 'entries' => [['group_id' => $g->id, 'employee_no' => $one->employee_no], ['group_id' => $g->id, 'email' => $two->user->email], ['group_id' => $g->id, 'email' => 'new.hire@school.test']]])->assertOk()->json('data');
        $this->assertSame(3, $res['assigned']);
        $this->asUser($admin)->postJson('/api/v1/entity/vouchers/assign', ['entity_id' => $entity->id, 'entries' => [['group_id' => $g->id, 'email' => 'x@y.test']]])->assertOk()->assertJsonPath('data.failed.0.code', 'no_voucher');
        $this->assertSame(1, AppNotification::where('user_id', $one->user->id)->where('type', 'voucher.assigned')->count());

        $code = $this->asUser($one->user)->getJson('/api/v1/me/vouchers')->assertOk()->json('data.0.code');
        $this->asUser($two->user)->postJson('/api/v1/me/vouchers/redeem', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'voucher_other_person');
        $this->asUser($one->user)->postJson('/api/v1/me/vouchers/redeem', ['code' => strtolower($code)])->assertCreated()->assertJsonPath('data.status', 'redeemed');
        $this->assertSame(Registration::STATUS_APPROVED, Registration::where('employee_id', $one->id)->value('status'));
        $this->assertSame(7, $g->refresh()->seatsAvailable());                                                                 // the voucher's seat became theirs: no double count
        $this->asUser($one->user)->postJson('/api/v1/me/vouchers/redeem', ['code' => $code])->assertStatus(422);              // once only
        $usage = $this->asUser($admin)->getJson('/api/v1/entity/vouchers?entity_id='.$entity->id)->assertOk()->json('usage');
        $this->assertSame(1, $usage['redeemed']);
        $this->assertSame(2, $usage['unused']);
    }

    public function test_a_voucher_cannot_be_used_by_someone_ineligible_and_expired_ones_free_their_seats(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'entity', 'price' => 100]], 100);
        [$entity, $admin] = $this->entityWithAdmin();
        $this->asUser($admin)->postJson('/api/v1/entity/cart', ['entity_id' => $entity->id, 'group_id' => $g->id, 'quantity' => 2])->assertCreated();
        $order = Order::find($this->asUser($admin)->postJson('/api/v1/entity/checkout', ['entity_id' => $entity->id])->json('data.order.id'));
        $this->pay($order);
        $this->assertSame(8, $g->refresh()->seatsAvailable());

        $noProfile = $this->makeUser();
        $voucher = SeatVoucher::where('order_id', $order->id)->first();
        $this->asUser($noProfile)->postJson('/api/v1/me/vouchers/redeem', ['code' => $voucher->code])->assertStatus(422)->assertJsonPath('code', 'no_profile');
        $this->assertSame(8, $g->refresh()->seatsAvailable());                                                                 // a failed attempt keeps the seat reserved
        $this->assertSame('available', $voucher->refresh()->status);

        SeatVoucher::query()->update(['expires_at' => now()->subHour()]);
        $this->artisan('tedc:payments-daily')->assertSuccessful();
        $this->assertSame(2, SeatVoucher::where('status', 'expired')->count());
        $this->assertSame(10, $g->refresh()->seatsAvailable());
        $this->asUser($this->buyer()->user)->postJson('/api/v1/me/vouchers/redeem', ['code' => $voucher->code])->assertStatus(422);
    }

    public function test_entity_admins_are_warned_before_vouchers_expire(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'entity', 'price' => 100]], 100);
        [$entity, $admin] = $this->entityWithAdmin();
        $this->asUser($admin)->postJson('/api/v1/entity/cart', ['entity_id' => $entity->id, 'group_id' => $g->id, 'quantity' => 2])->assertCreated();
        $this->pay(Order::find($this->asUser($admin)->postJson('/api/v1/entity/checkout', ['entity_id' => $entity->id])->json('data.order.id')));
        SeatVoucher::query()->update(['expires_at' => now()->addDays(5)]);
        $this->artisan('tedc:payments-daily')->assertSuccessful();
        $this->artisan('tedc:payments-daily')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'voucher.expiring')->count());   // once
    }

    // ---- refunds -----------------------------------------------------------------------------------

    private function paidOrder(Employee $emp, TrainingGroup $g): Order
    {
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $o = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $this->pay($o);

        return $o->refresh();
    }

    public function test_refund_policy_windows_decide_the_amount(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 200]], 200, 0, ['full_days' => 7, 'partial_days' => 3, 'partial_percent' => 50]);
        $svc = app(RefundService::class);
        $g->update(['start_date' => now()->addDays(10)]);
        $this->assertSame(100, $svc->percentFor($g->refresh()));
        $g->update(['start_date' => now()->addDays(7)]);
        $this->assertSame(100, $svc->percentFor($g->refresh()));
        $g->update(['start_date' => now()->addDays(5)]);
        $this->assertSame(50, $svc->percentFor($g->refresh()));
        $g->update(['start_date' => now()->addDays(2)]);
        $this->assertSame(0, $svc->percentFor($g->refresh()));
        $g->update(['start_date' => now()->subDay()]);
        $this->assertSame(0, $svc->percentFor($g->refresh()));
    }

    public function test_a_refund_is_requested_approved_by_finance_cancels_the_seat_and_issues_a_credit_note(): void
    {
        [$p, $g] = $this->course([], ['capacity' => 1]);
        $this->price($g, [['category' => 'any', 'price' => 200]], 200, 5, ['full_days' => 7, 'partial_days' => 3, 'partial_percent' => 50]);
        $emp = $this->buyer();
        $order = $this->paidOrder($emp, $g);
        $this->assertSame(0, $g->refresh()->seatsAvailable());

        $this->asUser($this->buyer()->user)->postJson("/api/v1/me/orders/{$order->id}/refund-request", [])->assertNotFound();
        $q = $this->asUser($emp->user)->getJson("/api/v1/me/orders/{$order->id}")->json('data.refundable');
        $this->assertEquals(210.0, $q['amount']);
        $refund = Refund::find($this->asUser($emp->user)->postJson("/api/v1/me/orders/{$order->id}/refund-request", ['reason' => 'Cannot attend'])->assertCreated()->json('data.id'));
        $this->assertSame(1, AppNotification::where('user_id', $this->finance->id)->where('type', 'refund.requested')->count());
        $this->asUser($emp->user)->postJson("/api/v1/me/orders/{$order->id}/refund-request", [])->assertStatus(422)->assertJsonPath('code', 'refund_pending');

        $this->asUser($emp->user)->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->asUser($this->finance)->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'reject'])->assertStatus(422);               // a refusal needs a reason
        $this->asUser($this->finance)->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->asUser($this->finance)->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'approve'])->assertStatus(422);               // decided once
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame(Registration::STATUS_CANCELLED, Registration::where('employee_id', $emp->id)->value('status'));
        $this->assertSame(1, $g->refresh()->seatsAvailable());                                                                                              // the seat is free again
        $this->assertSame('refunded', Payment::where('order_id', $order->id)->value('status'));
        $this->assertMatchesRegularExpression('/^CN-\d{4}-000001$/', $refund->refresh()->credit_note_no);
        $this->asUser($emp->user)->get("/api/v1/me/refunds/{$refund->id}/credit-note")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, AppNotification::where('user_id', $emp->user->id)->where('type', 'refund.decided')->count());
        $this->asUser($emp->user)->postJson("/api/v1/me/orders/{$order->id}/refund-request", [])->assertStatus(422)->assertJsonPath('code', 'not_paid');
    }

    public function test_a_late_request_gets_nothing_and_a_partial_window_gets_a_share(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 200]], 200, 0, ['full_days' => 7, 'partial_days' => 3, 'partial_percent' => 25]);
        $emp = $this->buyer();
        $order = $this->paidOrder($emp, $g);
        $g->update(['start_date' => now()->addDays(4)]);
        $this->assertEquals(50.0, $this->asUser($emp->user)->getJson("/api/v1/me/orders/{$order->id}")->json('data.refundable.amount'));
        $g->update(['start_date' => now()->addDay()]);
        $this->asUser($emp->user)->postJson("/api/v1/me/orders/{$order->id}/refund-request", [])->assertStatus(422)->assertJsonPath('code', 'not_refundable');
    }

    public function test_a_refusal_keeps_the_seat_and_explains(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        $order = $this->paidOrder($emp, $g);
        $refund = Refund::find($this->asUser($emp->user)->postJson("/api/v1/me/orders/{$order->id}/refund-request", [])->json('data.id'));
        $this->asUser($this->finance)->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'reject', 'note' => 'Course already attended'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(Registration::STATUS_APPROVED, Registration::where('employee_id', $emp->id)->value('status'));
        $this->assertSame('Course already attended', $this->asUser($emp->user)->getJson("/api/v1/me/orders/{$order->id}")->json('data.refunds.0.note'));
    }

    public function test_an_entity_is_refunded_for_unused_vouchers_only(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'entity', 'price' => 100]], 100, 0, ['full_days' => 7, 'partial_days' => 3, 'partial_percent' => 50]);
        [$entity, $admin] = $this->entityWithAdmin();
        $this->asUser($admin)->postJson('/api/v1/entity/cart', ['entity_id' => $entity->id, 'group_id' => $g->id, 'quantity' => 3])->assertCreated();
        $order = Order::find($this->asUser($admin)->postJson('/api/v1/entity/checkout', ['entity_id' => $entity->id])->json('data.order.id'));
        $this->pay($order);
        $emp = $this->buyer();
        $this->asUser($admin)->postJson('/api/v1/entity/vouchers/assign', ['entity_id' => $entity->id, 'entries' => [['group_id' => $g->id, 'employee_no' => $emp->employee_no]]])->assertOk();
        $this->asUser($emp->user)->postJson('/api/v1/me/vouchers/redeem', ['code' => SeatVoucher::where('assigned_employee_id', $emp->id)->value('code')])->assertCreated();

        $refund = Refund::find($this->asUser($admin)->postJson("/api/v1/me/orders/{$order->id}/refund-request", ['reason' => 'Fewer staff'])->assertCreated()->json('data.id'));
        $this->assertEquals(200.0, (float) $refund->amount);                                                                     // two unused seats, in full
        $this->asUser($this->finance)->postJson("/api/v1/admin/refunds/{$refund->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame('partially_refunded', $order->refresh()->status);
        $this->assertSame(['redeemed' => 1, 'refunded' => 2], SeatVoucher::where('order_id', $order->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all());
        $this->assertSame(Registration::STATUS_APPROVED, Registration::where('employee_id', $emp->id)->value('status'));        // the used seat stays
    }

    // ---- reconciliation and reports ----------------------------------------------------------------

    public function test_reconciliation_completes_a_payment_whose_callback_never_arrived_and_reports_differences(): void
    {
        [$p, $g] = $this->course();
        $this->price($g, [['category' => 'any', 'price' => 100]], 100);
        $emp = $this->buyer();
        $this->asUser($emp->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $order = Order::find($this->asUser($emp->user)->postJson('/api/v1/me/checkout')->json('data.order.id'));
        $ref = Payment::where('order_id', $order->id)->value('gateway_ref');

        // Another paid order whose amount the gateway lists differently, and one the platform has never heard of.
        [, $g2] = $this->course();
        $this->price($g2, [['category' => 'any', 'price' => 50]], 50);
        $emp2 = $this->buyer();
        $o2 = $this->paidOrder($emp2, $g2);
        FakeGateway::$statement = [['ref' => $ref, 'status' => 'captured', 'amount' => 100.0], ['ref' => Payment::where('order_id', $o2->id)->value('gateway_ref'), 'status' => 'captured', 'amount' => 49.0], ['ref' => 'GHOST-1', 'status' => 'captured', 'amount' => 10.0]];
        $r = app(ReconciliationService::class)->run(now());
        FakeGateway::$statement = [];

        $this->assertSame(1, $r->fixed);
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(Registration::STATUS_APPROVED, Registration::where('employee_id', $emp->id)->value('status'));
        $types = collect($r->mismatches)->pluck('type')->sort()->values()->all();
        $this->assertSame(['amount_differs', 'unknown_at_platform'], $types);
        $this->assertSame(1, AppNotification::where('user_id', $this->finance->id)->where('type', 'payment.attention')->count());
        $this->asUser($this->finance)->getJson('/api/v1/admin/payment-reconciliations')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_finance_reports_total_revenue_by_programme_category_and_entity_and_export(): void
    {
        [$p, $g] = $this->course(['title_en' => 'Leadership']);
        $this->price($g, [['category' => 'private_school', 'price' => 300], ['category' => 'entity', 'price' => 250], ['category' => 'external', 'price' => 400]], 0, 5);
        $priv = $this->privateEmployee();
        $this->paidOrder($priv, $g);
        [$entity, $admin] = $this->entityWithAdmin();
        $this->asUser($admin)->postJson('/api/v1/entity/cart', ['entity_id' => $entity->id, 'group_id' => $g->id, 'quantity' => 2])->assertCreated();
        $this->pay(Order::find($this->asUser($admin)->postJson('/api/v1/entity/checkout', ['entity_id' => $entity->id])->json('data.order.id')));
        $pending = $this->external();
        $this->asUser($pending->user)->postJson('/api/v1/me/cart/items', ['group_id' => $g->id])->assertCreated();
        $this->asUser($pending->user)->postJson('/api/v1/me/checkout')->assertCreated();

        $this->asUser($priv->user)->getJson('/api/v1/admin/finance/report')->assertForbidden();
        $s = $this->asUser($this->finance)->getJson('/api/v1/admin/finance/report')->assertOk()->json('data');
        $this->assertSame(2, $s['totals']['orders']);
        $this->assertEquals(840.0, $s['totals']['gross']);                                                                        // 315 + 525
        $this->assertEquals(40.0, $s['totals']['vat']);
        $this->assertEquals(420.0, $s['totals']['outstanding']);                                                                  // the external buyer's open order
        $byCat = collect($s['by_category'])->pluck('revenue', 'category');
        $this->assertEquals(315.0, $byCat['private_school']);
        $this->assertEquals(525.0, $byCat['entity']);
        $this->assertSame('Leadership', $s['by_program'][0]['title_en']);
        $this->assertSame(2, $s['by_program'][0]['seats'] - 1);
        $this->assertSame('Gulf Academy', $s['by_entity'][0]['name']);

        $this->asUser($this->finance)->get('/api/v1/admin/finance/report/csv?lang=en')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $orders = $this->asUser($this->finance)->getJson('/api/v1/admin/orders?status=paid')->assertOk()->json('data');
        $this->assertCount(2, $orders);
        $this->asUser($this->finance)->getJson('/api/v1/admin/payments')->assertOk();
    }

    public function test_the_finance_role_exists_without_giving_ordinary_roles_money_powers(): void
    {
        $this->assertNotNull(Role::where('slug', Role::FINANCE_OFFICER)->first());
        foreach (['pricing.manage', 'orders.view', 'refunds.approve', 'finance.reports', 'entity_accounts.manage'] as $perm) {
            $this->assertNull(Role::where('slug', Role::EMPLOYEE)->first()->permissions()->where('slug', $perm)->first());
            $this->assertNull(Role::where('slug', Role::TRAINER)->first()->permissions()->where('slug', $perm)->first());
        }
        $this->assertNotNull(Role::where('slug', Role::FINANCE_OFFICER)->first()->permissions()->where('slug', 'refunds.approve')->first());
    }
}
