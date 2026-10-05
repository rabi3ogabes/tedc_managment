<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\Registration;
use App\Models\ScormAttempt;
use App\Services\Content\Cmi5Service;
use App\Services\Content\PackageToken;
use App\Services\Content\ScormService;
use App\Services\Content\XapiService;
use App\Services\CourseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The learner's side of content packages: SCORM runtime, cmi5 launch, H5P / HTML5 launch and completion. */
class MyPackageController extends MeController
{
    public function __construct(private readonly ScormService $scorm, private readonly Cmi5Service $cmi5, private readonly XapiService $xapi, private readonly CourseService $course) {}

    public function scormStart(Request $request, CourseLesson $lesson): JsonResponse
    {
        return response()->json(['data' => $this->scorm->start($lesson, $this->registrationFor($lesson), $request->validate(['item_id' => ['nullable', 'string', 'max:120']])['item_id'] ?? null)]);
    }

    /** Debounced and idempotent: committing the same CMI tree twice changes nothing. */
    public function scormCommit(Request $request, ScormAttempt $attempt): JsonResponse
    {
        $this->own($attempt);
        $d = $request->validate(['cmi' => ['required', 'array']]);
        $a = $this->scorm->commit($attempt, $d['cmi']);

        return response()->json(['data' => ['completion_status' => $a->completion_status, 'success_status' => $a->success_status, 'score_scaled' => $a->score_scaled]]);
    }

    public function scormFinish(ScormAttempt $attempt): JsonResponse
    {
        $this->own($attempt);

        return response()->json(['data' => ['finished' => (bool) $this->scorm->finish($attempt)]]);
    }

    public function cmi5Launch(Request $request, CourseLesson $lesson): JsonResponse
    {
        return response()->json(['data' => $this->cmi5->launch($lesson, $this->registrationFor($lesson), $request->validate(['au_id' => ['nullable', 'string', 'max:500']])['au_id'] ?? null)]);
    }

    /** H5P, HTML5 and xAPI (TinCan) units: the files, and where to begin. */
    public function launch(CourseLesson $lesson): JsonResponse
    {
        $r = $this->registrationFor($lesson);
        $this->course->assertOpen($lesson, $r);
        $this->course->open($lesson, $r);
        $pkg = ContentPackage::where('status', 'ready')->findOrFail($lesson->package_id);
        $entry = collect($pkg->entry_points)->firstWhere('id', $lesson->package_item_id) ?? collect($pkg->entry_points)->first();

        return response()->json(['data' => ['standard' => $pkg->standard, 'base_url' => PackageToken::url($pkg->id, '', $this->user()->id), 'launch_url' => PackageToken::url($pkg->id, $entry['href'] ?? '', $this->user()->id), 'entry' => $entry]]);
    }

    /** An HTML5 or H5P unit reports completion (postMessage bridge in the player); the score is the unit's own claim. */
    public function complete(Request $request, CourseLesson $lesson): JsonResponse
    {
        $d = $request->validate(['completed' => ['required', 'boolean'], 'score' => ['nullable', 'numeric', 'min:0', 'max:100']]);
        $r = $this->registrationFor($lesson);
        abort_unless($lesson->package_id, 422);
        $p = $this->course->markFromPackage($lesson, $r, (bool) $d['completed'], null, isset($d['score']) ? (float) $d['score'] : null);
        $d['completed'] && $this->xapi->native($r, $lesson, 'http://adlnet.gov/expapi/verbs/completed', 'completed', isset($d['score']) ? ['result' => ['completion' => true, 'score' => ['scaled' => (float) $d['score'] / 100]]] : ['result' => ['completion' => true]]);

        return response()->json(['data' => ['status' => $p->status, 'percent' => (float) $p->percent]]);
    }

    /** xAPI statements produced inside an H5P unit are stored under the learner's own identity. */
    public function xapi(Request $request, CourseLesson $lesson): JsonResponse
    {
        $d = $request->validate(['statements' => ['required', 'array', 'max:50']]);
        $r = $this->registrationFor($lesson);
        $user = $this->user();
        $home = rtrim((string) config('tedc.web_url'), '/') ?: 'https://tedc.local';
        $ids = [];
        foreach ($d['statements'] as $s) {
            $s['actor'] = ['objectType' => 'Agent', 'name' => $user->name, 'account' => ['homePage' => $home, 'name' => (string) $user->id]];
            $s['context']['registration'] = $r->id;
            try {
                $ids[] = $this->xapi->store($s, $r->id, $lesson->id)['id'];
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['data' => ['ids' => $ids]], 201);
    }

    private function own(ScormAttempt $a): void
    {
        abort_unless($a->registration_id && Registration::whereKey($a->registration_id)->where('employee_id', $this->employee()->id)->exists(), 404);
    }

    private function registrationFor(CourseLesson $lesson): Registration
    {
        return Registration::with('employee.user')->where('program_id', $lesson->program_id)->where('employee_id', $this->employee()->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->firstOrFail();
    }
}
