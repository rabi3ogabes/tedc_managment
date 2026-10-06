<?php

namespace App\Integrations;

use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Real-time message-based sync: domain events are written to an outbox and delivered to each subscriber as a signed webhook
 * (HMAC-SHA256 over "timestamp.body"). Failed deliveries are retried with a growing delay, then parked as dead and can be replayed.
 * A message broker adapter (Azure Service Bus) can read the same outbox later.
 */
class EventBus
{
    /** Minutes to wait before each retry; after the last one the delivery is dead. */
    public const BACKOFF = [1, 5, 15, 60, 360];

    /** The events other systems may subscribe to. */
    public const EVENTS = ['registration.approved', 'registration.completed', 'certificate.issued', 'attendance.recorded', 'pd.approved', 'licence.updated', 'employee.synced', 'ticket.updated'];

    /** Writes the event and queues a delivery for every subscription that wants it. @param  array<string, mixed>  $payload  ids and facts, no personal data beyond what the receiver needs */
    public function emit(string $type, array $payload, ?string $correlationId = null): ?OutboxEvent
    {
        // With nobody listening there is nothing to deliver or replay, so nothing is written.
        if (! WebhookSubscription::where('enabled', true)->exists()) {
            return null;
        }
        $event = OutboxEvent::create(['type' => $type, 'payload' => $payload, 'correlation_id' => $correlationId ?? (string) Str::uuid(), 'occurred_at' => now()]);
        WebhookSubscription::where('enabled', true)->get()->filter(fn (WebhookSubscription $s) => in_array('*', $s->events, true) || in_array($type, $s->events, true))
            ->each(fn (WebhookSubscription $s) => WebhookDelivery::create(['subscription_id' => $s->id, 'outbox_event_id' => $event->id, 'status' => 'pending', 'next_attempt_at' => now()]));

        return $event;
    }

    /** Sends the deliveries that are due. @return array{delivered: int, retried: int, dead: int} */
    public function deliverDue(int $limit = 100): array
    {
        $out = ['delivered' => 0, 'retried' => 0, 'dead' => 0];
        WebhookDelivery::where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->with(['subscription', 'event'])->get()->each(function (WebhookDelivery $d) use (&$out) {
            $sub = $d->subscription;
            $event = $d->event;
            if (! $sub || ! $event || ! $sub->enabled) {
                $d->update(['status' => 'dead', 'last_error' => 'subscription_gone']);
                $out['dead']++;

                return;
            }
            $attempts = $d->attempts + 1;
            try {
                $body = json_encode(['id' => $event->id, 'type' => $event->type, 'occurred_at' => $event->occurred_at->toIso8601String(), 'correlation_id' => $event->correlation_id, 'data' => $event->payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $ts = (string) time();
                $res = Http::timeout(10)->withBody($body, 'application/json')->withHeaders([
                    'X-TEDC-Event' => $event->type, 'X-TEDC-Event-Id' => $event->id, 'X-TEDC-Delivery' => $d->id, 'X-TEDC-Timestamp' => $ts, 'X-TEDC-Signature' => 'sha256='.WebhookSignature::sign($this->secret($sub), $ts, $body),
                ])->post($sub->url);
                if ($res->successful()) {
                    $d->update(['status' => 'delivered', 'attempts' => $attempts, 'last_status' => $res->status(), 'last_error' => null, 'delivered_at' => now(), 'next_attempt_at' => null]);
                    $out['delivered']++;

                    return;
                }
                $error = 'HTTP '.$res->status();
                $status = $res->status();
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 240);
                $status = null;
            }
            if ($attempts > count(self::BACKOFF)) {
                $d->update(['status' => 'dead', 'attempts' => $attempts, 'last_status' => $status, 'last_error' => $error, 'next_attempt_at' => null]);
                $out['dead']++;
            } else {
                $d->update(['attempts' => $attempts, 'last_status' => $status, 'last_error' => $error, 'next_attempt_at' => now()->addMinutes(self::BACKOFF[$attempts - 1])]);
                $out['retried']++;
            }
        });

        return $out;
    }

    /** Puts a delivery (usually a dead one) back in the queue. */
    public function replay(WebhookDelivery $d): WebhookDelivery
    {
        $d->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null]);

        return $d;
    }

    public function secret(WebhookSubscription $s): string
    {
        return Crypt::decryptString($s->secret);
    }

    /** Old events nobody is waiting for are removed. */
    public function prune(int $days = 30): int
    {
        return OutboxEvent::where('occurred_at', '<', now()->subDays($days))->whereDoesntHave('deliveries', fn ($q) => $q->where('status', 'pending'))->delete();
    }
}
