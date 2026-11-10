<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EntityAccount;
use App\Models\Order;
use App\Models\Refund;
use App\Models\SeatVoucher;
use App\Models\TrainingGroup;
use App\Payments\CartService;
use App\Payments\CheckoutService;
use App\Payments\EntityPurchaseService;
use App\Payments\RefundService;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** The buyer's side: cart, checkout, orders with invoices, refund requests, and vouchers — for a person and for the entities they administer. */
class ShopController extends Controller
{
    public function __construct(private readonly CartService $carts, private readonly CheckoutService $checkout, private readonly RefundService $refunds, private readonly EntityPurchaseService $entities, private readonly FileStorage $files) {}

    private function entity(Request $request): ?EntityAccount
    {
        $id = $request->input('entity_id', $request->query('entity_id'));
        if (! $id) {
            return null;
        }
        abort_unless(Str::isUuid((string) $id), 422);
        $e = EntityAccount::findOrFail($id);
        abort_unless($this->entities->adminOf($this->user(), $e) && $e->status === 'active', 403);

        return $e;
    }

    // ---- cart ----------------------------------------------------------------------------------------

    public function cart(Request $request): JsonResponse
    {
        $cart = $this->carts->cartFor($this->user(), $this->entity($request));

        return response()->json(['data' => $this->carts->totals($cart)]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $d = $request->validate(['group_id' => ['required', 'uuid'], 'quantity' => ['nullable', 'integer', 'between:1,500'], 'entity_id' => ['nullable', 'uuid']]);
        $cart = $this->carts->cartFor($this->user(), $this->entity($request));
        $this->carts->add($cart, TrainingGroup::findOrFail($d['group_id']), (int) ($d['quantity'] ?? 1));

        return response()->json(['data' => $this->carts->totals($cart)], 201);
    }

    public function removeItem(Request $request, string $group): JsonResponse
    {
        abort_unless(Str::isUuid($group), 404);
        $cart = $this->carts->cartFor($this->user(), $this->entity($request));
        $this->carts->remove($cart, $group);

        return response()->json(['data' => $this->carts->totals($cart)]);
    }

    public function discount(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['nullable', 'string', 'max:40'], 'entity_id' => ['nullable', 'uuid']]);
        $cart = $this->carts->cartFor($this->user(), $this->entity($request));
        $this->carts->applyCode($cart, $d['code'] ?? null);

        return response()->json(['data' => $this->carts->totals($cart->refresh())]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $request->validate(['entity_id' => ['nullable', 'uuid']]);
        $cart = $this->carts->cartFor($this->user(), $this->entity($request));
        $r = $this->checkout->start($this->user(), $cart);

        return response()->json(['data' => ['order' => $this->order($r['order']), 'redirect_url' => $r['redirect_url']]], 201);
    }

    // ---- orders --------------------------------------------------------------------------------------

    public function orders(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $q = Order::where('user_id', $this->user()->id)->when($entity, fn ($w) => $w->where('entity_account_id', $entity->id), fn ($w) => $w->whereNull('entity_account_id'))->latest();

        return response()->json(['data' => $q->limit(100)->get()->map(fn (Order $o) => $this->order($o))->values()]);
    }

    public function showOrder(string $shopOrder): JsonResponse
    {
        abort_unless(Str::isUuid($shopOrder), 404);
        $order = Order::where('user_id', $this->user()->id)->findOrFail($shopOrder);

        return response()->json(['data' => $this->order($order, true) + ['refundable' => $this->refunds->quote($order)]]);
    }

    /** @return array<string, mixed> */
    private function order(Order $o, bool $detail = false): array
    {
        return ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'buyer_type' => $o->buyer_type, 'subtotal' => $o->subtotal, 'discount' => $o->discount, 'vat' => $o->vat, 'total' => $o->total, 'currency' => $o->currency, 'paid_at' => $o->paid_at?->toIso8601String(),
            'created_at' => $o->created_at?->toIso8601String(), 'invoice_no' => $o->invoice_no, 'has_invoice' => (bool) $o->invoice_pdf_path, 'expires_at' => $o->expires_at?->toIso8601String(),
            'items' => array_map(fn ($l) => ['group_id' => $l['group_id'], 'title_ar' => $l['title_ar'], 'title_en' => $l['title_en'], 'quantity' => $l['quantity'], 'total' => $l['total'] ?? null, 'fulfilment' => $l['fulfilment'] ?? null], $o->items)]
            + ($detail ? ['refunds' => Refund::where('order_id', $o->id)->latest()->get()->map(fn ($r) => ['id' => $r->id, 'amount' => $r->amount, 'status' => $r->status, 'credit_note_no' => $r->credit_note_no, 'reason' => $r->reason, 'note' => $r->decision_note])] : []);
    }

    public function invoice(string $shopOrder): Response
    {
        abort_unless(Str::isUuid($shopOrder), 404);
        $o = Order::findOrFail($shopOrder);
        abort_unless($o->user_id === $this->user()->id || $this->user()->hasPermission('orders.view'), 404);
        abort_unless($o->invoice_pdf_path, 404);

        return response($this->files->get('documents', $o->invoice_pdf_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$o->invoice_no.'.pdf"']);
    }

    public function creditNote(string $shopRefund): Response
    {
        abort_unless(Str::isUuid($shopRefund), 404);
        $r = Refund::with('order')->findOrFail($shopRefund);
        abort_unless($r->order->user_id === $this->user()->id || $this->user()->hasPermission('orders.view'), 404);
        abort_unless($r->credit_note_pdf_path, 404);

        return response($this->files->get('documents', $r->credit_note_pdf_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$r->credit_note_no.'.pdf"']);
    }

    public function refundRequest(Request $request, string $shopOrder): JsonResponse
    {
        abort_unless(Str::isUuid($shopOrder), 404);
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:250'], 'items' => ['nullable', 'array', 'max:500'], 'items.*' => ['uuid']]);
        $o = Order::where('user_id', $this->user()->id)->findOrFail($shopOrder);
        $r = $this->refunds->request($this->user(), $o, $d['reason'] ?? null, $d['items'] ?? null);

        return response()->json(['data' => ['id' => $r->id, 'amount' => $r->amount, 'status' => $r->status]], 201);
    }

    // ---- vouchers ------------------------------------------------------------------------------------

    public function redeem(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $v = $this->entities->redeem($this->user(), $d['code']);

        return response()->json(['data' => ['status' => $v->status, 'registration_id' => $v->registration_id]], 201);
    }

    public function myVouchers(): JsonResponse
    {
        $emp = Employee::where('user_id', $this->user()->id)->value('id');
        $rows = SeatVoucher::with('group.program:id,title_ar,title_en')->where(fn ($q) => $q->where('assigned_employee_id', $emp)->orWhereRaw('lower(assigned_email) = ?', [strtolower($this->user()->email)]))->whereIn('status', ['assigned', 'redeemed'])->latest()->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($v) => ['id' => $v->id, 'code' => $v->status === 'assigned' ? $v->code : null, 'status' => $v->status, 'expires_at' => $v->expires_at->toIso8601String(), 'program_ar' => $v->group?->program?->title_ar, 'program_en' => $v->group?->program?->title_en])]);
    }

    // ---- entities ------------------------------------------------------------------------------------

    public function myEntities(): JsonResponse
    {
        return response()->json(['data' => $this->entities->accountsOf($this->user())->map(fn ($e) => ['id' => $e->id, 'name_ar' => $e->name_ar, 'name_en' => $e->name_en, 'type' => $e->type, 'cr_number' => $e->cr_number] + ['usage' => $this->entities->usage($e)])]);
    }

    public function entityVouchers(Request $request): JsonResponse
    {
        $e = $this->entity($request) ?? abort(422);
        $rows = SeatVoucher::with('group.program:id,title_ar,title_en', 'employee.user:id,name,name_ar')->where('entity_account_id', $e->id)->latest()->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'status' => $v->status, 'expires_at' => $v->expires_at->toIso8601String(), 'program_ar' => $v->group?->program?->title_ar, 'program_en' => $v->group?->program?->title_en, 'group_id' => $v->group_id,
            'assigned_to' => $v->employee?->user?->displayName() ?? $v->assigned_email])->values(), 'usage' => $this->entities->usage($e)]);
    }

    public function assign(Request $request): JsonResponse
    {
        $d = $request->validate(['entity_id' => ['required', 'uuid'], 'entries' => ['required', 'array', 'min:1', 'max:500'], 'entries.*.group_id' => ['nullable', 'uuid'], 'entries.*.voucher_id' => ['nullable', 'uuid'], 'entries.*.employee_no' => ['nullable', 'string', 'max:60'], 'entries.*.email' => ['nullable', 'email', 'max:190']]);
        $e = $this->entity($request) ?? throw new BusinessRuleException('Choose the entity.', 'entity_required');

        return response()->json(['data' => $this->entities->assign($e, $d['entries'])]);
    }
}
