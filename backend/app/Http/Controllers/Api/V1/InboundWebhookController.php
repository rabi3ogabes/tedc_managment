<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\InboundRouter;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\WebhookSignature;
use App\Models\InboundEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Messages sent to us by the Ministry systems (HR changes, licence updates, ticket updates). Each is signed with the system's shared secret
 * (X-TEDC-Signature over "timestamp.body", X-TEDC-Timestamp) and carries an idempotency key (Idempotency-Key header or `id`): a repeat is acknowledged, not applied twice.
 */
class InboundWebhookController extends Controller
{
    public function __invoke(Request $request, string $key, IntegrationManager $hub, InboundRouter $router): JsonResponse
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]), 404);
        $secret = (string) ($hub->settings($key)['inbound_secret'] ?? '');
        $body = $request->getContent();
        if (! WebhookSignature::verify($secret, $request->header('X-TEDC-Timestamp'), $request->header('X-TEDC-Signature'), $body)) {
            $hub->logInbound($key, 'inbound', 'error', [], 'bad_signature');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON.'], 422);
        }
        $idem = (string) ($request->header('Idempotency-Key') ?: ($payload['id'] ?? ''));
        if ($idem === '') {
            return response()->json(['message' => 'An idempotency key is required.'], 422);
        }
        if (InboundEvent::where(['source' => $key, 'idempotency_key' => $idem])->exists()) {
            return response()->json(['data' => ['status' => 'duplicate']], 200);
        }

        try {
            $result = $router->handle($key, $payload);
            InboundEvent::create(['source' => $key, 'idempotency_key' => mb_substr($idem, 0, 120), 'payload' => ['type' => $payload['type'] ?? null], 'result' => $result, 'processed_at' => now()]);
            $hub->logInbound($key, (string) ($payload['type'] ?? 'message'), 'ok', ['type' => $payload['type'] ?? null]);
        } catch (Throwable $e) {
            $hub->logInbound($key, (string) ($payload['type'] ?? 'message'), 'error', ['type' => $payload['type'] ?? null], mb_substr($e->getMessage(), 0, 300));

            return response()->json(['message' => 'The message could not be applied.'], 500);   // not stored, so the sender's retry is processed
        }

        return response()->json(['data' => ['status' => $result]]);
    }
}
