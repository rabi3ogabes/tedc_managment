<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Http\Resources\KitCommentResource;
use App\Models\KitComment;
use App\Models\KitFile;
use App\Models\KitMember;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\Kits\KitAccess;
use App\Services\Kits\KitLog;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Review comments: pinned to slides, elements, pages, text or video moments, with threads and a fix-then-verify status flow. */
class KitCommentController extends KitBaseController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $user = $this->user();

        $query = KitComment::where('kit_id', $kit->id)->whereNull('parent_id')
            ->when($request->query('file_id'), fn ($q, $id) => $q->where('file_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->query('severity'), fn ($q, $s) => $q->whereIn('severity', explode(',', $s)))
            ->when($request->query('category'), fn ($q, $s) => $q->whereIn('category', explode(',', $s)))
            ->when($request->query('round'), fn ($q, $r) => $q->where('review_round', (int) $r))
            ->when($request->boolean('mine'), fn ($q) => $q->where(fn ($w) => $w->where('assignee_id', $user->id)->orWhere('author_id', $user->id)))
            ->when($request->query('q'), fn ($q, $t) => $q->whereLike('body', "%{$t}%"));

        $counts = (clone $query)->reorder()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        $comments = $query->with(['author.roles', 'assignee', 'file:id,name,kind', 'replies.author.roles'])->orderByRaw("case status when 'open' then 0 when 'addressed' then 1 else 2 end")->latest()->limit(300)->get();

        return response()->json(['data' => KitCommentResource::collection($comments), 'meta' => ['counts' => ['open' => (int) ($counts['open'] ?? 0), 'addressed' => (int) ($counts['addressed'] ?? 0), 'resolved' => (int) ($counts['resolved'] ?? 0)]]]);
    }

    public function store(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $user = $this->user();
        abort_unless(KitAccess::canComment($user, $kit), 403, __('auth.forbidden'));

        $comment = $this->create($kit, $user, $this->validated($request));

        return response()->json(['data' => new KitCommentResource($comment)], 201);
    }

    /** Creates several comments at once (e.g. from the automatic-check findings). */
    public function bulk(Request $request, TrainingKit $kit): JsonResponse
    {
        $this->viewable($kit);
        $user = $this->user();
        abort_unless(KitAccess::canComment($user, $kit), 403, __('auth.forbidden'));
        $request->validate(['comments' => ['required', 'array', 'min:1', 'max:30']]);

        $created = DB::transaction(fn () => collect($request->input('comments'))->map(function ($row) use ($kit, $user) {
            $data = validator((array) $row, $this->rules())->validate();

            return $this->create($kit, $user, $data, notify: false);
        }));
        $this->notifyTeam($kit, $user, $created->first(), $created->count());

        return response()->json(['data' => KitCommentResource::collection($created)], 201);
    }

    public function update(Request $request, TrainingKit $kit, KitComment $comment): JsonResponse
    {
        $this->viewable($kit);
        $user = $this->user();
        $canReview = KitAccess::canReview($user, $kit);
        abort_unless($comment->author_id === $user->id || $canReview || KitAccess::isStaff($user), 403, __('auth.forbidden'));

        $data = $request->validate([
            'body' => ['sometimes', 'string', 'min:1', 'max:5000'], 'category' => ['sometimes', Rule::in(KitComment::CATEGORIES)],
            'severity' => ['sometimes', Rule::in(KitComment::SEVERITIES)], 'assignee_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);
        if (isset($data['severity']) && ! $canReview && ! in_array($data['severity'], ['info', 'minor'], true)) {
            unset($data['severity']);
        }
        $previousAssignee = $comment->assignee_id;
        $comment->update($data);
        if (! empty($data['assignee_id']) && $data['assignee_id'] !== $previousAssignee && $data['assignee_id'] !== $user->id) {
            $this->notify($data['assignee_id'], 'kit.comment_assigned', $kit, $comment, $user, ['ar' => 'أُسندت إليك ملاحظة', 'en' => 'A comment was assigned to you']);
        }

        return response()->json(['data' => new KitCommentResource($comment->refresh()->load(['author.roles', 'assignee', 'file:id,name,kind', 'replies.author.roles']))]);
    }

    /**
     * Fix-then-verify: developers mark a comment addressed, the QA team verifies and resolves it
     * (or reopens it). Center staff can do either.
     */
    public function setStatus(Request $request, TrainingKit $kit, KitComment $comment): JsonResponse
    {
        $this->viewable($kit);
        $user = $this->user();
        abort_if($comment->parent_id !== null, 422, 'Only top-level comments have a status.');
        $data = $request->validate(['status' => ['required', Rule::in(KitComment::STATUSES)]]);
        $status = $data['status'];

        $isQa = KitAccess::canReview($user, $kit);
        $isDev = KitAccess::isStaff($user) || in_array(KitAccess::memberRole($user, $kit), [KitMember::DEVELOPER], true);
        abort_unless(match ($status) {
            'resolved' => $isQa || KitAccess::isStaff($user),
            'addressed' => $isDev || $isQa,
            default => KitAccess::canComment($user, $kit),   // reopen
        }, 403, __('auth.forbidden'));

        $comment->update([
            'status' => $status,
            'resolved_by' => $status === 'resolved' ? $user->id : null,
            'resolved_at' => $status === 'resolved' ? now() : null,
        ]);
        KitLog::record($kit, $user, 'comment_'.$status, 'comment', $comment->id, ['file_id' => $comment->file_id]);

        if ($status !== 'open' && $comment->author_id !== $user->id) {
            $this->notify($comment->author_id, 'kit.comment_'.$status, $kit, $comment, $user,
                $status === 'resolved' ? ['ar' => 'أُغلقت ملاحظتك', 'en' => 'Your comment was resolved'] : ['ar' => 'عولجت ملاحظتك وتنتظر التحقق', 'en' => 'Your comment was addressed and awaits verification']);
        } elseif ($status === 'open' && $comment->author_id !== $user->id) {
            $this->notify($comment->author_id, 'kit.comment_reopened', $kit, $comment, $user, ['ar' => 'أُعيد فتح ملاحظة', 'en' => 'A comment was reopened']);
        }

        return response()->json(['data' => new KitCommentResource($comment->refresh()->load(['author.roles', 'assignee', 'file:id,name,kind', 'replies.author.roles']))]);
    }

    public function destroy(TrainingKit $kit, KitComment $comment): JsonResponse
    {
        $this->viewable($kit);
        $user = $this->user();
        abort_unless($comment->author_id === $user->id || KitAccess::isStaff($user), 403, __('auth.forbidden'));
        $comment->replies()->delete();
        $comment->delete();

        return response()->json(null, 204);
    }

    // Internals --------------------------------------------------------------------------------

    private function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'file_id' => ['nullable', 'uuid'], 'parent_id' => ['nullable', 'uuid'],
            'anchor' => ['nullable', 'array'], 'anchor.type' => ['required_with:anchor', Rule::in(['slide', 'element', 'point', 'page', 'text', 'time', 'file'])],
            'anchor.slide_id' => ['nullable', 'string', 'max:40'], 'anchor.element_id' => ['nullable', 'string', 'max:40'], 'anchor.slide_index' => ['nullable', 'integer', 'min:0'],
            'anchor.x' => ['nullable', 'numeric'], 'anchor.y' => ['nullable', 'numeric'], 'anchor.page' => ['nullable', 'integer', 'min:1'],
            'anchor.quote' => ['nullable', 'string', 'max:500'], 'anchor.at' => ['nullable', 'numeric', 'min:0'], 'anchor.paragraph' => ['nullable', 'integer', 'min:0'],
            'file_version' => ['nullable', 'integer', 'min:1'],
            'category' => ['sometimes', Rule::in(KitComment::CATEGORIES)], 'severity' => ['sometimes', Rule::in(KitComment::SEVERITIES)],
            'assignee_id' => ['nullable', 'uuid', 'exists:users,id'],
            'mentions' => ['nullable', 'array', 'max:20'], 'mentions.*' => ['uuid', 'exists:users,id'],
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate($this->rules());
    }

    private function create(TrainingKit $kit, User $user, array $data, bool $notify = true): KitComment
    {
        $parent = ! empty($data['parent_id']) ? KitComment::where('kit_id', $kit->id)->whereNull('parent_id')->findOrFail($data['parent_id']) : null;
        $file = ! empty($data['file_id']) ? KitFile::where('kit_id', $kit->id)->findOrFail($data['file_id']) : $parent?->file;
        $canReview = KitAccess::canReview($user, $kit);

        // Only the QA team can raise a comment to major / critical.
        $severity = $data['severity'] ?? 'minor';
        if (! $canReview && in_array($severity, KitComment::BLOCKING, true)) {
            $severity = 'minor';
        }

        $comment = KitComment::create([
            'kit_id' => $kit->id, 'file_id' => $file?->id, 'parent_id' => $parent?->id, 'author_id' => $user->id, 'body' => $data['body'],
            'anchor' => $parent ? null : ($data['anchor'] ?? null), 'file_version' => $data['file_version'] ?? $file?->version,
            'category' => $parent ? $parent->category : ($data['category'] ?? 'content'), 'severity' => $parent ? $parent->severity : $severity,
            'status' => 'open', 'assignee_id' => $parent ? null : ($data['assignee_id'] ?? null), 'review_round' => $kit->review_round,
            'mentions' => $data['mentions'] ?? null,
        ]);
        KitLog::record($kit, $user, $parent ? 'comment_replied' : 'commented', 'comment', $comment->id, ['file_id' => $file?->id, 'severity' => $comment->severity]);

        if ($notify) {
            if ($parent) {
                foreach (array_unique(array_filter([$parent->author_id, $parent->assignee_id])) as $id) {
                    if ($id !== $user->id) {
                        $this->notify($id, 'kit.comment_reply', $kit, $comment, $user, ['ar' => 'رد جديد على ملاحظة', 'en' => 'New reply on a comment']);
                    }
                }
            } else {
                $this->notifyTeam($kit, $user, $comment, 1);
            }
            foreach (array_diff($comment->mentions ?? [], [$user->id]) as $id) {
                $this->notify($id, 'kit.mention', $kit, $comment, $user, ['ar' => 'تمت الإشارة إليك في ملاحظة', 'en' => 'You were mentioned in a comment']);
            }
            if ($comment->assignee_id && $comment->assignee_id !== $user->id) {
                $this->notify($comment->assignee_id, 'kit.comment_assigned', $kit, $comment, $user, ['ar' => 'أُسندت إليك ملاحظة', 'en' => 'A comment was assigned to you']);
            }
        }

        return $comment->load(['author.roles', 'assignee', 'file:id,name,kind', 'replies.author.roles']);
    }

    /** Tells the other side of the review that something new needs attention. */
    private function notifyTeam(TrainingKit $kit, User $author, ?KitComment $comment, int $count): void
    {
        if (! $comment) {
            return;
        }
        $isQa = KitAccess::canReview($author, $kit) && ! ($kit->owner_id === $author->id);
        $roles = $isQa ? [KitMember::DEVELOPER] : [KitMember::QA, KitMember::REVIEWER];
        $ids = $kit->members()->whereIn('role', $roles)->pluck('user_id')->all();
        if ($isQa) {
            $ids[] = $kit->owner_id;
        }
        foreach (array_unique(array_diff($ids, [$author->id])) as $id) {
            $this->notifications->send($id, 'kit.comment', ['ar' => $count > 1 ? "{$count} ملاحظات جديدة" : 'ملاحظة جديدة', 'en' => $count > 1 ? "{$count} new comments" : 'New comment'],
                ['ar' => "«{$kit->title_ar}» — {$author->displayName()}: ".mb_substr($comment->body, 0, 120), 'en' => "\"{$kit->title_en}\" - {$author->displayName()}: ".mb_substr($comment->body, 0, 120)], ['kit_id' => $kit->id, 'comment_id' => $comment->id, 'file_id' => $comment->file_id]);
        }
    }

    private function notify(string $userId, string $type, TrainingKit $kit, KitComment $comment, User $by, array $title): void
    {
        $this->notifications->send($userId, $type, $title, ['ar' => "«{$kit->title_ar}» — {$by->displayName()}: ".mb_substr($comment->body, 0, 120), 'en' => "\"{$kit->title_en}\" - {$by->displayName()}: ".mb_substr($comment->body, 0, 120)],
            ['kit_id' => $kit->id, 'comment_id' => $comment->id, 'file_id' => $comment->file_id]);
    }
}
