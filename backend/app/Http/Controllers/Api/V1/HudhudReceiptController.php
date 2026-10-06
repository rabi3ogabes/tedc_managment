<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Services\Channels\ChannelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hudhud's delivery receipts. The body is signed with the shared secret (HMAC-SHA256 of the raw body in X-Hudhud-Signature, hex);
 * a receipt for a message we sent moves that row to delivered or failed.
 */
class HudhudReceiptController extends Controller
{
    public function __invoke(Request $request, ChannelSettings $settings): JsonResponse
    {
        $secret = (string) ($settings->secrets('sms')['hudhud_receipt_secret'] ?? '');
        $signature = (string) $request->header('X-Hudhud-Signature', '');
        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($signature))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $receipts = $request->input('receipts') ?? [$request->all()];
        $updated = 0;
        foreach ((array) $receipts as $r) {
            $id = (string) ($r['message_id'] ?? '');
            $status = strtolower((string) ($r['status'] ?? ''));
            if ($id === '' || ! in_array($status, ['delivered', 'failed', 'undelivered', 'rejected', 'expired'], true)) {
                continue;
            }
            $row = NotificationDelivery::where('provider_message_id', $id)->first();
            if (! $row) {
                continue;
            }
            $ok = $status === 'delivered';
            $row->update($ok
                ? ['status' => 'delivered', 'delivered_at' => now(), 'failed_reason' => null]
                : ['status' => 'failed', 'failed_reason' => mb_substr((string) ($r['reason'] ?? $status), 0, 280), 'reason' => mb_substr((string) ($r['reason'] ?? $status), 0, 280)]);
            $updated++;
        }

        return response()->json(['data' => ['updated' => $updated]]);
    }
}
