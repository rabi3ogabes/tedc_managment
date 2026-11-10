<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A school's page: its staff, and the history of the training they attended as a timeline — for the whole school (year by year, course by
 * course, how many of the staff attended) or for one member of staff. Counts and course data only; national IDs never leave the server.
 */
class SchoolTrainingController extends Controller
{
    /** The school's staff with the hours each trained and when they last did. */
    public function staff(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($school);
        $approved = fn (Builder $q) => $q->where('status', 'approved');
        $staff = Employee::with(['user:id,name,name_ar', 'jobTitle:id,name_ar,name_en', 'department:id,name_ar,name_en'])
            ->where('school_id', $school->id)
            ->tap(fn ($q) => $this->scope()->constrainEmployees($q))
            ->withCount(['pdActivities as courses_count' => $approved])
            ->withSum(['pdActivities as hours_total' => $approved], 'approved_hours')
            ->withMax(['pdActivities as last_course_on' => $approved], 'starts_on')
            ->when($request->query('job_title_id'), fn ($q, $id) => $q->where('job_title_id', $id))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('status', 'inactive'), fn ($q) => $q->where('status', '!=', 'inactive'))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->whereLike('employee_no', "%{$term}%")
                ->unless(ctype_digit(trim((string) $term)), fn ($x) => $x->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$term}%")->orWhereLike('name_ar', "%{$term}%")))))
            ->when($request->query('sort') === 'hours', fn ($q) => $q->orderByDesc('hours_total'), fn ($q) => $q->orderBy('employee_no'))
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $staff->getCollection()->map(fn (Employee $e) => [
                'id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->user?->name_ar ?: $e->user?->name, 'job_title' => $e->jobTitle?->name_ar, 'job_title_en' => $e->jobTitle?->name_en,
                'unit' => $e->department?->name_ar ?? $e->sub_unit, 'status' => $e->status, 'hire_date' => $e->hire_date?->toDateString(),
                'courses' => (int) $e->courses_count, 'hours' => round((float) ($e->hours_total ?? 0), 1), 'last_course_on' => $e->last_course_on ? substr((string) $e->last_course_on, 0, 10) : null,
            ])->values(),
            'total' => $staff->total(), 'current_page' => $staff->currentPage(), 'last_page' => $staff->lastPage(),
        ]);
    }

    /**
     * The timeline. Without `employee_id`: the school's totals, every year with its numbers, and the courses of `year` (default the latest) with
     * how many of the staff attended each. With `employee_id`: that person's totals and every course they attended, newest first.
     */
    public function timeline(Request $request, School $school): JsonResponse
    {
        $this->authorizeSchool($school);
        $request->validate(['employee_id' => ['nullable', 'uuid'], 'year' => ['nullable', 'integer', 'between:1990,2100']]);

        // Only the school's staff this administrator may see.
        $visible = Employee::where('school_id', $school->id)->tap(fn ($q) => $this->scope()->constrainEmployees($q))->select('id');
        $base = DB::table('pd_activities as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.status', 'approved')->whereIn('a.employee_id', $visible);
        $hours = 'coalesce(a.approved_hours, a.computed_hours, 0)';
        $yearOf = DB::getDriverName() === 'sqlite' ? "cast(strftime('%Y', a.starts_on) as integer)" : 'extract(year from a.starts_on)';

        if ($employeeId = $request->query('employee_id')) {
            $person = Employee::where('school_id', $school->id)->findOrFail($employeeId);
            $own = (clone $base)->where('a.employee_id', $person->id);
            $rows = (clone $own)->orderByDesc('a.starts_on')->get(['a.id', 'a.title', 'a.provider', 'a.track', 'a.course_type', 'a.starts_on', 'a.ends_on', 'a.location', DB::raw("$hours as hours")]);
            $byYear = (clone $own)->selectRaw("$yearOf as year, count(*) as records, sum($hours) as hours")->groupBy('year')->orderByDesc('year')->get();

            return response()->json(['data' => [
                'scope' => 'employee',
                'summary' => ['courses' => $rows->count(), 'hours' => round((float) $rows->sum('hours'), 1), 'first_on' => $rows->min('starts_on'), 'last_on' => $rows->max('starts_on')],
                'by_year' => $byYear->map(fn ($y) => ['year' => (int) $y->year, 'courses' => (int) $y->records, 'hours' => round((float) $y->hours, 1)])->values(),
                'courses' => $rows->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'provider' => $r->provider, 'track' => $r->track, 'type' => $r->course_type, 'starts_on' => $r->starts_on, 'ends_on' => $r->ends_on, 'country' => $r->location, 'hours' => round((float) $r->hours, 1)])->values(),
            ]]);
        }

        $people = Employee::where('school_id', $school->id)->tap(fn ($q) => $this->scope()->constrainEmployees($q));
        $byYear = (clone $base)->selectRaw("$yearOf as year, count(*) as records, count(distinct a.employee_id) as people, count(distinct a.title || '|' || a.starts_on) as courses, sum($hours) as hours")
            ->groupBy('year')->orderByDesc('year')->get();
        $year = (int) ($request->query('year') ?: ($byYear->first()->year ?? 0));
        $courses = $year === 0 ? collect() : (clone $base)->whereRaw("$yearOf = ?", [$year])
            ->selectRaw("a.title, a.starts_on, a.ends_on, max(a.track) as track, max(a.course_type) as course_type, max(a.provider) as provider, count(distinct a.employee_id) as attendees, sum($hours) as hours, max($hours) as hours_each")
            ->groupBy('a.title', 'a.starts_on', 'a.ends_on')->orderByDesc('a.starts_on')->orderByDesc('attendees')->limit(400)->get();
        $trained = (clone $base)->distinct()->count('a.employee_id');

        return response()->json(['data' => [
            'scope' => 'school',
            'summary' => ['staff' => (clone $people)->count(), 'active_staff' => (clone $people)->where('status', '!=', 'inactive')->count(), 'trained' => $trained,
                'records' => (int) $byYear->sum('records'), 'hours' => round((float) $byYear->sum('hours'), 1)],
            'by_year' => $byYear->map(fn ($y) => ['year' => (int) $y->year, 'records' => (int) $y->records, 'people' => (int) $y->people, 'courses' => (int) $y->courses, 'hours' => round((float) $y->hours, 1)])->values(),
            'year' => $year ?: null,
            'courses' => $courses->map(fn ($c) => ['title' => $c->title, 'starts_on' => $c->starts_on, 'ends_on' => $c->ends_on, 'track' => $c->track, 'type' => $c->course_type, 'provider' => $c->provider,
                'attendees' => (int) $c->attendees, 'hours_each' => round((float) $c->hours_each, 1), 'hours' => round((float) $c->hours, 1)])->values(),
        ]]);
    }

    private function authorizeSchool(School $school): void
    {
        abort_unless($this->scope()->allowsSchool($school->id), 403);
    }
}
