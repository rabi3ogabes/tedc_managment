<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        return $this->session($this->auth->login($data['email'], $data['password']));
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
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:ar,en'],
        ]);

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

    private function profile(User $user): array
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
