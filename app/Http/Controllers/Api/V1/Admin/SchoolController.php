<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\FileStorage;
use App\Services\QatarSchools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class SchoolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $schools = School::withCount('employees')
            ->tap(fn ($q) => $this->scope()->constrainSchoolColumn($q, 'id'))
            ->when($request->query('region'), fn ($q, $r) => $q->where('region', $r))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->boolean('official'), fn ($q) => $q->where('source', 'like', 'moe_%'))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name_ar', "%{$t}%")->orWhereLike('name_en', "%{$t}%")->orWhereLike('code', "%{$t}%")))
            ->orderBy('name_ar')
            ->paginate($this->perPage($request, 25));

        return response()->json($schools);
    }

    /** Every school that has a position, in a compact shape for the national map. */
    public function map(): JsonResponse
    {
        $schools = School::withCount('employees')->where('source', 'like', 'moe_%')->whereNotNull('latitude')->whereNotNull('longitude')->where('status', 'active')
            ->tap(fn ($q) => $this->scope()->constrainSchoolColumn($q, 'id'))->orderBy('name_ar')->get();

        return response()->json(['data' => [
            'schools' => $schools->map(fn (School $s) => [
                'id' => $s->id, 'code' => $s->code, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'type' => $s->type, 'stage' => $s->stage, 'gender' => $s->gender,
                'region' => $s->region, 'district' => $s->district, 'lat' => $s->latitude, 'lng' => $s->longitude, 'phone' => $s->phone, 'email' => $s->email,
                'address' => $s->address, 'website' => $s->website, 'curriculum' => $s->curriculum, 'partner' => $s->is_partner, 'staff' => $s->employees_count, 'source' => $s->source,
            ])->values(),
            'synced_at' => School::whereNotNull('synced_at')->max('synced_at'),
        ]]);
    }

    /** Refreshes the national list: from the ministry service when reachable, from the copy shipped with the platform otherwise. */
    public function sync(QatarSchools $national): JsonResponse
    {
        try {
            $rows = $national->fetchLive();
            $from = 'ministry';
        } catch (Throwable $e) {
            // Expected whenever the ministry's server is down or sends an incomplete certificate chain: the shipped list is used and the answer says so. Not an error to log.
            Log::warning('School sync: ministry service unavailable, using the bundled list', ['reason' => mb_substr($e->getMessage(), 0, 200)]);
            $rows = $national->bundled();
            $from = 'bundled';
        }

        return response()->json(['data' => $national->import($rows) + ['from' => $from]]);
    }

    public function show(School $school): JsonResponse
    {
        abort_unless($this->scope()->allowsSchool($school->id), 403);

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
            'address' => ['nullable', 'string', 'max:255'], 'website' => ['nullable', 'string', 'max:255'], 'curriculum' => ['nullable', 'string', 'max:80'],
            'is_partner' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
