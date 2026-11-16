<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentImport;
use App\Models\ContentPackage;
use App\Models\Program;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\Content\CommonCartridgeService;
use App\Services\Content\QtiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** QTI import / export for question banks and Common Cartridge preview / import. */
class ContentImportController extends Controller
{
    public function __construct(private readonly QtiService $qti, private readonly CommonCartridgeService $cc) {}

    public function qtiImport(Request $request, QuestionBank $bank): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:zip,xml,txt']]);
        $r = $this->qti->import($bank, (string) file_get_contents($request->file('file')->getRealPath()), $this->user());
        ContentImport::create(['kind' => 'qti', 'log' => ['bank' => $bank->id, 'created' => $r['created'], 'errors' => $r['errors'], 'unsupported' => $r['unsupported']], 'created_by' => $this->user()->id]);

        return response()->json(['data' => ['created' => $r['created'], 'errors' => $r['errors'], 'unsupported' => $r['unsupported']]], 201);
    }

    public function qtiExport(Request $request, QuestionBank $bank): Response
    {
        $ids = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['uuid']])['ids'] ?? null;
        $questions = Question::where('bank_id', $bank->id)->where('status', 'active')->when($ids, fn ($q, $i) => $q->whereIn('id', $i))->get()->all();
        $r = $this->qti->export($questions);

        return response($r['zip'], 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="questions-qti21.zip"', 'X-Skipped' => (string) count($r['skipped'])]);
    }

    public function ccPreview(ContentPackage $package): JsonResponse
    {
        return response()->json(['data' => ['title' => $package->title, 'version' => $package->version, 'tree' => $this->cc->preview($package)]]);
    }

    public function ccImport(Request $request, ContentPackage $package): JsonResponse
    {
        $d = $request->validate(['program_id' => ['required', 'uuid', 'exists:programs,id'], 'item_ids' => ['nullable', 'array'], 'item_ids.*' => ['string', 'max:200']]);

        return response()->json(['data' => $this->cc->import($package, Program::findOrFail($d['program_id']), $d['item_ids'] ?? null, $this->user())], 201);
    }

    public function imports(): JsonResponse
    {
        return response()->json(['data' => ContentImport::latest()->limit(50)->get()->all()]);
    }
}
