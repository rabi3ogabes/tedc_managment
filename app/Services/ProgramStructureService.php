<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\ProgramUnit;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The five-level structure: category → main program → sub-program → training group → workshop/day. */
class ProgramStructureService
{
    /**
     * Creates a sub-program under a main program. It starts as a draft copy of the main program's offer
     * (category, audience, level, rules and skills), which its own data then overrides.
     *
     * @param  array<string, mixed>  $data  title_ar, title_en and optionally code, summary, total_hours, capacity, objectives, axes…
     */
    public function createSub(Program $main, array $data, User $by): Program
    {
        if ($main->parent_id !== null || $main->kind === 'sub') {
            throw new BusinessRuleException(__('messages.structure.one_level'), 'one_level_only');
        }

        return DB::transaction(function () use ($main, $data, $by) {
            $next = $main->children()->withTrashed()->count() + 1;
            $inherited = $main->only(['category_id', 'delivery_mode', 'level', 'total_hours', 'capacity', 'min_attendance_percent', 'require_biometric', 'requires_tasks', 'requires_evaluation', 'registration_modes', 'audience', 'owner_type', 'owner_school_id']);

            $sub = Program::create(array_merge($inherited, $data) + [
                'code' => $data['code'] ?? $main->code.'-S'.$next, 'parent_id' => $main->id, 'kind' => 'sub', 'status' => Program::STATUS_DRAFT, 'created_by' => $by->id,
                'coordinator_id' => $main->coordinator_id, 'is_emergency' => $main->is_emergency, 'approval_status' => $main->approval_status,
            ]);
            $sub->skills()->sync($main->skills->mapWithKeys(fn ($s) => [$s->id => ['target_level' => $s->pivot->target_level]])->all());
            foreach ($main->targetGroups as $group) {
                $sub->targetGroups()->create($group->only(['job_title_id', 'department_id', 'school_type', 'education_stage', 'description']));
            }

            return $sub;
        });
    }

    /**
     * The program with its sub-programs and groups, and rolled-up numbers (groups, seats, hours, completion).
     *
     * @return array<string, mixed>
     */
    public function tree(Program $program): array
    {
        $program->loadMissing(['groups', 'children.groups']);
        $own = $this->node($program);
        $children = $program->children->map(fn (Program $c) => $this->node($c));

        $groups = $own['rollup']['groups'] + $children->sum(fn ($c) => $c['rollup']['groups']);
        $completed = $own['rollup']['completed'] + $children->sum(fn ($c) => $c['rollup']['completed']);

        return array_merge($own, [
            'children' => $children->values()->all(),
            'rollup' => [
                'groups' => $groups,
                'completed' => $completed,
                'seats_taken' => $own['rollup']['seats_taken'] + $children->sum(fn ($c) => $c['rollup']['seats_taken']),
                'capacity' => $own['rollup']['capacity'] + $children->sum(fn ($c) => $c['rollup']['capacity']),
                'hours' => $own['rollup']['hours'] + $children->sum(fn ($c) => $c['rollup']['hours']),
                'completion_percent' => $groups ? round($completed / $groups * 100, 1) : 0,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function node(Program $p): array
    {
        $groups = $p->groups->map(fn (TrainingGroup $g) => [
            'id' => $g->id, 'code' => $g->code, 'sequence' => $g->sequence, 'status' => $g->status, 'start_date' => $g->start_date?->toDateString(), 'end_date' => $g->end_date?->toDateString(),
            'capacity' => $g->capacity, 'seats_taken' => $g->seatsTaken(), 'is_emergency' => $g->is_emergency, 'title' => $g->displayTitle(),
        ]);

        return [
            'id' => $p->id, 'code' => $p->code, 'kind' => $p->kind, 'title' => $p->translate('title'), 'status' => $p->status, 'total_hours' => (float) $p->total_hours, 'is_emergency' => $p->is_emergency,
            'groups' => $groups->values()->all(),
            'rollup' => [
                'groups' => $groups->count(), 'completed' => $groups->where('status', TrainingGroup::COMPLETED)->count(), 'seats_taken' => $groups->sum('seats_taken'),
                'capacity' => $groups->sum('capacity'), 'hours' => (float) $p->total_hours,
                'completion_percent' => $groups->count() ? round($groups->where('status', TrainingGroup::COMPLETED)->count() / $groups->count() * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Replaces the program's units (in the given order) with their objectives, hours and competencies.
     *
     * @param  list<array<string, mixed>>  $units
     * @return Collection<int, ProgramUnit>
     */
    public function syncUnits(Program $program, array $units)
    {
        return DB::transaction(function () use ($program, $units) {
            $program->units()->delete();
            foreach (array_values($units) as $i => $u) {
                $unit = $program->units()->create([
                    'sort_order' => $i, 'title_ar' => $u['title_ar'], 'title_en' => $u['title_en'], 'objectives' => $u['objectives'] ?? [], 'hours' => $u['hours'] ?? 0,
                    'summary_ar' => $u['summary_ar'] ?? null, 'summary_en' => $u['summary_en'] ?? null,
                ]);
                $unit->skills()->sync($u['skill_ids'] ?? []);
            }

            return $program->units()->with('skills:id,code,name_ar,name_en')->get();
        });
    }
}
