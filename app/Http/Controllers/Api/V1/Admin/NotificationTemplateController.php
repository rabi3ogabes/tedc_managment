<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\Program;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Settings → Notification templates: switch each automatic notification on/off, rewrite it, or create your own. */
class NotificationTemplateController extends Controller
{
    public function __construct(private readonly NotificationTemplates $templates) {}

    public function index(): JsonResponse
    {
        $this->templates->ensure();
        $catalog = NotificationCatalog::events();

        return response()->json(['data' => [
            'groups' => NotificationCatalog::groups(),
            'variables' => NotificationCatalog::VARIABLES,
            'templates' => NotificationTemplate::orderBy('is_system', 'desc')->orderBy('name_ar')->get()->map(fn (NotificationTemplate $t) => $this->row($t, $catalog))->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $template = NotificationTemplate::create($data + [
            'event' => 'custom.'.Str::lower(Str::random(8)), 'is_system' => false, 'updated_by' => $request->user()->id,
        ]);
        $this->templates->flush();

        return response()->json(['data' => $this->row($template, NotificationCatalog::events())], 201);
    }

    public function update(Request $request, NotificationTemplate $template): JsonResponse
    {
        $template->update($this->validated($request, false) + ['updated_by' => $request->user()->id]);
        $this->templates->flush();

        return response()->json(['data' => $this->row($template->refresh(), NotificationCatalog::events())]);
    }

    public function destroy(NotificationTemplate $template): JsonResponse
    {
        abort_if($template->is_system, 422, __('A built-in template cannot be deleted; switch it off instead.'));
        $template->delete();
        $this->templates->flush();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Restores the built-in wording of a system template. */
    public function reset(NotificationTemplate $template): JsonResponse
    {
        $default = NotificationCatalog::events()[$template->event] ?? null;
        abort_unless($default, 422);
        $template->update(collect($default)->only(['title_ar', 'title_en', 'body_ar', 'body_en'])->all());
        $this->templates->flush();

        return response()->json(['data' => $this->row($template->refresh(), NotificationCatalog::events())]);
    }

    /** Renders wording with the data of a real program (or placeholders) so the editor can show a live preview. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['title_ar' => ['nullable', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'], 'body_ar' => ['nullable', 'string', 'max:1000'], 'body_en' => ['nullable', 'string', 'max:1000'], 'program_id' => ['nullable', 'uuid']]);
        $program = isset($data['program_id']) ? Program::find($data['program_id']) : Program::orderByDesc('start_date')->first();
        $vars = $program ? $this->templates->programVariables($program) : ['ar' => [], 'en' => []];
        $sample = ['name' => ['ar' => 'مريم الكواري', 'en' => 'Maryam Al-Kuwari']];
        $r = fn (string $k, string $l) => $this->templates->render((string) ($data["{$k}_{$l}"] ?? ''), array_merge($vars[$l], ['name' => $sample['name'][$l]]));

        return response()->json(['data' => ['title_ar' => $r('title', 'ar'), 'title_en' => $r('title', 'en'), 'body_ar' => $r('body', 'ar'), 'body_en' => $r('body', 'en')]]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $rule = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name_ar' => [$rule, 'string', 'max:120'], 'name_en' => [$rule, 'string', 'max:120'],
            'title_ar' => [$rule, 'string', 'max:200'], 'title_en' => [$rule, 'string', 'max:200'],
            'body_ar' => ['sometimes', 'nullable', 'string', 'max:1000'], 'body_en' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'enabled' => ['sometimes', 'boolean'], 'push' => ['sometimes', 'boolean'], 'email' => ['sometimes', 'boolean'], 'sms' => ['sometimes', 'boolean'],
        ]);
    }

    private function row(NotificationTemplate $t, array $catalog): array
    {
        return $t->only(['id', 'event', 'is_system', 'name_ar', 'name_en', 'title_ar', 'title_en', 'body_ar', 'body_en', 'enabled', 'push', 'email', 'sms']) + [
            'group' => $t->is_system ? ($catalog[$t->event]['group'] ?? 'custom') : 'custom',
            'customised' => $t->is_system && $this->templates->isCustomised($t->toArray()),
            'default' => $t->is_system && isset($catalog[$t->event]) ? collect($catalog[$t->event])->only(['title_ar', 'title_en', 'body_ar', 'body_en']) : null,
        ];
    }
}
