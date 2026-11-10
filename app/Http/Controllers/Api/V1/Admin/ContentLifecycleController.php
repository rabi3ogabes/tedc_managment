<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\CourseLessonVersion;
use App\Models\JobGroup;
use App\Models\Program;
use App\Models\SharingPolicy;
use App\Models\TrainingKit;
use App\Services\Content\LessonVersionService;
use App\Services\Library\JobGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Lesson versions, kits linked to several programs, job groups and sharing policies. */
class ContentLifecycleController extends Controller
{
    public function __construct(private readonly LessonVersionService $versions, private readonly JobGroupService $groups) {}

    // ── lesson versions

    public function versions(CourseLesson $lesson): JsonResponse
    {
        $this->versions->ensureCurrent($lesson, $this->user());

        return response()->json(['data' => ['current' => $lesson->version, 'versions' => CourseLessonVersion::where('lesson_id', $lesson->id)->orderByDesc('version')->get(['id', 'version', 'note', 'is_archived', 'created_at'])]]);
    }

    public function publish(Request $request, CourseLesson $lesson): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:255'], 'learners' => ['required', Rule::in(['keep', 'move'])]]);

        return response()->json(['data' => $this->versions->publish($lesson, $d['note'] ?? null, $d['learners'], $this->user())], 201);
    }

    public function restore(CourseLesson $lesson, int $version): JsonResponse
    {
        return response()->json(['data' => $this->versions->restore($lesson, $version, $this->user())], 201);
    }

    public function archive(CourseLesson $lesson, int $version): JsonResponse
    {
        abort_if($version === $lesson->version, 422);
        CourseLessonVersion::where('lesson_id', $lesson->id)->where('version', $version)->update(['is_archived' => true]);

        return response()->json(['data' => ['archived' => true]]);
    }

    public function diff(Request $request, CourseLesson $lesson): JsonResponse
    {
        $d = $request->validate(['from' => ['required', 'integer'], 'to' => ['required', 'integer']]);

        return response()->json(['data' => $this->versions->diff($lesson, $d['from'], $d['to'])]);
    }

    // ── kits and programs

    public function kitPrograms(TrainingKit $kit): JsonResponse
    {
        return response()->json(['data' => $kit->programs()->get(['programs.id', 'programs.code', 'programs.title_ar', 'programs.title_en'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'title_ar' => $p->title_ar, 'title_en' => $p->title_en, 'pinned_version' => $p->pivot->pinned_version])->values()]);
    }

    /** Assigns an approved kit to several programs (each can pin the kit version). */
    public function syncKitPrograms(Request $request, TrainingKit $kit): JsonResponse
    {
        abort_unless(in_array($kit->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true), 422, __('messages.kit.not_approved'));
        $d = $request->validate(['programs' => ['present', 'array', 'max:100'], 'programs.*.program_id' => ['required', 'uuid', 'exists:programs,id', 'distinct'], 'programs.*.pinned_version' => ['nullable', 'integer', 'min:1']]);
        DB::transaction(function () use ($kit, $d) {
            DB::table('kit_program')->where('kit_id', $kit->id)->delete();
            foreach ($d['programs'] as $p) {
                DB::table('kit_program')->insert(['id' => (string) Str::uuid(), 'kit_id' => $kit->id, 'program_id' => $p['program_id'], 'pinned_version' => $p['pinned_version'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $kit->update(['program_id' => $d['programs'][0]['program_id'] ?? null]);

        return $this->kitPrograms($kit);
    }

    // ── job groups

    public function jobGroups(): JsonResponse
    {
        return response()->json(['data' => JobGroup::orderBy('name_ar')->get()->map(fn ($g) => $g->toArray() + ['members' => $this->groups->members($g, 500)->count()])->all()]);
    }

    public function saveJobGroup(Request $request, ?JobGroup $group = null): JsonResponse
    {
        $r = $group ? 'sometimes' : 'required';
        $d = $request->validate(['name_ar' => [$r, 'string', 'max:200'], 'name_en' => [$r, 'string', 'max:200'], 'rule' => [$r, 'array'], 'rule.job_title_ids' => ['nullable', 'array'], 'rule.job_categories' => ['nullable', 'array'], 'rule.school_ids' => ['nullable', 'array'], 'rule.subjects' => ['nullable', 'array']]);
        $row = $group ? tap($group)->update($d) : JobGroup::create($d);

        return response()->json(['data' => $row->fresh()], $group ? 200 : 201);
    }

    public function deleteJobGroup(JobGroup $group): JsonResponse
    {
        DB::table('resource_shares')->where('target_type', 'job_group')->where('target_id', $group->id)->delete();
        $group->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function jobGroupMembers(JobGroup $group): JsonResponse
    {
        return response()->json(['data' => $this->groups->members($group)->map(fn ($e) => ['id' => $e->id, 'name' => $e->user?->displayName(), 'job' => $e->jobTitle?->translate('name')])->values()]);
    }

    // ── sharing policies

    public function policies(): JsonResponse
    {
        return response()->json(['data' => SharingPolicy::orderBy('role')->get()->all()]);
    }

    public function savePolicy(Request $request): JsonResponse
    {
        $d = $request->validate(['role' => ['required', 'string', 'max:40', 'exists:roles,slug'], 'resource_types' => ['required', 'array'], 'resource_types.*' => [Rule::in(['material', 'kit_file', 'library_item', 'lesson'])], 'target_types' => ['required', 'array'], 'target_types.*' => [Rule::in(['program', 'group', 'job_group', 'role', 'user'])],
            'allow_reshare' => ['boolean'], 'allow_download' => ['boolean'], 'watermark' => ['boolean']]);

        return response()->json(['data' => SharingPolicy::updateOrCreate(['role' => $d['role']], $d)]);
    }

    public function deletePolicy(SharingPolicy $policy): JsonResponse
    {
        $policy->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function programOptions(): JsonResponse
    {
        return response()->json(['data' => Program::orderByDesc('created_at')->limit(300)->get(['id', 'code', 'title_ar', 'title_en'])]);
    }
}
