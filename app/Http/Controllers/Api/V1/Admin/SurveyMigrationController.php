<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Surveys\SurveyMigrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Survey Studio v2, Gate 5: the migration panel (what a run would do, run it, roll a batch back). */
class SurveyMigrationController extends Controller
{
    public function __construct(private readonly SurveyMigrator $migrator) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->migrator->plan()]);
    }

    public function run(Request $request): JsonResponse
    {
        $d = $request->validate(['only' => ['sometimes', Rule::in(['all', 'needs', 'evaluations'])]]);

        return response()->json(['data' => $this->migrator->run($d['only'] ?? 'all', $this->user())]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $d = $request->validate(['batch' => ['required', 'string', 'max:40'], 'force' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->migrator->rollback($d['batch'], (bool) ($d['force'] ?? false), $this->user())]);
    }
}
