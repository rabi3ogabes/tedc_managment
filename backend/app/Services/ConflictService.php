<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Program;
use App\Models\ProgramEquivalence;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TrainingGroup;
use Illuminate\Support\Collection;

/** Time clashes between a trainee's programs, and repeats of the same or an equivalent program. */
class ConflictService
{
    /** Sessions a registration into this program/group would attend. */
    private function sessionsOf(Program $program, ?TrainingGroup $group): Collection
    {
        return ProgramSession::where('status', '!=', 'cancelled')->where(fn ($q) => $group && ! $program->hasSingleGroup() ? $q->where('training_group_id', $group->id) : $q->where('program_id', $program->id))->get();
    }

    /**
     * Overlaps with the employee's other registrations.
     *
     * @param  list<string>  $statuses  registration statuses that count
     * @return list<array{registration_id: string, program: string, group: ?string, session_id: string, starts_at: string, ends_at: string}>
     */
    public function conflicts(Employee $employee, Program $program, ?TrainingGroup $group, array $statuses, ?string $exceptRegistrationId = null): array
    {
        $mine = $this->sessionsOf($program, $group);
        if ($mine->isEmpty()) {
            return [];
        }
        $out = [];
        $others = Registration::with(['program', 'trainingGroup'])->where('employee_id', $employee->id)->whereIn('status', $statuses)->where('program_id', '!=', $program->id)
            ->when($exceptRegistrationId, fn ($q, $id) => $q->where('id', '!=', $id))->get();
        foreach ($others as $reg) {
            foreach ($this->sessionsOf($reg->program, $reg->trainingGroup) as $theirs) {
                $clash = $mine->first(fn ($m) => $m->starts_at < $theirs->ends_at && $m->ends_at > $theirs->starts_at);
                if ($clash) {
                    $out[] = ['registration_id' => $reg->id, 'program' => $reg->program->translate('title'), 'group' => $reg->trainingGroup?->code, 'session_id' => $theirs->id, 'starts_at' => $theirs->starts_at->toIso8601String(), 'ends_at' => $theirs->ends_at->toIso8601String()];

                    break;
                }
            }
        }

        return $out;
    }

    /** Programs counted as the same as $program (itself plus its equivalents, both ways when bidirectional). @return list<string> */
    public function equivalentIds(Program $program): array
    {
        $ids = [$program->id];
        foreach (ProgramEquivalence::where('program_id', $program->id)->orWhere(fn ($q) => $q->where('equivalent_program_id', $program->id)->where('bidirectional', true))->get() as $e) {
            $ids[] = $e->program_id === $program->id ? $e->equivalent_program_id : $e->program_id;
        }

        return array_values(array_unique($ids));
    }

    /** The completed registration that makes this a repeat, if any. */
    public function completedEquivalent(Employee $employee, Program $program): ?Registration
    {
        return Registration::with('program')->where('employee_id', $employee->id)->where('status', Registration::STATUS_COMPLETED)->whereIn('program_id', $this->equivalentIds($program))->first();
    }
}
