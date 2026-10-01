<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $session = $this->auth->login($data['email'], $data['password']);
        // A fresh sign-in is real activity and clears any idle lock.
        $session['user']->forceFill(['last_active_at' => now(), 'locked_at' => null])->saveQuietly();

        return $this->session($session);
    }

    /** Locks the dashboard immediately (idle timeout reached in the browser). */
    public function lock(Request $request): JsonResponse
    {
        $request->user()->forceFill(['locked_at' => now()])->saveQuietly();

        return response()->json(['data' => ['locked' => true]]);
    }

    /** Unlocks after the password is confirmed again. */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);

        if (! $this->auth->checkPassword($request->user(), $data['password'])) {
            throw ValidationException::withMessages(['password' => __('auth.wrong_password')]);
        }
        $request->user()->forceFill(['locked_at' => null, 'last_active_at' => now()])->saveQuietly();

        return response()->json(['data' => ['locked' => false]]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string']]);

        return $this->session($this->auth->refresh($data['refresh_token']));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        // Personal data is read-only: corrections go through change requests (My account). Only the language is a preference.
        $data = $request->validate(['locale' => ['sometimes', 'in:ar,en']]);

        $request->user()->update($data);

        return response()->json(['data' => $this->profile($request->user()->refresh())]);
    }

    private function session(array $session): JsonResponse
    {
        return response()->json([
            'access_token' => $session['access_token'],
            'refresh_token' => $session['refresh_token'],
            'token_type' => 'bearer',
            'expires_in' => $session['expires_in'],
            'user' => $this->profile($session['user']),
        ]);
    }

    public function profile(User $user): array
    {
        $user->loadMissing(['roles', 'employee.school', 'employee.jobTitle', 'employee.department', 'trainer']);

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
            'name_en' => $user->name,
            'name_ar' => $user->name_ar,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'roles' => $user->roles->map(fn ($r) => ['slug' => $r->slug, 'name' => app()->getLocale() === 'ar' ? $r->name_ar : $r->name_en]),
            'permissions' => $user->isSuperAdmin() ? ['*'] : $user->permissionSlugs(),
            'employee' => $user->employee ? new EmployeeResource($user->employee) : null,
            'trainer_id' => $user->trainer?->id,
        ];
    }
}
