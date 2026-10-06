<?php

namespace App\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\CourseLesson;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Space;
use App\Models\SpaceEvent;
use App\Models\SpaceEventRsvp;
use App\Models\SpaceMember;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Communities, forums and channels. A community is made by someone and joined by others; forums of programs and groups, the trainers' channel and lesson
 * threads are created by the platform and their members follow registrations, so nobody has to add people by hand.
 */
class SpaceService
{
    public const AUTO = ['program_forum', 'group_forum', 'trainers_channel', 'lesson_thread'];

    public function __construct(private readonly NotificationService $notifications) {}

    // ---- communities ---------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $d */
    public function create(User $by, array $d): Space
    {
        return DB::transaction(function () use ($by, $d) {
            $space = Space::create([
                'type' => 'community', 'title_ar' => $d['title_ar'], 'title_en' => $d['title_en'], 'description_ar' => $d['description_ar'] ?? null, 'description_en' => $d['description_en'] ?? null,
                'visibility' => $d['visibility'] ?? 'members', 'join_policy' => $d['join_policy'] ?? 'open', 'settings' => $this->settings($d['settings'] ?? []), 'created_by' => $by->id,
            ]);
            SpaceMember::create(['space_id' => $space->id, 'user_id' => $by->id, 'role' => 'owner', 'status' => 'active', 'source' => 'manual', 'joined_at' => now()]);

            return $space;
        });
    }

    /** @param  array<string, mixed>  $s @return array<string, bool> */
    public function settings(array $s): array
    {
        return ['allow_polls' => (bool) ($s['allow_polls'] ?? true), 'allow_files' => (bool) ($s['allow_files'] ?? true), 'moderation' => (bool) ($s['moderation'] ?? false), 'anonymous_qa' => (bool) ($s['anonymous_qa'] ?? false)];
    }

    // ---- platform spaces -----------------------------------------------------------------------------

    public function programForum(Program $program): Space
    {
        $space = Space::firstOrCreate(['type' => 'program_forum', 'subject_type' => 'program', 'subject_id' => $program->id], [
            'title_ar' => 'منتدى: '.$program->title_ar, 'title_en' => 'Forum: '.$program->title_en, 'visibility' => 'private', 'join_policy' => 'invite', 'settings' => $this->settings([]),
        ]);
        $this->syncMembers($space);

        return $space;
    }

    public function groupForum(TrainingGroup $group): Space
    {
        $group->loadMissing('program');
        $space = Space::firstOrCreate(['type' => 'group_forum', 'subject_type' => 'group', 'subject_id' => $group->id], [
            'title_ar' => 'منتدى المجموعة: '.$group->displayTitle('ar'), 'title_en' => 'Group forum: '.$group->displayTitle('en'), 'visibility' => 'private', 'join_policy' => 'invite', 'settings' => $this->settings([]),
        ]);
        $this->syncMembers($space);

        return $space;
    }

    public function trainersChannel(): Space
    {
        $space = Space::firstOrCreate(['type' => 'trainers_channel', 'subject_type' => null, 'subject_id' => null], [
            'title_ar' => 'قناة المدربين', 'title_en' => "Trainers' channel", 'visibility' => 'private', 'join_policy' => 'invite', 'settings' => $this->settings([]),
        ]);
        $this->syncMembers($space);

        return $space;
    }

    public function lessonThread(CourseLesson $lesson): Space
    {
        $space = Space::firstOrCreate(['type' => 'lesson_thread', 'subject_type' => 'lesson', 'subject_id' => $lesson->id], [
            'title_ar' => 'نقاش الدرس: '.$lesson->title_ar, 'title_en' => 'Lesson discussion: '.$lesson->title_en, 'visibility' => 'private', 'join_policy' => 'invite', 'settings' => $this->settings(['allow_polls' => false]),
        ]);
        $this->syncMembers($space);

        return $space;
    }

    /** Members of a platform space follow registrations (and the trainers and supervisor); people who no longer qualify are removed. */
    public function syncMembers(Space $space): void
    {
        $want = match ($space->type) {
            'program_forum', 'lesson_thread' => $this->programPeople($space->type === 'program_forum' ? $space->subject_id : CourseLesson::whereKey($space->subject_id)->value('program_id')),
            'group_forum' => $this->groupPeople($space->subject_id),
            'trainers_channel' => $this->trainerPeople(),
            default => null,
        };
        if ($want === null) {
            return;
        }
        $have = SpaceMember::where('space_id', $space->id)->where('source', 'auto')->get()->keyBy('user_id');
        foreach ($want as $uid => $role) {
            $m = $have->get($uid);
            if (! $m) {
                SpaceMember::updateOrCreate(['space_id' => $space->id, 'user_id' => $uid], ['role' => $role, 'status' => 'active', 'source' => 'auto', 'joined_at' => now()]);
            } elseif ($m->status !== 'banned' && $m->role !== $role) {
                $m->update(['role' => $role]);
            }
        }
        foreach ($have as $uid => $m) {
            if (! isset($want[$uid]) && $m->status !== 'banned') {
                $m->delete();   // not registered any more
            }
        }
    }

    /** @return array<string, string> user id => role */
    private function programPeople(?string $programId): array
    {
        $out = [];
        if (! $programId) {
            return $out;
        }
        foreach (Registration::with('employee')->where('program_id', $programId)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->get() as $r) {
            if ($r->employee?->user_id) {
                $out[$r->employee->user_id] = 'member';
            }
        }
        foreach (DB::table('group_trainers as gt')->join('training_groups as g', 'g.id', '=', 'gt.group_id')->join('trainers as t', 't.id', '=', 'gt.trainer_id')->where('gt.status', 'approved')->where('g.program_id', $programId)->whereNotNull('t.user_id')->pluck('t.user_id') as $uid) {
            $out[$uid] = 'moderator';
        }
        $prog = Program::find($programId);
        if ($prog?->coordinator_id) {
            $out[$prog->coordinator_id] = 'moderator';
        }

        return $out;
    }

    /** @return array<string, string> */
    private function groupPeople(?string $groupId): array
    {
        $out = [];
        $group = $groupId ? TrainingGroup::find($groupId) : null;
        if (! $group) {
            return $out;
        }
        foreach (Registration::with('employee')->where('training_group_id', $group->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])->get() as $r) {
            if ($r->employee?->user_id) {
                $out[$r->employee->user_id] = 'member';
            }
        }
        foreach (DB::table('group_trainers as gt')->join('trainers as t', 't.id', '=', 'gt.trainer_id')->where('gt.status', 'approved')->where('gt.group_id', $group->id)->whereNotNull('t.user_id')->pluck('t.user_id') as $uid) {
            $out[$uid] = 'moderator';
        }
        if ($group->supervisor_id && ($uid = DB::table('employees')->where('id', $group->supervisor_id)->value('user_id'))) {
            $out[$uid] = 'moderator';
        }

        return $out;
    }

    /** @return array<string, string> */
    private function trainerPeople(): array
    {
        $out = [];
        foreach (Trainer::where('status', 'active')->whereNotNull('user_id')->pluck('user_id') as $uid) {
            $out[$uid] = 'member';
        }
        foreach (User::where('status', 'active')->whereHas('roles', fn ($q) => $q->whereIn('slug', ['program_coordinator', 'training_head', 'center_admin']))->pluck('id') as $uid) {
            $out[$uid] = 'moderator';
        }

        return $out;
    }

    // ---- membership ----------------------------------------------------------------------------------

    public function join(Space $space, User $user): SpaceMember
    {
        $this->assertOpen($space);
        if ($space->type !== 'community') {
            throw new BusinessRuleException('Membership of this space follows registrations.', 'auto_membership');
        }
        $existing = SpaceMember::where(['space_id' => $space->id, 'user_id' => $user->id])->first();
        if ($existing?->status === 'banned') {
            throw new BusinessRuleException('You cannot join this community.', 'banned');
        }
        if ($existing) {
            return $existing;
        }
        if ($space->join_policy === 'invite') {
            throw new BusinessRuleException('This community is by invitation only.', 'invite_only');
        }
        $pending = $space->join_policy === 'request';
        $m = SpaceMember::create(['space_id' => $space->id, 'user_id' => $user->id, 'role' => 'member', 'status' => $pending ? 'pending' : 'active', 'source' => 'manual', 'joined_at' => $pending ? null : now()]);
        if ($pending) {
            foreach ($this->managers($space) as $uid) {
                $this->notifications->send($uid, 'space.join_request', ['ar' => 'طلب انضمام جديد', 'en' => 'New join request'], ['ar' => $user->displayName().' — '.$space->title_ar, 'en' => $user->displayName().' — '.$space->title_en], ['space_id' => $space->id, 'route' => '/communities/'.$space->id], raw: true);
            }
        }

        return $m;
    }

    public function leave(Space $space, User $user): void
    {
        $m = SpaceMember::where(['space_id' => $space->id, 'user_id' => $user->id])->first();
        if ($m && $m->role === 'owner' && SpaceMember::where('space_id', $space->id)->where('role', 'owner')->count() <= 1) {
            throw new BusinessRuleException('Hand the community to another owner first.', 'last_owner');
        }
        if ($m && $m->source === 'manual' && $m->status !== 'banned') {
            $m->delete();
        }
    }

    /** An owner or manager adds someone, accepts or refuses a request, changes a role, or bans. @param  array{role?: string, status?: string}  $d */
    public function updateMember(Space $space, User $target, array $d, User $by): SpaceMember
    {
        $this->assertManager($space, $by);
        $m = SpaceMember::firstOrNew(['space_id' => $space->id, 'user_id' => $target->id]);
        if (isset($d['role']) && in_array($d['role'], ['owner', 'manager', 'moderator', 'member'], true)) {
            if ($d['role'] === 'owner' && $this->role($space, $by) !== 'owner' && ! $this->staff($space, $by)) {
                throw new BusinessRuleException('Only an owner can make another owner.', 'forbidden_role');
            }
            $m->role = $d['role'];
        }
        if (isset($d['status']) && in_array($d['status'], ['active', 'pending', 'banned'], true)) {
            $m->status = $d['status'];
            if ($d['status'] === 'active' && ! $m->joined_at) {
                $m->joined_at = now();
            }
        }
        if (! $m->exists) {
            $m->fill(['role' => $m->role ?: 'member', 'status' => $m->status ?: 'active', 'source' => 'manual', 'joined_at' => now()]);
        }
        $m->save();
        if ($m->wasChanged('status') && $m->status === 'active') {
            $this->notifications->send($target, 'space.joined', ['ar' => 'أصبحت عضوًا في '.$space->title_ar, 'en' => 'You are now a member of '.$space->title_en], null, ['space_id' => $space->id, 'route' => '/communities/'.$space->id], raw: true);
        }

        return $m;
    }

    public function removeMember(Space $space, User $target, User $by): void
    {
        $this->assertManager($space, $by);
        $m = SpaceMember::where(['space_id' => $space->id, 'user_id' => $target->id])->first();
        if ($m?->role === 'owner' && SpaceMember::where('space_id', $space->id)->where('role', 'owner')->count() <= 1) {
            throw new BusinessRuleException('A community needs an owner.', 'last_owner');
        }
        $m?->delete();
    }

    // ---- access --------------------------------------------------------------------------------------

    /** Staff who may moderate every space of a kind, whether or not they are members. */
    public function staff(Space $space, User $user): bool
    {
        return $user->hasPermission($space->type === 'community' ? 'communities.moderate' : 'forums.moderate');
    }

    public function role(Space $space, User $user): ?string
    {
        $m = SpaceMember::where(['space_id' => $space->id, 'user_id' => $user->id])->first();

        return $m && $m->status === 'active' ? $m->role : null;
    }

    public function member(Space $space, User $user): ?SpaceMember
    {
        return SpaceMember::where(['space_id' => $space->id, 'user_id' => $user->id])->first();
    }

    /** Whether the person may read what is inside the space. */
    public function canRead(Space $space, User $user): bool
    {
        if ($this->staff($space, $user) || $this->role($space, $user) !== null) {
            return true;
        }

        return $space->type === 'community' && $space->visibility === 'public_in_scope' && ! $space->archived_at && $this->member($space, $user)?->status !== 'banned';
    }

    public function canPost(Space $space, User $user): bool
    {
        return ! $space->archived_at && ($this->role($space, $user) !== null || ($this->staff($space, $user) && $space->type !== 'community'));
    }

    public function canModerate(Space $space, User $user): bool
    {
        return $this->staff($space, $user) || in_array($this->role($space, $user), ['owner', 'manager', 'moderator'], true);
    }

    public function canManage(Space $space, User $user): bool
    {
        return $this->staff($space, $user) || in_array($this->role($space, $user), ['owner', 'manager'], true);
    }

    public function assertManager(Space $space, User $user): void
    {
        if (! $this->canManage($space, $user)) {
            abort(403);
        }
    }

    /** @return list<string> */
    public function managers(Space $space): array
    {
        return SpaceMember::where('space_id', $space->id)->where('status', 'active')->whereIn('role', ['owner', 'manager'])->pluck('user_id')->all();
    }

    private function assertOpen(Space $space): void
    {
        if ($space->archived_at) {
            throw new BusinessRuleException('This space is archived.', 'archived');
        }
    }

    /** Spaces the person can see in lists: theirs, public ones to discover, and — for staff — the ones they moderate. @return Builder<Space> */
    public function visibleTo(User $user): Builder
    {
        $mine = SpaceMember::where('user_id', $user->id)->where('status', '!=', 'banned')->select('space_id');

        return Space::query()->whereNull('archived_at')->where(function ($q) use ($user, $mine) {
            $q->whereIn('id', $mine)->orWhere(fn ($w) => $w->where('type', 'community')->whereIn('visibility', ['public_in_scope', 'members']));
            if ($user->hasPermission('forums.moderate')) {
                $q->orWhere('type', '!=', 'community');
            }
            if ($user->hasPermission('communities.moderate')) {
                $q->orWhere('type', 'community');
            }
        });
    }

    public function archive(Space $space, User $by): Space
    {
        $this->assertManager($space, $by);
        $space->update(['archived_at' => now()]);

        return $space;
    }

    // ---- events --------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $d */
    public function createEvent(Space $space, User $by, array $d): SpaceEvent
    {
        if (! $this->canModerate($space, $by)) {
            abort(403);
        }
        $event = SpaceEvent::create(['space_id' => $space->id, 'title' => $d['title'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'] ?? null, 'location' => $d['location'] ?? null, 'online_url' => $d['online_url'] ?? null,
            'agenda' => $d['agenda'] ?? null, 'rsvp_required' => (bool) ($d['rsvp_required'] ?? false), 'created_by' => $by->id]);
        $ids = SpaceMember::where('space_id', $space->id)->where('status', 'active')->where('user_id', '!=', $by->id)->where('notify', '!=', 'none')->pluck('user_id');
        $this->notifications->broadcast($ids, 'space.event_scheduled', ['ar' => 'فعالية جديدة: '.$event->title, 'en' => 'New event: '.$event->title], ['ar' => $space->title_ar, 'en' => $space->title_en], ['space_id' => $space->id, 'route' => '/communities/'.$space->id], raw: true);

        return $event;
    }

    public function rsvp(SpaceEvent $event, User $user, string $status): SpaceEventRsvp
    {
        $space = $event->space;
        if (! $this->canPost($space, $user) && ! $this->canRead($space, $user)) {
            abort(403);
        }

        return SpaceEventRsvp::updateOrCreate(['event_id' => $event->id, 'user_id' => $user->id], ['status' => in_array($status, ['going', 'maybe', 'no'], true) ? $status : 'going']);
    }

    /** A reminder a day before, once per event. */
    public function remindEvents(): int
    {
        $n = 0;
        SpaceEvent::with('space')->whereNull('reminded_at')->whereBetween('starts_at', [now(), now()->addHours(24)])->get()->each(function (SpaceEvent $e) use (&$n) {
            $ids = SpaceEventRsvp::where('event_id', $e->id)->whereIn('status', ['going', 'maybe'])->pluck('user_id');
            if ($ids->isEmpty() && ! $e->rsvp_required) {
                $ids = SpaceMember::where('space_id', $e->space_id)->where('status', 'active')->where('notify', 'all')->pluck('user_id');
            }
            $n += $this->notifications->broadcast($ids, 'space.event_reminder', ['ar' => 'تذكير: '.$e->title, 'en' => 'Reminder: '.$e->title], ['ar' => 'تبدأ '.$e->starts_at->format('Y-m-d H:i'), 'en' => 'Starts '.$e->starts_at->format('Y-m-d H:i')], ['space_id' => $e->space_id, 'route' => '/communities/'.$e->space_id], raw: true);
            $e->update(['reminded_at' => now()]);
        });

        return $n;
    }
}
