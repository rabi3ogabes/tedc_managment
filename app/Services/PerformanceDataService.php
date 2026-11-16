<?php

namespace App\Services;

use App\Models\ClassroomObservation;
use App\Models\Employee;
use App\Models\PerformanceAppraisal;
use App\Models\Skill;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Imports of appraisals and classroom observations (with a validation report) and the weak-performer report. */
class PerformanceDataService
{
    public const RATINGS = ['excellent' => ['excellent', 'ممتاز'], 'very_good' => ['very_good', 'very good', 'جيد جدا', 'جيد جداً'], 'good' => ['good', 'جيد'], 'acceptable' => ['acceptable', 'مقبول'], 'weak' => ['weak', 'ضعيف']];

    public function __construct(private readonly IndividualNeedsService $needs) {}

    /** @return list<array<string, mixed>> */
    public function readRows(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($sheet) ?? []);

        return array_map(fn ($r) => array_combine($header, array_pad(array_slice($r, 0, count($header)), count($header), null)), $sheet);
    }

    /** @return array{imported: int, updated: int, errors: list<array{line: int, reason: string}>} */
    public function importAppraisals(array $rows): array
    {
        $imported = $updated = 0;
        $errors = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $no = trim((string) ($row['employee_no'] ?? ''));
            if ($no === '' && trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }
            $employee = Employee::where('employee_no', $no)->first();
            $year = (int) ($row['year'] ?? 0);
            $rating = $this->rating((string) ($row['rating'] ?? ''));
            $score = $row['score'] ?? null;
            $reason = match (true) {
                ! $employee => "unknown employee {$no}",
                $year < 2000 || $year > (int) now()->year + 1 => 'invalid year',
                ! $rating => 'invalid rating',
                $score !== null && $score !== '' && (! is_numeric($score) || $score < 0 || $score > 100) => 'score must be 0-100',
                isset($seen["{$no}|{$year}"]) => 'duplicate in file',
                default => null,
            };
            if ($reason) {
                $errors[] = ['line' => $line, 'reason' => $reason];

                continue;
            }
            $seen["{$no}|{$year}"] = true;
            $row = PerformanceAppraisal::updateOrCreate(['employee_id' => $employee->id, 'year' => $year], ['rating_code' => $rating, 'score' => is_numeric($score) ? (int) $score : null, 'source' => 'import', 'imported_at' => now()]);
            $row->wasRecentlyCreated ? $imported++ : $updated++;
        }

        return ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
    }

    /** Columns: employee_no, observed_on, observer_role, subject, grade, overall, notes and `skill:<code>` scores (1-5). */
    public function importObservations(array $rows): array
    {
        $imported = 0;
        $errors = [];
        $skills = Skill::pluck('id', 'code');
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $no = trim((string) ($row['employee_no'] ?? ''));
            if ($no === '' && trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }
            $employee = Employee::where('employee_no', $no)->first();
            $date = strtotime((string) ($row['observed_on'] ?? ''));
            $scores = [];
            $bad = null;
            foreach ($row as $col => $v) {
                if (str_starts_with((string) $col, 'skill:') && $v !== null && $v !== '') {
                    $id = $skills[substr($col, 6)] ?? null;
                    if (! $id || ! is_numeric($v) || $v < 1 || $v > 5) {
                        $bad = "bad competency column or score ({$col})";
                        break;
                    }
                    $scores[$id] = (int) $v;
                }
            }
            $reason = ! $employee ? "unknown employee {$no}" : (! $date ? 'invalid observed_on' : $bad);
            if ($reason) {
                $errors[] = ['line' => $line, 'reason' => $reason];

                continue;
            }
            ClassroomObservation::updateOrCreate(['employee_id' => $employee->id, 'observed_on' => date('Y-m-d', $date), 'subject' => $row['subject'] ?? null],
                ['observer_role' => $row['observer_role'] ?? null, 'grade' => $row['grade'] ?? null, 'scores' => $scores, 'overall' => is_numeric($row['overall'] ?? null) ? (int) $row['overall'] : null, 'notes' => $row['notes'] ?? null, 'source' => 'import']);
            $imported++;
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /** Employees rated in the chosen ratings in the chosen years. @return Collection<int, array<string, mixed>> */
    public function weak(User $user, array $years, array $ratings): Collection
    {
        $rows = PerformanceAppraisal::whereIn('year', $years)->whereIn('rating_code', $ratings)->with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en', 'employee.jobTitle:id,name_ar,name_en'])->get()
            ->filter(fn ($a) => $a->employee && AccessScope::current($user)->allowsEmployee($a->employee));

        return $rows->groupBy('employee_id')->map(fn ($g) => [
            'employee_id' => $g->first()->employee_id, 'name' => $g->first()->employee->user?->displayName(), 'employee_no' => $g->first()->employee->employee_no,
            'school' => $g->first()->employee->school?->translate('name'), 'job_title' => $g->first()->employee->jobTitle?->translate('name'),
            'ratings' => $g->map(fn ($a) => ['year' => $a->year, 'rating' => $a->rating_code, 'score' => $a->score])->sortBy('year')->values()->all(),
        ])->values();
    }

    /** One-click "target these employees": a need per employee and competency, with the reason. @param  list<string>  $skillIds */
    public function target(User $user, array $years, array $ratings, array $skillIds, int $required): int
    {
        $created = [];
        foreach ($this->weak($user, $years, $ratings) as $w) {
            $employee = Employee::find($w['employee_id']);
            foreach ($skillIds as $skillId) {
                $need = $this->needs->upsert($employee, $skillId, 'appraisal', [
                    'required_level' => $required, 'priority_score' => $required * 10, 'source_ref' => ['years' => $years, 'ratings' => $ratings],
                    'explanation_ar' => 'استُهدف بسبب تقييم الأداء: '.implode('، ', array_column($w['ratings'], 'rating')).'.', 'explanation_en' => 'Targeted because of appraisal: '.implode(', ', array_column($w['ratings'], 'rating')).'.',
                ]);
                $need && $created[] = $need;
            }
        }
        $this->needs->notifyManagers($created);

        return count($created);
    }

    private function rating(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        foreach (self::RATINGS as $code => $aliases) {
            if (in_array($v, $aliases, true)) {
                return $code;
            }
        }

        return null;
    }
}
