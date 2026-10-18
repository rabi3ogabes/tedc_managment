<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\PlcNeed;
use App\Models\PlcWorkshop;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;

/** The register of incoming needs and proposals, and their conversion into workshops. */
class NeedsService
{
    public function __construct(private readonly WorkshopService $workshops) {}

    /** The needs inside the person's scope. `$withCounts` adds the converted-workshops count per row; aggregate queries (group by) leave it out, since PostgreSQL refuses a plain column next to a grouped count. */
    public function query(User $user, bool $withCounts = true): Builder
    {
        $scope = AccessScope::current($user);
        $q = $withCounts ? PlcNeed::query()->withCount('workshops') : PlcNeed::query();
        if ($scope->isMinistryWide()) {
            return $q;
        }
        $ids = $scope->schoolIds() ?? [];

        return $q->where(function ($w) use ($ids, $user) {
            $w->where('created_by', $user->id);
            foreach ($ids as $id) {
                $w->orWhereJsonContains('school_ids', $id);
            }
        });
    }

    public function create(User $by, array $data): PlcNeed
    {
        if (! in_array($data['source'], PlcNeed::SOURCES, true)) {
            throw new BusinessRuleException(__('messages.plc.bad_source'), 'plc_bad_source');
        }
        $scope = AccessScope::current($by);
        $schoolIds = array_values(array_filter((array) ($data['school_ids'] ?? [])));
        if (! $scope->isMinistryWide()) {
            // School users register needs for their own schools only.
            $schoolIds = array_values(array_intersect($schoolIds ?: ($scope->schoolIds() ?? []), $scope->schoolIds() ?? []));
            if (! $schoolIds) {
                throw new BusinessRuleException(__('messages.plc.school_required'), 'plc_school_required');
            }
        }

        return PlcNeed::create(array_intersect_key($data, array_flip(['source', 'reference_no', 'title', 'description', 'domain', 'target_audience', 'received_at', 'source_url', 'notes', 'entity_name', 'proposer_name', 'justification', 'expected_outcomes']))
            + ['school_ids' => $schoolIds ?: null, 'review_status' => 'new', 'created_by' => $by->id]);
    }

    public function update(PlcNeed $need, User $by, array $data): PlcNeed
    {
        if ($need->review_status === 'converted' && (isset($data['source']) || isset($data['reference_no']))) {
            throw new BusinessRuleException(__('messages.plc.source_frozen'), 'plc_source_frozen');
        }
        $need->update(array_intersect_key($data, array_flip(['reference_no', 'title', 'description', 'domain', 'target_audience', 'received_at', 'source_url', 'notes', 'school_ids', 'entity_name', 'proposer_name', 'justification', 'expected_outcomes'])));
        if (isset($data['review_status']) && in_array($data['review_status'], ['new', 'under_review', 'declined'], true) && $need->review_status !== 'converted') {
            $need->update(['review_status' => $data['review_status'], 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        }

        return $need->refresh();
    }

    /** Turns a need into a workshop (one need may yield several). */
    public function convert(PlcNeed $need, User $by, array $data): PlcWorkshop
    {
        if ($need->review_status === 'declined') {
            throw new BusinessRuleException(__('messages.plc.need_declined'), 'plc_need_declined');
        }

        return $this->workshops->create($by, $data + ['need_id' => $need->id, 'title_ar' => $data['title_ar'] ?? $need->title, 'domain' => $need->domain, 'target_audience' => $need->target_audience]);
    }

    public function present(PlcNeed $n): array
    {
        return [
            'id' => $n->id, 'source' => $n->source, 'reference_no' => $n->reference_no, 'title' => $n->title, 'description' => $n->description, 'domain' => $n->domain,
            'target_audience' => $n->target_audience, 'received_at' => $n->received_at?->toDateString(), 'review_status' => $n->review_status, 'source_url' => $n->source_url, 'notes' => $n->notes,
            'school_ids' => $n->school_ids ?? [], 'entity_name' => $n->entity_name, 'proposer_name' => $n->proposer_name, 'justification' => $n->justification, 'expected_outcomes' => $n->expected_outcomes,
            'workshops' => $n->workshops_count ?? $n->workshops()->count(), 'created_at' => $n->created_at, 'updated_at' => $n->updated_at,
        ];
    }
}
