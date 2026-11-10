<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Services\Content\ContentPackageService;
use App\Services\Content\PackageToken;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Upload, process and attach content packages (SCORM, xAPI, cmi5, H5P, HTML5, Common Cartridge). */
class PackageController extends Controller
{
    public function __construct(private readonly ContentPackageService $packages, private readonly FileStorage $storage) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => ContentPackage::when($request->query('standard'), fn ($q, $s) => $q->where('standard', $s))->latest()->limit(200)->get(['id', 'standard', 'title', 'version', 'size', 'status', 'error', 'created_at'])->all()]);
    }

    public function show(ContentPackage $package): JsonResponse
    {
        return response()->json(['data' => $package->toArray() + ['preview_url' => PackageToken::url($package->id, $package->entry_points[0]['href'] ?? '', $this->user()->id)]]);
    }

    /** Direct upload (development and small packages). */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'mimes:zip,h5p', 'max:204800'], 'title' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->packages->ingest($request->file('file')->getRealPath(), $this->user(), $d['title'] ?? null)], 201);
    }

    /** Large packages go to storage with a signed upload first; then they are processed from there. */
    public function sign(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:200']]);
        $path = 'packages-upload/'.Str::uuid().'/'.Str::slug(pathinfo($d['name'], PATHINFO_FILENAME)).'.zip';

        return response()->json(['data' => ['path' => $path] + $this->storage->signedUpload('packages', $path, 'application/zip')]);
    }

    public function process(Request $request): JsonResponse
    {
        $d = $request->validate(['path' => ['required', 'string', 'starts_with:packages-upload/', 'max:300'], 'title' => ['nullable', 'string', 'max:255']]);
        abort_if(str_contains($d['path'], '..'), 422);
        $tmp = tempnam(sys_get_temp_dir(), 'pkg');
        file_put_contents($tmp, $this->storage->get('packages', $d['path']));
        try {
            $package = $this->packages->ingest($tmp, $this->user(), $d['title'] ?? null);
        } finally {
            @unlink($tmp);
            $this->storage->delete('packages', $d['path']);
        }

        return response()->json(['data' => $package], 201);
    }

    public function destroy(ContentPackage $package): JsonResponse
    {
        abort_if(CourseLesson::where('package_id', $package->id)->exists(), 422, __('messages.package.in_use'));
        $package->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Attaches a package (and one of its units) to a lesson. */
    public function attach(Request $request, CourseLesson $lesson): JsonResponse
    {
        $d = $request->validate(['package_id' => ['required', 'uuid', 'exists:content_packages,id'], 'item_id' => ['nullable', 'string', 'max:120']]);
        abort_unless($lesson->type === CourseLesson::PACKAGE, 422);
        $lesson->update(['package_id' => $d['package_id'], 'package_item_id' => $d['item_id'] ?? null]);

        return response()->json(['data' => $lesson->fresh()]);
    }
}
