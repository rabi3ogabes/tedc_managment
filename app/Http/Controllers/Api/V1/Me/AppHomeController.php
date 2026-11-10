<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AppActions;
use App\Services\AppHomeLayout;
use App\Services\AppThemes;
use App\Support\ActiveRole;
use App\Support\Nationalities;
use Illuminate\Http\JsonResponse;

/** What the app's home screen shows this person: the cards of their role's view, their action tiles and today's sessions. */
class AppHomeController extends Controller
{
    public function __invoke(AppHomeLayout $layout, AppActions $actions, AppThemes $themes): JsonResponse
    {
        $user = $this->user();
        $slug = app(ActiveRole::class)->for($user)?->role?->slug ?? $user->roles->sortByDesc('level')->first()?->slug ?? Role::EMPLOYEE;

        $cards = $layout->cards($slug);
        // A trainee's look may bring its own card order, until the administrator sets one for trainees.
        if ($slug === Role::EMPLOYEE && ! $layout->customised($slug) && ($look = $themes->forUser($user, $layout)) && ! empty($look['home'])) {
            $cards = array_values(array_intersect($look['home'], AppHomeLayout::CARDS));
        }

        return response()->json(['data' => [
            'role' => $slug,
            'greeting_name' => $user->displayName(),
            'identity' => Nationalities::identity($user),
            'sections' => $cards,
            'actions' => in_array('actions', $cards, true) ? $actions->tiles($user, $layout->actions($slug)) : [],
            'today' => in_array('today', $cards, true) ? $actions->today($user) : [],
            'unread_notifications' => $user->appNotifications()->whereNull('read_at')->count(),
        ]]);
    }
}
