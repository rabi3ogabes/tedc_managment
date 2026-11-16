<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiModelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Settings → AI models: connect providers (OpenRouter and others), add models, and choose which one does each kind of work. */
class AiModelsController extends Controller
{
    public function __construct(private readonly AiModelSettings $settings, private readonly AiGateway $gateway) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    public function saveConnection(Request $request, ?string $id = null): JsonResponse
    {
        abort_if($id !== null && ! $this->settings->connection($id), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'driver' => ['required', Rule::in(array_keys(AiModelSettings::DRIVERS))],
            'base_url' => ['nullable', 'required_if:driver,custom', 'url:https', 'max:300'], 'enabled' => ['sometimes', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:500'], 'clear_key' => ['sometimes', 'boolean'],
        ]);
        $row = $this->settings->saveConnection($data, $id, $request->user());

        return response()->json(['data' => $this->settings->forAdmin(), 'id' => $row['id']], $id ? 200 : 201);
    }

    public function deleteConnection(Request $request, string $id): JsonResponse
    {
        abort_unless($this->settings->connection($id), 404);
        $this->settings->deleteConnection($id, $request->user());

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /** What the provider offers, for the picker (OpenRouter lists hundreds of models). */
    public function catalog(string $id): JsonResponse
    {
        abort_unless($this->settings->connection($id), 404);
        try {
            return response()->json(['data' => $this->gateway->catalog($id)]);
        } catch (Throwable $e) {
            return response()->json(['message' => AiGateway::explain($e), 'code' => 'catalog_failed'], 422);
        }
    }

    public function saveModel(Request $request, ?string $id = null): JsonResponse
    {
        abort_if($id !== null && ! $this->settings->model($id), 404);
        $data = $request->validate([
            'connection_id' => ['required', 'string'], 'model' => ['required', 'string', 'max:200'], 'label' => ['nullable', 'string', 'max:100'],
            'tasks' => ['required', 'array', 'min:1'], 'tasks.*' => [Rule::in(AiModelSettings::TASKS)], 'enabled' => ['sometimes', 'boolean'],
            'params' => ['sometimes', 'array'], 'params.temperature' => ['nullable', 'numeric', 'between:0,2'], 'params.max_tokens' => ['nullable', 'integer', 'between:16,200000'], 'params.voice' => ['nullable', 'string', 'max:40'],
        ]);
        abort_unless($this->settings->connection($data['connection_id']), 422, __('messages.ai_models.no_connection'));
        $row = $this->settings->saveModel($data, $id, $request->user());

        return response()->json(['data' => $this->settings->forAdmin(), 'id' => $row['id']], $id ? 200 : 201);
    }

    public function deleteModel(Request $request, string $id): JsonResponse
    {
        abort_unless($this->settings->model($id), 404);
        $this->settings->deleteModel($id, $request->user());

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    public function assign(Request $request): JsonResponse
    {
        $rules = [];
        foreach (AiModelSettings::TASKS as $task) {
            $rules[$task] = ['sometimes', 'nullable', 'string'];
        }
        $this->settings->assign($request->validate($rules), $request->user());

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        abort_unless($this->settings->model($id), 404);
        $task = $request->validate(['task' => ['nullable', Rule::in(AiModelSettings::TASKS)]])['task'] ?? null;

        return response()->json(['data' => $this->gateway->test($id, $task) + ['models' => $this->settings->forAdmin()['models']]]);
    }
}
