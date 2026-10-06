<?php

namespace App\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\AbuseReport;
use App\Models\Comment;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Services\Cms\HtmlSanitizer;
use App\Services\NotificationService;
use App\Social\Events\AnswerAccepted;
use App\Social\Events\CommentPosted;
use App\Social\Events\PostPublished;
use App\Social\Events\ReactionReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Posts, comments, reactions, polls, accepted answers and moderation. Everything written is sanitised; people are rate limited. */
class PostService
{
    public const KINDS = ['discussion', 'question', 'announcement', 'poll', 'resource', 'meeting'];

    public const REACTIONS = ['like', 'insightful', 'thanks'];

    public function __construct(private readonly SpaceService $spaces, private readonly NotificationService $notifications) {}

    /** @param  array<string, mixed>  $d */
    public function create(Space $space, User $by, array $d): Post
    {
        if (! $this->spaces->canPost($space, $by)) {
            abort(403);
        }
        $kind = in_array($d['kind'] ?? 'discussion', self::KINDS, true) ? ($d['kind'] ?? 'discussion') : 'discussion';
        if (in_array($kind, ['announcement', 'meeting'], true) && ! $this->spaces->canModerate($space, $by)) {
            abort(403);
        }
        if ($kind === 'poll' && ! ($space->settings['allow_polls'] ?? true)) {
            throw new BusinessRuleException('Polls are switched off in this space.', 'polls_off');
        }
        $this->throttle($by, 'post', 12, 600);
        $body = HtmlSanitizer::clean((string) ($d['body'] ?? ''));
        if (trim(strip_tags($body)) === '' && $kind !== 'poll') {
            throw new BusinessRuleException('Write something first.', 'empty');
        }
        $hold = ($space->settings['moderation'] ?? false) && ! $this->spaces->canModerate($space, $by);

        return DB::transaction(function () use ($space, $by, $d, $kind, $body, $hold) {
            $post = Post::create([
                'space_id' => $space->id, 'author_id' => $by->id, 'kind' => $kind, 'title' => isset($d['title']) ? Str::limit(strip_tags((string) $d['title']), 250, '') : null, 'body' => $body,
                'attachments' => ($space->settings['allow_files'] ?? true) ? ($d['attachments'] ?? null) : null, 'status' => $hold ? 'hidden' : 'published',
            ]);
            if ($kind === 'poll') {
                $opts = collect($d['poll']['options'] ?? [])->map(fn ($t) => trim(strip_tags((string) $t)))->filter()->values();
                if ($opts->count() < 2 || $opts->count() > 10) {
                    throw new BusinessRuleException('A poll needs between 2 and 10 options.', 'poll_options');
                }
                Poll::create(['post_id' => $post->id, 'question' => Str::limit(strip_tags((string) ($d['poll']['question'] ?? $post->title ?? '')), 250, ''),
                    'options' => $opts->map(fn ($t, $i) => ['id' => 'o'.($i + 1), 'text' => $t])->all(), 'multiple' => (bool) ($d['poll']['multiple'] ?? false), 'anonymous' => (bool) ($d['poll']['anonymous'] ?? false), 'closes_at' => $d['poll']['closes_at'] ?? null]);
            }
            if (! $hold) {
                $space->increment('posts_count');
                $this->notifyMembers($space, $post, $by, $kind === 'announcement' ? 'all' : 'all');
            }
            event(new PostPublished($post));

            return $post->load('author', 'poll');
        });
    }

    private function notifyMembers(Space $space, Post $post, User $by, string $level): void
    {
        $ids = SpaceMember::where('space_id', $space->id)->where('status', 'active')->where('user_id', '!=', $by->id)
            ->when($post->kind !== 'announcement', fn ($q) => $q->where('notify', 'all'))->when($post->kind === 'announcement', fn ($q) => $q->where('notify', '!=', 'none'))->pluck('user_id');
        if ($space->type === 'trainers_channel' || $ids->count() > 500) {
            $ids = $ids->take(500);
        }
        $this->notifications->broadcast($ids, 'space.post', ['ar' => 'منشور جديد في '.$space->title_ar, 'en' => 'New post in '.$space->title_en], ['ar' => Str::limit(strip_tags($post->body), 120), 'en' => Str::limit(strip_tags($post->body), 120)],
            ['space_id' => $space->id, 'post_id' => $post->id, 'route' => '/communities/'.$space->id.'?post='.$post->id], raw: true);
    }

    /** @param  array<string, mixed>  $d */
    public function update(Post $post, User $by, array $d): Post
    {
        if ($post->author_id !== $by->id && ! $this->spaces->canModerate($post->space, $by)) {
            abort(403);
        }
        $edits = $post->edits ?? [];
        $edits[] = ['body' => $post->body, 'title' => $post->title, 'at' => now()->toIso8601String(), 'by' => $by->id];
        $post->update(['body' => HtmlSanitizer::clean((string) ($d['body'] ?? $post->body)), 'title' => array_key_exists('title', $d) ? Str::limit(strip_tags((string) $d['title']), 250, '') : $post->title, 'edits' => array_slice($edits, -20), 'edited_at' => now()]);

        return $post;
    }

    public function remove(Post $post, User $by): void
    {
        if ($post->author_id !== $by->id && ! $this->spaces->canModerate($post->space, $by)) {
            abort(403);
        }
        $post->update(['status' => 'deleted']);
        $post->space->where('id', $post->space_id)->where('posts_count', '>', 0)->decrement('posts_count');
    }

    public function moderate(Post $post, User $by, string $action): Post
    {
        if (! $this->spaces->canModerate($post->space, $by)) {
            abort(403);
        }
        match ($action) {
            'pin' => $post->update(['is_pinned' => true]),
            'unpin' => $post->update(['is_pinned' => false]),
            'lock' => $post->update(['is_locked' => true]),
            'unlock' => $post->update(['is_locked' => false]),
            'hide' => $post->update(['status' => 'hidden']),
            'publish' => $post->update(['status' => 'published']),
            default => throw new BusinessRuleException('Unknown action.', 'bad_action'),
        };
        if ($action === 'publish') {
            $post->space->increment('posts_count');
            $this->notifications->send($post->author_id, 'space.post_approved', ['ar' => 'تم نشر منشورك', 'en' => 'Your post was published'], null, ['space_id' => $post->space_id, 'route' => '/communities/'.$post->space_id], raw: true);
        }

        return $post;
    }

    // ---- comments ------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $d */
    public function comment(Post $post, User $by, array $d): Comment
    {
        $space = $post->space;
        if (! $this->spaces->canPost($space, $by) || $post->status !== 'published') {
            abort(403);
        }
        if ($post->is_locked && ! $this->spaces->canModerate($space, $by)) {
            throw new BusinessRuleException('This thread is locked.', 'locked');
        }
        $this->throttle($by, 'comment', 30, 600);
        $body = HtmlSanitizer::clean((string) ($d['body'] ?? ''));
        if (trim(strip_tags($body)) === '') {
            throw new BusinessRuleException('Write something first.', 'empty');
        }
        $parent = ! empty($d['parent_id']) && Str::isUuid($d['parent_id']) ? Comment::where('post_id', $post->id)->find($d['parent_id']) : null;
        $comment = Comment::create(['post_id' => $post->id, 'parent_id' => $parent?->parent_id ?? $parent?->id, 'author_id' => $by->id, 'body' => $body, 'attachments' => $d['attachments'] ?? null]);
        $post->increment('comments_count');
        $notify = collect([$post->author_id, $parent?->author_id])->filter()->unique()->reject(fn ($id) => $id === $by->id);
        $this->notifications->broadcast($notify, 'space.comment', ['ar' => 'تعليق جديد من '.$by->displayName('ar'), 'en' => 'New comment from '.$by->displayName('en')], ['ar' => Str::limit(strip_tags($body), 120), 'en' => Str::limit(strip_tags($body), 120)],
            ['space_id' => $space->id, 'post_id' => $post->id, 'route' => '/communities/'.$space->id.'?post='.$post->id], raw: true);
        $this->mentions($space, $post, $by, $body, $notify->all());
        event(new CommentPosted($comment));

        return $comment->load('author');
    }

    /** @param  list<string>  $already */
    private function mentions(Space $space, Post $post, User $by, string $body, array $already): void
    {
        preg_match_all('/@([\w.\-]{2,40})/u', strip_tags($body), $m);
        if (! $m[1]) {
            return;
        }
        $members = User::whereIn('id', SpaceMember::where('space_id', $space->id)->where('status', 'active')->select('user_id'))->get();
        foreach ($m[1] as $tag) {
            $u = $members->first(fn (User $x) => Str::lower(str_replace(' ', '', $x->name ?? '')) === Str::lower($tag) || Str::lower(strtok($x->email, '@')) === Str::lower($tag));
            if ($u && $u->id !== $by->id && ! in_array($u->id, $already, true)) {
                $this->notifications->send($u, 'space.mention', ['ar' => 'تمت الإشارة إليك', 'en' => 'You were mentioned'], null, ['space_id' => $space->id, 'post_id' => $post->id, 'route' => '/communities/'.$space->id.'?post='.$post->id], raw: true);
            }
        }
    }

    public function updateComment(Comment $c, User $by, string $body): Comment
    {
        if ($c->author_id !== $by->id && ! $this->spaces->canModerate($c->post->space, $by)) {
            abort(403);
        }
        $c->update(['body' => HtmlSanitizer::clean($body), 'edited_at' => now()]);

        return $c;
    }

    public function removeComment(Comment $c, User $by): void
    {
        if ($c->author_id !== $by->id && ! $this->spaces->canModerate($c->post->space, $by)) {
            abort(403);
        }
        if ($c->status !== 'deleted') {
            $c->update(['status' => 'deleted']);
            Post::where('id', $c->post_id)->where('comments_count', '>', 0)->decrement('comments_count');
        }
    }

    /** The asker or a moderator marks the helpful answer (question posts only). */
    public function acceptAnswer(Post $post, ?Comment $comment, User $by): Post
    {
        if ($post->kind !== 'question') {
            throw new BusinessRuleException('Only questions have an accepted answer.', 'not_question');
        }
        if ($post->author_id !== $by->id && ! $this->spaces->canModerate($post->space, $by)) {
            abort(403);
        }
        if ($comment && $comment->post_id !== $post->id) {
            abort(404);
        }
        $post->update(['accepted_answer_id' => $comment?->id]);
        if ($comment && $comment->author_id !== $by->id) {
            event(new AnswerAccepted($comment));
        }

        return $post;
    }

    // ---- reactions / polls ---------------------------------------------------------------------------

    /** Toggle: the same reaction again removes it; another type replaces it. */
    public function react(string $targetType, string $targetId, User $by, string $type = 'like'): array
    {
        $type = in_array($type, self::REACTIONS, true) ? $type : 'like';
        $target = $targetType === 'comment' ? Comment::with('post.space')->findOrFail($targetId) : Post::with('space')->findOrFail($targetId);
        $space = $targetType === 'comment' ? $target->post->space : $target->space;
        if (! $this->spaces->canPost($space, $by) && ! $this->spaces->canRead($space, $by)) {
            abort(403);
        }
        $this->throttle($by, 'react', 120, 600);
        $existing = Reaction::where(['target_type' => $targetType, 'target_id' => $targetId, 'user_id' => $by->id])->first();
        $state = 'added';
        if ($existing && $existing->type === $type) {
            $existing->delete();
            $state = 'removed';
        } elseif ($existing) {
            $existing->update(['type' => $type]);
        } else {
            Reaction::create(['target_type' => $targetType, 'target_id' => $targetId, 'user_id' => $by->id, 'type' => $type]);
        }
        if ($targetType === 'post') {
            Post::whereKey($targetId)->update(['reactions_count' => Reaction::where(['target_type' => 'post', 'target_id' => $targetId])->count()]);
        }
        if ($state === 'added' && $target->author_id !== $by->id) {
            event(new ReactionReceived($targetType, $target));
        }

        return ['state' => $state, 'counts' => Reaction::where(['target_type' => $targetType, 'target_id' => $targetId])->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->all()];
    }

    /** @param  list<string>  $optionIds */
    public function vote(Poll $poll, User $by, array $optionIds): Poll
    {
        $space = $poll->post->space;
        if (! $this->spaces->canPost($space, $by)) {
            abort(403);
        }
        if ($poll->closes_at && $poll->closes_at->isPast()) {
            throw new BusinessRuleException('This poll is closed.', 'poll_closed');
        }
        $valid = collect($poll->options)->pluck('id')->all();
        $picked = array_values(array_unique(array_intersect($optionIds, $valid)));
        if (! $picked || (! $poll->multiple && count($picked) > 1)) {
            throw new BusinessRuleException('Choose '.($poll->multiple ? 'at least one option' : 'one option').'.', 'bad_vote');
        }
        PollVote::updateOrCreate(['poll_id' => $poll->id, 'user_id' => $by->id], ['option_ids' => $picked]);

        return $poll;
    }

    /** @return array<string, mixed> */
    public function results(Poll $poll, ?User $viewer = null): array
    {
        $votes = PollVote::where('poll_id', $poll->id)->get();
        $counts = [];
        foreach ($votes as $v) {
            foreach ($v->option_ids as $o) {
                $counts[$o] = ($counts[$o] ?? 0) + 1;
            }
        }
        $mine = $viewer ? ($votes->firstWhere('user_id', $viewer->id)?->option_ids ?? []) : [];

        return ['question' => $poll->question, 'multiple' => $poll->multiple, 'anonymous' => $poll->anonymous, 'closes_at' => $poll->closes_at?->toIso8601String(), 'total_voters' => $votes->count(), 'mine' => $mine,
            'options' => collect($poll->options)->map(fn ($o) => ['id' => $o['id'], 'text' => $o['text'], 'votes' => $counts[$o['id']] ?? 0])->all()];
    }

    // ---- abuse reports -------------------------------------------------------------------------------

    public function report(string $targetType, string $targetId, User $by, string $reason, ?string $note): AbuseReport
    {
        $target = $targetType === 'comment' ? Comment::with('post.space')->findOrFail($targetId) : Post::with('space')->findOrFail($targetId);
        $space = $targetType === 'comment' ? $target->post->space : $target->space;
        if (! $this->spaces->canRead($space, $by)) {
            abort(403);
        }
        $r = AbuseReport::firstOrCreate(['target_type' => $targetType, 'target_id' => $targetId, 'reporter_id' => $by->id, 'status' => 'open'], ['reason' => Str::limit($reason, 24, ''), 'note' => $note ? Str::limit(strip_tags($note), 1000, '') : null]);
        if ($r->wasRecentlyCreated) {
            $this->notifications->broadcast(array_unique(array_merge($this->spaces->managers($space), SpaceMember::where('space_id', $space->id)->where('status', 'active')->where('role', 'moderator')->pluck('user_id')->all())), 'space.abuse_report',
                ['ar' => 'بلاغ جديد عن محتوى', 'en' => 'Content reported'], ['ar' => $space->title_ar, 'en' => $space->title_en], ['space_id' => $space->id, 'route' => '/communities/'.$space->id.'/moderation'], raw: true);
        }

        return $r;
    }

    /** @param  'hide'|'warn'|'ban'|'dismiss'  $action */
    public function resolveReport(AbuseReport $r, User $by, string $action): AbuseReport
    {
        $target = $r->target_type === 'comment' ? Comment::with('post.space')->find($r->target_id) : Post::with('space')->find($r->target_id);
        $space = $target ? ($r->target_type === 'comment' ? $target->post->space : $target->space) : null;
        if ($space ? ! $this->spaces->canModerate($space, $by) : ! ($by->hasPermission('forums.moderate') || $by->hasPermission('communities.moderate'))) {
            abort(403);
        }
        if ($target && $action === 'hide') {
            $target->update(['status' => 'hidden']);
        }
        if ($target && $action === 'warn') {
            $this->notifications->send($target->author_id, 'space.warning', ['ar' => 'تنبيه من المشرف', 'en' => 'A moderator has warned you'], ['ar' => 'يخالف محتواك قواعد المجتمع.', 'en' => 'Your content broke the community rules.'], ['space_id' => $space?->id], raw: true);
        }
        if ($target && $action === 'ban' && $space) {
            SpaceMember::updateOrCreate(['space_id' => $space->id, 'user_id' => $target->author_id], ['status' => 'banned', 'role' => 'member', 'source' => 'manual']);
            $target->update(['status' => 'hidden']);
        }
        $r->update(['status' => $action === 'dismiss' ? 'dismissed' : 'actioned', 'action' => $action === 'dismiss' ? 'none' : ($action === 'hide' ? 'hidden' : ($action === 'warn' ? 'warned' : 'banned')), 'handled_by' => $by->id, 'handled_at' => now()]);

        return $r;
    }

    private function throttle(User $by, string $what, int $max, int $seconds): void
    {
        $key = "social:{$what}:{$by->id}";
        Cache::add($key, 0, $seconds);
        if ((int) Cache::get($key, 0) >= $max) {
            throw new BusinessRuleException('You are posting too quickly. Try again in a few minutes.', 'rate_limited');
        }
        Cache::increment($key);
    }
}
