<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/** Organizations that supply trainers (Qatar Foundation, Ministry of Public Health, universities ...). */
class PartnerOrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return JsonResource::collection(PartnerOrganization::withCount('trainers')
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name_ar', "%{$t}%")->orWhereLike('name_en', "%{$t}%")))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->orderBy('name_ar')->paginate($this->perPage($request, 50)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => PartnerOrganization::create($this->validated($request))], 201);
    }

    public function update(Request $request, PartnerOrganization $partner): JsonResponse
    {
        $partner->update($this->validated($request, true));

        return response()->json(['data' => $partner->refresh()]);
    }

    public function destroy(PartnerOrganization $partner): JsonResponse
    {
        if ($partner->trainers()->exists()) {
            $partner->update(['status' => 'inactive']);

            return response()->json(['data' => $partner, 'meta' => ['deactivated' => true]]);
        }
        $partner->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name_ar' => [$required, 'string', 'max:255'],
            'name_en' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::in(PartnerOrganization::TYPES)],
            'country' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }
}
