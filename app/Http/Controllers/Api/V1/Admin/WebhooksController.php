<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\EventBus;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Webhook subscriptions (who receives which events), the delivery log with replay, and the outbox. */
class WebhooksController extends Controller
{
    public function __construct(private readonly EventBus $bus) {}

    public function index(): JsonResponse
    {
        $subs = WebhookSubscription::orderBy('name')->get()->map(fn ($s) => $this->present($s));

        return response()->json(['data' => $subs, 'meta' => ['events' => EventBus::EVENTS]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $secret = Str::random(40);
        $s = WebhookSubscription::create($d + ['secret' => Crypt::encryptString($secret), 'created_by' => $this->user()->id]);

        return response()->json(['data' => $this->present($s) + ['secret' => $secret]], 201);   // the secret is shown once
    }

    public function update(Request $request, WebhookSubscription $subscription): JsonResponse
    {
        $subscription->update($this->validated($request, true));

        return response()->json(['data' => $this->present($subscription)]);
    }

    public function rotate(WebhookSubscription $subscription): JsonResponse
    {
        $secret = Str::random(40);
        $subscription->update(['secret' => Crypt::encryptString($secret)]);

        return response()->json(['data' => ['secret' => $secret]]);
    }

    public function destroy(WebhookSubscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json(null, 204);
    }

    public function deliveries(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['pending', 'delivered', 'dead'])], 'subscription_id' => ['nullable', 'uuid']]);

        return response()->json(WebhookDelivery::with(['subscription:id,name,url', 'event:id,type,occurred_at'])->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['subscription_id'] ?? null, fn ($q, $v) => $q->where('subscription_id', $v))->latest()->paginate($this->perPage($request)));
    }

    public function replay(WebhookDelivery $delivery): JsonResponse
    {
        return response()->json(['data' => $this->bus->replay($delivery)]);
    }

    /** Sends a harmless test event to one subscription. */
    public function test(WebhookSubscription $subscription): JsonResponse
    {
        $event = OutboxEvent::create(['type' => 'webhook.test', 'payload' => ['message' => 'Hello from TEDC'], 'correlation_id' => (string) Str::uuid(), 'occurred_at' => now()]);
        $d = WebhookDelivery::create(['subscription_id' => $subscription->id, 'outbox_event_id' => $event->id, 'status' => 'pending', 'next_attempt_at' => now()]);
        $this->bus->deliverDue();

        return response()->json(['data' => $d->refresh()]);
    }

    private function present(WebhookSubscription $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'url' => $s->url, 'events' => $s->events, 'enabled' => $s->enabled,
            'pending' => WebhookDelivery::where('subscription_id', $s->id)->where('status', 'pending')->count(), 'dead' => WebhookDelivery::where('subscription_id', $s->id)->where('status', 'dead')->count()];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate(['name' => [$req, 'string', 'max:120'], 'url' => [$req, 'url:https', 'max:500'], 'events' => [$req, 'array', 'min:1'], 'events.*' => ['string', Rule::in(array_merge(['*'], EventBus::EVENTS))], 'enabled' => ['sometimes', 'boolean']]);
    }
}
