<?php

namespace App\Ai\Forecast;

use App\Models\Forecast;
use App\Models\IndividualNeed;
use App\Models\JobTitle;
use App\Models\ProfessionalLicence;
use App\Models\School;
use App\Models\Skill;
use App\Models\TrainingNeed;

/**
 * What training will be needed next year, by competency, job title and school. History = needs raised each year (school requests and individual gaps);
 * the forecast is exponential smoothing with a trend (Holt) over up to five years, with a range from how far past forecasts missed.
 * Licences that expire next year add to the demand of the job titles concerned. Each forecast carries its explanation and the history it used.
 */
class ForecastService
{
    /** @param  list<float>  $series  oldest first @return array{value: float, low: float, high: float, model: string} */
    public static function project(array $series, int $steps = 1): array
    {
        $n = count($series);
        if ($n === 0) {
            return ['value' => 0.0, 'low' => 0.0, 'high' => 0.0, 'model' => 'naive'];
        }
        if ($n < 3) {
            $trend = $n === 2 ? $series[1] - $series[0] : 0.0;
            $v = max(0.0, $series[$n - 1] + 0.5 * $trend * min($steps, 2));
            $band = max(1.0, 0.3 * $v);

            return ['value' => round($v, 1), 'low' => round(max(0, $v - $band), 1), 'high' => round($v + $band, 1), 'model' => 'naive'];
        }
        // Holt's linear method.
        $alpha = 0.6;
        $beta = 0.3;
        $level = $series[0];
        $trend = $series[1] - $series[0];
        $errors = [];
        for ($i = 1; $i < $n; $i++) {
            $forecast = $level + $trend;
            $errors[] = $series[$i] - $forecast;
            $prev = $level;
            $level = $alpha * $series[$i] + (1 - $alpha) * ($level + $trend);
            $trend = $beta * ($level - $prev) + (1 - $beta) * $trend;
        }
        $damped = 0.0;
        for ($h = 1; $h <= max(1, $steps); $h++) {
            $damped += 0.8 ** $h;     // the trend fades the further ahead we look
        }
        $v = max(0.0, $level + $damped * $trend);
        $sd = sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / max(1, count($errors)));
        $band = max(0.15 * $v, 1.28 * $sd);

        return ['value' => round($v, 1), 'low' => round(max(0, $v - $band), 1), 'high' => round($v + $band, 1), 'model' => 'ets'];
    }

    /** Rebuilds the forecasts of the next year. @return int forecasts stored */
    public function run(?int $year = null): int
    {
        $year ??= (int) now()->year + 1;
        Forecast::where('year', $year)->delete();
        $n = 0;
        $last = min($year - 1, (int) now()->year - 1);   // the year in progress is not history yet
        $steps = $year - $last;
        $years = range($last - 4, $last);

        // Competencies: school requests + individual gaps raised each year.
        $needs = TrainingNeed::query()->whereNotNull('skill_id')->get(['skill_id', 'employees_count', 'created_at'])->groupBy('skill_id');
        $indiv = IndividualNeed::query()->get(['skill_id', 'created_at'])->groupBy('skill_id');
        $skills = array_unique(array_merge($needs->keys()->all(), $indiv->keys()->all()));
        $names = Skill::whereIn('id', $skills)->get()->keyBy('id');
        foreach ($skills as $sid) {
            $series = [];
            foreach ($years as $y) {
                $series[] = (float) ($needs->get($sid, collect())->filter(fn ($r) => (int) $r->created_at->year === $y)->sum(fn ($r) => max(1, (int) $r->employees_count)) + $indiv->get($sid, collect())->filter(fn ($r) => (int) $r->created_at->year === $y)->count());
            }
            $n += $this->store('competency', $sid, $names[$sid]?->name_en ?: (string) $sid, $year, $years, $series, [], 0.0, $steps);
        }

        // Job titles: individual needs by the employee's job, plus licences expiring next year.
        $jobRows = IndividualNeed::query()->join('employees', 'employees.id', '=', 'individual_needs.employee_id')->whereNotNull('employees.job_title_id')->get(['employees.job_title_id as jid', 'individual_needs.created_at'])->groupBy('jid');
        $licences = ProfessionalLicence::query()->join('employees', 'employees.id', '=', 'professional_licences.employee_id')->whereYear('professional_licences.expires_at', $year)->selectRaw('employees.job_title_id as jid, count(*) as c')->groupBy('employees.job_title_id')->pluck('c', 'jid');
        $jobs = array_unique(array_merge($jobRows->keys()->all(), $licences->keys()->all()));
        $jobNames = JobTitle::whereIn('id', $jobs)->get()->keyBy('id');
        foreach ($jobs as $jid) {
            $series = [];
            foreach ($years as $y) {
                $series[] = (float) $jobRows->get($jid, collect())->filter(fn ($r) => (int) $r->created_at->year === $y)->count();
            }
            $extra = (float) ($licences[$jid] ?? 0);
            $n += $this->store('job', $jid, $jobNames[$jid]?->name_en ?: (string) $jid, $year, $years, $series, $extra ? ['licence_expiries' => $extra] : [], $extra, $steps);
        }

        // Schools: requests submitted by each school.
        $bySchool = TrainingNeed::query()->whereNotNull('school_id')->get(['school_id', 'employees_count', 'created_at'])->groupBy('school_id');
        $schoolNames = School::whereIn('id', $bySchool->keys())->get()->keyBy('id');
        foreach ($bySchool as $sid => $rows) {
            $series = array_map(fn ($y) => (float) $rows->filter(fn ($r) => (int) $r->created_at->year === $y)->sum(fn ($r) => max(1, (int) $r->employees_count)), $years);
            $n += $this->store('school', $sid, $schoolNames[$sid]?->name_en ?: (string) $sid, $year, $years, $series, [], 0.0, $steps);
        }

        return $n;
    }

    /** @param  list<int>  $years  @param  list<float>  $series  @param  array<string, float>  $signals */
    private function store(string $dimension, string $subject, string $label, int $year, array $years, array $series, array $signals, float $extra = 0.0, int $steps = 1): int
    {
        $series = array_values($series);
        // Drop leading years with no data so a young history is not read as growth from zero.
        while (count($series) > 1 && $series[0] === 0.0) {
            array_shift($series);
            array_shift($years);
        }
        if (array_sum($series) + $extra <= 0) {
            return 0;
        }
        $f = self::project($series, $steps);
        $value = round($f['value'] + $extra, 1);
        $trend = count($series) >= 2 ? end($series) - $series[count($series) - 2] : 0;
        $why = match ($f['model']) {
            'ets' => sprintf('Exponential smoothing with trend over %d years (%s); last year %s, change %+.1f.', count($series), implode(', ', array_map(fn ($v) => (string) $v, $series)), end($series), $trend),
            default => sprintf('Short history (%d year%s: %s): last value carried forward with half the latest change.', count($series), count($series) === 1 ? '' : 's', implode(', ', array_map(fn ($v) => (string) $v, $series))),
        };
        if ($extra) {
            $why .= sprintf(' Plus %s licence expiries next year.', $extra);
        }
        Forecast::create(['dimension' => $dimension, 'subject_id' => $subject, 'label' => mb_substr($label, 0, 190), 'year' => $year, 'value' => $value, 'low' => round($f['low'] + $extra, 1), 'high' => round($f['high'] + $extra, 1), 'model' => $f['model'], 'explanation' => $why,
            'history' => ['years' => array_slice($years, -count($series)), 'values' => $series, 'signals' => $signals]]);

        return 1;
    }

    /** How sure we are, 0..1: more history and a narrower range mean more confidence. */
    public static function confidence(Forecast $f): float
    {
        $n = count($f->history['values'] ?? []);
        $width = $f->value > 0 ? ($f->high - $f->low) / max(1, $f->value) : 1;

        return round(max(0.1, min(0.95, 0.25 + 0.12 * $n - 0.3 * min(1, $width))), 2);
    }
}
