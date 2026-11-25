<?php

namespace App\Gamification;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Employee;
use App\Models\GamificationProfile;
use App\Models\GamificationRule;
use App\Models\LeaderboardSnapshot;
use App\Models\Level;
use App\Models\PointLedger;
use App\Models\Registration;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\FeatureSettings;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Points, levels, badges, challenges, rewards and leaderboards. Points come from a ledger of rules: each event is paid once per source, within the caps,
 * and a reversal is another line in the ledger, so a person's total is always the sum of what is written down.
 */
class GamificationService
{
    public function __construct(private readonly NotificationService $notifications, private readonly GamificationSettings $settings, private readonly FeatureSettings $features) {}

    public function enabled(): bool
    {
        return $this->features->enabled('gamification');
    }

    /** Whether this person earns points for this thing (flag, role, program). */
    public function earns(User $user, ?string $programId = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $s = $this->settings->all();
        if ($programId && in_array($programId, $s['disabled_programs'], true)) {
            return false;
        }

        return ! $user->roles()->whereIn('slug', $s['disabled_roles'])->exists() || $user->roles()->whereNotIn('slug', $s['disabled_roles'])->exists();
    }

    /**
     * Pay a rule once per source. Returns the points added (0 when nothing was due).
     *
     * @param  array<string, mixed>  $ctx  value (score etc.) for rules with a threshold, program_id for per-program switches
     */
    public function award(User|string $user, string $event, ?string $sourceType = null, ?string $sourceId = null, array $ctx = []): int
    {
        $user = $user instanceof User ? $user : User::find($user);
        if (! $user || ! $this->earns($user, $ctx['program_id'] ?? null)) {
            return 0;
        }
        $rule = GamificationRule::where('event', $event)->where('is_active', true)->first();
        if (! $rule) {
            return 0;
        }
        $threshold = $rule->conditions['threshold'] ?? null;
        if ($threshold !== null && (float) ($ctx['value'] ?? 0) < (float) $threshold) {
            return 0;
        }
        $points = (int) ($ctx['points'] ?? $rule->points);

        return DB::transaction(function () use ($user, $event, $sourceType, $sourceId, $rule, $points) {
            if ($sourceId && PointLedger::where(['user_id' => $user->id, 'event' => $event, 'source_type' => $sourceType, 'source_id' => $sourceId])->where('points', '>', -1)->exists()) {
                return 0;   // paid already
            }
            foreach (['day' => now()->startOfDay(), 'week' => now()->startOfWeek()] as $k => $since) {
                $cap = $rule->caps[$k] ?? null;
                if ($cap && PointLedger::where(['user_id' => $user->id, 'event' => $event])->where('created_at', '>=', $since)->where('points', '>', -1)->count() >= $cap) {
                    return 0;
                }
            }
            $before = $this->level($user)->level_no;
            PointLedger::create(['user_id' => $user->id, 'event' => $event, 'points' => $points, 'source_type' => $sourceType, 'source_id' => $sourceId, 'created_at' => now()]);
            $this->afterAward($user, $event, $before);

            return $points;
        });
    }

    private function afterAward(User $user, string $event, int $levelBefore): void
    {
        $this->evaluateBadges($user, $event);
        $this->advanceChallenges($user, $event);
        $after = $this->level($user);
        if ($after->level_no > $levelBefore) {
            $this->notifications->send($user, 'gamification.level_up', ['ar' => 'ارتقيت إلى المستوى '.$after->level_no.': '.$after->name_ar, 'en' => 'You reached level '.$after->level_no.': '.$after->name_en], null, ['route' => '/portal/achievements'], raw: true);
        }
    }

    /** Take back what a source paid (a lesson re-opened, a post deleted): written as a negative line, never by erasing. */
    public function reverse(User|string $user, string $event, string $sourceType, string $sourceId): int
    {
        $id = $user instanceof User ? $user->id : $user;
        $paid = (int) PointLedger::where(['user_id' => $id, 'event' => $event, 'source_type' => $sourceType, 'source_id' => $sourceId])->sum('points');
        if ($paid <= 0) {
            return 0;
        }
        PointLedger::create(['user_id' => $id, 'event' => $event, 'points' => -$paid, 'source_type' => $sourceType, 'source_id' => $sourceId, 'note' => 'reversal', 'created_at' => now()]);

        return $paid;
    }

    /** A person with the right permission adds or removes points, with the reason kept in the audit trail. */
    public function adjust(User $target, int $points, string $note, User $by): PointLedger
    {
        if ($points === 0 || abs($points) > 10000) {
            throw new BusinessRuleException('Enter a number of points between 1 and 10000.', 'bad_points');
        }
        if (trim($note) === '') {
            throw new BusinessRuleException('A reason is required.', 'reason_required');
        }
        $row = PointLedger::create(['user_id' => $target->id, 'event' => 'manual_adjustment', 'points' => $points, 'note' => Str::limit(strip_tags($note), 250, ''), 'created_by' => $by->id, 'created_at' => now()]);
        AuditLog::create(['user_id' => $by->id, 'action' => 'points_adjusted', 'auditable_type' => User::class, 'auditable_id' => $target->id, 'new_values' => ['points' => $points, 'note' => $row->note]]);
        $this->evaluateBadges($target, 'manual_adjustment');

        return $row;
    }

    // ---- balances ------------------------------------------------------------------------------------

    public function total(User|string $user): int
    {
        return (int) PointLedger::where('user_id', $user instanceof User ? $user->id : $user)->sum('points');
    }

    /** Points earned (not spent on rewards) decide the level, so buying a reward never drops anyone a level. */
    public function earned(User|string $user): int
    {
        return (int) PointLedger::where('user_id', $user instanceof User ? $user->id : $user)->where('event', '!=', 'reward_redeemed')->sum('points');
    }

    public function level(User $user): Level
    {
        $pts = $this->earned($user);

        return Level::where('min_points', '<=', $pts)->orderByDesc('min_points')->first() ?? Level::orderBy('min_points')->first() ?? new Level(['level_no' => 1, 'name_ar' => 'مبتدئ', 'name_en' => 'Newcomer', 'min_points' => 0]);
    }

    /** @return array<string, mixed> */
    public function profile(User $user): array
    {
        $earned = $this->earned($user);
        $level = $this->level($user);
        $next = Level::where('min_points', '>', $level->min_points)->orderBy('min_points')->first();
        $profile = GamificationProfile::find($user->id);

        return [
            'enabled' => $this->enabled(), 'points' => $this->total($user), 'earned' => $earned,
            'level' => ['no' => $level->level_no, 'name_ar' => $level->name_ar, 'name_en' => $level->name_en, 'min_points' => $level->min_points],
            'next_level' => $next ? ['no' => $next->level_no, 'name_ar' => $next->name_ar, 'name_en' => $next->name_en, 'points_needed' => max(0, $next->min_points - $earned), 'min_points' => $next->min_points] : null,
            'hidden' => (bool) $profile?->hidden,
            'badges' => UserBadge::with('badge')->where('user_id', $user->id)->orderByDesc('awarded_at')->get()->map(fn (UserBadge $b) => $this->badgeRow($b->badge, $b->awarded_at))->all(),
            'recent' => PointLedger::where('user_id', $user->id)->orderByDesc('created_at')->limit(15)->get(['event', 'points', 'note', 'created_at']),
        ];
    }

    /** @return array<string, mixed> */
    public function badgeRow(?Badge $b, $at = null): array
    {
        return ['id' => $b?->id, 'code' => $b?->code, 'name_ar' => $b?->name_ar, 'name_en' => $b?->name_en, 'description_ar' => $b?->description_ar, 'description_en' => $b?->description_en, 'tier' => $b?->tier, 'icon_svg' => $b?->icon_svg, 'awarded_at' => $at?->toIso8601String()];
    }

    public function setHidden(User $user, bool $hidden): void
    {
        GamificationProfile::updateOrCreate(['user_id' => $user->id], ['hidden' => $hidden]);
    }

    // ---- badges --------------------------------------------------------------------------------------

    public function evaluateBadges(User $user, string $event): void
    {
        $have = UserBadge::where('user_id', $user->id)->pluck('badge_id')->all();
        Badge::where('is_active', true)->whereNotIn('id', $have ?: ['00000000-0000-0000-0000-000000000000'])->get()->each(function (Badge $b) use ($user, $event) {
            $c = $b->criteria;
            if (($c['event'] ?? null) !== $event) {
                return;
            }
            $q = PointLedger::where(['user_id' => $user->id, 'event' => $event])->where('points', '>', 0);
            if (! empty($c['within_days'])) {
                $q->where('created_at', '>=', now()->subDays((int) $c['within_days']));
            }
            if ($q->count() >= (int) ($c['count'] ?? 1)) {
                $this->grantBadge($user, $b, 'rule');
            }
        });
    }

    public function grantBadge(User $user, Badge $badge, string $source = 'manual'): ?UserBadge
    {
        $ub = UserBadge::firstOrCreate(['user_id' => $user->id, 'badge_id' => $badge->id], ['source' => $source, 'awarded_at' => now()]);
        if ($ub->wasRecentlyCreated) {
            $this->notifications->send($user, 'gamification.badge', ['ar' => 'شارة جديدة: '.$badge->name_ar, 'en' => 'New badge: '.$badge->name_en], null, ['badge_id' => $badge->id, 'route' => '/portal/achievements'], raw: true);

            return $ub;
        }

        return null;
    }

    // ---- challenges ----------------------------------------------------------------------------------

    /** Whether the person is in the audience of a challenge (schools and roles; empty = everyone). */
    public function inAudience(User $user, Challenge $c): bool
    {
        $a = $c->audience ?? [];
        if (! empty($a['schools'])) {
            $school = Employee::where('user_id', $user->id)->value('school_id');
            if (! $school || ! in_array($school, $a['schools'], true)) {
                return false;
            }
        }
        if (! empty($a['roles']) && ! $user->roles()->whereIn('slug', $a['roles'])->exists()) {
            return false;
        }

        return true;
    }

    public function joinChallenge(User $user, Challenge $c): ChallengeParticipant
    {
        if (! $c->is_active || $c->closed_at || $c->ends_at->isPast() || $c->starts_at->isFuture()) {
            throw new BusinessRuleException('This challenge is not open.', 'challenge_closed');
        }
        if (! $this->inAudience($user, $c)) {
            abort(403);
        }

        return ChallengeParticipant::firstOrCreate(['challenge_id' => $c->id, 'user_id' => $user->id], ['progress' => 0]);
    }

    private function advanceChallenges(User $user, string $event): void
    {
        ChallengeParticipant::with('challenge')->where('user_id', $user->id)->whereNull('completed_at')->get()->each(function (ChallengeParticipant $p) use ($user, $event) {
            $c = $p->challenge;
            if (! $c || ! $c->is_active || $c->closed_at || now()->lt($c->starts_at) || now()->gt($c->ends_at) || ($c->goal['event'] ?? null) !== $event) {
                return;
            }
            $p->increment('progress');
            if ($p->progress >= (int) ($c->goal['count'] ?? 1)) {
                $p->update(['completed_at' => now()]);
                $this->reward($user, $c);
            }
        });
    }

    private function reward(User $user, Challenge $c): void
    {
        $r = $c->reward ?? [];
        if (! empty($r['points'])) {
            PointLedger::create(['user_id' => $user->id, 'event' => 'challenge_completed', 'points' => (int) $r['points'], 'source_type' => 'challenge', 'source_id' => $c->id, 'created_at' => now()]);
        }
        if (! empty($r['badge_code']) && ($b = Badge::where('code', $r['badge_code'])->first())) {
            $this->grantBadge($user, $b, 'challenge');
        }
        $this->notifications->send($user, 'gamification.challenge_done', ['ar' => 'أنجزت التحدي: '.$c->title_ar, 'en' => 'Challenge complete: '.$c->title_en], null, ['challenge_id' => $c->id, 'route' => '/portal/achievements'], raw: true);
    }

    /** Challenges past their end are closed; the people who did not finish are left as they are. */
    public function closeEnded(): int
    {
        return Challenge::whereNull('closed_at')->where('ends_at', '<', now())->update(['closed_at' => now()]);
    }

    // ---- rewards -------------------------------------------------------------------------------------

    public function redeem(User $user, Reward $reward): RewardRedemption
    {
        return DB::transaction(function () use ($user, $reward) {
            $reward = Reward::whereKey($reward->id)->lockForUpdate()->firstOrFail();
            if (! $reward->is_active) {
                throw new BusinessRuleException('This reward is not available.', 'reward_inactive');
            }
            if ($reward->stock !== null && $reward->stock <= 0) {
                throw new BusinessRuleException('This reward is out of stock.', 'out_of_stock');
            }
            if ($this->level($user)->level_no < $reward->min_level) {
                throw new BusinessRuleException('Reach level '.$reward->min_level.' to unlock this reward.', 'level_required');
            }
            if ($this->total($user) < $reward->cost_points) {
                throw new BusinessRuleException('You do not have enough points.', 'not_enough_points');
            }
            PointLedger::create(['user_id' => $user->id, 'event' => 'reward_redeemed', 'points' => -$reward->cost_points, 'source_type' => 'reward', 'source_id' => $reward->id, 'created_at' => now()]);
            if ($reward->stock !== null) {
                $reward->decrement('stock');
            }
            $red = RewardRedemption::create(['reward_id' => $reward->id, 'user_id' => $user->id, 'points' => $reward->cost_points, 'code' => strtoupper(Str::random(10)), 'status' => 'granted']);
            $this->notifications->send($user, 'gamification.reward', ['ar' => 'استبدلت مكافأة: '.$reward->title_ar, 'en' => 'Reward redeemed: '.$reward->title_en], null, ['redemption_id' => $red->id, 'route' => '/portal/achievements'], raw: true);

            return $red;
        });
    }

    public function cancelRedemption(RewardRedemption $red, User $by): RewardRedemption
    {
        if ($red->status === 'cancelled') {
            return $red;
        }
        DB::transaction(function () use ($red, $by) {
            $red->update(['status' => 'cancelled']);
            PointLedger::create(['user_id' => $red->user_id, 'event' => 'reward_redeemed', 'points' => $red->points, 'source_type' => 'reward', 'source_id' => $red->reward_id, 'note' => 'refund', 'created_by' => $by->id, 'created_at' => now()]);
            Reward::whereKey($red->reward_id)->whereNotNull('stock')->increment('stock');
        });

        return $red;
    }

    // ---- leaderboards --------------------------------------------------------------------------------

    /**
     * @param  'week'|'month'|'term'  $period
     * @param  'ministry'|'school'|'program'|'region'  $scope
     * @return array{rows: list<array<string, mixed>>, me: array<string, mixed>|null}
     */
    public function leaderboard(string $period, string $scope, ?string $scopeId, ?User $viewer = null): array
    {
        $since = $this->periodStart($period);
        $q = PointLedger::query()->where('point_ledger.created_at', '>=', $since)->where('point_ledger.points', '>', 0)->where('point_ledger.event', '!=', 'reward_redeemed')
            ->whereNotIn('point_ledger.user_id', GamificationProfile::where('hidden', true)->select('user_id'));
        if ($scope === 'school' && $scopeId) {
            $q->whereIn('point_ledger.user_id', Employee::where('school_id', $scopeId)->whereNotNull('user_id')->select('user_id'));
        } elseif ($scope === 'region' && $scopeId) {
            $q->whereIn('point_ledger.user_id', Employee::whereIn('school_id', DB::table('schools')->where('region', $scopeId)->select('id'))->whereNotNull('user_id')->select('user_id'));
        } elseif ($scope === 'program' && $scopeId && Str::isUuid($scopeId)) {
            $q->whereIn('point_ledger.user_id', Employee::whereIn('id', Registration::where('program_id', $scopeId)->select('employee_id'))->whereNotNull('user_id')->select('user_id'));
        }
        $size = (int) $this->settings->all()['leaderboard_size'];
        $totals = $q->selectRaw('point_ledger.user_id, sum(point_ledger.points) as pts')->groupBy('point_ledger.user_id')->orderByDesc('pts')->orderBy('point_ledger.user_id')->limit(500)->get();

        return $this->shape($totals, $size, $viewer);
    }

    /** @param  Collection<int, mixed>  $totals */
    private function shape(Collection $totals, int $size, ?User $viewer): array
    {
        $rank = 0;
        $prev = null;
        $ranked = $totals->values()->map(function ($t, $i) use (&$rank, &$prev) {
            if ($prev === null || (int) $t->pts !== $prev) {
                $rank = $i + 1;
                $prev = (int) $t->pts;
            }

            return ['user_id' => $t->user_id, 'points' => (int) $t->pts, 'rank' => $rank];
        });
        $show = $this->settings->all()['show_names'];
        $top = $ranked->take($size);
        $users = User::whereIn('id', $top->pluck('user_id'))->get()->keyBy('id');
        $rows = $top->map(fn ($r) => ['rank' => $r['rank'], 'points' => $r['points'], 'user_id' => $r['user_id'], 'name' => $show || $r['user_id'] === $viewer?->id ? ($users[$r['user_id']]?->displayName() ?? '') : null, 'me' => $r['user_id'] === $viewer?->id])->values()->all();
        $mine = $viewer ? $ranked->firstWhere('user_id', $viewer->id) : null;

        return ['rows' => $rows, 'me' => $mine ? ['rank' => $mine['rank'], 'points' => $mine['points']] : null];
    }

    public function periodStart(string $period): Carbon
    {
        return match ($period) {
            'week' => now()->startOfWeek(), 'month' => now()->startOfMonth(), default => now()->month >= 9 ? now()->startOfYear()->month(9)->startOfMonth() : now()->subYear()->startOfYear()->month(9)->startOfMonth(),
        };
    }

    /** Weekly record of the ministry-wide and per-school boards, for history and the "last week" view. */
    public function snapshot(): int
    {
        $n = 0;
        foreach (['week', 'month'] as $period) {
            $start = $this->periodStart($period)->toDateString();
            $board = $this->leaderboard($period, 'ministry', null);
            LeaderboardSnapshot::updateOrCreate(['period' => $period, 'scope' => 'ministry', 'scope_id' => null, 'period_start' => $start], ['ranks' => array_map(fn ($r) => ['user_id' => $r['user_id'], 'points' => $r['points'], 'rank' => $r['rank']], $board['rows'])]);
            $n++;
        }

        return $n;
    }
}
