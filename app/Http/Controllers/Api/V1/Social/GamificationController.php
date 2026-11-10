<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Gamification\GamificationService;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Employee;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\UserBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** What the person sees: points and level, badges, leaderboards, open challenges and the rewards shop. */
class GamificationController extends Controller
{
    public function __construct(private readonly GamificationService $game) {}

    public function me(): JsonResponse
    {
        return response()->json(['data' => $this->game->profile($this->user())]);
    }

    public function privacy(Request $request): JsonResponse
    {
        $d = $request->validate(['hidden' => ['required', 'boolean']]);
        $this->game->setHidden($this->user(), $d['hidden']);

        return response()->json(['data' => ['hidden' => $d['hidden']]]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $d = $request->validate(['period' => ['nullable', Rule::in(['week', 'month', 'term'])], 'scope' => ['nullable', Rule::in(['ministry', 'school', 'program', 'region'])], 'scope_id' => ['nullable', 'string', 'max:120']]);
        $scope = $d['scope'] ?? 'ministry';
        $scopeId = $d['scope_id'] ?? null;
        if ($scope === 'school' && ! $scopeId) {
            $scopeId = Employee::where('user_id', $this->user()->id)->value('school_id');
        }
        if (in_array($scope, ['school', 'program'], true) && $scopeId && ! Str::isUuid($scopeId)) {
            abort(422);
        }

        return response()->json(['data' => $this->game->leaderboard($d['period'] ?? 'month', $scope, $scopeId, $this->user()) + ['period' => $d['period'] ?? 'month', 'scope' => $scope, 'scope_id' => $scopeId]]);
    }

    public function badges(): JsonResponse
    {
        $mine = UserBadge::where('user_id', $this->user()->id)->pluck('awarded_at', 'badge_id');

        return response()->json(['data' => Badge::where('is_active', true)->orderBy('tier')->orderBy('code')->get()->map(fn (Badge $b) => $this->game->badgeRow($b, $mine[$b->id] ?? null) + ['earned' => isset($mine[$b->id]), 'criteria' => $b->criteria])->values()]);
    }

    public function challenges(): JsonResponse
    {
        $user = $this->user();
        $mine = ChallengeParticipant::where('user_id', $user->id)->get()->keyBy('challenge_id');
        $rows = Challenge::where('is_active', true)->where(fn ($q) => $q->where('ends_at', '>=', now())->orWhereIn('id', $mine->keys()))->orderBy('ends_at')->limit(50)->get()
            ->filter(fn (Challenge $c) => $this->game->inAudience($user, $c) || isset($mine[$c->id]))
            ->map(fn (Challenge $c) => ['id' => $c->id, 'title_ar' => $c->title_ar, 'title_en' => $c->title_en, 'description_ar' => $c->description_ar, 'description_en' => $c->description_en, 'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(),
                'goal' => $c->goal, 'reward' => $c->reward, 'closed' => (bool) $c->closed_at, 'joined' => isset($mine[$c->id]), 'progress' => $mine[$c->id]->progress ?? 0, 'completed' => (bool) ($mine[$c->id]->completed_at ?? null), 'participants' => $c->participants()->count()]);

        return response()->json(['data' => $rows->values()]);
    }

    public function join(Challenge $challenge): JsonResponse
    {
        $this->game->joinChallenge($this->user(), $challenge);

        return response()->json(['message' => 'ok'], 201);
    }

    public function rewards(): JsonResponse
    {
        $user = $this->user();
        $level = $this->game->level($user)->level_no;
        $points = $this->game->total($user);
        $rows = Reward::where('is_active', true)->orderBy('cost_points')->get()->map(fn (Reward $r) => ['id' => $r->id, 'title_ar' => $r->title_ar, 'title_en' => $r->title_en, 'description_ar' => $r->description_ar, 'description_en' => $r->description_en, 'kind' => $r->kind,
            'cost_points' => $r->cost_points, 'min_level' => $r->min_level, 'in_stock' => $r->stock === null || $r->stock > 0, 'can_redeem' => ($r->stock === null || $r->stock > 0) && $level >= $r->min_level && $points >= $r->cost_points]);

        return response()->json(['data' => $rows->values(), 'points' => $points, 'level' => $level, 'redemptions' => RewardRedemption::with('reward:id,title_ar,title_en')->where('user_id', $user->id)->latest()->limit(20)->get()
            ->map(fn (RewardRedemption $x) => ['id' => $x->id, 'title_ar' => $x->reward?->title_ar, 'title_en' => $x->reward?->title_en, 'points' => $x->points, 'code' => $x->code, 'status' => $x->status, 'created_at' => $x->created_at->toIso8601String()])->values()]);
    }

    public function redeem(Reward $reward): JsonResponse
    {
        $x = $this->game->redeem($this->user(), $reward);

        return response()->json(['data' => ['id' => $x->id, 'code' => $x->code, 'points_left' => $this->game->total($this->user())]], 201);
    }
}
