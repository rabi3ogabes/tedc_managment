<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\CheckoutService;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\GatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Where the gateway talks to the platform: the signed callback (the only thing that moves money into "paid"), the buyer's return, and the training gateway's pretend page. */
class GatewayController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout, private readonly GatewayFactory $gateways) {}

    /** POST /payments/callback/{gateway} — the raw body is what is signed. */
    public function callback(Request $request, string $gateway): JsonResponse
    {
        $r = $this->checkout->callback($gateway, $request->getContent(), $request->header('X-Signature'));
        if ($r['outcome'] === 'rejected') {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return response()->json(['ok' => true, 'outcome' => $r['outcome']]);
    }

    /** GET /payments/return?order=ORD-… — the buyer is back; show what the server knows (never trusted as proof of payment). */
    public function return(Request $request): JsonResponse
    {
        $d = $request->validate(['order' => ['required', 'string', 'max:30']]);
        $o = Order::where('number', $d['order'])->firstOrFail();

        return response()->json(['data' => ['number' => $o->number, 'status' => $o->status, 'total' => $o->total, 'currency' => $o->currency]]);
    }

    /** The training gateway's hosted page calls this to finish: pay or fail. Never available in production. */
    public function fakeComplete(Request $request, string $ref): JsonResponse
    {
        abort_if(app()->environment('production'), 404);
        $d = $request->validate(['outcome' => ['required', 'in:captured,failed,cancelled']]);
        $payment = Payment::where('gateway_ref', $ref)->where('gateway', 'fake')->firstOrFail();
        $order = Order::findOrFail($payment->order_id);
        $gw = $this->gateways->make();
        abort_unless($gw instanceof FakeGateway, 404);
        $body = json_encode(['event_id' => 'evt-'.Str::random(12), 'ref' => $ref, 'status' => $d['outcome'], 'amount' => (float) $payment->amount, 'order_number' => $order->number]);
        $r = $this->checkout->callback('fake', $body, $gw->sign($body));

        return response()->json(['data' => ['outcome' => $r['outcome'], 'order' => $order->refresh()->number, 'status' => $order->status]]);
    }
}
