<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerResource;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\ProgramCategory;
use App\Models\School;
use App\Models\Skill;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Reference data: trainers, rooms, categories, skills, job titles, departments and lookups.
 */
class CatalogController extends Controller
{
    public function lookups(): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => ProgramCategory::orderBy('name_ar')->get(),
            'skills' => Skill::orderBy('category')->orderBy('name_ar')->get(),
            'job_titles' => JobTitle::orderBy('name_ar')->get(),
            'departments' => Department::whereNull('school_id')->orderBy('name_ar')->get(),
            'rooms' => TrainingRoom::orderBy('name_ar')->get(),
            'trainers' => Trainer::where('status', 'active')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'schools' => School::orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en', 'region', 'type', 'stage']),
            'regions' => School::REGIONS,
            'school_types' => School::TYPES,
            'stages' => School::STAGES,
        ]]);
    }

    // Trainers ----------------------------------------------------------

    public function trainers(Request $request): AnonymousResourceCollection
    {
        return TrainerResource::collection(Trainer::withCount('programs')
            ->when($request->query('q'), fn ($q, $t) => $q->where('name_ar', 'like', "%{$t}%")->orWhere('name_en', 'like', "%{$t}%"))
            ->orderBy('name_ar')->paginate($this->perPage($request, 50)));
    }

    public function storeTrainer(Request $request): TrainerResource
    {
        return new TrainerResource(Trainer::create($this->trainerData($request)));
    }

    public function updateTrainer(Request $request, Trainer $trainer): TrainerResource
    {
        $trainer->update($this->trainerData($request, $trainer));

        return new TrainerResource($trainer);
    }

    public function trainerPhoto(Request $request, Trainer $trainer, FileStorage $storage): TrainerResource
    {
        $request->validate(['photo' => ['required', 'image', 'max:3072']]);
        $trainer->update(['photo_path' => $storage->uploadPublic($request->file('photo'), 'trainers')]);

        return new TrainerResource($trainer);
    }

    public function destroyTrainer(Trainer $trainer): JsonResponse
    {
        $trainer->delete();

        return response()->json(null, 204);
    }

    // Generic reference tables ------------------------------------------

    public function storeCategory(Request $request): JsonResponse
    {
        return response()->json(['data' => ProgramCategory::create($request->validate([
            'slug' => ['required', 'alpha_dash', 'max:64', 'unique:program_categories,slug'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:48'],
            'color' => ['nullable', 'string', 'max:16'],
        ]))], 201);
    }

    public function storeSkill(Request $request): JsonResponse
    {
        return response()->json(['data' => Skill::create($request->validate([
            'code' => ['required', 'alpha_dash', 'max:48', 'unique:skills,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:48'],
        ]))], 201);
    }

    public function storeJobTitle(Request $request): JsonResponse
    {
        return response()->json(['data' => JobTitle::create($request->validate([
            'code' => ['required', 'alpha_dash', 'max:32', 'unique:job_titles,code'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['teaching', 'leadership', 'administrative', 'support'])],
        ]))], 201);
    }

    private function trainerData(Request $request, ?Trainer $trainer = null): array
    {
        $required = $trainer ? 'sometimes' : 'required';

        return $request->validate([
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'bio_ar' => ['nullable', 'string'],
            'bio_en' => ['nullable', 'string'],
            'specializations' => ['nullable', 'array'],
            'is_external' => ['sometimes', 'boolean'],
            'organization' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id', Rule::unique('trainers', 'user_id')->ignore($trainer?->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
