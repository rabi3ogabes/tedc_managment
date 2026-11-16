<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Exceptions\BusinessRuleException;
use App\Gamification\Defaults;
use App\Gamification\GamificationService;
use App\Gamification\GamificationSettings;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\GamificationRule;
use App\Models\Level;
use App\Models\PointLedger;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\User;
use App\Models\UserBadge;
use App\Support\SvgGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The studio: rules, levels, badges, challenges, rewards, manual adjustments and a view of participation. */
class GamificationAdminController extends Controller
{
    public function __construct(private readonly GamificationService $game, private readonly GamificationSettings $settings) {}

    public function overview(): JsonResponse
    {
        Defaults::seed();
        $since = now()->subDays(30);
        $active = PointLedger::where('created_at', '>=', $since)->where('points', '>', 0)->distinct()->count('user_id');

        return response()->json(['data' => [
            'rules' => GamificationRule::orderBy('event')->get(), 'levels' => Level::orderBy('level_no')->get(), 'badges' => Badge::orderBy('code')->get()->map(fn (Badge $b) => $b->toArray() + ['awarded' => UserBadge::where('badge_id', $b->id)->count()]),
            'challenges' => Challenge::orderByDesc('starts_at')->limit(50)->get()->map(fn (Challenge $c) => $c->toArray() + ['participants' => $c->participants()->count(), 'completed' => $c->participants()->whereNotNull('completed_at')->count()]),
            'rewards' => Reward::orderBy('cost_points')->get()->map(fn (Reward $r) => $r->toArray() + ['redeemed' => RewardRedemption::where('reward_id', $r->id)->where('status', '!=', 'cancelled')->count()]),
            'settings' => $this->settings->all(), 'stats' => ['active_30d' => $active, 'points_30d' => (int) PointLedger::where('created_at', '>=', $since)->where('points', '>', 0)->sum('points'), 'badges_awarded' => UserBadge::count(), 'redemptions' => RewardRedemption::where('status', '!=', 'cancelled')->count()],
        ]]);
    }

    public function saveRule(Request $request, string $event): JsonResponse
    {
        $d = $request->validate(['points' => ['required', 'integer', 'between:0,1000'], 'is_active' => ['boolean'], 'caps' => ['nullable', 'array'], 'caps.day' => ['nullable', 'integer', 'between:1,1000'], 'caps.week' => ['nullable', 'integer', 'between:1,5000'], 'conditions' => ['nullable', 'array'], 'conditions.threshold' => ['nullable', 'numeric', 'between:0,100']]);
        $rule = GamificationRule::where('event', $event)->firstOrFail();
        $rule->update(['points' => $d['points'], 'is_active' => $d['is_active'] ?? $rule->is_active, 'caps' => array_filter($d['caps'] ?? []) ?: null, 'conditions' => array_filter($d['conditions'] ?? [], fn ($v) => $v !== null) ?: null]);

        return response()->json(['data' => $rule]);
    }

    public function saveLevels(Request $request): JsonResponse
    {
        $d = $request->validate(['levels' => ['required', 'array', 'min:1', 'max:20'], 'levels.*.level_no' => ['required', 'integer', 'between:1,50', 'distinct'], 'levels.*.name_ar' => ['required', 'string', 'max:80'], 'levels.*.name_en' => ['required', 'string', 'max:80'], 'levels.*.min_points' => ['required', 'integer', 'min:0']]);
        $sorted = collect($d['levels'])->sortBy('level_no')->values();
        if ($sorted->first()['min_points'] !== 0 || $sorted->pluck('min_points')->sort()->values()->all() !== $sorted->pluck('min_points')->all() || $sorted->pluck('min_points')->unique()->count() !== $sorted->count()) {
            throw new BusinessRuleException('Level 1 starts at 0 points and every level needs more points than the one before.', 'bad_levels');
        }
        foreach ($sorted as $l) {
            Level::updateOrCreate(['level_no' => $l['level_no']], ['name_ar' => $l['name_ar'], 'name_en' => $l['name_en'], 'min_points' => $l['min_points']]);
        }
        Level::whereNotIn('level_no', $sorted->pluck('level_no'))->delete();

        return response()->json(['data' => Level::orderBy('level_no')->get()]);
    }

    /** @return array<string, mixed> */
    private function badgeRules(): array
    {
        return ['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'], 'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120'], 'description_ar' => ['nullable', 'string', 'max:500'], 'description_en' => ['nullable', 'string', 'max:500'],
            'icon_svg' => ['nullable', 'string', 'max:100000'], 'tier' => ['required', Rule::in(['bronze', 'silver', 'gold'])], 'criteria' => ['required', 'array'], 'criteria.event' => ['required', 'string', 'max:32', Rule::exists('gamification_rules', 'event')], 'criteria.count' => ['required', 'integer', 'between:1,10000'],
            'criteria.within_days' => ['nullable', 'integer', 'between:1,365'], 'is_active' => ['boolean']];
    }

    public function saveBadge(Request $request, ?Badge $badge = null): JsonResponse
    {
        $rules = $this->badgeRules();
        if ($badge) {
            $rules['code'] = ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('badges', 'code')->ignore($badge->id)];
        } else {
            $rules['code'][] = 'unique:badges,code';
        }
        $d = $request->validate($rules);
        if (! empty($d['icon_svg']) && ! SvgGuard::isSafe($d['icon_svg'])) {
            throw new BusinessRuleException('This icon is not a plain SVG drawing.', 'unsafe_svg');
        }
        $d['icon_svg'] = $d['icon_svg'] ?? Defaults::icon($d['tier']);
        $d['criteria'] = array_filter($d['criteria'], fn ($v) => $v !== null);
        $badge = $badge ? tap($badge)->update($d) : Badge::create($d);

        return response()->json(['data' => $badge], $badge->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteBadge(Badge $badge): JsonResponse
    {
        $badge->delete();

        return response()->json(['message' => 'ok']);
    }

    public function awardBadge(Request $request, Badge $badge): JsonResponse
    {
        $d = $request->validate(['user_id' => ['required', 'uuid', 'exists:users,id']]);
        $this->game->grantBadge(User::findOrFail($d['user_id']), $badge, 'manual');

        return response()->json(['message' => 'ok']);
    }

    public function adjust(Request $request): JsonResponse
    {
        $d = $request->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'points' => ['required', 'integer', 'not_in:0'], 'note' => ['required', 'string', 'max:250']]);
        $row = $this->game->adjust(User::findOrFail($d['user_id']), (int) $d['points'], $d['note'], $this->user());

        return response()->json(['data' => ['id' => $row->id, 'points' => $row->points, 'balance' => $this->game->total($d['user_id'])]], 201);
    }

    /** @return array<string, mixed> */
    private function challengeRules(): array
    {
        return ['title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'audience' => ['nullable', 'array'], 'audience.schools' => ['nullable', 'array'], 'audience.schools.*' => ['uuid'], 'audience.roles' => ['nullable', 'array'], 'audience.roles.*' => ['string', 'max:60'], 'goal' => ['required', 'array'], 'goal.event' => ['required', 'string', Rule::exists('gamification_rules', 'event')],
            'goal.count' => ['required', 'integer', 'between:1,10000'], 'reward' => ['nullable', 'array'], 'reward.points' => ['nullable', 'integer', 'between:0,5000'], 'reward.badge_code' => ['nullable', 'string', 'max:40'], 'reward.text' => ['nullable', 'string', 'max:300'], 'type' => ['nullable', Rule::in(['individual', 'school'])], 'is_active' => ['boolean']];
    }

    public function saveChallenge(Request $request, ?Challenge $challenge = null): JsonResponse
    {
        $d = $request->validate($this->challengeRules());
        $challenge = $challenge ? tap($challenge)->update($d) : Challenge::create($d);

        return response()->json(['data' => $challenge], $challenge->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteChallenge(Challenge $challenge): JsonResponse
    {
        if ($challenge->participants()->exists()) {
            $challenge->update(['is_active' => false, 'closed_at' => now()]);
        } else {
            $challenge->delete();
        }

        return response()->json(['message' => 'ok']);
    }

    public function saveReward(Request $request, ?Reward $reward = null): JsonResponse
    {
        $d = $request->validate(['title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['required', 'string', 'max:200'], 'description_ar' => ['nullable', 'string', 'max:1000'], 'description_en' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', Rule::in(['certificate', 'content', 'voucher'])], 'cost_points' => ['required', 'integer', 'between:1,1000000'], 'min_level' => ['nullable', 'integer', 'between:1,50'], 'stock' => ['nullable', 'integer', 'min:0'], 'is_active' => ['boolean']]);
        $d['min_level'] ??= 1;
        $reward = $reward ? tap($reward)->update($d) : Reward::create($d);

        return response()->json(['data' => $reward], $reward->wasRecentlyCreated ? 201 : 200);
    }

    public function redemptions(Request $request): JsonResponse
    {
        $rows = RewardRedemption::with('reward:id,title_ar,title_en', 'user:id,name,name_ar')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (RewardRedemption $x) => ['id' => $x->id, 'user' => $x->user?->displayName(), 'reward' => $x->reward?->title_en, 'reward_ar' => $x->reward?->title_ar, 'points' => $x->points, 'code' => $x->code, 'status' => $x->status, 'created_at' => $x->created_at->toIso8601String()])->values()]);
    }

    public function fulfil(Request $request, RewardRedemption $redemption): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(['fulfilled', 'cancelled'])]]);
        if ($d['status'] === 'cancelled') {
            $this->game->cancelRedemption($redemption, $this->user());
        } else {
            $redemption->update(['status' => 'fulfilled']);
        }

        return response()->json(['data' => ['status' => $redemption->refresh()->status]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['disabled_roles' => ['array'], 'disabled_roles.*' => ['string', 'max:60'], 'disabled_programs' => ['array'], 'disabled_programs.*' => ['uuid'], 'show_names' => ['boolean'], 'leaderboard_size' => ['integer', 'between:5,100']]);

        return response()->json(['data' => $this->settings->save($d, $this->user()->id)]);
    }

    /** A name search for picking the person to adjust. */
    public function people(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($q) < 2, 422);
        $like = '%'.mb_strtolower($q).'%';
        $rows = User::where('status', 'active')->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw("lower(coalesce(name_ar, '')) like ?", [$like])->orWhereRaw('lower(email) like ?', [$like]))->limit(15)->get(['id', 'name', 'name_ar', 'email']);

        return response()->json(['data' => $rows->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email])]);
    }

    /** A person's ledger, for answering "why do I have these points?". */
    public function ledger(User $user): JsonResponse
    {
        return response()->json(['data' => PointLedger::where('user_id', $user->id)->orderByDesc('created_at')->limit(200)->get(['id', 'event', 'points', 'source_type', 'note', 'created_by', 'created_at']), 'balance' => $this->game->total($user)]);
    }
}
