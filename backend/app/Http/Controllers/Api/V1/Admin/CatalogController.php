<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\ProgramCategory;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\Skill;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'coordinators' => User::whereHas('roles', fn ($q) => $q->whereIn('slug', Role::CENTER_STAFF))->where('status', 'active')->orderBy('name_ar')->get(['id', 'name', 'name_ar']),
            'trainers' => Trainer::where('status', 'active')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'schools' => School::orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en', 'region', 'type', 'stage']),
            'school_groups' => SchoolGroup::orderBy('name_ar')->get(['id', 'name_ar', 'name_en']),
            'roles' => Role::where('slug', '!=', Role::SUPER_ADMIN)->orderBy('level', 'desc')->get(['slug', 'name_ar', 'name_en']),
            'regions' => School::REGIONS,
            'school_types' => School::TYPES,
            'stages' => School::STAGES,
        ]]);
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
}
