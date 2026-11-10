<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Learning\EKits\EKitBuilder;
use App\Learning\EKits\EKitSource;
use App\Learning\EKits\ScormExporter;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The interactive e-kits of resources/ekits: publish them as e-courses and download them as SCORM 2004 packages. */
class EKitController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = array_map(function (array $k) {
            $p = Program::where('code', $k['code'])->first();

            return [
                'code' => $k['code'], 'title_ar' => $k['title_ar'], 'title_en' => $k['title_en'], 'hours' => $k['hours'] ?? null, 'note' => $k['status_note'] ?? null,
                'chapters' => count($k['chapters']), 'check_questions' => array_sum(array_map(fn ($c) => count($c['check']), $k['chapters'])), 'final_questions' => count($k['final']),
                'published' => (bool) ($p && $p->courseModules()->exists()), 'program_id' => $p?->id,
            ];
        }, EKitSource::all());

        return response()->json(['data' => $rows]);
    }

    public function build(Request $request, string $code, EKitBuilder $builder): JsonResponse
    {
        $kit = $this->kit($code);
        $r = $builder->build($kit, (bool) $request->boolean('rebuild'));

        return response()->json(['data' => ['created' => $r['created'], 'program_id' => $r['program']->id]], $r['created'] ? 201 : 200);
    }

    public function scorm(Request $request, string $code, ScormExporter $exporter): Response
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'ar';

        return response($exporter->export($this->kit($code), $lang), 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$code.'-'.$lang.'-scorm2004.zip"']);
    }

    private function kit(string $code): array
    {
        return EKitSource::find($code) ?? throw new BusinessRuleException(__('messages.not_found'), 'ekit_not_found');
    }
}
