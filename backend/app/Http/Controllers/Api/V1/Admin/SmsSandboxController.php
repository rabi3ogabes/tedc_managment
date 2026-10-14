<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Contracts\ContractException;
use App\Integrations\IntegrationAudit;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\SmsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Notification channels → SMS: the gateway presets, and a test message to your own phone that shows the exact request and the answer. */
class SmsSandboxController extends Controller
{
    /** The common shapes of an SMS gateway, each a whole contract and mapping to start from; Hudhud's assumption first. */
    public function presets(): JsonResponse
    {
        $order = ['hudhud', 'json_post', 'form_post', 'get_query'];
        $presets = collect($order)->map(fn ($key) => json_decode((string) @file_get_contents(resource_path("contracts/hudhud/presets/{$key}.json")), true))->filter()->values();

        return response()->json(['data' => $presets]);
    }

    /** A test message to the administrator's own number (there is no way to name another), or only the request it would send. */
    public function send(Request $request, SmsGateway $sms, ChannelSettings $settings): JsonResponse
    {
        $d = $request->validate(['text' => ['nullable', 'string', 'max:500'], 'dry' => ['nullable', 'boolean']]);
        $to = $sms->normalise($request->user()->phone);
        abort_if($to === null, 422, 'Add your own phone number to your account first: a test message goes only to the person who asks for it.');
        abort_unless(($settings->all()['sms']['driver'] ?? '') === 'hudhud', 422, 'The test shows the template driver: choose it as the SMS provider first.');
        $dry = (bool) ($d['dry'] ?? false);
        abort_unless($dry || $settings->ready('sms'), 422, 'The gateway address and credentials are not complete yet.');
        $text = trim((string) ($d['text'] ?? '')) ?: 'TEDC: رسالة تجريبية / test message';

        try {
            $result = $sms->sandbox($to, $text, $dry);
        } catch (ContractException $e) {
            return response()->json(['message' => $e->getMessage()], 422);   // the request could not even be made: no address, no credentials
        }
        IntegrationAudit::record('hudhud', 'sms.sandbox_sent', $request->user()->id, ['dry' => $dry, 'ok' => $result['ok'], 'alphabet' => $result['encoding']['alphabet'], 'segments' => $result['encoding']['segments']], null, $request->ip());

        return response()->json(['data' => ['to' => '+'.substr($to, 0, 3).'•••'.substr($to, -3)] + $result]);
    }
}
