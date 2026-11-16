<?php

namespace App\Services\Library;

use App\Models\Employee;
use App\Models\JobGroup;
use Illuminate\Support\Collection;

/** A job group is a rule over job titles, categories, schools and subjects (principals, mathematics teachers, trainers …). */
class JobGroupService
{
    public function matches(JobGroup $g, Employee $e): bool
    {
        $e->loadMissing('jobTitle');
        $r = $g->rule ?? [];
        $checks = [
            'job_title_ids' => fn ($v) => in_array($e->job_title_id, $v, true),
            'job_categories' => fn ($v) => $e->jobTitle?->category !== null && in_array($e->jobTitle->category, $v, true),
            'school_ids' => fn ($v) => in_array($e->school_id, $v, true),
            'subjects' => fn ($v) => count(array_intersect(array_map('mb_strtolower', $v), array_map('mb_strtolower', $e->subjects ?? []))) > 0,
        ];
        $any = false;
        foreach ($checks as $k => $fn) {
            if (! empty($r[$k])) {
                $any = true;
                if (! $fn((array) $r[$k])) {
                    return false;
                }
            }
        }

        return $any;
    }

    /** @return Collection<int, Employee> */
    public function members(JobGroup $g, int $limit = 1000): Collection
    {
        $r = $g->rule ?? [];

        return Employee::with('jobTitle', 'user:id,name,name_ar')->when(! empty($r['job_title_ids']), fn ($q) => $q->whereIn('job_title_id', $r['job_title_ids']))->when(! empty($r['school_ids']), fn ($q) => $q->whereIn('school_id', $r['school_ids']))
            ->when(! empty($r['job_categories']), fn ($q) => $q->whereHas('jobTitle', fn ($j) => $j->whereIn('category', $r['job_categories'])))->limit($limit)->get()->filter(fn ($e) => $this->matches($g, $e))->values();
    }

    /** @return list<string> ids of the groups the employee belongs to */
    public function groupsOf(Employee $e): array
    {
        return JobGroup::all()->filter(fn ($g) => $this->matches($g, $e))->pluck('id')->all();
    }
}
