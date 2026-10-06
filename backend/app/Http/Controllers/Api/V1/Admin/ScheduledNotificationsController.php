<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ScheduledNotification;
use App\Services\Notifications\AudienceResolver;
use App\Services\Notifications\DeliveryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Notifications written now and sent later (once or repeating), and the audience builder's live count. */
class ScheduledNotificationsController extends Controller
{
    public function __construct(private readonly AudienceResolver $audiences, private readonly DeliveryPolicy $policy) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(ScheduledNotification::with('creator:id,name,name_ar')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case when status = 'scheduled' then 0 else 1 end")->orderBy('send_at')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertFuture($data);

        return response()->json(['data' => ScheduledNotification::create($data + ['status' => 'scheduled', 'created_by' => $this->user()->id])], 201);
    }

    public function update(Request $request, ScheduledNotification $scheduled): JsonResponse
    {
        $this->assertEditable($scheduled);
        $data = $this->validated($request, true);
        $this->assertFuture($data);
        $scheduled->update($data);

        return response()->json(['data' => $scheduled]);
    }

    /** Cancels a notification that has not gone out (or stops a repeating one). */
    public function destroy(ScheduledNotification $scheduled): JsonResponse
    {
        if ($scheduled->status === 'sending') {
            throw new BusinessRuleException('It is being sent right now.', 'being_sent');
        }
        $scheduled->update(['status' => 'cancelled']);

        return response()->json(['data' => $scheduled]);
    }

    /** How many people an audience reaches, with a few names — and whether a send time falls inside the quiet hours of the channels. */
    public function previewAudience(Request $request): JsonResponse
    {
        $d = $request->validate(['audience' => ['nullable', 'array'], 'send_at' => ['nullable', 'date'], 'channels' => ['nullable', 'array']]);

        return response()->json(['data' => $this->audiences->preview($d['audience'] ?? [], $this->user())]);
    }

    private function assertEditable(ScheduledNotification $s): void
    {
        if (! in_array($s->status, ['scheduled', 'failed'], true)) {
            throw new BusinessRuleException('Only a notification that has not been sent can be changed.', 'not_editable');
        }
    }

    private function assertFuture(array $data): void
    {
        if (isset($data['send_at']) && now()->subMinute()->gt($data['send_at'])) {
            throw new BusinessRuleException('Choose a time in the future.', 'send_at_past');
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title_ar' => [$req, 'string', 'max:255'], 'title_en' => [$req, 'string', 'max:255'],
            'body_ar' => ['nullable', 'string', 'max:2000'], 'body_en' => ['nullable', 'string', 'max:2000'],
            'template_id' => ['nullable', 'uuid', 'exists:notification_templates,id'],
            'channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(['push', 'email', 'sms'])],
            'audience' => ['nullable', 'array'],
            'send_at' => [$req, 'date'],
            'repeat' => ['sometimes', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'repeat_until' => ['nullable', 'date', 'after_or_equal:send_at'],
        ]);
    }
}
