<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Services\Reports\ReportRunService;
use Illuminate\Http\Request;

/** The link e-mailed with a scheduled report: signed and expiring, so it works without signing in. */
class ReportSignedDownloadController extends Controller
{
    public function __invoke(Request $request, ReportRun $run, ReportRunService $runs)
    {
        $format = (string) $request->query('format');
        abort_unless(in_array($format, ReportRunService::FORMATS, true), 404);
        $f = $runs->file($run, $format, null);

        return response($f['content'], 200, ['Content-Type' => $f['mime'], 'Content-Disposition' => "attachment; filename=\"{$f['filename']}\""]);
    }
}
