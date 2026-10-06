<?php

namespace App\Http\Controllers\Api\V1\Help;

use App\Exceptions\BusinessRuleException;
use App\Help\HelpService;
use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\Role;
use App\Models\UserTour;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** What every signed-in user sees: articles for their roles and page, feedback, manuals as PDF, support channels and tours. */
class HelpController extends Controller
{
    public function __construct(private readonly HelpService $help) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $list = $request->filled('route') ? $this->help->forRoute($user, (string) $request->query('route')) : ($request->filled('q') ? $this->help->search($user, (string) $request->query('q')) : $this->help->visibleTo($user));
        if ($request->filled('module')) {
            $list = $list->where('module', $request->query('module'))->values();
        }

        return response()->json(['data' => $list->map(fn ($a) => $this->help->present($a, false))->all(), 'support' => $this->help->support(), 'roles' => $this->help->roleSlugs($user)]);
    }

    public function show(string $slug): JsonResponse
    {
        $article = $this->help->visibleTo($this->user())->firstWhere('slug', $slug);
        if (! $article) {
            throw new BusinessRuleException(__('messages.help.not_found'), 'help_not_found');
        }

        return response()->json(['data' => $this->help->present($article)]);
    }

    public function feedback(Request $request, string $slug): JsonResponse
    {
        $d = $request->validate(['helpful' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $article = HelpArticle::where('slug', $slug)->where('status', 'published')->firstOrFail();
        $this->help->feedback($article, $this->user(), (bool) $d['helpful'], $d['comment'] ?? null);

        return response()->json(['data' => ['saved' => true]], 201);
    }

    /** Manuals the user may download: those of their own roles (administrators: all). */
    public function manuals(): JsonResponse
    {
        $user = $this->user();
        $mine = $this->help->roleSlugs($user);
        $all = $user->hasRole(Role::SUPER_ADMIN, Role::CENTER_ADMIN);
        $roles = Role::whereIn('slug', HelpService::MANUAL_ROLES)->get()->filter(fn ($r) => $all || in_array($r->slug, $mine, true));

        return response()->json(['data' => $roles->map(fn ($r) => ['role' => $r->slug, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en, 'articles' => $this->help->manualArticles($r->slug)->count()])->values()->all()]);
    }

    public function manualPdf(Request $request, string $manual): Response
    {
        $user = $this->user();
        $allowed = $user->hasRole(Role::SUPER_ADMIN, Role::CENTER_ADMIN) || in_array($manual, $this->help->roleSlugs($user), true);
        if (! in_array($manual, HelpService::MANUAL_ROLES, true) || ! $allowed) {
            throw new BusinessRuleException(__('messages.help.not_found'), 'help_not_found');
        }
        $lang = $request->query('lang') === 'en' ? 'en' : 'ar';

        return response($this->help->manualPdf($manual, $lang), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="manual-'.$manual.'-'.$lang.'.pdf"']);
    }

    public function tour(): JsonResponse
    {
        return response()->json(['data' => $this->help->pendingTour($this->user()), 'release' => HelpService::RELEASE]);
    }

    public function finishTour(Request $request): JsonResponse
    {
        $d = $request->validate(['key' => ['required', 'string', 'max:80', 'regex:/^(first-login|whats-new):[a-z0-9_.-]+$/'], 'state' => ['nullable', 'in:done,dismissed']]);
        $this->help->finishTour($this->user(), $d['key'], $d['state'] ?? 'done');

        return response()->json(['data' => ['saved' => true]], 201);
    }

    /** Replays the tours: forgets what the user finished. */
    public function resetTours(): JsonResponse
    {
        $this->user()->hasMany(UserTour::class)->delete();

        return response()->json(['data' => ['reset' => true]]);
    }
}
