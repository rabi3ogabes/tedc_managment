<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The administration team's chat inbox: every conversation with its full history, and manual answers. */
class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter', 'all');
        $q = ChatConversation::with(['messages' => fn ($m) => $m->reorder()->latest('created_at')->limit(1)])
            ->when($filter === 'needs_human', fn ($w) => $w->where('needs_human', true)->where('status', 'open'))
            ->when($filter === 'unread', fn ($w) => $w->where('admin_unread', '>', 0))
            ->when($filter === 'open', fn ($w) => $w->where('status', 'open'))
            ->when($filter === 'closed', fn ($w) => $w->where('status', 'closed'))
            ->when($request->query('q'), fn ($w, $v) => $w->where(fn ($x) => $x->where('visitor_name', 'like', "%{$v}%")->orWhere('visitor_email', 'like', "%{$v}%")
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', "%{$v}%"))))
            ->orderByRaw("case when needs_human = true and status = 'open' then 0 else 1 end")->orderByDesc('last_message_at');

        return response()->json(['summary' => $this->summary(), 'data' => $q->paginate(30)->through(fn (ChatConversation $c) => $this->row($c))]);
    }

    public function summary(): array
    {
        return [
            'unread' => (int) ChatConversation::where('admin_unread', '>', 0)->count(),
            'needs_human' => ChatConversation::where('needs_human', true)->where('status', 'open')->count(),
            'open' => ChatConversation::where('status', 'open')->count(), 'total' => ChatConversation::count(),
        ];
    }

    public function badge(): JsonResponse
    {
        return response()->json(['data' => $this->summary()]);
    }

    public function show(ChatConversation $conversation): JsonResponse
    {
        $conversation->update(['admin_unread' => 0]);

        return response()->json(['data' => $this->detail($conversation)]);
    }

    /** A manual answer: the administrator takes over and the bot pauses. */
    public function reply(Request $request, ChatConversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        ChatMessage::create(['conversation_id' => $conversation->id, 'sender' => 'admin', 'admin_id' => $request->user()->id, 'body' => trim(strip_tags($data['body'])), 'created_at' => now()]);
        $conversation->forceFill(['mode' => 'human', 'needs_human' => false, 'status' => 'open', 'admin_unread' => 0, 'visitor_unread' => $conversation->visitor_unread + 1, 'messages_count' => $conversation->messages_count + 1, 'last_message_at' => now()])->save();

        return response()->json(['data' => $this->detail($conversation->refresh())], 201);
    }

    public function mode(Request $request, ChatConversation $conversation): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['bot', 'human'])]]);
        $conversation->update(['mode' => $data['mode'], 'needs_human' => $data['mode'] === 'human' ? $conversation->needs_human : false]);

        return response()->json(['data' => $this->detail($conversation->refresh())]);
    }

    public function status(Request $request, ChatConversation $conversation): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        $conversation->update(['status' => $data['status'], 'needs_human' => $data['status'] === 'closed' ? false : $conversation->needs_human]);

        return response()->json(['data' => $this->detail($conversation->refresh())]);
    }

    public function export(ChatConversation $conversation): StreamedResponse
    {
        $rows = $conversation->messages()->with('admin:id,name,name_ar')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Time', 'From', 'Message']);
            foreach ($rows as $m) {
                fputcsv($out, [$m->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i'), $m->sender === 'admin' ? 'team: '.($m->admin?->displayName() ?? '') : $m->sender, $m->body]);
            }
            fclose($out);
        }, "chat-{$conversation->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function row(ChatConversation $c): array
    {
        $last = $c->messages->first();

        return [
            'id' => $c->id, 'name' => $c->visitor_name ?: null, 'email' => $c->visitor_email, 'is_member' => $c->user_id !== null, 'mode' => $c->mode, 'status' => $c->status, 'needs_human' => $c->needs_human,
            'unread' => $c->admin_unread, 'messages' => $c->messages_count, 'last_at' => $c->last_message_at?->toIso8601String(),
            'preview' => $last ? mb_substr($last->body, 0, 90) : null, 'last_sender' => $last?->sender,
        ];
    }

    private function detail(ChatConversation $c): array
    {
        return $this->row($c->loadMissing(['messages' => fn ($m) => $m->reorder()->latest('created_at')->limit(1)])) + [
            'locale' => $c->locale, 'created_at' => $c->created_at->toIso8601String(), 'device' => $c->user_agent,
            'thread' => ChatMessage::where('conversation_id', $c->id)->with('admin:id,name,name_ar')->orderBy('created_at')->get()->map(fn (ChatMessage $m) => [
                'id' => $m->id, 'sender' => $m->sender, 'body' => $m->body, 'created_at' => $m->created_at->toIso8601String(), 'by' => $m->admin?->displayName(),
                'refused' => (bool) ($m->meta['refused'] ?? false), 'source' => $m->meta['source'] ?? null, 'program_codes' => $m->meta['program_codes'] ?? [],
            ])->values(),
        ];
    }
}
