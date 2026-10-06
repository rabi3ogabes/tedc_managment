<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\Comment;
use App\Models\Poll;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\Space;
use App\Models\User;
use App\Services\FeatureSettings;
use App\Social\PostService;
use App\Social\SpaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The conversation inside a space: posts, comments, reactions, polls, accepted answers, reports and the moderators' queue. */
class PostController extends Controller
{
    public function __construct(private readonly PostService $posts, private readonly SpaceService $spaces, private readonly FeatureSettings $features) {}

    private function gate(Space $space): void
    {
        abort_unless($this->features->enabled($space->type === 'community' ? 'plc' : 'forums'), 403);
    }

    private function readable(Space $space): void
    {
        $this->gate($space);
        abort_unless($this->spaces->canRead($space, $this->user()), 403);
    }

    public function index(Request $request, Space $space): JsonResponse
    {
        $this->readable($space);
        $user = $this->user();
        $mod = $this->spaces->canModerate($space, $user);
        $q = Post::with('author:id,name,name_ar', 'poll')->where('space_id', $space->id)
            ->where(fn ($w) => $w->where('status', 'published')->when($mod, fn ($x) => $x->orWhere('status', 'hidden'))->orWhere(fn ($x) => $x->where('status', 'hidden')->where('author_id', $user->id)))
            ->orderByDesc('is_pinned')->latest();
        if (in_array($request->query('kind'), PostService::KINDS, true)) {
            $q->where('kind', $request->query('kind'));
        }
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%'.mb_strtolower($s).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(coalesce(title, \'\')) like ?', [$like])->orWhereRaw('lower(body) like ?', [$like]));
        }
        $page = $q->paginate($this->perPage($request, 15));
        $mine = Reaction::where(['target_type' => 'post', 'user_id' => $user->id])->whereIn('target_id', $page->pluck('id'))->pluck('type', 'target_id');

        return response()->json(['data' => $page->getCollection()->map(fn (Post $p) => $this->row($p, $user, $mine[$p->id] ?? null))->values(), 'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()]]);
    }

    /** @return array<string, mixed> */
    private function row(Post $p, User $user, ?string $myReaction = null): array
    {
        $anon = ($p->space->settings['anonymous_qa'] ?? false) && $p->kind === 'question' && $p->author_id !== $user->id && ! $this->spaces->canModerate($p->space, $user);

        return ['id' => $p->id, 'space_id' => $p->space_id, 'kind' => $p->kind, 'title' => $p->title, 'body' => $p->body, 'attachments' => $p->attachments, 'is_pinned' => $p->is_pinned, 'is_locked' => $p->is_locked,
            'status' => $p->status, 'accepted_answer_id' => $p->accepted_answer_id, 'edited_at' => $p->edited_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String(), 'comments_count' => $p->comments_count, 'reactions_count' => $p->reactions_count,
            'author' => $anon ? ['id' => null, 'name' => null, 'anonymous' => true] : ['id' => $p->author_id, 'name' => $p->author?->displayName(), 'anonymous' => false], 'mine' => $p->author_id === $user->id, 'my_reaction' => $myReaction,
            'poll' => $p->poll ? $this->posts->results($p->poll, $user) + ['id' => $p->poll->id] : null];
    }

    public function store(Request $request, Space $space): JsonResponse
    {
        $this->gate($space);
        $d = $request->validate([
            'kind' => ['nullable', Rule::in(PostService::KINDS)], 'title' => ['nullable', 'string', 'max:250'], 'body' => ['nullable', 'string', 'max:20000'], 'attachments' => ['nullable', 'array', 'max:10'], 'attachments.*.url' => ['nullable', 'string', 'max:500'],
            'attachments.*.name' => ['nullable', 'string', 'max:200'], 'poll' => ['nullable', 'array'], 'poll.question' => ['nullable', 'string', 'max:250'], 'poll.options' => ['nullable', 'array', 'max:10'], 'poll.options.*' => ['string', 'max:200'],
            'poll.multiple' => ['boolean'], 'poll.anonymous' => ['boolean'], 'poll.closes_at' => ['nullable', 'date', 'after:now'],
        ]);
        $post = $this->posts->create($space, $this->user(), $d);

        return response()->json(['data' => $this->row($post->load('space'), $this->user())], 201);
    }

    public function show(Post $post): JsonResponse
    {
        $this->readable($post->space);
        $user = $this->user();
        abort_if($post->status !== 'published' && ! $this->spaces->canModerate($post->space, $user) && $post->author_id !== $user->id, 403);
        $comments = Comment::with('author:id,name,name_ar')->where('post_id', $post->id)->where('status', '!=', 'deleted')->orderBy('created_at')->limit(300)->get();
        $mine = Reaction::where(['user_id' => $user->id])->where(fn ($w) => $w->where(['target_type' => 'post', 'target_id' => $post->id])->orWhere(fn ($x) => $x->where('target_type', 'comment')->whereIn('target_id', $comments->pluck('id'))))->pluck('type', 'target_id');
        $counts = Reaction::where('target_type', 'comment')->whereIn('target_id', $comments->pluck('id'))->selectRaw('target_id, count(*) as n')->groupBy('target_id')->pluck('n', 'target_id');
        $mod = $this->spaces->canModerate($post->space, $user);

        return response()->json(['data' => $this->row($post->load('space', 'author', 'poll'), $user, $mine[$post->id] ?? null) + ['comments' => $comments->map(fn (Comment $c) => [
            'id' => $c->id, 'parent_id' => $c->parent_id, 'body' => $c->status === 'hidden' && ! $mod ? null : $c->body, 'status' => $c->status, 'created_at' => $c->created_at->toIso8601String(), 'edited_at' => $c->edited_at?->toIso8601String(),
            'author' => ['id' => $c->author_id, 'name' => $c->author?->displayName()], 'mine' => $c->author_id === $user->id, 'accepted' => $post->accepted_answer_id === $c->id, 'reactions' => (int) ($counts[$c->id] ?? 0), 'my_reaction' => $mine[$c->id] ?? null,
        ])->values()]]);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $this->gate($post->space);
        $d = $request->validate(['title' => ['nullable', 'string', 'max:250'], 'body' => ['required', 'string', 'max:20000']]);

        return response()->json(['data' => $this->row($this->posts->update($post, $this->user(), $d)->load('space', 'author', 'poll'), $this->user())]);
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->gate($post->space);
        $this->posts->remove($post, $this->user());

        return response()->json(['message' => 'ok']);
    }

    public function moderate(Request $request, Post $post): JsonResponse
    {
        $this->gate($post->space);
        $d = $request->validate(['action' => ['required', Rule::in(['pin', 'unpin', 'lock', 'unlock', 'hide', 'publish'])]]);

        return response()->json(['data' => $this->row($this->posts->moderate($post, $this->user(), $d['action'])->load('space', 'author', 'poll'), $this->user())]);
    }

    public function comment(Request $request, Post $post): JsonResponse
    {
        $this->gate($post->space);
        $d = $request->validate(['body' => ['required', 'string', 'max:10000'], 'parent_id' => ['nullable', 'uuid'], 'attachments' => ['nullable', 'array', 'max:5']]);
        $c = $this->posts->comment($post, $this->user(), $d);

        return response()->json(['data' => ['id' => $c->id, 'body' => $c->body, 'parent_id' => $c->parent_id, 'created_at' => $c->created_at->toIso8601String(), 'author' => ['id' => $c->author_id, 'name' => $c->author?->displayName()]]], 201);
    }

    public function updateComment(Request $request, Comment $postComment): JsonResponse
    {
        $this->gate($postComment->post->space);
        $d = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $this->posts->updateComment($postComment, $this->user(), $d['body']);

        return response()->json(['message' => 'ok']);
    }

    public function destroyComment(Comment $postComment): JsonResponse
    {
        $this->gate($postComment->post->space);
        $this->posts->removeComment($postComment, $this->user());

        return response()->json(['message' => 'ok']);
    }

    public function accept(Request $request, Post $post): JsonResponse
    {
        $this->gate($post->space);
        $d = $request->validate(['comment_id' => ['nullable', 'uuid']]);
        $c = ! empty($d['comment_id']) ? Comment::findOrFail($d['comment_id']) : null;

        return response()->json(['data' => ['accepted_answer_id' => $this->posts->acceptAnswer($post, $c, $this->user())->accepted_answer_id]]);
    }

    public function react(Request $request): JsonResponse
    {
        $d = $request->validate(['target_type' => ['required', Rule::in(['post', 'comment'])], 'target_id' => ['required', 'uuid'], 'type' => ['nullable', Rule::in(PostService::REACTIONS)]]);
        $space = $d['target_type'] === 'post' ? Post::findOrFail($d['target_id'])->space : Comment::findOrFail($d['target_id'])->post->space;
        $this->gate($space);

        return response()->json(['data' => $this->posts->react($d['target_type'], $d['target_id'], $this->user(), $d['type'] ?? 'like')]);
    }

    public function vote(Request $request, Poll $spacePoll): JsonResponse
    {
        $this->gate($spacePoll->post->space);
        $d = $request->validate(['option_ids' => ['required', 'array', 'min:1', 'max:10'], 'option_ids.*' => ['string', 'max:10']]);
        $this->posts->vote($spacePoll, $this->user(), $d['option_ids']);

        return response()->json(['data' => $this->posts->results($spacePoll->refresh(), $this->user())]);
    }

    // ---- reports -------------------------------------------------------------------------------------

    public function report(Request $request): JsonResponse
    {
        $d = $request->validate(['target_type' => ['required', Rule::in(['post', 'comment'])], 'target_id' => ['required', 'uuid'], 'reason' => ['required', Rule::in(['spam', 'abuse', 'inappropriate', 'privacy', 'other'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $space = $d['target_type'] === 'post' ? Post::findOrFail($d['target_id'])->space : Comment::findOrFail($d['target_id'])->post->space;
        $this->gate($space);
        $this->posts->report($d['target_type'], $d['target_id'], $this->user(), $d['reason'], $d['note'] ?? null);

        return response()->json(['message' => 'ok'], 201);
    }

    /** The moderators' queue of one space (or every space for staff). */
    public function reports(Request $request, Space $space): JsonResponse
    {
        $this->gate($space);
        abort_unless($this->spaces->canModerate($space, $this->user()), 403);
        $postIds = Post::where('space_id', $space->id)->pluck('id');
        $commentIds = Comment::whereIn('post_id', $postIds)->pluck('id');
        $rows = AbuseReport::with('reporter:id,name,name_ar')->where('status', $request->query('status', 'open'))->where(fn ($w) => $w->where('target_type', 'post')->whereIn('target_id', $postIds)->orWhere(fn ($x) => $x->where('target_type', 'comment')->whereIn('target_id', $commentIds)))->latest()->limit(100)->get();
        $targets = ['post' => Post::whereIn('id', $rows->where('target_type', 'post')->pluck('target_id'))->get()->keyBy('id'), 'comment' => Comment::whereIn('id', $rows->where('target_type', 'comment')->pluck('target_id'))->get()->keyBy('id')];

        return response()->json(['data' => $rows->map(fn (AbuseReport $r) => ['id' => $r->id, 'target_type' => $r->target_type, 'target_id' => $r->target_id, 'reason' => $r->reason, 'note' => $r->note, 'status' => $r->status, 'action' => $r->action,
            'reporter' => $r->reporter?->displayName(), 'excerpt' => Str::limit(strip_tags((string) ($targets[$r->target_type][$r->target_id]->body ?? '')), 200), 'created_at' => $r->created_at->toIso8601String()])->values()]);
    }

    public function resolveReport(Request $request, AbuseReport $abuseReport): JsonResponse
    {
        $d = $request->validate(['action' => ['required', Rule::in(['hide', 'warn', 'ban', 'dismiss'])]]);
        $this->posts->resolveReport($abuseReport, $this->user(), $d['action']);

        return response()->json(['data' => ['status' => $abuseReport->refresh()->status, 'action' => $abuseReport->action]]);
    }
}
