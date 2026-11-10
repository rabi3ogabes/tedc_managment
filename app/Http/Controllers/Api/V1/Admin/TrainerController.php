<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrainerResource;
use App\Models\Employee;
use App\Models\PartnerOrganization;
use App\Models\ProgramSession;
use App\Models\Skill;
use App\Models\Trainer;
use App\Services\FileStorage;
use App\Services\TrainerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Trainer management by source: center, school, ministry, partner organization, external, international.
 */
class TrainerController extends Controller
{
    public function __construct(private readonly TrainerService $trainers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $free = $request->filled('available_from') && $request->filled('available_to');
        $busy = $free ? ProgramSession::whereNotNull('trainer_id')->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', Carbon::parse($request->query('available_to')))->where('ends_at', '>', Carbon::parse($request->query('available_from')))
            ->pluck('trainer_id') : collect();

        $trainers = Trainer::with(['school:id,name_ar,name_en', 'partner:id,name_ar,name_en,type'])->withCount(['programs', 'sessions'])
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('name_ar', "%{$t}%")->orWhereLike('name_en', "%{$t}%")->orWhereLike('organization', "%{$t}%")->orWhereLike('email', "%{$t}%")))
            ->when($request->query('source'), fn ($q, $v) => $q->whereIn('source', explode(',', $v)))
            ->when($request->query('school_id'), fn ($q, $v) => $q->where('school_id', $v))
            ->when($request->query('partner_id'), fn ($q, $v) => $q->where('partner_id', $v))
            ->when($request->query('country'), fn ($q, $v) => $q->where('country', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('specialization'), fn ($q, $v) => $q->whereJsonContains('specializations', $v))
            ->when($request->query('language'), fn ($q, $v) => $q->whereJsonContains('languages', $v))
            ->when($request->query('min_rating'), fn ($q, $v) => $q->where('rating', '>=', (float) $v))
            ->when($free, fn ($q) => $q->whereNotIn('id', $busy))
            ->orderBy('name_ar')->paginate($this->perPage($request, 50));

        return TrainerResource::collection($trainers);
    }

    public function show(Trainer $trainer): TrainerResource
    {
        return new TrainerResource($trainer->load(['school:id,name_ar,name_en', 'partner:id,name_ar,name_en,type'])->loadCount(['programs', 'sessions']));
    }

    /** Sources, partner organizations and the lookups the trainer form needs. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'sources' => collect(Trainer::SOURCES)->map(fn ($l, $k) => ['key' => $k, 'ar' => $l[0], 'en' => $l[1], 'outside' => in_array($k, Trainer::OUTSIDE, true)])->values(),
            'partners' => PartnerOrganization::where('status', 'active')->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'type', 'country']),
            'partner_types' => PartnerOrganization::TYPES,
            'countries' => Trainer::whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
            'languages' => Trainer::whereNotNull('languages')->pluck('languages')->flatten()->unique()->sort()->values(),
            'specializations' => Trainer::whereNotNull('specializations')->pluck('specializations')->flatten()->unique()->sort()->values(),
            'skills' => Skill::orderBy('name_ar')->get(['id', 'code', 'name_ar', 'name_en']),
        ]]);
    }

    /** School employees who can be registered as a school trainer with their details pre-filled. */
    public function candidates(Request $request): JsonResponse
    {
        $employees = Employee::with(['user:id,name,name_ar,email', 'school:id,name_ar,name_en', 'jobTitle:id,name_ar,name_en'])
            ->where('status', 'active')
            ->when($request->query('school_id'), fn ($q, $v) => $q->where('school_id', $v))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->whereLike('employee_no', "%{$t}%")->orWhereLike('specialization', "%{$t}%")
                ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$t}%")->orWhereLike('name_ar', "%{$t}%")->orWhereLike('email', "%{$t}%"))))
            ->orderBy('employee_no')->limit(30)->get();
        $taken = Trainer::whereIn('employee_id', $employees->pluck('id'))->pluck('id', 'employee_id');

        return response()->json(['data' => $employees->map(fn (Employee $e) => [
            'employee_id' => $e->id, 'employee_no' => $e->employee_no, 'name_ar' => $e->user?->name_ar, 'name_en' => $e->user?->name, 'email' => $e->user?->email,
            'school_id' => $e->school_id, 'school' => $e->school?->translate('name'), 'job_title' => $e->jobTitle?->name_ar, 'specialization' => $e->specialization,
            'experience_years' => $e->experience_years, 'already_trainer' => $taken->has($e->id), 'trainer_id' => $taken->get($e->id),
        ])->values()]);
    }

    /** Best trainers for a topic, optionally for a slot. */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skill_ids' => ['nullable', 'array'], 'skill_ids.*' => ['uuid'],
            'specializations' => ['nullable', 'array'], 'specializations.*' => ['string', 'max:100'],
            'sources' => ['nullable', 'array'], 'sources.*' => [Rule::in(array_keys(Trainer::SOURCES))],
            'languages' => ['nullable', 'array'], 'languages.*' => ['string', 'max:32'],
            'starts_at' => ['nullable', 'date', 'required_with:ends_at'], 'ends_at' => ['nullable', 'date', 'after:starts_at', 'required_with:starts_at'],
        ]);
        $data['specializations'] = array_merge($data['specializations'] ?? [], Skill::whereIn('id', $data['skill_ids'] ?? [])->pluck('code')->all());
        $data['starts_at'] = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $data['ends_at'] = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;

        return response()->json(['data' => $this->trainers->suggest($data)->map(fn ($r) => ['trainer' => new TrainerResource($r['trainer'])] + collect($r)->except('trainer')->all())->values()]);
    }

    public function schedule(Request $request, Trainer $trainer): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => ProgramSession::with('program:id,code,title_ar,title_en')->where('trainer_id', $trainer->id)->where('status', '!=', 'cancelled')
            ->where('starts_at', '<=', Carbon::parse($data['to'])->endOfDay())->where('ends_at', '>=', Carbon::parse($data['from'])->startOfDay())
            ->orderBy('starts_at')->get()
            ->map(fn ($s) => ['id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'), 'program_id' => $s->program_id, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()])]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->show(Trainer::create($this->data($request)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Trainer $trainer): TrainerResource
    {
        $trainer->update($this->data($request, $trainer));

        return $this->show($trainer->refresh());
    }

    public function photo(Request $request, Trainer $trainer, FileStorage $storage): TrainerResource
    {
        $request->validate(['photo' => ['required', 'image', 'max:3072']]);
        $trainer->update(['photo_path' => $storage->uploadPublic($request->file('photo'), 'trainers')]);

        return new TrainerResource($trainer);
    }

    /** A trainer who has taught is deactivated so programs and certificates keep their history. */
    public function destroy(Trainer $trainer): JsonResponse
    {
        if ($trainer->programs()->exists() || $trainer->sessions()->exists()) {
            $trainer->update(['status' => 'inactive']);

            return response()->json(['data' => new TrainerResource($trainer), 'meta' => ['deactivated' => true]]);
        }

        $trainer->delete();

        return response()->json(null, 204);
    }

    /**
     * Validates by source and completes the profile: a school trainer picked from the staff list
     * inherits name, email, school and specialization; a partner trainer inherits the organization.
     */
    private function data(Request $request, ?Trainer $trainer = null): array
    {
        $source = $request->input('source', $trainer?->source ?? Trainer::CENTER);
        $create = ! $trainer;

        $namesRequired = $create && ! ($source === Trainer::SCHOOL && $request->filled('employee_id'));

        $data = $request->validate([
            'source' => ['sometimes', Rule::in(array_keys(Trainer::SOURCES))],
            'name_ar' => [$namesRequired ? 'required' : 'sometimes', 'string', 'max:255'],
            'name_en' => [$namesRequired ? 'required' : 'sometimes', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'bio_ar' => ['nullable', 'string'],
            'bio_en' => ['nullable', 'string'],
            'specializations' => ['nullable', 'array'], 'specializations.*' => ['string', 'max:100'],
            'languages' => ['nullable', 'array'], 'languages.*' => ['string', 'max:32'],
            'organization' => ['nullable', 'string', 'max:255'],
            'school_id' => ['nullable', 'uuid', 'exists:schools,id'],
            'employee_id' => ['nullable', 'uuid', 'exists:employees,id', Rule::unique('trainers', 'employee_id')->ignore($trainer?->id)],
            'partner_id' => ['nullable', 'uuid', 'exists:partner_organizations,id'],
            'country' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:64'],
            'experience_years' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id', Rule::unique('trainers', 'user_id')->ignore($trainer?->id)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $data['source'] = $source;

        $merged = $data + ($trainer ? $trainer->only(['school_id', 'employee_id', 'partner_id', 'country', 'organization']) : []);
        $errors = [];

        if ($source === Trainer::SCHOOL) {
            if (! empty($merged['employee_id'])) {
                $employee = Employee::with(['user', 'school'])->find($merged['employee_id']);
                $data['school_id'] ??= $employee->school_id;
                $data['name_ar'] ??= $employee->user?->name_ar ?: $employee->user?->name;
                $data['name_en'] ??= $employee->user?->name ?: $employee->user?->name_ar;
                $data['email'] ??= $employee->user?->email;
                $data['experience_years'] ??= $employee->experience_years;
                if (empty($data['specializations']) && $employee->specialization) {
                    $data['specializations'] = [$employee->specialization];
                }
                $merged['school_id'] = $data['school_id'];
            }
            if (empty($merged['school_id'])) {
                $errors['school_id'] = __('validation.required', ['attribute' => 'school_id']);
            }
        } elseif ($source === Trainer::PARTNER) {
            if (empty($merged['partner_id'])) {
                $errors['partner_id'] = __('validation.required', ['attribute' => 'partner_id']);
            } else {
                $partner = PartnerOrganization::find($merged['partner_id']);
                $data['organization'] = $partner->name_ar;
                $data['country'] ??= $partner->country;
            }
        } elseif ($source === Trainer::INTERNATIONAL) {
            $country = $merged['country'] ?? null;
            if (! $country || in_array(mb_strtolower($country), ['qatar', 'قطر'], true)) {
                $errors['country'] = __('validation.required', ['attribute' => 'country']);
            }
        }

        // Fields that belong to other sources are cleared so a profile never carries stale links.
        if ($source !== Trainer::SCHOOL) {
            $data['school_id'] = null;
            $data['employee_id'] = null;
        }
        if ($source !== Trainer::PARTNER) {
            $data['partner_id'] = null;
        }
        if ($source === Trainer::MINISTRY) {
            $data['organization'] ??= $merged['organization'] ?? 'وزارة التربية والتعليم والتعليم العالي';
        }
        if (in_array($source, [Trainer::CENTER, Trainer::SCHOOL], true)) {
            $data['country'] ??= 'Qatar';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
