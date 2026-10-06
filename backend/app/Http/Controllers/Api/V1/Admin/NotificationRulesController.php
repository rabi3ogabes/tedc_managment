<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Services\Notifications\AudienceResolver;
use App\Services\Notifications\DeliveryPolicy;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Rules per event, category, program and audience: switch an event off, narrow its channels, delay it, keep it inside allowed hours. */
class NotificationRulesController extends Controller
{
    public function index(): JsonResponse
    {
        $events = collect(NotificationCatalog::events())->map(fn ($e, $k) => ['event' => $k, 'group' => $e['group'], 'name' => ['ar' => $e['name_ar'], 'en' => $e['name_en']], 'mandatory' => in_array($k, DeliveryPolicy::MANDATORY, true)])->values();

        return response()->json([
            'data' => NotificationRule::with([])->orderBy('event')->orderByDesc('priority')->get(),
            'meta' => ['events' => $events, 'audience_keys' => AudienceResolver::KEYS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => NotificationRule::create($this->validated($request))], 201);
    }

    public function update(Request $request, NotificationRule $rule): JsonResponse
    {
        $rule->update($this->validated($request, true));

        return response()->json(['data' => $rule]);
    }

    public function destroy(NotificationRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $events = array_merge(array_keys(NotificationCatalog::events()), ['*', 'announcement']);

        return $request->validate([
            'event' => [$req, Rule::in($events)],
            'name' => ['nullable', 'string', 'max:160'],
            'audience_filter' => ['nullable', 'array'],
            'audience_filter.roles' => ['sometimes', 'array'], 'audience_filter.job_titles' => ['sometimes', 'array'], 'audience_filter.schools' => ['sometimes', 'array'],
            'audience_filter.school_groups' => ['sometimes', 'array'], 'audience_filter.programs' => ['sometimes', 'array'],
            'channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(['push', 'email', 'sms'])],
            'enabled' => ['sometimes', 'boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.days' => ['sometimes', 'array'], 'quiet_hours.days.*' => ['integer', 'between:0,6'],
            'quiet_hours.from' => ['sometimes', 'date_format:H:i'], 'quiet_hours.to' => ['sometimes', 'date_format:H:i'],
            'quiet_hours.timezone' => ['sometimes', 'timezone:all'],
            'quiet_hours.channels' => ['sometimes', 'array'], 'quiet_hours.channels.*' => [Rule::in(['push', 'email', 'sms'])],
            'delay_minutes' => ['sometimes', 'integer', 'between:0,10080'],
            'program_id' => ['nullable', 'uuid', 'exists:programs,id'],
            'category_id' => ['nullable', 'uuid', 'exists:program_categories,id'],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
        ]);
    }
}
