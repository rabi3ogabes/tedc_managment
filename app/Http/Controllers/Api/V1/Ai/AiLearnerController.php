<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\Adaptive\AdaptivePath;
use App\Ai\Assistant\TraineeAssistant;
use App\Ai\Feedback\SmartFeedback;
use App\Ai\Recommend\HybridRecommender;
use App\Http\Controllers\Controller;
use App\Models\AssessmentAttempt;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\Registration;
use App\Services\Assessment\AttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** What the learner sees from the AI features: "For you", smart feedback after an exam, the adaptive path, and the assistant. */
class AiLearnerController extends Controller
{
    public function __construct(private readonly HybridRecommender $recommender, private readonly SmartFeedback $feedback, private readonly AdaptivePath $path, private readonly TraineeAssistant $assistant, private readonly AttemptService $attempts) {}

    // ---- recommendations -----------------------------------------------------------------------------

    public function recommendationFeedback(Request $request, string $program): JsonResponse
    {
        abort_unless(Str::isUuid($program), 404);
        $d = $request->validate(['event' => ['required', Rule::in(['clicked', 'dismissed', 'liked', 'disliked'])], 'note' => ['nullable', 'string', 'max:200']]);
        $this->recommender->feedback($this->user(), $program, $d['event'], $d['note'] ?? null);

        return response()->json(['message' => 'ok'], 201);
    }

    // ---- smart feedback and the path -----------------------------------------------------------------

    public function smartFeedback(AssessmentAttempt $attempt): JsonResponse
    {
        $this->own($attempt->registration);
        $this->attempts->settle($attempt);
        $at = $attempt->fresh('assessment');
        $result = $this->attempts->result($at);

        return response()->json(['data' => $this->feedback->forLearner($at, $this->user(), $result)]);
    }

    public function path(Registration $registration): JsonResponse
    {
        $this->own($registration);

        return response()->json(['data' => $this->path->for($registration)]);
    }

    private function own(?Registration $r): void
    {
        $emp = Employee::where('user_id', $this->user()->id)->value('id');
        abort_unless($r && $emp && $r->employee_id === $emp, 404);
    }

    // ---- assistant -----------------------------------------------------------------------------------

    /**
     * A question to the assistant. With "Accept: text/event-stream" (or stream=1) the answer arrives as server-sent
     * events while it is written — "data: {delta}" pieces, then "event: done" with the saved message and its sources.
     * Closing the connection stops the answer; what was said so far is kept.
     */
    public function ask(Request $request): JsonResponse|StreamedResponse
    {
        $d = $request->validate(['message' => ['required', 'string', 'max:2000'], 'conversation_id' => ['nullable', 'uuid'], 'stream' => ['nullable', 'boolean']]);
        $user = $this->user();
        $c = $this->assistant->conversation($user, $d['conversation_id'] ?? null, app()->getLocale());
        if (! $request->boolean('stream') && ! str_contains((string) $request->header('Accept'), 'text/event-stream')) {
            $m = $this->assistant->ask($user, $c, $d['message']);

            return response()->json(['data' => ['conversation_id' => $c->id, 'message' => $this->present($m)]]);
        }

        return response()->stream(function () use ($user, $c, $d) {
            ignore_user_abort(true);   // notice the closed connection ourselves, and keep what was said
            $send = function (?string $event, array $payload) {
                echo ($event ? "event: {$event}\n" : '').'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                @flush();
            };
            $send('start', ['conversation_id' => $c->id]);
            try {
                $m = $this->assistant->ask($user, $c, $d['message'], fn (string $delta) => $send(null, ['delta' => $delta]), fn () => connection_aborted() === 1);
                if (connection_aborted() !== 1) {
                    $send('done', ['conversation_id' => $c->id, 'message' => $this->present($m)]);
                }
            } catch (\Throwable $e) {
                report($e);
                $send('error', ['message' => __('messages.server_error')]);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }

    public function conversations(): JsonResponse
    {
        $rows = AssistantConversation::where('user_id', $this->user()->id)->orderByDesc('updated_at')->limit(30)->get(['id', 'title', 'updated_at']);

        return response()->json(['data' => $rows]);
    }

    public function conversation(string $conversation): JsonResponse
    {
        abort_unless(Str::isUuid($conversation), 404);
        $c = AssistantConversation::where('user_id', $this->user()->id)->findOrFail($conversation);

        return response()->json(['data' => ['id' => $c->id, 'title' => $c->title, 'messages' => AssistantMessage::where('conversation_id', $c->id)->orderBy('created_at')->get()->map(fn ($m) => $this->present($m))]]);
    }

    public function messageFeedback(Request $request, string $message): JsonResponse
    {
        $m = $this->mine($message);
        $d = $request->validate(['feedback' => ['required', Rule::in(['up', 'down'])], 'reason' => ['nullable', 'string', 'max:200']]);
        $m->update(['feedback' => $d['feedback'], 'feedback_reason' => $d['reason'] ?? null]);

        return response()->json(['message' => 'ok']);
    }

    public function escalate(Request $request, string $message): JsonResponse
    {
        $m = $this->mine($message);
        $d = $request->validate(['target' => ['required', Rule::in(['trainer', 'support'])], 'program_id' => ['nullable', 'uuid']]);

        return response()->json(['data' => $this->assistant->escalate($this->user(), $m, $d['target'], $d['program_id'] ?? null)], 201);
    }

    private function mine(string $id): AssistantMessage
    {
        abort_unless(Str::isUuid($id), 404);
        $m = AssistantMessage::with('conversation')->findOrFail($id);
        abort_unless($m->conversation->user_id === $this->user()->id && $m->role === 'assistant', 404);

        return $m;
    }

    /** @return array<string, mixed> */
    private function present(AssistantMessage $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'citations' => $m->citations ?? [], 'meta' => $m->meta ? ['source' => $m->meta['source'] ?? null, 'refused' => $m->meta['refused'] ?? false, 'confident' => $m->meta['confident'] ?? true, 'escalate' => $m->meta['escalate'] ?? null, 'escalated' => $m->meta['escalated'] ?? null, 'cancelled' => (bool) ($m->meta['cancelled'] ?? false)] : null,
            'feedback' => $m->feedback, 'created_at' => $m->created_at?->toIso8601String()];
    }
}
