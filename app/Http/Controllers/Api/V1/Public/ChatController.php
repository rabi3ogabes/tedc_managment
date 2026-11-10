<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\Chat\ChatBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** The website chat: visitors talk to the AI assistant, and to the administration team once it takes over. */
class ChatController extends Controller
{
    private const DAILY_LIMIT = 120;

    public function __construct(private readonly ChatBot $bot) {}

    /** Opens a conversation; the secret token is returned once and kept by the visitor's browser. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'locale' => ['nullable', 'in:ar,en']]);
        $token = Str::random(40);
        $locale = $data['locale'] ?? 'ar';
        $user = auth('api')->user();

        $conversation = ChatConversation::create([
            'token_hash' => hash('sha256', $token), 'user_id' => $user?->id, 'visitor_name' => $data['name'] ?? $user?->displayName(), 'visitor_email' => $data['email'] ?? $user?->email,
            'locale' => $locale, 'user_agent' => mb_substr((string) $request->userAgent(), 0, 160), 'last_message_at' => now(),
        ]);
        $this->add($conversation, 'bot', $this->bot->greeting($locale), ['source' => 'rules', 'greeting' => true]);

        return response()->json(['data' => ['id' => $conversation->id, 'token' => $token] + $this->state($conversation->refresh())], 201);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        $conversation->update(['visitor_unread' => 0]);

        return response()->json(['data' => $this->state($conversation, $request->query('after'))]);
    }

    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        $data = $request->validate(['message' => ['required', 'string', 'max:'.ChatBot::MAX_LENGTH]]);
        abort_if($conversation->status === 'closed', 423, __('messages.chat.closed'));
        abort_if($conversation->messages()->where('sender', 'visitor')->where('created_at', '>=', now()->subDay())->count() >= self::DAILY_LIMIT, 429);

        $message = trim(strip_tags($data['message']));
        $this->add($conversation, 'visitor', $message);
        $conversation->increment('admin_unread');

        // While an administrator handles the conversation the bot stays silent; otherwise the assistant answers.
        if ($conversation->mode === 'bot') {
            $reply = $this->bot->reply($message, $conversation->locale);
            $this->add($conversation, 'bot', $reply['answer'], ['program_codes' => $reply['program_codes'], 'refused' => $reply['refused'], 'source' => $reply['source'], 'offer_human' => $reply['offer_human']]);
        }

        return response()->json(['data' => $this->state($conversation->refresh(), $request->input('after'))]);
    }

    /** "Talk to a team member": pauses the bot and flags the conversation for the administration team. */
    public function human(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorise($request, $conversation);
        if (! $conversation->needs_human) {
            $conversation->update(['needs_human' => true, 'mode' => 'human', 'admin_unread' => max(1, $conversation->admin_unread)]);
            $this->add($conversation, 'bot', $conversation->locale === 'en'
                ? 'I have passed your request to the team. A colleague will reply here as soon as possible — you can keep writing in the meantime.'
                : 'تم تحويل طلبك إلى فريق المركز. سيرد عليك أحد الزملاء هنا في أقرب وقت، ويمكنك متابعة الكتابة في الأثناء.', ['source' => 'rules', 'handoff' => true]);
        }

        return response()->json(['data' => $this->state($conversation->refresh(), $request->input('after'))]);
    }

    private function authorise(Request $request, ChatConversation $conversation): void
    {
        abort_unless($conversation->owns($request->input('token', $request->query('token'))), 404);
    }

    private function add(ChatConversation $c, string $sender, string $body, array $meta = []): ChatMessage
    {
        $m = ChatMessage::create(['conversation_id' => $c->id, 'sender' => $sender, 'body' => $body, 'meta' => $meta ?: null, 'created_at' => now()]);
        $c->forceFill(['last_message_at' => now(), 'messages_count' => $c->messages_count + 1])->save();

        return $m;
    }

    /** @return array<string, mixed> */
    private function state(ChatConversation $c, ?string $after = null): array
    {
        // The client sends the time of the last message it has; the same second is included and de-duplicated by id there.
        $since = $after ? rescue(fn () => Carbon::parse($after), null, false) : null;
        $messages = $c->messages()->with('admin:id,name,name_ar')->when($since, fn ($q) => $q->where('created_at', '>=', $since->copy()->subSecond()))->get();

        return [
            'mode' => $c->mode, 'status' => $c->status, 'needs_human' => $c->needs_human,
            'messages' => $messages->map(fn (ChatMessage $m) => [
                'id' => $m->id, 'sender' => $m->sender, 'body' => $m->body, 'created_at' => $m->created_at->toIso8601String(),
                'program_codes' => $m->meta['program_codes'] ?? [], 'offer_human' => (bool) ($m->meta['offer_human'] ?? false), 'by' => $m->admin?->displayName(),
            ])->values(),
        ];
    }
}
