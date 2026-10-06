<?php

namespace Tests\Feature;

use App\Gamification\Defaults;
use App\Gamification\GamificationService;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\GamificationRule;
use App\Models\LessonProgress;
use App\Models\Level;
use App\Models\PointLedger;
use App\Models\Registration;
use App\Models\Reward;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class GamificationTest extends TestCase
{
    private User $admin;

    private GamificationService $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        Defaults::seed();
        $this->game = app(GamificationService::class);
    }

    private function on(): void
    {
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/gamification', ['enabled' => true, 'reason' => 'test'])->assertOk();
        $this->game = app(GamificationService::class);
    }

    public function test_nothing_is_earned_while_the_flag_is_off_and_defaults_are_seeded(): void
    {
        $u = $this->makeUser();
        $this->assertSame(0, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'x1'));
        $this->assertSame(0, PointLedger::count());
        $this->assertGreaterThanOrEqual(10, GamificationRule::count());
        $this->assertSame(6, Level::count());
        $this->assertGreaterThanOrEqual(6, Badge::count());
        $this->asUser($u)->getJson('/api/v1/gamification/me')->assertForbidden();
    }

    public function test_a_rule_pays_once_per_source_and_respects_daily_caps(): void
    {
        $this->on();
        $u = $this->makeUser();
        $this->assertSame(10, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'p1'));
        $this->assertSame(0, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'p1'));   // the same source never pays twice
        $this->assertSame(10, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'p2'));
        $this->assertSame(20, $this->game->total($u));

        // post_created is capped at three a day.
        $paid = array_map(fn ($i) => $this->game->award($u, 'post_created', 'post', "post{$i}"), range(1, 5));
        $this->assertSame([5, 5, 5, 0, 0], $paid);

        // A threshold stops a low score earning the points.
        GamificationRule::where('event', 'assessment_passed')->update(['conditions' => ['threshold' => 80]]);
        $this->assertSame(0, $this->game->award($u, 'assessment_passed', 'attempt', 'a1', ['value' => 70]));
        $this->assertSame(20, $this->game->award($u, 'assessment_passed', 'attempt', 'a2', ['value' => 85]));

        GamificationRule::where('event', 'lesson_completed')->update(['is_active' => false]);
        $this->assertSame(0, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'p9'));
    }

    public function test_reversal_is_a_negative_line_and_the_total_is_always_the_ledger_sum(): void
    {
        $this->on();
        $u = $this->makeUser();
        $this->game->award($u, 'post_created', 'post', 'p1');
        $this->assertSame(5, $this->game->reverse($u, 'post_created', 'post', 'p1'));
        $this->assertSame(0, $this->game->reverse($u, 'post_created', 'post', 'p1'));   // already taken back
        $this->assertSame(0, $this->game->total($u));
        $this->assertSame(2, PointLedger::where('user_id', $u->id)->count());
    }

    public function test_levels_rise_with_earned_points_and_notify(): void
    {
        $this->on();
        $u = $this->makeUser();
        $this->assertSame(1, $this->game->level($u)->level_no);
        $this->game->award($u, 'course_completed', 'registration', 'r1', ['points' => 120]);
        $this->assertSame(2, $this->game->level($u)->level_no);
        $this->assertSame(1, AppNotification::where('user_id', $u->id)->where('type', 'gamification.level_up')->count());
        $p = $this->asUser($u)->getJson('/api/v1/gamification/me')->assertOk()->json('data');
        $this->assertSame(2, $p['level']['no']);
        $this->assertSame(180, $p['next_level']['points_needed']);   // level 3 starts at 300
    }

    public function test_badges_come_from_rules_once_and_icons_must_be_safe(): void
    {
        $this->on();
        $u = $this->makeUser();
        $this->game->award($u, 'lesson_completed', 'lesson_progress', 'l1');
        $this->assertSame(['first_lesson'], UserBadge::with('badge')->where('user_id', $u->id)->get()->pluck('badge.code')->all());
        $this->assertSame(1, AppNotification::where('user_id', $u->id)->where('type', 'gamification.badge')->count());
        $this->game->award($u, 'lesson_completed', 'lesson_progress', 'l2');
        $this->assertSame(1, UserBadge::where('user_id', $u->id)->count());

        $bad = ['code' => 'evil', 'name_ar' => 'ش', 'name_en' => 'Evil', 'tier' => 'gold', 'criteria' => ['event' => 'lesson_completed', 'count' => 1], 'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/badges', $bad)->assertStatus(422);
        $ok = $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/badges', ['icon_svg' => null] + $bad)->assertCreated()->json('data.id');
        $this->assertStringContainsString('<svg', Badge::find($ok)->icon_svg);
        $this->asUser($u)->postJson('/api/v1/gamification/admin/badges', $bad)->assertForbidden();
    }

    public function test_manual_adjustments_need_a_reason_and_are_audited(): void
    {
        $this->on();
        $u = $this->makeUser();
        $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/adjust', ['user_id' => $u->id, 'points' => 50, 'note' => ''])->assertStatus(422);
        $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/adjust', ['user_id' => $u->id, 'points' => 50, 'note' => 'Event volunteer'])->assertCreated()->assertJsonPath('data.balance', 50);
        $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/adjust', ['user_id' => $u->id, 'points' => -20, 'note' => 'Correction'])->assertCreated()->assertJsonPath('data.balance', 30);
        $this->assertSame(2, AuditLog::where('action', 'points_adjusted')->where('auditable_id', $u->id)->count());
        $this->asUser($u)->postJson('/api/v1/gamification/admin/adjust', ['user_id' => $u->id, 'points' => 500, 'note' => 'me'])->assertForbidden();
        $this->asUser($this->admin)->getJson("/api/v1/gamification/admin/users/{$u->id}/ledger")->assertOk()->assertJsonPath('balance', 30)->assertJsonCount(2, 'data');
    }

    public function test_challenges_track_progress_reward_on_completion_and_close(): void
    {
        $this->on();
        $u = $this->makeUser();
        $c = $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/challenges', ['title_ar' => 'تحدي', 'title_en' => 'Learn 2', 'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addWeek()->toIso8601String(),
            'goal' => ['event' => 'lesson_completed', 'count' => 2], 'reward' => ['points' => 100, 'badge_code' => 'ten_lessons']])->assertCreated()->json('data.id');
        $this->asUser($u)->postJson("/api/v1/gamification/challenges/{$c}/join")->assertCreated();
        $this->game->award($u, 'lesson_completed', 'lesson_progress', 'c1');
        $this->assertSame(1, ChallengeParticipant::where('user_id', $u->id)->value('progress'));
        $this->game->award($u, 'lesson_completed', 'lesson_progress', 'c2');
        $this->assertNotNull(ChallengeParticipant::where('user_id', $u->id)->value('completed_at'));
        $this->assertSame(100, (int) PointLedger::where(['user_id' => $u->id, 'event' => 'challenge_completed'])->sum('points'));
        $this->assertNotNull(UserBadge::where('user_id', $u->id)->whereHas('badge', fn ($q) => $q->where('code', 'ten_lessons'))->first());
        $this->assertSame(1, AppNotification::where('user_id', $u->id)->where('type', 'gamification.challenge_done')->count());

        // Not counted before joining, nor after the end.
        $late = $this->makeUser();
        $this->game->award($late, 'lesson_completed', 'lesson_progress', 'z1');
        $this->assertSame(0, ChallengeParticipant::where('user_id', $late->id)->count());
        Challenge::whereKey($c)->update(['ends_at' => now()->subMinute()]);
        $this->asUser($late)->postJson("/api/v1/gamification/challenges/{$c}/join")->assertStatus(422);
        $this->assertSame(1, $this->game->closeEnded());
    }

    public function test_a_challenge_can_be_limited_to_a_school(): void
    {
        $this->on();
        $in = $this->makeEmployee();
        $out = $this->makeEmployee();
        $ch = Challenge::create(['title_ar' => 'ت', 'title_en' => 'School only', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'goal' => ['event' => 'post_created', 'count' => 1], 'audience' => ['schools' => [$in->school_id]]]);
        $this->asUser($in->user)->postJson("/api/v1/gamification/challenges/{$ch->id}/join")->assertCreated();
        $this->asUser($out->user)->postJson("/api/v1/gamification/challenges/{$ch->id}/join")->assertForbidden();
        $this->assertCount(0, $this->asUser($out->user)->getJson('/api/v1/gamification/challenges')->json('data'));
    }

    public function test_rewards_need_points_level_and_stock_and_refunds_return_both(): void
    {
        $this->on();
        $u = $this->makeUser();
        $r = $this->asUser($this->admin)->postJson('/api/v1/gamification/admin/rewards', ['title_ar' => 'قسيمة', 'title_en' => 'Voucher', 'kind' => 'voucher', 'cost_points' => 100, 'min_level' => 2, 'stock' => 1])->assertCreated()->json('data.id');
        $this->asUser($u)->postJson("/api/v1/gamification/rewards/{$r}/redeem")->assertStatus(422);       // no points
        $this->game->award($u, 'course_completed', 'registration', 'r1', ['points' => 90]);
        $this->asUser($u)->postJson("/api/v1/gamification/rewards/{$r}/redeem")->assertStatus(422);       // not enough
        $this->game->award($u, 'course_completed', 'registration', 'r2', ['points' => 50]);                 // 140 earned: level 2
        $red = $this->asUser($u)->postJson("/api/v1/gamification/rewards/{$r}/redeem")->assertCreated()->assertJsonPath('data.points_left', 40)->json('data.id');
        $this->assertSame(2, $this->game->level($u)->level_no);                                              // spending never drops a level
        $this->assertSame(0, Reward::find($r)->stock);
        $this->asUser($u)->postJson("/api/v1/gamification/rewards/{$r}/redeem")->assertStatus(422);       // out of stock

        $this->asUser($this->admin)->postJson("/api/v1/gamification/admin/redemptions/{$red}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(140, $this->game->total($u));
        $this->assertSame(1, Reward::find($r)->stock);
        $this->asUser($this->admin)->postJson("/api/v1/gamification/admin/redemptions/{$red}/status", ['status' => 'cancelled'])->assertOk();   // not refunded twice
        $this->assertSame(140, $this->game->total($u));
        $this->asUser($u)->postJson('/api/v1/gamification/admin/rewards', ['title_ar' => 'x', 'title_en' => 'x', 'kind' => 'voucher', 'cost_points' => 1])->assertForbidden();
    }

    public function test_leaderboards_rank_ties_equally_hide_opted_out_people_and_scope_to_school(): void
    {
        $this->on();
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $c = $this->makeEmployee();
        $this->game->award($a->user, 'course_completed', 'registration', 'a', ['points' => 50]);
        $this->game->award($b->user, 'course_completed', 'registration', 'b', ['points' => 50]);
        $this->game->award($c->user, 'course_completed', 'registration', 'c', ['points' => 20]);
        $board = $this->asUser($c->user)->getJson('/api/v1/gamification/leaderboard?period=month&scope=ministry')->assertOk()->json('data');
        $this->assertSame([1, 1, 3], array_column($board['rows'], 'rank'));
        $this->assertSame(3, $board['me']['rank']);

        $this->asUser($b->user)->putJson('/api/v1/gamification/me/privacy', ['hidden' => true])->assertOk();
        $board = $this->asUser($a->user)->getJson('/api/v1/gamification/leaderboard?period=month&scope=ministry')->json('data');
        $this->assertSame(2, count($board['rows']));
        $this->assertNull($this->asUser($b->user)->getJson('/api/v1/gamification/leaderboard?scope=ministry')->json('data.me'));

        $school = $this->asUser($a->user)->getJson('/api/v1/gamification/leaderboard?scope=school')->assertOk()->json('data');
        $this->assertCount(1, $school['rows']);   // just this person's own school
        $this->asUser($a->user)->getJson('/api/v1/gamification/leaderboard?scope=school&scope_id=not-a-uuid')->assertStatus(422);
        $this->assertSame(2, $this->game->snapshot());
    }

    public function test_it_can_be_switched_off_for_a_program_or_a_role(): void
    {
        $this->on();
        $program = $this->makeProgram();
        $u = $this->makeUser(Role::EMPLOYEE);
        $this->asUser($this->admin)->putJson('/api/v1/gamification/admin/settings', ['disabled_programs' => [$program->id]])->assertOk();
        $this->assertSame(0, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'd1', ['program_id' => $program->id]));
        $this->assertSame(10, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'd2', ['program_id' => $this->makeProgram()->id]));
        $this->asUser($this->admin)->putJson('/api/v1/gamification/admin/settings', ['disabled_roles' => [Role::EMPLOYEE]])->assertOk();
        $this->assertSame(0, $this->game->award($u, 'lesson_completed', 'lesson_progress', 'd3'));
    }

    public function test_real_activity_pays_through_the_listeners(): void
    {
        $this->on();
        $emp = $this->makeEmployee();
        $program = $this->makeProgram();
        $reg = Registration::create(['program_id' => $program->id, 'employee_id' => $emp->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'M', 'sort_order' => 0]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'د', 'title_en' => 'L', 'sort_order' => 0, 'status' => 'published']);
        $p = LessonProgress::create(['lesson_id' => $lesson->id, 'lesson_version' => 1, 'registration_id' => $reg->id, 'employee_id' => $emp->id, 'status' => 'in_progress', 'percent' => 50]);
        $p->update(['status' => 'completed', 'percent' => 100, 'completed_at' => now()]);
        $this->assertSame(10, $this->game->total($emp->user));
        $reg->update(['status' => Registration::STATUS_COMPLETED]);
        $this->assertSame(60, $this->game->total($emp->user));

        // Signing in once a day pays once a day.
        $emp->user->update(['password' => 'Secret#12345']);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $emp->user->email, 'password' => 'Secret#12345'])->assertOk();
        }
        $this->assertSame(1, PointLedger::where(['user_id' => $emp->user->id, 'event' => 'daily_login'])->count());
    }
}
