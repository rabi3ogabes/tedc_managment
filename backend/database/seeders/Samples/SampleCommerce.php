<?php

namespace Database\Seeders\Samples;

use App\Models\AdaptiveRule;
use App\Models\AiLog;
use App\Models\AiRecommendationEvent;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\DiscountCode;
use App\Models\EntityAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentReconciliation;
use App\Models\PriceList;
use App\Models\Refund;
use App\Models\SeatVoucher;
use App\Models\Skill;
use App\Payments\InvoiceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/** Prices, discount codes, entity accounts, paid and pending orders with invoices, a refund, vouchers and reconciliation; and the smart assistant, forecasts, risks and adaptive rules. */
class SampleCommerce
{
    use Steps;

    public function __construct(private readonly SampleContext $c, private readonly InvoiceService $invoices) {}

    public function run(): void
    {
        $this->step('prices', fn () => $this->prices());
        $this->step('orders', fn () => $this->orders());
        $this->step('ai', fn () => $this->ai());
    }

    private function line($group, float $price, int $qty = 1, float $vatRate = 0.0): array
    {
        $net = $price * $qty;
        $vat = round($net * $vatRate / 100, 2);

        return ['group_id' => $group->id, 'program_id' => $group->program_id, 'title_ar' => $group->program?->title_ar ?? $group->code, 'title_en' => $group->program?->title_en ?? $group->code, 'quantity' => $qty, 'unit_price' => $price, 'discount' => 0, 'vat' => $vat, 'total' => $net + $vat];
    }

    private function prices(): void
    {
        $groups = $this->c->groups(4);
        foreach ($groups as $i => $g) {
            if (PriceList::where('group_id', $g->id)->exists() || ! $g->program) {
                continue;
            }
            PriceList::create(['group_id' => $g->id, 'currency' => 'QAR', 'default_price' => 150 + $i * 50, 'vat_rate' => 0, 'rules' => [['category' => 'ministry_staff', 'price' => 0, 'label_ar' => 'موظفو الوزارة مجانًا', 'label_en' => 'Ministry staff free'], ['category' => 'private_school', 'price' => 200 + $i * 50, 'label_ar' => 'المدارس الخاصة', 'label_en' => 'Private schools']], 'refund_policy' => ['full_days' => 7, 'partial_days' => 3, 'partial_percent' => 50], 'is_active' => true]);
        }
        foreach ([['WELCOME10', 'percent', 10, 'all'], ['SPRING50', 'amount', 50, 'all']] as [$code, $type, $value, $scope]) {
            DiscountCode::firstOrCreate(['code' => $code], ['type' => $type, 'value' => $value, 'scope' => $scope, 'usage_limit' => 100, 'per_user_limit' => 1, 'used' => 0, 'valid_from' => now()->subDay(), 'valid_to' => now()->addMonths(3), 'source' => 'manual', 'is_active' => true]);
        }
    }

    private function orders(): void
    {
        $groups = $this->c->groups(4);
        $t = $this->c->trainees();
        if (! $groups || ! $t || Order::where('number', 'like', 'ORD-SMP-%')->exists()) {
            return;
        }
        $g0 = $groups[0];
        $paid = Order::create(['number' => 'ORD-SMP-0001', 'buyer_type' => 'user', 'user_id' => $t[0]->id, 'items' => [$this->line($g0, 200)], 'subtotal' => 200, 'discount' => 20, 'vat' => 0, 'total' => 180, 'currency' => 'QAR', 'discount_code' => 'WELCOME10', 'status' => 'paid', 'paid_at' => now()->subDays(3)]);
        $pay = Payment::create(['order_id' => $paid->id, 'gateway' => 'fake', 'gateway_ref' => 'FAKE-'.Str::upper(Str::random(8)), 'amount' => 180, 'status' => 'captured', 'signature_valid' => true, 'captured_at' => now()->subDays(3)]);
        PaymentEvent::create(['gateway' => 'fake', 'event_id' => 'evt-'.Str::random(10), 'kind' => 'payment.captured', 'signature_valid' => true, 'outcome' => 'applied']);
        $this->invoices->issue($paid->fresh());
        $second = Order::create(['number' => 'ORD-SMP-0002', 'buyer_type' => 'user', 'user_id' => $t[1]->id, 'items' => [$this->line($groups[1] ?? $g0, 250)], 'subtotal' => 250, 'discount' => 0, 'vat' => 0, 'total' => 250, 'currency' => 'QAR', 'status' => 'paid', 'paid_at' => now()->subDays(1)]);
        Payment::create(['order_id' => $second->id, 'gateway' => 'fake', 'gateway_ref' => 'FAKE-'.Str::upper(Str::random(8)), 'amount' => 250, 'status' => 'captured', 'signature_valid' => true, 'captured_at' => now()->subDay()]);
        $this->invoices->issue($second->fresh());
        Refund::create(['order_id' => $second->id, 'payment_id' => Payment::where('order_id', $second->id)->value('id'), 'items' => [], 'amount' => 125, 'reason' => 'تعذّر الحضور بسبب تكليف رسمي', 'status' => 'requested', 'requested_by' => $t[1]->id]);
        Order::create(['number' => 'ORD-SMP-0003', 'buyer_type' => 'user', 'user_id' => $t[2]->id, 'items' => [$this->line($groups[2] ?? $g0, 300)], 'subtotal' => 300, 'discount' => 0, 'vat' => 0, 'total' => 300, 'currency' => 'QAR', 'status' => 'pending', 'expires_at' => now()->addHours(2)]);

        $entity = EntityAccount::firstOrCreate(['name_en' => 'Al Noor Private School'], ['name_ar' => 'مدرسة النور الخاصة', 'type' => 'private_school', 'cr_number' => 'CR-123456', 'contacts' => [['name' => 'مسؤول الحسابات', 'email' => 'accounts@alnoor.example.qa']], 'billing_address' => 'الدوحة', 'status' => 'active']);
        $eo = Order::create(['number' => 'ORD-SMP-0004', 'buyer_type' => 'entity', 'user_id' => $this->c->admin()->id, 'entity_account_id' => $entity->id, 'items' => [$this->line($g0, 200, 5)], 'subtotal' => 1000, 'discount' => 0, 'vat' => 0, 'total' => 1000, 'currency' => 'QAR', 'status' => 'paid', 'paid_at' => now()->subDays(2)]);
        Payment::create(['order_id' => $eo->id, 'gateway' => 'fake', 'gateway_ref' => 'FAKE-'.Str::upper(Str::random(8)), 'amount' => 1000, 'status' => 'captured', 'signature_valid' => true, 'captured_at' => now()->subDays(2)]);
        $this->invoices->issue($eo->fresh());
        foreach (range(1, 5) as $n) {
            SeatVoucher::create(['order_id' => $eo->id, 'entity_account_id' => $entity->id, 'group_id' => $g0->id, 'code' => 'SMP-'.strtoupper(Str::random(8)), 'status' => $n === 1 ? 'assigned' : 'open', 'assigned_email' => $n === 1 ? 'teacher.alnoor@example.qa' : null, 'expires_at' => now()->addDays(60)]);
        }
        PaymentReconciliation::firstOrCreate(['day' => now()->subDay()->toDateString()], ['matched' => 3, 'fixed' => 0, 'mismatches' => []]);
    }

    private function ai(): void
    {
        $t = $this->c->trainees();
        if (! $t || AssistantConversation::where('title', 'like', '%سؤال عن التسجيل%')->exists()) {
            return;
        }
        $conv = AssistantConversation::create(['user_id' => $t[0]->id, 'title' => 'سؤال عن التسجيل', 'locale' => 'ar']);
        AssistantMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'كيف أسجل في برنامج التقويم التكويني؟']);
        AssistantMessage::create(['conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'افتح صفحة «البرامج»، اختر البرنامج ثم المجموعة المناسبة واضغط «سجّل». إن لزمت موافقة المدير ستصلك رسالة عند اعتمادها.', 'citations' => [['title' => 'التسجيل في برنامج تدريبي', 'slug' => 'register-for-a-program']], 'feedback' => 'up']);
        AiLog::create(['feature' => 'assistant', 'user_id' => $t[0]->id, 'model' => 'local', 'status' => 'ok', 'residency' => 'local', 'prompt_chars' => 44, 'tokens_in' => 30, 'tokens_out' => 80, 'latency_ms' => 420, 'redactions' => 0, 'created_at' => now()->subHours(3)]);
        $p = $this->c->program('TEST-P1');
        $skill = Skill::query()->first();
        if ($p && $skill && ! AdaptiveRule::where('program_id', $p->id)->exists()) {
            AdaptiveRule::create(['program_id' => $p->id, 'skill_id' => $skill->id, 'action' => 'skip_module', 'skip_at' => 0.8, 'is_active' => true]);
        }
        foreach ($t as $i => $u) {
            if ($p) {
                AiRecommendationEvent::create(['user_id' => $u->id, 'item_type' => 'program', 'item_id' => $p->id, 'event' => $i % 2 ? 'clicked' : 'shown', 'variant' => 'hybrid', 'score' => 0.8 - $i * 0.1, 'reasons' => ['يناسب مسماك الوظيفي'], 'created_at' => now()->subHours($i + 1)]);
            }
        }
        Artisan::call('tedc:ai-nightly');   // forecasts, risk flags and recommendation similarity from the data above
    }
}
