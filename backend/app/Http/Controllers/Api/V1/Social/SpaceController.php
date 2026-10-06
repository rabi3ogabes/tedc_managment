<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\Program;
use App\Models\Space;
use App\Models\SpaceEvent;
use App\Models\SpaceEventRsvp;
use App\Models\SpaceMember;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\FeatureSettings;
use App\Social\CourseSocial;
use App\Social\SpaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Communities, forums and channels: browsing, joining, members and events. */
class SpaceController extends Controller
{
    public function __construct(private readonly SpaceService $spaces, private readonly CourseSocial $course, private readonly FeatureSettings $features) {}

    /** Communities need the `plc` flag, every other kind of space the `forums` flag. */
    private function gate(Space|string $space): void
    {
        $type = $space instanceof Space ? $space->type : $space;
        abort_unless($this->features->enabled($type === 'community' ? 'plc' : 'forums'), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $type = $request->query('type');
        abort_unless($this->features->enabled('plc') || $this->features->enabled('forums'), 403);
        $q = $this->spaces->visibleTo($user)->withCount(['members as members_count' => fn ($m) => $m->where('status', 'active')])->orderByDesc('updated_at');
        if (! $this->features->enabled('plc')) {
            $q->where('type', '!=', 'community');
        }
        if (! $this->features->enabled('forums')) {
            $q->where('type', 'community');
        }
        if ($type && in_array($type, ['community', 'program_forum', 'group_forum', 'trainers_channel', 'lesson_thread'], true)) {
            $q->where('type', $type);
        }
        $mine = SpaceMember::where('user_id', $user->id)->get()->keyBy('space_id');
        if ($request->boolean('mine')) {
            $q->whereIn('id', $mine->keys());
        }
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%'.mb_strtolower($s).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(title_ar) like ?', [$like])->orWhereRaw('lower(title_en) like ?', [$like]));
        }
        $rows = $q->limit(100)->get()->map(fn (Space $s) => $this->card($s, $mine[$s->id] ?? null));

        return response()->json(['data' => $rows->values(), 'can_create' => $this->features->enabled('plc') && $user->hasPermission('communities.create')]);
    }

    /** @return array<string, mixed> */
    private function card(Space $s, ?SpaceMember $m): array
    {
        return ['id' => $s->id, 'type' => $s->type, 'title_ar' => $s->title_ar, 'title_en' => $s->title_en, 'description_ar' => $s->description_ar, 'description_en' => $s->description_en, 'visibility' => $s->visibility, 'join_policy' => $s->join_policy,
            'posts_count' => $s->posts_count, 'members_count' => $s->members_count ?? null, 'archived' => (bool) $s->archived_at, 'my_role' => $m?->status === 'active' ? $m->role : null, 'my_status' => $m?->status, 'my_notify' => $m?->notify];
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate('community');
        abort_unless($this->user()->hasPermission('communities.create'), 403);
        $d = $request->validate([
            'title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['nullable', Rule::in(['public_in_scope', 'members', 'private'])], 'join_policy' => ['nullable', Rule::in(['open', 'request', 'invite'])],
            'settings' => ['nullable', 'array'], 'settings.allow_polls' => ['boolean'], 'settings.allow_files' => ['boolean'], 'settings.moderation' => ['boolean'], 'settings.anonymous_qa' => ['boolean'],
        ]);

        return response()->json(['data' => $this->detail($this->spaces->create($this->user(), $d))], 201);
    }

    public function show(Space $space): JsonResponse
    {
        $this->gate($space);
        $user = $this->user();
        abort_unless($this->spaces->canRead($space, $user) || ($space->type === 'community' && $space->visibility !== 'private'), 403);

        return response()->json(['data' => $this->detail($space)]);
    }

    /** @return array<string, mixed> */
    private function detail(Space $s): array
    {
        $user = $this->user();
        $m = $this->spaces->member($s, $user);
        $s->loadCount(['members as members_count' => fn ($q) => $q->where('status', 'active')]);

        return $this->card($s, $m) + ['settings' => $s->settings, 'can_post' => $this->spaces->canPost($s, $user), 'can_moderate' => $this->spaces->canModerate($s, $user), 'can_manage' => $this->spaces->canManage($s, $user), 'can_read' => $this->spaces->canRead($s, $user)];
    }

    public function update(Request $request, Space $space): JsonResponse
    {
        $this->gate($space);
        $this->spaces->assertManager($space, $this->user());
        $d = $request->validate([
            'title_ar' => ['sometimes', 'string', 'max:200'], 'title_en' => ['sometimes', 'string', 'max:200'], 'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::in(['public_in_scope', 'members', 'private'])], 'join_policy' => ['sometimes', Rule::in(['open', 'request', 'invite'])], 'settings' => ['sometimes', 'array'],
        ]);
        if (isset($d['settings'])) {
            $d['settings'] = $this->spaces->settings($d['settings']);
        }
        if ($space->type !== 'community') {
            unset($d['visibility'], $d['join_policy']);   // platform spaces keep their rules
        }
        $space->update($d);

        return response()->json(['data' => $this->detail($space)]);
    }

    public function archive(Space $space): JsonResponse
    {
        $this->gate($space);
        $this->spaces->archive($space, $this->user());

        return response()->json(['data' => $this->detail($space->refresh())]);
    }

    // ---- members -------------------------------------------------------------------------------------

    public function join(Space $space): JsonResponse
    {
        $this->gate($space);
        $m = $this->spaces->join($space, $this->user());

        return response()->json(['data' => $this->detail($space), 'status' => $m->status]);
    }

    public function leave(Space $space): JsonResponse
    {
        $this->gate($space);
        $this->spaces->leave($space, $this->user());

        return response()->json(['message' => 'ok']);
    }

    public function members(Request $request, Space $space): JsonResponse
    {
        $this->gate($space);
        abort_unless($this->spaces->canRead($space, $this->user()), 403);
        $manage = $this->spaces->canManage($space, $this->user());
        $rows = SpaceMember::with('user:id,name,name_ar')->where('space_id', $space->id)->when(! $manage, fn ($q) => $q->where('status', 'active'))->orderByRaw("case role when 'owner' then 0 when 'manager' then 1 when 'moderator' then 2 else 3 end")->limit(300)->get()
            ->map(fn (SpaceMember $m) => ['user_id' => $m->user_id, 'name' => $m->user?->displayName(), 'role' => $m->role, 'status' => $m->status, 'source' => $m->source, 'joined_at' => $m->joined_at?->toIso8601String()]);

        return response()->json(['data' => $rows->values()]);
    }

    public function updateMember(Request $request, Space $space, User $user): JsonResponse
    {
        $this->gate($space);
        $d = $request->validate(['role' => ['sometimes', Rule::in(['owner', 'manager', 'moderator', 'member'])], 'status' => ['sometimes', Rule::in(['active', 'pending', 'banned'])]]);
        $m = $this->spaces->updateMember($space, $user, $d, $this->user());

        return response()->json(['data' => ['user_id' => $m->user_id, 'role' => $m->role, 'status' => $m->status]]);
    }

    public function removeMember(Space $space, User $user): JsonResponse
    {
        $this->gate($space);
        $this->spaces->removeMember($space, $user, $this->user());

        return response()->json(['message' => 'ok']);
    }

    /** The person's own notification choice for the space. */
    public function preference(Request $request, Space $space): JsonResponse
    {
        $this->gate($space);
        $d = $request->validate(['notify' => ['required', Rule::in(['all', 'mentions', 'none'])]]);
        $m = SpaceMember::where(['space_id' => $space->id, 'user_id' => $this->user()->id])->firstOrFail();
        $m->update(['notify' => $d['notify']]);

        return response()->json(['data' => ['notify' => $m->notify]]);
    }

    /** People who can be added to a community: a name search. */
    public function people(Request $request): JsonResponse
    {
        $this->gate('community');
        abort_unless($this->user()->hasPermission('communities.create'), 403);
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) < 2, 422);
        $like = '%'.mb_strtolower($q).'%';
        $rows = User::where('status', 'active')->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(name_ar, \'\')) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]))->limit(15)->get(['id', 'name', 'name_ar', 'email']);

        return response()->json(['data' => $rows->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email])]);
    }

    // ---- program / group / lesson / trainers spaces --------------------------------------------------

    /** Opens (creating when needed) the forum that belongs to a program, group or lesson, or the trainers' channel — only for people it concerns. */
    public function resolve(Request $request): JsonResponse
    {
        $d = $request->validate(['type' => ['required', Rule::in(['program', 'group', 'lesson', 'trainers'])], 'id' => ['nullable', 'uuid']]);
        $user = $this->user();
        abort_unless($this->features->enabled('forums'), 403);
        $space = match ($d['type']) {
            'program' => $this->forProgram(Program::findOrFail($d['id'] ?? abort(422)), $user),
            'group' => $this->forGroup(TrainingGroup::findOrFail($d['id'] ?? abort(422)), $user),
            'lesson' => $this->forLesson(CourseLesson::findOrFail($d['id'] ?? abort(422)), $user),
            default => $this->forTrainers($user),
        };

        return response()->json(['data' => $this->detail($space)]);
    }

    private function forProgram(Program $p, User $user): Space
    {
        abort_unless($this->course->registrationFor($user, $p->id) || $this->course->staffOfProgram($user, $p->id), 403);

        return $this->spaces->programForum($p);
    }

    private function forGroup(TrainingGroup $g, User $user): Space
    {
        $reg = $this->course->registrationFor($user, $g->program_id);
        abort_unless(($reg && $reg->training_group_id === $g->id) || $this->course->staffOfProgram($user, $g->program_id), 403);

        return $this->spaces->groupForum($g);
    }

    private function forLesson(CourseLesson $l, User $user): Space
    {
        abort_unless($this->course->registrationFor($user, $l->program_id) || $this->course->staffOfProgram($user, $l->program_id), 403);

        return $this->spaces->lessonThread($l);
    }

    private function forTrainers(User $user): Space
    {
        $isTrainer = Trainer::where('user_id', $user->id)->exists();
        abort_unless($isTrainer || $user->hasPermission('forums.moderate'), 403);

        return $this->spaces->trainersChannel();
    }

    // ---- events --------------------------------------------------------------------------------------

    public function events(Space $space): JsonResponse
    {
        $this->gate($space);
        abort_unless($this->spaces->canRead($space, $this->user()), 403);
        $mine = SpaceEventRsvp::where('user_id', $this->user()->id)->pluck('status', 'event_id');
        $rows = SpaceEvent::where('space_id', $space->id)->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->limit(50)->withCount(['rsvps as going' => fn ($q) => $q->where('status', 'going')])->get()
            ->map(fn (SpaceEvent $e) => ['id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at?->toIso8601String(), 'location' => $e->location, 'online_url' => $e->online_url, 'agenda' => $e->agenda, 'rsvp_required' => $e->rsvp_required, 'going' => $e->going, 'my_rsvp' => $mine[$e->id] ?? null]);

        return response()->json(['data' => $rows->values()]);
    }

    public function createEvent(Request $request, Space $space): JsonResponse
    {
        $this->gate($space);
        $d = $request->validate(['title' => ['required', 'string', 'max:250'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'location' => ['nullable', 'string', 'max:250'],
            'online_url' => ['nullable', 'url:https', 'max:500'], 'agenda' => ['nullable', 'string', 'max:3000'], 'rsvp_required' => ['boolean']]);
        $e = $this->spaces->createEvent($space, $this->user(), $d);

        return response()->json(['data' => ['id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at->toIso8601String()]], 201);
    }

    public function rsvp(Request $request, SpaceEvent $spaceEvent): JsonResponse
    {
        $this->gate($spaceEvent->space);
        $d = $request->validate(['status' => ['required', Rule::in(['going', 'maybe', 'no'])]]);

        return response()->json(['data' => ['status' => $this->spaces->rsvp($spaceEvent, $this->user(), $d['status'])->status]]);
    }
}
