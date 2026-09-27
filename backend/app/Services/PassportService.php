<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Registration;

/**
 * Training Passport — the professional training profile of an employee.
 */
class PassportService
{
    public function build(Employee $employee): array
    {
        $employee->loadMissing(['user', 'skills', 'jobTitle', 'school']);

        $completed = Registration::with(['program.category', 'certificate'])
            ->where('employee_id', $employee->id)
            ->where('status', Registration::STATUS_COMPLETED)
            ->orderBy('completed_at')
            ->get();

        $certificates = Certificate::with('program')->where('employee_id', $employee->id)->where('status', 'valid')->latest('issued_at')->get();
        $attendedMinutes = Attendance::where('employee_id', $employee->id)->sum('minutes_attended');

        $inProgress = Registration::with('program')
            ->where('employee_id', $employee->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_WAITLISTED])
            ->get();

        $skillsByCategory = $employee->skills->groupBy('category')->map(fn ($skills, $category) => [
            'category' => $category,
            'average_level' => round($skills->avg(fn ($s) => $s->pivot->level), 2),
            'skills' => $skills->count(),
        ])->values();

        return [
            'summary' => [
                'total_hours' => (float) $certificates->sum('hours'),
                'attended_hours' => round($attendedMinutes / 60, 1),
                'completed_programs' => $completed->count(),
                'certificates' => $certificates->count(),
                'skills' => $employee->skills->count(),
                'in_progress' => $inProgress->count(),
                'avg_impact_score' => $completed->whereNotNull('impact_score')->avg('impact_score') ? round($completed->whereNotNull('impact_score')->avg('impact_score'), 1) : null,
            ],
            'hours_by_category' => $completed->groupBy(fn ($r) => $r->program->category?->translate('name') ?? '—')
                ->map(fn ($rows, $name) => ['category' => $name, 'hours' => (float) $rows->sum(fn ($r) => $r->program->total_hours)])
                ->values(),
            'skills' => $employee->skills->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->translate('name'),
                'category' => $s->category,
                'level' => (int) $s->pivot->level,
                'source' => $s->pivot->source,
                'verified_at' => $s->pivot->verified_at,
            ])->sortByDesc('level')->values(),
            'skills_by_category' => $skillsByCategory,
            'certificates' => $certificates->map(fn ($c) => [
                'id' => $c->id,
                'certificate_no' => $c->certificate_no,
                'program' => $c->program->translate('title'),
                'hours' => $c->hours,
                'issued_at' => $c->issued_at->toDateString(),
                'verification_url' => $c->verificationUrl(),
            ]),
            // Professional growth path: chronological milestones.
            'growth_path' => $completed->map(fn ($r) => [
                'date' => $r->completed_at?->toDateString(),
                'program' => $r->program->translate('title'),
                'level' => $r->program->level,
                'hours' => $r->program->total_hours,
                'category' => $r->program->category?->translate('name'),
            ])->values(),
            'in_progress' => $inProgress->map(fn ($r) => [
                'registration_id' => $r->id,
                'program' => $r->program->translate('title'),
                'status' => $r->status,
                'attendance_percent' => $r->attendance_percent,
                'start_date' => $r->program->start_date?->toDateString(),
            ]),
        ];
    }
}
