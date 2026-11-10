<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\EmployeePathProgress;
use App\Models\KnowledgeTransfer;
use App\Models\PdActivity;
use App\Models\ProfessionalLicence;
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
            // Phase 09: every source of professional growth in one place.
            'hours_this_year' => app(AnnualHoursService::class)->summary($employee),
            'external_pd' => PdActivity::with('type:id,name_ar,name_en')->where('employee_id', $employee->id)->where('status', 'approved')->orderByDesc('starts_on')->limit(50)->get()->map(fn ($a) => ['title' => $a->title, 'type' => $a->type?->translate('name'), 'date' => $a->starts_on->toDateString(), 'hours' => (float) ($a->approved_hours ?? $a->computed_hours)])->values(),
            'knowledge_transfers' => KnowledgeTransfer::where('employee_id', $employee->id)->where('status', 'approved')->get()->map(fn ($k) => ['date' => $k->delivered_on?->toDateString(), 'hours' => $k->hours, 'beneficiaries' => $k->beneficiary_count])->values(),
            'licences' => ProfessionalLicence::with('path:id,title_ar,title_en')->where('employee_id', $employee->id)->orderByDesc('issued_at')->get()->map(fn ($l) => ['licence_no' => $l->licence_no, 'level' => $l->level_no, 'path' => $l->path?->translate('title'), 'status' => $l->status, 'expires_at' => $l->expires_at?->toDateString()])->values(),
            'paths' => EmployeePathProgress::with('path:id,type,title_ar,title_en')->where('employee_id', $employee->id)->get()->map(fn ($p) => ['title' => $p->path?->translate('title'), 'type' => $p->path?->type, 'current_level' => $p->current_level_no, 'status' => $p->status])->values(),
        ];
    }
}
