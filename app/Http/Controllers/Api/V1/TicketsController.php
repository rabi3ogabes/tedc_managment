<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Ministry\SaeedTickets;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Report a problem": any signed-in person raises a ticket (with the page and context captured); administrators see them all. */
class TicketsController extends Controller
{
    public function __construct(private readonly SaeedTickets $tickets) {}

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => SupportTicket::where('user_id', $this->user()->id)->latest()->limit(50)->get()->map(fn ($t) => $this->present($t))]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'category' => ['required', Rule::in(SaeedTickets::CATEGORIES)], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'subject' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:5000'],
            'page_url' => ['nullable', 'string', 'max:500'], 'context' => ['nullable', 'array'], 'context.app_version' => ['nullable', 'string', 'max:40'], 'context.platform' => ['nullable', 'string', 'max:40'], 'context.browser' => ['nullable', 'string', 'max:200'], 'context.viewport' => ['nullable', 'string', 'max:20'],
            'screenshot' => ['nullable', 'file', 'max:5120', 'mimes:png,jpg,jpeg,webp'],
        ]);
        $ticket = $this->tickets->create($this->user(), $d, $request->file('screenshot'));

        return response()->json(['data' => $this->present($ticket)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'string', 'max:12'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(SupportTicket::with('user:id,name,name_ar,email')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(subject) like ?', [$like])->orWhereRaw('lower(coalesce(saaed_ticket_no, \'\')) like ?', [$like]));
            })->latest()->paginate($this->perPage($request)));
    }

    public function resend(SupportTicket $ticket): JsonResponse
    {
        $ticket->update(['attempts' => 0, 'status' => 'queued']);
        $this->tickets->send($ticket);

        return response()->json(['data' => $this->present($ticket->refresh())]);
    }

    private function present(SupportTicket $t): array
    {
        return ['id' => $t->id, 'subject' => $t->subject, 'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status, 'ticket_no' => $t->saaed_ticket_no, 'saaed_status' => $t->saaed_status, 'created_at' => $t->created_at?->toIso8601String(), 'page_url' => $t->page_url];
    }
}
