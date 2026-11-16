<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Settings → RFP Compliance: coverage of the RFP's requirements, read from the generated status file. */
class RfpStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => json_decode((string) file_get_contents(resource_path('rfp/status.json')), true)]);
    }
}
