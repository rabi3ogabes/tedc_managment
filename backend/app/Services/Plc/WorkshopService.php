<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\PlcNeed;
use App\Models\PlcWorkshop;
use App\Models\PlcWorkshopSchool;
use App\Models\Program;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and edits workshops on the programme engine. The internal scenario is a preset: the host is the
 * requester's own school and the participants stay inside it until the scope is widened and approved.
 */
class WorkshopService
{
    public function __construct(private readonly WorkshopWorkflow $workflow) {}

    /** Workshops the user may see: everything for ministry-wide roles, otherwise the ones touching their schools. */
    public function query(User $user): Builder
    {
        $scope = AccessScope::current($user);
        $q = PlcWorkshop::query()->with(['program:id,code,title_ar,title_en,status,start_date,end_date,delivery_mode,capacity,created_by', 'hostSchool:id,name_ar,name_en,gender,region', 'community:id,code,title_ar,title_en', 'coordinator:id,name,name_ar']);
        if ($scope->isMinistryWide()) {
            return $q;
        }
        $ids = $scope->schoolIds() ?? [];

        return $q->where(function ($w) use ($ids, $user) {
            $w->whereIn('host_school_id', $ids)
                ->orWhereHas('schools', fn ($s) => $s->whereIn('school_id', $ids)->where('status', '!=', 'excluded'))
                ->orWhereHas('program', fn ($p) => $p->where('created_by', $user->id));
        });
    }

    public function assertVisible(PlcWorkshop $w, User $user): void
    {
        if (! $this->query($user)->whereKey($w->program_id)->exists()) {
            abort(403, __('messages.plc.outside_scope'));
        }
    }

    public function create(User $by, array $data): PlcWorkshop
    {
        $scope = AccessScope::current($user = $by);
        $scenario = ($data['scenario'] ?? 'shared') === 'internal' ? 'internal' : 'shared';
        if (! $user->hasPermission('plc.manage')) {
            $scenario = 'internal'; // school users can only propose internal workshops
        }
        $hostId = $data['host_school_id'] ?? null;
        if ($scenario === 'internal') {
            $hostId = $hostId ?: ($scope->singleSchoolId() ?? $user->employee?->school_id);
            if (! $hostId || ! $scope->allowsSchool($hostId)) {
                throw new BusinessRuleException(__('messages.plc.school_required'), 'plc_school_required');
            }
        }
        $source = in_array($data['source'] ?? null, PlcWorkshop::SOURCES, true) ? $data['source'] : ($scenario === 'internal' ? 'school' : 'centre');
        if (! $user->hasPermission('plc.manage')) {
            $source = 'school'; // a school's own proposal, unless it answers a registered need
        }
        $need = ! empty($data['need_id']) ? PlcNeed::find($data['need_id']) : null;
        if ($need) {
            $source = $need->source;
        }

        return DB::transaction(function () use ($by, $data, $scenario, $hostId, $source, $need) {
            $program = Program::create([
                'code' => 'PLC-'.now()->format('y').'-'.Str::upper(Str::random(5)),
                'kind' => 'plc_workshop',
                'title_ar' => $data['title_ar'], 'title_en' => $data['title_en'] ?? $data['title_ar'],
                'summary_ar' => $data['summary_ar'] ?? null, 'summary_en' => $data['summary_en'] ?? null,
                'objectives' => $data['objectives'] ?? null,
                'delivery_mode' => in_array($data['delivery_mode'] ?? null, ['in_person', 'online', 'hybrid'], true) ? $data['delivery_mode'] : 'in_person',
                'total_hours' => $data['total_hours'] ?? 0, 'capacity' => $data['capacity'] ?? ($data['expected_participants'] ?? 30),
                'start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? ($data['start_date'] ?? null),
                'min_attendance_percent' => $data['min_attendance_percent'] ?? 80,
                'status' => Program::STATUS_DRAFT, 'approval_status' => 'pending',
                'owner_type' => $scenario === 'internal' ? 'school' : 'center', 'owner_school_id' => $scenario === 'internal' ? $hostId : null,
                'registration_modes' => $scenario === 'internal' ? ['school_nomination'] : ['center_nomination', 'school_nomination'],
                'audience' => $hostId && $scenario === 'internal' ? ['school_ids' => [$hostId]] : null,
                'created_by' => $by->id, 'coordinator_id' => $data['coordinator_user_id'] ?? null,
                'requires_tasks' => false, 'requires_evaluation' => true,
            ]);
            $w = PlcWorkshop::create([
                'program_id' => $program->id, 'community_id' => $data['community_id'] ?? null, 'need_id' => $need?->id,
                'source' => $source, 'source_ref' => $need?->reference_no ?? ($data['source_ref'] ?? null), 'organiser' => $data['organiser'] ?? ($scenario === 'internal' ? null : 'centre'),
                'coordinator_user_id' => $data['coordinator_user_id'] ?? $by->id,
                'priority' => in_array($data['priority'] ?? null, PlcWorkshop::PRIORITIES, true) ? $data['priority'] : 'normal',
                'expected_participants' => $data['expected_participants'] ?? null, 'prerequisites' => $data['prerequisites'] ?? null, 'outcomes' => $data['outcomes'] ?? null,
                'target_audience' => $data['target_audience'] ?? null, 'domain' => $data['domain'] ?? $need?->domain,
                'scenario' => $scenario, 'host_school_id' => $hostId, 'workflow_state' => 'draft', 'state_changed_at' => now(),
            ]);
            if ($hostId) {
                PlcWorkshopSchool::create(['program_id' => $program->id, 'school_id' => $hostId, 'role' => 'host', 'status' => 'selected', 'decided_by' => $by->id]);
            }
            if ($need && $need->review_status !== 'converted') {
                $need->update(['review_status' => 'converted', 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            }
            $this->workflow->decision($w, 'created', $scenario, null, ['source' => $source], $by);

            return $w->load('program');
        });
    }

    /** Edits the draft. The source and its reference are frozen once the workshop was approved, except through a recorded exception. */
    public function update(PlcWorkshop $w, User $by, array $data): PlcWorkshop
    {
        if (! in_array($w->workflow_state, WorkshopWorkflow::EDITABLE, true) && ! $by->hasPermission('plc.exceptions')) {
            throw new BusinessRuleException(__('messages.plc.locked', ['state' => $w->workflow_state]), 'plc_locked');
        }
        $frozen = ! in_array($w->workflow_state, ['draft', 'submitted', 'needs_changes'], true);
        $sourceChange = (isset($data['source']) && $data['source'] !== $w->source) || (array_key_exists('source_ref', $data) && $data['source_ref'] !== $w->source_ref) || (array_key_exists('need_id', $data) && $data['need_id'] !== $w->need_id);
        if ($frozen && $sourceChange) {
            if (! $by->hasPermission('plc.exceptions') || ! filled($data['exception_reason'] ?? null)) {
                throw new BusinessRuleException(__('messages.plc.source_frozen'), 'plc_source_frozen');
            }
            $this->workflow->decision($w, 'exception', 'source_changed', $data['exception_reason'], ['from' => $w->source, 'to' => $data['source'] ?? $w->source], $by);
        }

        return DB::transaction(function () use ($w, $by, $data) {
            $program = $w->program;
            $pp = [];
            foreach (['title_ar', 'title_en', 'summary_ar', 'summary_en', 'objectives', 'total_hours', 'capacity', 'start_date', 'end_date', 'min_attendance_percent'] as $f) {
                if (array_key_exists($f, $data)) {
                    $pp[$f] = $data[$f];
                }
            }
            if (isset($data['delivery_mode']) && in_array($data['delivery_mode'], ['in_person', 'online', 'hybrid'], true)) {
                $pp['delivery_mode'] = $data['delivery_mode'];
            }
            if (array_key_exists('coordinator_user_id', $data)) {
                $pp['coordinator_id'] = $data['coordinator_user_id'];
            }
            if ($pp) {
                $program->update($pp);
            }
            $wp = [];
            foreach (['community_id', 'need_id', 'source', 'source_ref', 'organiser', 'coordinator_user_id', 'priority', 'expected_participants', 'prerequisites', 'outcomes', 'target_audience', 'domain'] as $f) {
                if (array_key_exists($f, $data)) {
                    $wp[$f] = $data[$f];
                }
            }
            if (isset($wp['source']) && ! in_array($wp['source'], PlcWorkshop::SOURCES, true)) {
                unset($wp['source']);
            }
            if (isset($wp['priority']) && ! in_array($wp['priority'], PlcWorkshop::PRIORITIES, true)) {
                unset($wp['priority']);
            }
            // The host of an internal workshop is the school itself and never changes through this edit.
            if (! $w->isInternal() && array_key_exists('host_school_id', $data) && $data['host_school_id'] !== $w->host_school_id) {
                $wp['host_school_id'] = $data['host_school_id'];
                PlcWorkshopSchool::where('program_id', $w->program_id)->where('role', 'host')->delete();
                if ($data['host_school_id']) {
                    PlcWorkshopSchool::updateOrCreate(['program_id' => $w->program_id, 'school_id' => $data['host_school_id']], ['role' => 'host', 'status' => 'selected', 'decided_by' => $by->id]);
                }
            }
            if ($wp) {
                $w->update($wp);
            }
            // Editing a submitted workshop sends it back to the start of the review.
            if ($w->workflow_state === 'submitted' && ($pp || $wp)) {
                $w->forceFill(['workflow_state' => 'draft', 'approval_path' => null, 'state_changed_at' => now()])->save();
                $this->workflow->decision($w, 'transition', 'draft', __('messages.plc.edited_after_submit'), ['from' => 'submitted'], $by);
            }

            return $w->refresh()->load('program');
        });
    }

    /** Attaches a programme created by the legacy internal-workshop route so both paths meet in one engine. */
    public function attachLegacy(Program $program, User $by): PlcWorkshop
    {
        $w = PlcWorkshop::firstOrCreate(['program_id' => $program->id], [
            'source' => 'school', 'scenario' => 'internal', 'host_school_id' => $program->owner_school_id, 'coordinator_user_id' => $program->created_by ?? $by->id,
            'workflow_state' => 'draft', 'state_changed_at' => now(),
        ]);
        if ($program->owner_school_id) {
            PlcWorkshopSchool::firstOrCreate(['program_id' => $program->id, 'school_id' => $program->owner_school_id], ['role' => 'host', 'status' => 'selected', 'decided_by' => $by->id]);
        }
        if ($w->workflow_state === 'draft' && $program->approval_status === 'pending') {
            $this->workflow->submit($w, $by);
        }

        return $w;
    }

    public function detail(PlcWorkshop $w, User $user): array
    {
        $w->loadMissing(['program', 'hostSchool', 'community', 'need', 'coordinator', 'schools.school:id,name_ar,name_en,gender,region,type']);
        $p = $w->program;
        $pending = collect($w->approval_path ?? [])->firstWhere('status', 'pending');

        return [
            'id' => $w->program_id, 'code' => $p->code, 'title_ar' => $p->title_ar, 'title_en' => $p->title_en, 'summary_ar' => $p->summary_ar, 'summary_en' => $p->summary_en,
            'objectives' => $p->objectives, 'delivery_mode' => $p->delivery_mode, 'total_hours' => (float) $p->total_hours, 'capacity' => $p->capacity,
            'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'program_status' => $p->status,
            'scenario' => $w->scenario, 'source' => $w->source, 'source_ref' => $w->source_ref, 'organiser' => $w->organiser, 'priority' => $w->priority,
            'expected_participants' => $w->expected_participants, 'prerequisites' => $w->prerequisites, 'outcomes' => $w->outcomes, 'target_audience' => $w->target_audience, 'domain' => $w->domain,
            'community' => $w->community ? ['id' => $w->community->id, 'code' => $w->community->code, 'title' => $w->community->title()] : null,
            'need' => $w->need ? ['id' => $w->need->id, 'title' => $w->need->title, 'reference_no' => $w->need->reference_no, 'source' => $w->need->source] : null,
            'coordinator' => $w->coordinator?->displayName(), 'coordinator_user' => $w->coordinator ? ['id' => $w->coordinator->id, 'name' => $w->coordinator->displayName()] : null,
            'host_school' => $w->hostSchool ? ['id' => $w->hostSchool->id, 'name' => $w->hostSchool->name_ar, 'name_en' => $w->hostSchool->name_en, 'gender' => $w->hostSchool->gender, 'region' => $w->hostSchool->region] : null,
            'schools' => $w->schools->map(fn ($s) => ['id' => $s->school_id, 'name' => $s->school?->name_ar, 'name_en' => $s->school?->name_en, 'gender' => $s->school?->gender, 'region' => $s->school?->region, 'role' => $s->role, 'status' => $s->status, 'seats' => $s->seats, 'reasons' => $s->suggestion_reasons, 'distance_km' => $s->distance_km])->values(),
            'state' => $w->workflow_state, 'state_changed_at' => $w->state_changed_at, 'decision_note' => $w->decision_note,
            'approval_path' => $w->approval_path, 'pending_step' => $pending ? ['key' => $pending['key'], 'permission' => $pending['permission'], 'mine' => $user->hasPermission($pending['permission'])] : null,
            'can' => [
                'edit' => in_array($w->workflow_state, WorkshopWorkflow::EDITABLE, true) && ($user->hasPermission('plc.manage') || $user->hasPermission('plc.internal.submit')),
                'submit' => $this->workflow->canTransition($w, 'submitted') && ($user->hasPermission('plc.manage') || $user->hasPermission('plc.internal.submit')),
                'review' => $w->workflow_state === 'submitted' && $pending && $user->hasPermission($pending['permission']),
                'cancel' => $this->workflow->canTransition($w, 'cancelled') && ($user->hasPermission('plc.manage') || ($p->created_by === $user->id)),
            ],
            'transitions' => WorkshopWorkflow::TRANSITIONS[$w->workflow_state] ?? [],
            'decisions' => $w->decisions()->with('decider:id,name,name_ar')->limit(100)->get()->map(fn ($d) => ['kind' => $d->kind, 'decision' => $d->decision, 'reason' => $d->reason, 'data' => $d->data, 'by' => $d->decider?->displayName(), 'at' => $d->created_at]),
            'created_by' => $p->created_by, 'created_at' => $w->created_at, 'updated_at' => $w->updated_at,
        ];
    }

    public function summary(PlcWorkshop $w): array
    {
        $p = $w->program;

        return [
            'id' => $w->program_id, 'code' => $p?->code, 'title_ar' => $p?->title_ar, 'title_en' => $p?->title_en, 'delivery_mode' => $p?->delivery_mode,
            'start_date' => $p?->start_date?->toDateString(), 'end_date' => $p?->end_date?->toDateString(), 'capacity' => $p?->capacity,
            'scenario' => $w->scenario, 'source' => $w->source, 'source_ref' => $w->source_ref, 'priority' => $w->priority, 'state' => $w->workflow_state, 'state_changed_at' => $w->state_changed_at,
            'host_school' => $w->hostSchool ? ['id' => $w->hostSchool->id, 'name' => $w->hostSchool->name_ar, 'name_en' => $w->hostSchool->name_en] : null,
            'community' => $w->community ? ['id' => $w->community->id, 'title' => $w->community->title()] : null,
            'coordinator' => $w->coordinator?->displayName(), 'updated_at' => $w->updated_at,
        ];
    }
}
