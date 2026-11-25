<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\RoleUser;
use App\Models\School;
use App\Models\SchoolGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** School groups (directorates, clusters, stages…): the scope a role can be granted over. */
class SchoolGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = SchoolGroup::withCount('schools')
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name_ar', "%{$t}%")->orWhereLike('name_en', "%{$t}%")->orWhereLike('code', "%{$t}%")))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderBy('name_ar')->paginate($this->perPage($request, 50));

        return response()->json($groups);
    }

    public function show(SchoolGroup $schoolGroup): JsonResponse
    {
        return response()->json(['data' => $schoolGroup->load('schools:id,code,name_ar,name_en,region,type,latitude,longitude')->loadCount('schools')]);
    }

    public function store(Request $request): JsonResponse
    {
        $group = SchoolGroup::create($request->validate($this->rules()));

        return response()->json(['data' => $group], 201);
    }

    public function update(Request $request, SchoolGroup $schoolGroup): JsonResponse
    {
        $schoolGroup->update($request->validate($this->rules($schoolGroup)));

        return response()->json(['data' => $schoolGroup]);
    }

    public function destroy(SchoolGroup $schoolGroup): JsonResponse
    {
        if (RoleUser::where('scope_type', 'school_group')->where('scope_id', $schoolGroup->id)->exists()) {
            throw new BusinessRuleException(__('messages.roles.group_in_use'), 'group_in_use');
        }
        $schoolGroup->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function syncSchools(Request $request, SchoolGroup $schoolGroup): JsonResponse
    {
        $data = $request->validate(['school_ids' => ['present', 'array'], 'school_ids.*' => ['uuid', 'exists:schools,id']]);
        $schoolGroup->schools()->sync($data['school_ids']);

        return response()->json(['data' => $schoolGroup->loadCount('schools')]);
    }

    /** CSV: group_code, group_name_ar, group_name_en, type, school_code — creates or updates groups and links schools; unknown school codes are reported. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) fgetcsv($handle));
        foreach (['group_code', 'school_code'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                throw new BusinessRuleException(__('messages.import.invalid_file'), 'invalid_file');
            }
        }

        $report = ['groups_created' => 0, 'groups_updated' => 0, 'linked' => 0, 'unknown_schools' => [], 'invalid_rows' => []];
        $line = 1;
        DB::transaction(function () use ($handle, $header, &$report, &$line) {
            while (($raw = fgetcsv($handle)) !== false) {
                $line++;
                if ($raw === [null] || count($raw) < 2) {
                    continue;
                }
                $row = array_combine($header, array_pad(array_map('trim', $raw), count($header), ''));
                $code = $row['group_code'] ?? '';
                if ($code === '') {
                    $report['invalid_rows'][] = ['line' => $line, 'reason' => 'group_code'];

                    continue;
                }
                $group = SchoolGroup::where('code', $code)->first();
                $type = in_array($row['type'] ?? '', SchoolGroup::TYPES, true) ? $row['type'] : ($group?->type ?? 'custom');
                if (! $group) {
                    $group = SchoolGroup::create(['code' => $code, 'name_ar' => ($row['group_name_ar'] ?? '') ?: $code, 'name_en' => ($row['group_name_en'] ?? '') ?: $code, 'type' => $type]);
                    $report['groups_created']++;
                } elseif (($row['group_name_ar'] ?? '') !== '' && $group->name_ar !== $row['group_name_ar']) {
                    $group->update(['name_ar' => $row['group_name_ar'], 'name_en' => ($row['group_name_en'] ?? '') ?: $group->name_en, 'type' => $type]);
                    $report['groups_updated']++;
                }
                $schoolCode = $row['school_code'] ?? '';
                $school = $schoolCode !== '' ? School::where('code', $schoolCode)->first() : null;
                if (! $school) {
                    $report['unknown_schools'][] = ['line' => $line, 'school_code' => $schoolCode];

                    continue;
                }
                $group->schools()->syncWithoutDetaching([$school->id]);
                $report['linked']++;
            }
        });
        fclose($handle);

        return response()->json(['data' => $report]);
    }

    /** @return array<string, mixed> */
    private function rules(?SchoolGroup $group = null): array
    {
        return [
            'code' => [$group ? 'sometimes' : 'required', 'alpha_dash', 'max:40', Rule::unique('school_groups', 'code')->ignore($group?->id)],
            'name_ar' => [$group ? 'sometimes' : 'required', 'string', 'max:255'], 'name_en' => [$group ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(SchoolGroup::TYPES)], 'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
