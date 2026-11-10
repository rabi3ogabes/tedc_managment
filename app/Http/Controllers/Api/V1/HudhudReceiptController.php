<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\JsonPath;
use App\Integrations\Contracts\Signature;
use App\Models\NotificationDelivery;
use App\Services\Channels\ChannelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gateway's delivery receipts. How they are signed (header, algorithm, what is signed), where the list of receipts is, and how the
 * gateway's status codes become delivered or failed (the mapping's status table) are in the gateway's contract; the shipped one is
 * Hudhud's assumption (HMAC-SHA256 of the raw body in X-Hudhud-Signature, hex). A receipt for a message we sent moves that row to
 * delivered or failed; a repeat changes nothing.
 */
class HudhudReceiptController extends Controller
{
    public function __invoke(Request $request, ChannelSettings $settings, ContractStore $store): JsonResponse
    {
        $contract = $store->active('hudhud');
        $inbound = $contract->inbound();
        $spec = (array) ($inbound['signature'] ?? []);
        $stamped = ! empty($spec['timestamp_header']);
        if ($spec === [] || ! Signature::verify($spec, (string) ($settings->contractSettings()[$spec['secret_setting'] ?? 'receipt_secret'] ?? ''), $request->header((string) ($spec['header'] ?? '')),
            $stamped ? (string) $request->header((string) $spec['timestamp_header']) : '', $request->getContent(), $stamped ? (int) ($spec['tolerance'] ?? 300) : null)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $json = json_decode($request->getContent(), true);
        $found = isset($inbound['receipt']['records']) ? JsonPath::get($json, (string) $inbound['receipt']['records']) : null;
        $records = is_array($found) && array_is_list($found) ? $found : [$json];   // a list of receipts, or one receipt at the top
        $updated = 0;
        foreach ($records as $record) {
            $receipt = $contract->map('receipt', is_array($record) ? $record : []);
            $id = (string) ($receipt['message_id'] ?? '');
            $status = strtolower((string) ($receipt['status'] ?? ''));
            if ($id === '' || ! in_array($status, ['delivered', 'failed'], true)) {
                continue;   // not one of ours to settle: still on its way, or a code the table does not know
            }
            $row = NotificationDelivery::where('provider_message_id', $id)->first();
            if (! $row || ($status === 'delivered' && $row->status === 'delivered')) {
                continue;
            }
            $reason = mb_substr((string) ($receipt['reason'] ?? $status), 0, 280);
            $row->update($status === 'delivered'
                ? ['status' => 'delivered', 'delivered_at' => now(), 'failed_reason' => null]
                : ['status' => 'failed', 'failed_reason' => $reason, 'reason' => $reason]);
            $updated++;
        }

        return response()->json(['data' => ['updated' => $updated]]);
    }
}
