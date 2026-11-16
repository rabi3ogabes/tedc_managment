<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationAudit;
use App\Payments\MoeEpayCertification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Settings → Payments → "Run the certification plan against the mock": the plan for the Ministry's gateway, on pretend orders that are rolled back. */
class CertificationController extends Controller
{
    public function __invoke(Request $request, MoeEpayCertification $certification): JsonResponse
    {
        abort_if(app()->isProduction(), 403, 'The certification plan works on pretend orders and does not run in production.');
        $result = $certification->run();
        IntegrationAudit::record('moe_epay', 'payments.certification.run', $request->user()->id, ['ok' => $result['ok'], 'passed' => count(array_filter($result['cases'], fn ($c) => $c['ok'])), 'total' => count($result['cases'])], null, $request->ip());

        return response()->json(['data' => $result]);
    }
}
