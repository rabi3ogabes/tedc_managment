<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $schools = School::withCount('employees')
            ->when($this->schoolScope(), fn ($q, $id) => $q->whereKey($id))
            ->when($request->query('region'), fn ($q, $r) => $q->where('region', $r))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name_ar', 'like', "%{$t}%")->orWhere('name_en', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")))
            ->orderBy('name_ar')
            ->paginate($this->perPage($request, 25));

        return response()->json($schools);
    }

    public function show(School $school): JsonResponse
    {
        abort_if($this->schoolScope() && $this->schoolScope() !== $school->id, 403);

        return response()->json(['data' => $school->loadCount('employees')]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => School::create($this->validated($request))], 201);
    }

    public function update(Request $request, School $school): JsonResponse
    {
        $school->update($this->validated($request, $school));

        return response()->json(['data' => $school]);
    }

    public function logo(Request $request, School $school, FileStorage $storage): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'max:2048']]);
        $school->update(['logo_path' => $storage->uploadPublic($request->file('logo'), 'schools')]);

        return response()->json(['data' => $school]);
    }

    private function validated(Request $request, ?School $school = null): array
    {
        $required = $school ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:32', Rule::unique('schools', 'code')->ignore($school?->id)],
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::in(School::TYPES)],
            'gender' => ['nullable', Rule::in(['boys', 'girls', 'mixed'])],
            'stage' => [$required, Rule::in(School::STAGES)],
            'region' => [$required, Rule::in(School::REGIONS)],
            'district' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'is_partner' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
