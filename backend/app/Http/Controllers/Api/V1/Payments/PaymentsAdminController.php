<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\DiscountCode;
use App\Models\EntityAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Models\PriceList;
use App\Models\Program;
use App\Models\Refund;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Payments\FinanceReports;
use App\Payments\PaymentSettings;
use App\Payments\PricingService;
use App\Payments\ReconciliationService;
use App\Payments\RefundService;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pricing, orders and payments, refunds queue, discount codes, entity accounts, reconciliation and the finance reports. */
class PaymentsAdminController extends Controller
{
    public function __construct(private readonly PricingService $pricing, private readonly RefundService $refunds, private readonly PaymentSettings $settings, private readonly FinanceReports $finance, private readonly ReconciliationService $reconciliation) {}

    // ---- prices --------------------------------------------------------------------------------------

    public function priceList(Request $request): JsonResponse
    {
        $list = $this->subjectList($request);

        return response()->json(['data' => $list ? $this->presentList($list) : null, 'categories' => PricingService::CATEGORIES, 'defaults' => ['refund_policy' => RefundService::DEFAULT_POLICY, 'vat_rate' => $this->settings->all()['default_vat_rate']]]);
    }

    private function subjectList(Request $request): ?PriceList
    {
        [$col, $id] = $this->subject($request);

        return PriceList::where($col, $id)->first();
    }

    /** @return array{0: string, 1: string} */
    private function subject(Request $request): array
    {
        $g = $request->route('group');
        $p = $request->route('program');
        if ($g) {
            abort_unless(Str::isUuid((string) $g), 404);

            return ['group_id', TrainingGroup::findOrFail($g)->id];
        }
        abort_unless(Str::isUuid((string) $p), 404);

        return ['program_id', Program::findOrFail($p)->id];
    }

    /** @return array<string, mixed> */
    private function presentList(PriceList $l): array
    {
        return ['id' => $l->id, 'currency' => $l->currency, 'rules' => $l->rules ?? [], 'default_price' => $l->default_price, 'vat_rate' => $l->vat_rate, 'refund_policy' => array_replace(RefundService::DEFAULT_POLICY, (array) $l->refund_policy), 'is_active' => $l->is_active, 'preview' => $this->pricing->preview($l)];
    }

    public function savePriceList(Request $request): JsonResponse
    {
        $d = $request->validate([
            'rules' => ['array', 'max:30'], 'rules.*.category' => ['required', Rule::in(PricingService::CATEGORIES)], 'rules.*.price' => ['required', 'numeric', 'min:0', 'max:1000000'], 'rules.*.label_ar' => ['nullable', 'string', 'max:120'], 'rules.*.label_en' => ['nullable', 'string', 'max:120'],
            'rules.*.match' => ['nullable', 'array', 'max:10'], 'rules.*.match.*.field' => ['required', 'string', 'max:40'], 'rules.*.match.*.operator' => ['required', 'string', 'max:20'], 'rules.*.match.*.value' => ['nullable'],
            'default_price' => ['required', 'numeric', 'min:0', 'max:1000000'], 'vat_rate' => ['required', 'numeric', 'between:0,100'], 'is_active' => ['boolean'],
            'refund_policy' => ['array'], 'refund_policy.full_days' => ['integer', 'between:0,365'], 'refund_policy.partial_days' => ['integer', 'between:0,365'], 'refund_policy.partial_percent' => ['integer', 'between:0,100'],
        ]);
        $policy = array_replace(RefundService::DEFAULT_POLICY, $d['refund_policy'] ?? []);
        if ($policy['partial_days'] > $policy['full_days']) {
            throw new BusinessRuleException('The partial-refund window cannot start earlier than the full-refund window.', 'bad_policy');
        }
        [$col, $id] = $this->subject($request);
        $rules = array_map(fn ($r) => array_filter(['category' => $r['category'], 'price' => (float) $r['price'], 'label_ar' => $r['label_ar'] ?? null, 'label_en' => $r['label_en'] ?? null, 'match' => $r['match'] ?? null], fn ($v) => $v !== null), $d['rules'] ?? []);
        $list = PriceList::updateOrCreate([$col => $id], ['rules' => $rules, 'default_price' => $d['default_price'], 'vat_rate' => $d['vat_rate'], 'refund_policy' => $policy, 'is_active' => $d['is_active'] ?? true, 'currency' => 'QAR']);

        return response()->json(['data' => $this->presentList($list)]);
    }

    public function deletePriceList(Request $request): JsonResponse
    {
        [$col, $id] = $this->subject($request);
        PriceList::where($col, $id)->delete();

        return response()->json(['message' => 'ok']);
    }

    // ---- orders, payments, refunds -------------------------------------------------------------------

    public function orders(Request $request): JsonResponse
    {
        $rows = Order::with('user:id,name,name_ar,email')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereRaw('lower(number) like ?', ['%'.mb_strtolower($t).'%'])->orWhereRaw("lower(coalesce(invoice_no, '')) like ?", ['%'.mb_strtolower($t).'%'])))
            ->when($request->query('buyer_type'), fn ($q, $t) => $q->where('buyer_type', $t))->latest()->paginate($this->perPage($request, 25));

        return response()->json(['data' => $rows->getCollection()->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'buyer_type' => $o->buyer_type, 'buyer' => $o->user?->displayName(), 'email' => $o->user?->email, 'total' => $o->total, 'currency' => $o->currency, 'invoice_no' => $o->invoice_no,
            'paid_at' => $o->paid_at?->toIso8601String(), 'created_at' => $o->created_at->toIso8601String(), 'items' => count($o->items)])->values(), 'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $rows = Payment::with('order:id,number')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate($this->perPage($request, 25));

        return response()->json(['data' => $rows->getCollection()->map(fn (Payment $p) => ['id' => $p->id, 'order' => $p->order?->number, 'gateway' => $p->gateway, 'gateway_ref' => $p->gateway_ref, 'amount' => $p->amount, 'status' => $p->status, 'signature_valid' => $p->signature_valid, 'captured_at' => $p->captured_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String()])->values(), 'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function refundQueue(Request $request): JsonResponse
    {
        $rows = Refund::with('order:id,number,user_id', 'order.user:id,name,name_ar')->when($request->query('status', 'requested'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Refund $r) => ['id' => $r->id, 'order' => $r->order?->number, 'buyer' => $r->order?->user?->displayName(), 'amount' => $r->amount, 'status' => $r->status, 'reason' => $r->reason, 'items' => $r->items, 'credit_note_no' => $r->credit_note_no, 'decision_note' => $r->decision_note, 'created_at' => $r->created_at->toIso8601String()])->values()]);
    }

    public function decideRefund(Request $request, string $shopRefund): JsonResponse
    {
        abort_unless(Str::isUuid($shopRefund), 404);
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:250']]);
        if ($d['decision'] === 'reject' && ! filled($d['note'] ?? null)) {
            throw new BusinessRuleException('Give the reason for refusing.', 'reason_required');
        }
        $r = $this->refunds->decide(Refund::findOrFail($shopRefund), $this->user(), $d['decision'], $d['note'] ?? null);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status, 'credit_note_no' => $r->credit_note_no]]);
    }

    // ---- discount codes ------------------------------------------------------------------------------

    public function codes(): JsonResponse
    {
        return response()->json(['data' => DiscountCode::latest()->limit(200)->get()]);
    }

    public function saveCode(Request $request, ?string $discountCode = null): JsonResponse
    {
        $code = $discountCode ? DiscountCode::findOrFail($discountCode) : null;
        $d = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('discount_codes', 'code')->ignore($code?->id)], 'type' => ['required', Rule::in(['percent', 'amount'])], 'value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'scope' => ['required', Rule::in(['all', 'program', 'group'])], 'scope_id' => ['nullable', 'uuid'], 'usage_limit' => ['nullable', 'integer', 'min:1'], 'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'], 'is_active' => ['boolean'],
        ]);
        if ($d['type'] === 'percent' && $d['value'] > 100) {
            throw new BusinessRuleException('A percentage cannot exceed 100.', 'bad_value');
        }
        if ($d['scope'] !== 'all' && empty($d['scope_id'])) {
            throw new BusinessRuleException('Choose the programme or group the code applies to.', 'scope_required');
        }
        $d['code'] = strtoupper($d['code']);
        $d['per_user_limit'] ??= 1;
        $code = $code ? tap($code)->update($d) : DiscountCode::create($d + ['source' => 'manual']);

        return response()->json(['data' => $code], $code->wasRecentlyCreated ? 201 : 200);
    }

    // ---- entity accounts -----------------------------------------------------------------------------

    public function entities(): JsonResponse
    {
        $rows = EntityAccount::latest()->limit(300)->get()->map(fn ($e) => $e->toArray() + ['admins' => User::whereIn('id', DB::table('entity_account_users')->where('entity_account_id', $e->id)->select('user_id'))->get(['id', 'name', 'name_ar', 'email'])]);

        return response()->json(['data' => $rows]);
    }

    public function saveEntity(Request $request, ?string $entityAccount = null): JsonResponse
    {
        $e = $entityAccount ? EntityAccount::findOrFail($entityAccount) : null;
        $d = $request->validate(['name_ar' => ['required', 'string', 'max:200'], 'name_en' => ['required', 'string', 'max:200'], 'type' => ['required', Rule::in(['private_school', 'company', 'other'])], 'cr_number' => ['nullable', 'string', 'max:40'], 'billing_address' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])], 'admin_emails' => ['nullable', 'array', 'max:10'], 'admin_emails.*' => ['email']]);
        $e = $e ? tap($e)->update(collect($d)->except('admin_emails')->all()) : EntityAccount::create(collect($d)->except('admin_emails')->all());
        foreach ($d['admin_emails'] ?? [] as $mail) {
            if ($u = User::whereRaw('lower(email) = ?', [strtolower($mail)])->first()) {
                DB::table('entity_account_users')->updateOrInsert(['entity_account_id' => $e->id, 'user_id' => $u->id], ['role' => 'admin']);
            }
        }

        return response()->json(['data' => $e->refresh()], $e->wasRecentlyCreated ? 201 : 200);
    }

    // ---- settings, reconciliation, finance -----------------------------------------------------------

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['hold_minutes' => ['integer', 'between:5,120'], 'order_minutes' => ['integer', 'between:10,240'], 'skip_manager_approval' => ['boolean'], 'voucher_valid_days' => ['integer', 'between:7,730'], 'voucher_reminder_days' => ['integer', 'between:1,60'],
            'default_vat_rate' => ['numeric', 'between:0,100'], 'seller_name_ar' => ['nullable', 'string', 'max:160'], 'seller_name_en' => ['nullable', 'string', 'max:160'], 'tax_id' => ['nullable', 'string', 'max:40'], 'finance_email' => ['nullable', 'email', 'max:160']]);

        return response()->json(['data' => $this->settings->save($d, $this->user()->id)]);
    }

    public function reconciliations(): JsonResponse
    {
        return response()->json(['data' => PaymentReconciliation::orderByDesc('day')->limit(60)->get()]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $d = $request->validate(['day' => ['nullable', 'date']]);

        return response()->json(['data' => $this->reconciliation->run(isset($d['day']) ? Carbon::parse($d['day']) : null)]);
    }

    public function report(Request $request): JsonResponse
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json(['data' => $this->finance->summary($d['from'] ?? null, $d['to'] ?? null)]);
    }

    public function export(Request $request, ReportExporter $exporter, string $format): Response
    {
        abort_unless(in_array($format, ['xlsx', 'pdf', 'csv'], true), 404);
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'lang' => ['nullable', 'in:ar,en']]);
        $doc = $this->finance->document($this->finance->summary($d['from'] ?? null, $d['to'] ?? null), ($d['lang'] ?? 'ar') === 'ar');

        return response($exporter->render($doc, $format, $d['lang'] ?? 'ar'), 200, ['Content-Type' => ReportExporter::MIME[$format], 'Content-Disposition' => "attachment; filename=\"finance-report.{$format}\""]);
    }
}
