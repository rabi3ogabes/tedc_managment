<?php

namespace Tests\Feature;

use App\Models\AbuseReport;
use App\Models\AppNotification;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\CourseQuestion;
use App\Models\GroupTrainer;
use App\Models\Post;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Social\CourseSocial;
use App\Social\DailyDigest;
use App\Social\SocialSettings;
use App\Social\SpaceService;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CollaborationTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        foreach (['plc', 'forums'] as $flag) {
            $this->asUser($this->admin)->putJson("/api/v1/admin/features/{$flag}", ['enabled' => true, 'reason' => 'test'])->assertOk();
        }
    }

    private function trainerUser(string $groupId): User
    {
        $u = $this->makeUser(Role::TRAINER);
        $t = Trainer::create(['user_id' => $u->id, 'name_ar' => 'م', 'name_en' => 'T', 'status' => 'active', 'source' => 'center']);
        GroupTrainer::create(['group_id' => $groupId, 'trainer_id' => $t->id, 'status' => 'approved']);

        return $u;
    }

    /** @return array{0: Program, 1: TrainingGroup, 2: User, 3: User} program, group, trainee user, trainer user */
    private function course(): array
    {
        $program = $this->makeProgram();
        $group = TrainingGroup::where('program_id', $program->id)->first() ?? TrainingGroup::create(['program_id' => $program->id, 'code' => 'G1', 'title_ar' => 'م', 'title_en' => 'G', 'sequence' => 1, 'delivery_mode' => 'online', 'start_date' => now()->addDay(), 'end_date' => now()->addDays(5), 'capacity' => 20, 'status' => 'published']);
        $trainee = $this->makeUser();
        Registration::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'employee_id' => $this->makeEmployee([], $trainee)->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        return [$program, $group, $trainee, $this->trainerUser($group->id)];
    }

    private function community(User $owner, array $over = []): string
    {
        return $this->asUser($owner)->postJson('/api/v1/social/spaces', $over + ['title_ar' => 'مجتمع', 'title_en' => 'Maths teachers'])->assertCreated()->json('data.id');
    }

    public function test_flags_keep_everything_off_by_default(): void
    {
        $off = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/plc', ['enabled' => false, 'reason' => 'test'])->assertOk();
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/forums', ['enabled' => false, 'reason' => 'test'])->assertOk();
        $this->asUser($off)->getJson('/api/v1/social/spaces')->assertForbidden();
        $this->asUser($off)->postJson('/api/v1/social/spaces', ['title_ar' => 'م', 'title_en' => 'C'])->assertForbidden();
        $this->asUser($off)->getJson('/api/v1/gamification/me')->assertForbidden();
    }

    public function test_only_people_with_the_permission_create_communities_and_the_creator_owns_it(): void
    {
        $employee = $this->makeUser();
        $this->asUser($employee)->postJson('/api/v1/social/spaces', ['title_ar' => 'م', 'title_en' => 'C'])->assertForbidden();
        $trainer = $this->makeUser(Role::TRAINER);
        $id = $this->community($trainer);
        $this->asUser($trainer)->getJson("/api/v1/social/spaces/{$id}")->assertOk()->assertJsonPath('data.my_role', 'owner')->assertJsonPath('data.can_manage', true);
        $this->assertSame(1, SpaceMember::where('space_id', $id)->count());
    }

    public function test_join_policies_open_request_and_invite(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $a = $this->makeUser();
        $open = $this->community($owner, ['visibility' => 'public_in_scope', 'join_policy' => 'open']);
        $this->asUser($a)->postJson("/api/v1/social/spaces/{$open}/join")->assertOk()->assertJsonPath('status', 'active');

        $req = $this->community($owner, ['visibility' => 'members', 'join_policy' => 'request']);
        $this->asUser($a)->postJson("/api/v1/social/spaces/{$req}/join")->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame(1, AppNotification::where('user_id', $owner->id)->where('type', 'space.join_request')->count());
        $this->asUser($a)->getJson("/api/v1/social/spaces/{$req}/posts")->assertForbidden();   // pending is not membership
        $this->asUser($owner)->putJson("/api/v1/social/spaces/{$req}/members/{$a->id}", ['status' => 'active'])->assertOk();
        $this->asUser($a)->getJson("/api/v1/social/spaces/{$req}/posts")->assertOk();

        $inv = $this->community($owner, ['join_policy' => 'invite']);
        $this->asUser($a)->postJson("/api/v1/social/spaces/{$inv}/join")->assertStatus(422);
        $this->asUser($a)->putJson("/api/v1/social/spaces/{$inv}/members/{$a->id}", ['role' => 'moderator'])->assertForbidden();   // members cannot promote themselves

        $this->asUser($owner)->putJson("/api/v1/social/spaces/{$open}/members/{$a->id}", ['status' => 'banned'])->assertOk();
        $this->asUser($a)->postJson("/api/v1/social/spaces/{$open}/posts", ['body' => 'hi'])->assertForbidden();
        $this->asUser($a)->postJson("/api/v1/social/spaces/{$open}/join")->assertStatus(422);   // banned people cannot rejoin
    }

    public function test_the_last_owner_cannot_leave_and_private_spaces_stay_private(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $id = $this->community($owner, ['visibility' => 'private']);
        $this->asUser($owner)->deleteJson("/api/v1/social/spaces/{$id}/leave")->assertStatus(422);
        $stranger = $this->makeUser();
        $this->asUser($stranger)->getJson("/api/v1/social/spaces/{$id}")->assertForbidden();
        $this->asUser($stranger)->getJson('/api/v1/social/spaces')->assertOk();
        $this->assertSame(0, collect($this->asUser($stranger)->getJson('/api/v1/social/spaces')->json('data'))->where('id', $id)->count() === 0 ? 0 : 1);
        $this->asUser($this->admin)->getJson("/api/v1/social/spaces/{$id}")->assertOk();   // staff with the moderation permission see it
    }

    public function test_posts_are_sanitised_polled_pinned_locked_and_edited_with_history(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $m = $this->makeUser();
        $id = $this->community($owner, ['join_policy' => 'open']);
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/join")->assertOk();

        $r = $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/posts", ['title' => 'Hi', 'body' => '<p onclick="x()">Hello <script>alert(1)</script><b>there</b></p>'])->assertCreated();
        $this->assertStringNotContainsString('script', $r->json('data.body'));
        $this->assertStringNotContainsString('onclick', $r->json('data.body'));
        $pid = $r->json('data.id');

        $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/posts", ['body' => '  <p></p> '])->assertStatus(422);
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/posts", ['kind' => 'announcement', 'body' => 'x'])->assertForbidden();   // announcements are for moderators

        $this->asUser($m)->patchJson("/api/v1/social/posts/{$pid}", ['body' => 'Second version'])->assertOk();
        $this->assertCount(1, Post::find($pid)->edits);
        $this->asUser($owner)->postJson("/api/v1/social/posts/{$pid}/moderate", ['action' => 'pin'])->assertOk()->assertJsonPath('data.is_pinned', true);
        $this->asUser($m)->postJson("/api/v1/social/posts/{$pid}/moderate", ['action' => 'pin'])->assertForbidden();

        $poll = $this->asUser($owner)->postJson("/api/v1/social/spaces/{$id}/posts", ['kind' => 'poll', 'title' => 'When?', 'body' => 'Pick', 'poll' => ['question' => 'When?', 'options' => ['Sun', 'Mon', 'Tue'], 'multiple' => false]])->assertCreated();
        $pollId = $poll->json('data.poll.id');
        $this->asUser($m)->postJson("/api/v1/social/polls/{$pollId}/vote", ['option_ids' => ['o2']])->assertOk()->assertJsonPath('data.total_voters', 1);
        $this->asUser($m)->postJson("/api/v1/social/polls/{$pollId}/vote", ['option_ids' => ['o1', 'o2']])->assertStatus(422);   // single choice
        $this->asUser($m)->postJson("/api/v1/social/polls/{$pollId}/vote", ['option_ids' => ['o3']])->assertOk();               // a vote can be changed, never doubled
        $this->assertSame(1, $this->asUser($m)->getJson("/api/v1/social/posts/{$poll->json('data.id')}")->json('data.poll.total_voters'));
        $this->asUser($owner)->postJson("/api/v1/social/spaces/{$id}/posts", ['kind' => 'poll', 'body' => 'x', 'poll' => ['options' => ['only one']]])->assertStatus(422);

        $this->asUser($owner)->postJson("/api/v1/social/posts/{$pid}/moderate", ['action' => 'lock'])->assertOk();
        $this->asUser($m)->postJson("/api/v1/social/posts/{$pid}/comments", ['body' => 'late'])->assertStatus(422);
        $this->asUser($owner)->postJson("/api/v1/social/posts/{$pid}/comments", ['body' => 'moderators may still reply'])->assertCreated();
    }

    public function test_comments_reactions_mentions_and_accepted_answers(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $asker = $this->makeUser(Role::EMPLOYEE, ['name' => 'Asker One', 'email' => 'asker@moe.test']);
        $helper = $this->makeUser(Role::EMPLOYEE, ['name' => 'Helper Two', 'email' => 'helper@moe.test']);
        $id = $this->community($owner);
        foreach ([$asker, $helper] as $u) {
            $this->asUser($u)->postJson("/api/v1/social/spaces/{$id}/join")->assertOk();
        }
        $pid = $this->asUser($asker)->postJson("/api/v1/social/spaces/{$id}/posts", ['kind' => 'question', 'title' => 'How?', 'body' => 'How do I do it?'])->assertCreated()->json('data.id');
        $c = $this->asUser($helper)->postJson("/api/v1/social/posts/{$pid}/comments", ['body' => 'Like this @owner'])->assertCreated()->json('data.id');
        $this->assertSame(1, AppNotification::where('user_id', $asker->id)->where('type', 'space.comment')->count());
        $reply = $this->asUser($asker)->postJson("/api/v1/social/posts/{$pid}/comments", ['body' => 'thanks', 'parent_id' => $c])->assertCreated()->json('data.parent_id');
        $this->assertSame($c, $reply);

        $this->asUser($helper)->postJson("/api/v1/social/posts/{$pid}/accept", ['comment_id' => $c])->assertForbidden();   // only the asker or a moderator
        $this->asUser($asker)->postJson("/api/v1/social/posts/{$pid}/accept", ['comment_id' => $c])->assertOk()->assertJsonPath('data.accepted_answer_id', $c);
        $this->asUser($asker)->getJson("/api/v1/social/posts/{$pid}")->assertOk()->assertJsonPath('data.comments.0.accepted', true);

        $this->asUser($asker)->postJson('/api/v1/social/reactions', ['target_type' => 'comment', 'target_id' => $c, 'type' => 'thanks'])->assertOk()->assertJsonPath('data.state', 'added');
        $this->asUser($asker)->postJson('/api/v1/social/reactions', ['target_type' => 'comment', 'target_id' => $c, 'type' => 'thanks'])->assertOk()->assertJsonPath('data.state', 'removed');
        $this->asUser($this->makeUser())->postJson('/api/v1/social/reactions', ['target_type' => 'post', 'target_id' => $pid])->assertForbidden();   // not a member
    }

    public function test_reports_reach_moderators_who_can_hide_warn_or_ban(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $bad = $this->makeUser();
        $watcher = $this->makeUser();
        $id = $this->community($owner);
        foreach ([$bad, $watcher] as $u) {
            $this->asUser($u)->postJson("/api/v1/social/spaces/{$id}/join")->assertOk();
        }
        $pid = $this->asUser($bad)->postJson("/api/v1/social/spaces/{$id}/posts", ['body' => 'buy cheap stuff'])->assertCreated()->json('data.id');
        $this->asUser($watcher)->postJson('/api/v1/social/reports', ['target_type' => 'post', 'target_id' => $pid, 'reason' => 'spam'])->assertCreated();
        $this->asUser($watcher)->postJson('/api/v1/social/reports', ['target_type' => 'post', 'target_id' => $pid, 'reason' => 'spam'])->assertCreated();   // the same report is not doubled
        $this->assertSame(1, AbuseReport::count());
        $this->assertSame(1, AppNotification::where('user_id', $owner->id)->where('type', 'space.abuse_report')->count());

        $this->asUser($watcher)->getJson("/api/v1/social/spaces/{$id}/reports")->assertForbidden();
        $rid = $this->asUser($owner)->getJson("/api/v1/social/spaces/{$id}/reports")->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->asUser($watcher)->postJson("/api/v1/social/reports/{$rid}/resolve", ['action' => 'ban'])->assertForbidden();
        $this->asUser($owner)->postJson("/api/v1/social/reports/{$rid}/resolve", ['action' => 'ban'])->assertOk()->assertJsonPath('data.status', 'actioned');
        $this->assertSame('hidden', Post::find($pid)->status);
        $this->assertSame('banned', SpaceMember::where(['space_id' => $id, 'user_id' => $bad->id])->value('status'));
    }

    public function test_a_moderated_community_holds_posts_until_a_moderator_publishes_them(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $m = $this->makeUser();
        $id = $this->community($owner, ['settings' => ['moderation' => true]]);
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/join")->assertOk();
        $pid = $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/posts", ['body' => 'please check'])->assertCreated()->assertJsonPath('data.status', 'hidden')->json('data.id');
        $this->assertSame(0, Space::find($id)->posts_count);
        $other = $this->makeUser();
        $this->asUser($other)->postJson("/api/v1/social/spaces/{$id}/join")->assertOk();
        $this->assertCount(0, $this->asUser($other)->getJson("/api/v1/social/spaces/{$id}/posts")->json('data'));
        $this->asUser($owner)->postJson("/api/v1/social/posts/{$pid}/moderate", ['action' => 'publish'])->assertOk();
        $this->assertCount(1, $this->asUser($other)->getJson("/api/v1/social/spaces/{$id}/posts")->json('data'));
        $this->assertSame(1, AppNotification::where('user_id', $m->id)->where('type', 'space.post_approved')->count());
    }

    public function test_people_are_rate_limited_when_posting_too_fast(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $id = $this->community($owner);
        for ($i = 0; $i < 12; $i++) {
            $this->asUser($owner)->postJson("/api/v1/social/spaces/{$id}/posts", ['body' => "post {$i}"])->assertCreated();
        }
        $this->asUser($owner)->postJson("/api/v1/social/spaces/{$id}/posts", ['body' => 'one too many'])->assertStatus(422);
        Cache::flush();
    }

    public function test_program_and_group_forums_follow_registrations_and_the_trainers_moderate(): void
    {
        [$program, $group, $trainee, $trainer] = $this->course();
        $outsider = $this->makeUser();

        $this->asUser($outsider)->getJson("/api/v1/social/spaces/resolve?type=program&id={$program->id}")->assertForbidden();
        $space = $this->asUser($trainee)->getJson("/api/v1/social/spaces/resolve?type=program&id={$program->id}")->assertOk()->assertJsonPath('data.my_role', 'member')->json('data');
        $this->assertSame('program_forum', $space['type']);
        $this->assertSame('moderator', SpaceMember::where(['space_id' => $space['id'], 'user_id' => $trainer->id])->value('role'));
        $this->assertFalse($space['can_moderate']);
        $this->asUser($trainee)->postJson("/api/v1/social/spaces/{$space['id']}/join")->assertStatus(422);   // membership follows registrations
        $this->asUser($trainer)->getJson("/api/v1/social/spaces/resolve?type=group&id={$group->id}")->assertOk()->assertJsonPath('data.can_moderate', true);

        // A withdrawn trainee leaves the forum at the next sync.
        Registration::where('program_id', $program->id)->update(['status' => Registration::STATUS_WITHDRAWN]);
        app(SpaceService::class)->syncMembers(Space::find($space['id']));
        $this->assertSame(0, SpaceMember::where(['space_id' => $space['id'], 'user_id' => $trainee->id])->count());
    }

    public function test_the_trainers_channel_is_for_trainers_only_and_lesson_threads_for_the_course(): void
    {
        [$program, $group, $trainee, $trainer] = $this->course();
        $this->asUser($trainee)->getJson('/api/v1/social/spaces/resolve?type=trainers')->assertForbidden();
        $this->asUser($trainer)->getJson('/api/v1/social/spaces/resolve?type=trainers')->assertOk()->assertJsonPath('data.type', 'trainers_channel');

        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 0]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'د', 'title_en' => 'L', 'sort_order' => 0, 'status' => 'published']);
        $sid = $this->asUser($trainee)->getJson("/api/v1/social/spaces/resolve?type=lesson&id={$lesson->id}")->assertOk()->assertJsonPath('data.type', 'lesson_thread')->assertJsonPath('data.can_post', true)->json('data.id');
        $this->asUser($trainee)->postJson("/api/v1/social/spaces/{$sid}/posts", ['kind' => 'poll', 'body' => 'x', 'poll' => ['options' => ['a', 'b']]])->assertStatus(422);   // polls are off in lesson threads
        $this->asUser($this->makeUser())->getJson("/api/v1/social/spaces/resolve?type=lesson&id={$lesson->id}")->assertForbidden();
    }

    public function test_ask_the_trainer_routes_to_the_group_trainers_with_a_deadline_and_notifies_the_answer(): void
    {
        [$program, $group, $trainee, $trainer] = $this->course();
        $this->asUser($this->makeUser())->postJson('/api/v1/social/questions', ['program_id' => $program->id, 'subject' => 'x', 'body' => 'y'])->assertForbidden();

        $q = $this->asUser($trainee)->postJson('/api/v1/social/questions', ['program_id' => $program->id, 'subject' => 'Deadline?', 'body' => 'When is the task due?'])->assertCreated()->json('data');
        $this->assertSame('open', $q['status']);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, Carbon::parse($q['due_at'])->timestamp, 5);
        $this->assertSame(1, AppNotification::where('user_id', $trainer->id)->where('type', 'course.question')->count());
        $this->asUser($trainer)->getJson('/api/v1/social/trainer-inbox')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/social/trainer-inbox')->assertOk()->assertJsonCount(0, 'data');   // other trainers do not see it

        $this->asUser($trainee)->postJson("/api/v1/social/questions/{$q['id']}/answer", ['answer' => 'self'])->assertForbidden();
        $this->asUser($trainer)->postJson("/api/v1/social/questions/{$q['id']}/answer", ['answer' => 'Friday'])->assertOk()->assertJsonPath('data.status', 'answered');
        $this->assertSame(1, AppNotification::where('user_id', $trainee->id)->where('type', 'course.question_answered')->count());
        $this->asUser($trainee)->getJson('/api/v1/social/questions')->assertOk()->assertJsonPath('data.0.answer', 'Friday');
    }

    public function test_a_group_visible_question_becomes_a_forum_post_and_overdue_questions_are_chased_once(): void
    {
        [$program, $group, $trainee, $trainer] = $this->course();
        $q = $this->asUser($trainee)->postJson('/api/v1/social/questions', ['program_id' => $program->id, 'subject' => 'Shared', 'body' => 'for everyone', 'visibility' => 'group'])->assertCreated()->json('data');
        $this->assertNotNull(CourseQuestion::find($q['id'])->post_id);
        $this->asUser($trainer)->postJson("/api/v1/social/questions/{$q['id']}/answer", ['answer' => 'Here you go'])->assertOk();
        $this->assertSame(1, Post::find(CourseQuestion::find($q['id'])->post_id)->comments_count);

        $q2 = $this->asUser($trainee)->postJson('/api/v1/social/questions', ['program_id' => $program->id, 'subject' => 'Late', 'body' => 'unanswered'])->assertCreated()->json('data.id');
        CourseQuestion::whereKey($q2)->update(['due_at' => now()->subHour()]);
        $this->assertGreaterThan(0, app(CourseSocial::class)->chaseOverdue());
        $this->assertSame(0, app(CourseSocial::class)->chaseOverdue());   // once a day
        $this->assertSame(1, $this->asUser($trainer)->getJson('/api/v1/social/trainer-inbox?overdue=1')->json('data.0.overdue') ? 1 : 0);
    }

    public function test_notes_are_private_and_ratings_count_once_per_person(): void
    {
        [$program, $group, $trainee, $trainer] = $this->course();
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 0]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'د', 'title_en' => 'L', 'sort_order' => 0, 'status' => 'published']);

        $nid = $this->asUser($trainee)->postJson('/api/v1/social/lesson-notes', ['lesson_id' => $lesson->id, 'body' => 'remember <b>this</b>'])->assertCreated()->json('data.id');
        $this->asUser($trainee)->getJson("/api/v1/social/lesson-notes?lesson_id={$lesson->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'remember this');
        $this->asUser($trainer)->getJson("/api/v1/social/lesson-notes?lesson_id={$lesson->id}")->assertOk()->assertJsonCount(0, 'data');   // notes are the learner's alone
        $this->asUser($trainer)->deleteJson("/api/v1/social/lesson-notes/{$nid}")->assertForbidden();
        $this->asUser($this->makeUser())->postJson('/api/v1/social/lesson-notes', ['lesson_id' => $lesson->id, 'body' => 'x'])->assertForbidden();

        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'lesson', 'subject_id' => $lesson->id, 'stars' => 4, 'review' => 'Clear'])->assertCreated();
        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'lesson', 'subject_id' => $lesson->id, 'stars' => 5, 'review' => 'Great'])->assertCreated()->assertJsonPath('data.count', 1)->assertJsonPath('data.average', 5);
        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'lesson', 'subject_id' => $lesson->id, 'stars' => 6])->assertStatus(422);
        $this->asUser($this->makeUser())->postJson('/api/v1/social/ratings', ['subject_type' => 'lesson', 'subject_id' => $lesson->id, 'stars' => 3])->assertForbidden();

        $rid = ContentRating::first()->id;
        $this->asUser($trainee)->postJson("/api/v1/social/ratings/{$rid}/moderate", ['status' => 'hidden'])->assertForbidden();
        $this->asUser($this->admin)->postJson("/api/v1/social/ratings/{$rid}/moderate", ['status' => 'hidden'])->assertOk();
        $this->asUser($trainee)->getJson("/api/v1/social/ratings/lesson/{$lesson->id}")->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_events_remind_once_and_the_digest_is_one_message_a_day(): void
    {
        $owner = $this->makeUser(Role::TRAINER);
        $m = $this->makeUser();
        $id = $this->community($owner);
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/join")->assertOk();
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$id}/events", ['title' => 'Meetup', 'starts_at' => now()->addDay()->toIso8601String()])->assertForbidden();
        $e = $this->asUser($owner)->postJson("/api/v1/social/spaces/{$id}/events", ['title' => 'Meetup', 'starts_at' => now()->addHours(20)->toIso8601String(), 'rsvp_required' => true])->assertCreated()->json('data.id');
        $this->assertSame(1, AppNotification::where('user_id', $m->id)->where('type', 'space.event_scheduled')->count());
        $this->asUser($m)->postJson("/api/v1/social/space-events/{$e}/rsvp", ['status' => 'going'])->assertOk();
        $this->asUser($m)->getJson("/api/v1/social/spaces/{$id}/events")->assertOk()->assertJsonPath('data.0.my_rsvp', 'going')->assertJsonPath('data.0.going', 1);

        $this->assertSame(1, app(SpaceService::class)->remindEvents());
        $this->assertSame(0, app(SpaceService::class)->remindEvents());

        $this->asUser($owner)->postJson("/api/v1/social/spaces/{$id}/posts", ['body' => 'news'])->assertCreated();
        $this->assertGreaterThan(0, app(DailyDigest::class)->run(true));
        $this->assertSame(1, AppNotification::where('user_id', $m->id)->where('type', 'space.digest')->count());
    }

    public function test_settings_and_permissions_for_staff(): void
    {
        $this->asUser($this->makeUser())->getJson('/api/v1/social/settings')->assertForbidden();
        $this->asUser($this->admin)->putJson('/api/v1/social/settings', ['trainer_sla_hours' => 24])->assertOk()->assertJsonPath('data.trainer_sla_hours', 24);
        $this->assertSame(24, app(SocialSettings::class)->all()['trainer_sla_hours']);
        $this->asUser($this->admin)->putJson('/api/v1/social/settings', ['trainer_sla_hours' => 0])->assertStatus(422);
        $this->assertNull(Role::where('slug', Role::EMPLOYEE)->first()->permissions()->where('slug', 'communities.moderate')->first());
    }
}
