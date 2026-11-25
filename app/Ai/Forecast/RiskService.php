<?php

namespace App\Ai\Forecast;

use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\ProfessionalLicence;
use App\Models\Program;
use App\Models\Registration;
use App\Models\RiskFlag;
use App\Models\TrainingGroup;
use App\Services\AnnualHoursService;

/**
 * Early warnings with reasons: people likely to miss their annual hours, licences about to expire, groups likely to run under-filled, programmes with weak satisfaction.
 * Flags are recomputed each night; those that no longer apply are marked resolved (not deleted), so the history stays.
 */
class RiskService
{
    public function __construct(private readonly AnnualHoursService $hours) {}

    /** @return array{raised: int, resolved: int} */
    public function run(): array
    {
        $keep = [];
        foreach ([...$this->hoursShortfall(), ...$this->licences(), ...$this->underfill(), ...$this->satisfaction()] as $f) {
            $row = RiskFlag::updateOrCreate(['type' => $f['type'], 'subject_id' => $f['subject_id']], ['subject_type' => $f['subject_type'], 'label' => mb_substr($f['label'], 0, 190), 'score' => round($f['score'], 2), 'reasons' => $f['reasons'], 'resolved_at' => null]);
            $keep[] = $row->id;
        }
        $resolved = RiskFlag::whereNull('resolved_at')->whereNotIn('id', $keep ?: ['00000000-0000-0000-0000-000000000000'])->update(['resolved_at' => now()]);

        return ['raised' => count($keep), 'resolved' => $resolved];
    }

    /** Projected hours at the current pace fall short of the target. @return list<array<string, mixed>> */
    private function hoursShortfall(): array
    {
        $year = $this->hours->yearOf();
        [$from, $to] = $this->hours->bounds($year);
        $elapsed = max(0.1, min(1.0, $from->diffInDays(now()) / max(1, $from->diffInDays($to))));
        $out = [];
        Employee::where('status', 'active')->limit(5000)->get()->each(function (Employee $e) use ($year, $elapsed, &$out) {
            $s = $this->hours->summary($e, $year);
            if (! $s['target'] || $s['target'] <= 0) {
                return;
            }
            $projected = $s['total'] / $elapsed;
            $score = max(0.0, ($s['target'] - $projected) / $s['target']);
            if ($score >= 0.3 && $s['shortfall'] > 0) {
                $out[] = ['type' => 'hours_shortfall', 'subject_type' => 'employee', 'subject_id' => $e->id, 'label' => $e->user?->name ?? $e->employee_no, 'score' => min(1, $score),
                    'reasons' => ["{$s['total']} of {$s['target']} hours done with ".round((1 - $elapsed) * 100).'% of the year left', 'At this pace the projected total is '.round($projected, 1).' hours']];
            }
        });

        return $out;
    }

    /** Licence expiring within 180 days while the person is also short of hours. @return list<array<string, mixed>> */
    private function licences(): array
    {
        $out = [];
        foreach (ProfessionalLicence::with('employee')->where('status', 'active')->whereBetween('expires_at', [now(), now()->addDays(180)])->get() as $l) {
            $days = (int) now()->diffInDays($l->expires_at);
            $hours = $l->employee ? $this->hours->summary($l->employee) : null;
            $short = $hours && ($hours['shortfall'] ?? 0) > 0;
            $score = min(1, (180 - $days) / 180 * 0.7 + ($short ? 0.3 : 0));
            if ($score >= 0.35 && $l->employee) {
                $out[] = ['type' => 'licence_gap', 'subject_type' => 'employee', 'subject_id' => $l->employee_id, 'label' => $l->employee->user?->name ?? $l->employee->employee_no, 'score' => $score,
                    'reasons' => ["Licence expires in {$days} days", $short ? "{$hours['shortfall']} professional-development hours still missing" : 'Hours are on track']];
            }
        }

        return $out;
    }

    /** Groups starting soon that hold less than 40% of their seats. @return list<array<string, mixed>> */
    private function underfill(): array
    {
        $out = [];
        foreach (TrainingGroup::with('program:id,title_en')->whereBetween('start_date', [now(), now()->addDays(45)])->where('capacity', '>', 0)->get() as $g) {
            $taken = Registration::where('training_group_id', $g->id)->whereIn('status', Registration::SEAT_HOLDING)->count();
            $fill = $taken / $g->capacity;
            if ($fill < 0.4) {
                $out[] = ['type' => 'group_underfill', 'subject_type' => 'group', 'subject_id' => $g->id, 'label' => ($g->program?->title_en ?? '').' · '.$g->displayTitle('en'), 'score' => 1 - $fill,
                    'reasons' => ["{$taken} of {$g->capacity} seats taken", 'Starts '.$g->start_date->toDateString()]];
            }
        }

        return $out;
    }

    /** Programmes whose participants were not satisfied, and that run again soon. @return list<array<string, mixed>> */
    private function satisfaction(): array
    {
        $out = [];
        $rows = Evaluation::query()->selectRaw('program_id, avg(satisfaction_score) as avg_score, count(*) as n')->groupBy('program_id')->havingRaw('count(*) >= 5')->get();
        foreach ($rows as $r) {
            if ((float) $r->avg_score < 70 && TrainingGroup::where('program_id', $r->program_id)->where('start_date', '>=', now())->exists()) {
                $out[] = ['type' => 'low_satisfaction', 'subject_type' => 'program', 'subject_id' => $r->program_id, 'label' => (string) Program::whereKey($r->program_id)->value('title_en'), 'score' => (100 - (float) $r->avg_score) / 100,
                    'reasons' => ['Average satisfaction '.round((float) $r->avg_score).'% from '.$r->n.' evaluations', 'The programme runs again soon']];
            }
        }

        return $out;
    }
}
