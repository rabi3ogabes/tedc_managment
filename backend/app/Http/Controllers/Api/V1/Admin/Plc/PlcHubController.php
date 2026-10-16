<?php

namespace App\Http\Controllers\Api\V1\Admin\Plc;

use App\Http\Controllers\Controller;
use App\Models\PlcWorkshop;
use App\Models\School;
use App\Models\User;
use App\Services\Plc\CommunityService;
use App\Services\Plc\NeedsService;
use App\Services\Plc\PlcReports;
use App\Services\Plc\PlcSettings;
use App\Services\Plc\WorkshopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The PLC hub's overview (real counts inside the person's scope), lookups and settings. */
class PlcHubController extends Controller
{
    public function __construct(private readonly WorkshopService $workshops, private readonly NeedsService $needs, private readonly CommunityService $communities, private readonly PlcSettings $settings, private readonly PlcReports $reports) {}

    public function dashboard(): JsonResponse
    {
        $user = $this->user();
        $w = fn () => $this->workshops->query($user);
        $n = fn () => $this->needs->query($user);
        $byState = $w()->toBase()->selectRaw('workflow_state, count(*) as c')->groupBy('workflow_state')->pluck('c', 'workflow_state');
        $bySource = $w()->toBase()->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source');
        $byScenario = $w()->toBase()->selectRaw('scenario, count(*) as c')->groupBy('scenario')->pluck('c', 'scenario');
        $needsByStatus = $n()->toBase()->selectRaw('review_status, count(*) as c')->groupBy('review_status')->pluck('c', 'review_status');
        $needsBySource = $n()->toBase()->selectRaw('source, count(*) as c')->groupBy('source')->pluck('c', 'source');
        $pending = $w()->where('workflow_state', 'submitted')->get()->filter(function (PlcWorkshop $x) use ($user) {
            $step = collect($x->approval_path ?? [])->firstWhere('status', 'pending');

            return $step && $user->hasPermission($step['permission']);
        })->values();

        // The same figures the reports tab shows (Phase 7): attendance, evaluation, people and sessions, unfiltered, inside the scope.
        $kpis = $user->hasPermission('plc.reports') ? array_intersect_key($this->reports->build($user), array_flip(['attendance', 'evaluation', 'people', 'sessions', 'impact'])) : null;

        return response()->json(['data' => [
            'kpis' => $kpis,
            'communities' => $this->communities->query($user)->where('status', 'active')->count(),
            'workshops' => ['total' => array_sum($byState->all()), 'by_state' => $byState, 'by_source' => $bySource, 'by_scenario' => $byScenario],
            'needs' => ['total' => array_sum($needsByStatus->all()), 'by_status' => $needsByStatus, 'by_source' => $needsBySource, 'unconverted' => (int) (($needsByStatus['new'] ?? 0) + ($needsByStatus['under_review'] ?? 0))],
            'schools_involved' => $w()->toBase()->join('plc_workshop_schools as s', 's.program_id', '=', 'plc_workshops.program_id')->where('s.status', '!=', 'excluded')->distinct()->count('s.school_id'),
            'my_reviews' => $pending->map(fn (PlcWorkshop $x) => $this->workshops->summary($x))->all(),
            'rased_integration' => (bool) $this->settings->get('rased_integration'),
            'upcoming' => $w()->whereIn('workflow_state', ['approved', 'scheduled', 'ready'])->whereHas('program', fn ($p) => $p->whereDate('start_date', '>=', today()))->orderBy('updated_at')->limit(8)->get()->map(fn (PlcWorkshop $x) => $this->workshops->summary($x))->all(),
        ]]);
    }

    /** Schools and people pickers for the wizard, limited to what the person may see. */
    public function lookup(Request $request, string $kind): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $scope = $this->scope();
        if ($kind === 'schools') {
            $rows = School::query()->where('status', 'active')
                ->when(! $scope->isMinistryWide(), fn ($b) => $b->whereIn('id', $scope->schoolIds() ?? []))
                ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name_ar', 'like', "%$q%")->orWhere('name_en', 'like', "%$q%")->orWhere('code', 'like', "%$q%")->orWhere('moe_no', 'like', "%$q%")))
                ->when($request->query('gender'), fn ($b, $v) => $b->where('gender', $v))
                ->when($request->query('region'), fn ($b, $v) => $b->where('region', $v))
                ->orderBy('name_ar')->limit(30)->get(['id', 'code', 'name_ar', 'name_en', 'gender', 'type', 'stage', 'region', 'district']);

            return response()->json(['data' => $rows]);
        }
        if ($kind === 'users') {
            $rows = User::query()->where('status', 'active')
                ->when(! $scope->isMinistryWide(), fn ($b) => $b->whereHas('employee', fn ($e) => $e->whereIn('school_id', $scope->schoolIds() ?? [])))
                ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('name_ar', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
                ->orderBy('name')->limit(20)->get(['id', 'name', 'name_ar', 'email']);

            return response()->json(['data' => $rows->map(fn ($u) => ['id' => $u->id, 'name' => $u->displayName(), 'email' => $u->email])]);
        }
        abort(404);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gender_policy' => ['sometimes', 'in:strict,allow_with_exception,open'], 'max_distance_km' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'require_same_region' => ['sometimes', 'boolean'], 'allow_unapproved_execution' => ['sometimes', 'boolean'], 'geofence_default' => ['sometimes', 'boolean'],
            'late_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'], 'early_leave_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'attachment_retention_months' => ['sometimes', 'integer', 'min:1', 'max:240'], 'approval_paths' => ['sometimes', 'array'], 'evaluation_rules' => ['sometimes', 'array'], 'ai_feedback_opt_in' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->settings->update($data, $this->user())]);
    }
}
