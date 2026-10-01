<?php

namespace App\Services\Notifications;

use App\Models\Evaluation;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The program survey (the trainees' evaluation of a program): always available, opened by an administrator,
 * or opened automatically a number of hours after the program ends.
 */
class ProgramSurvey
{
    public function __construct(private readonly NotificationCampaigns $campaigns, private readonly NotificationTemplates $templates) {}

    /** When the last session (or the end date) finishes. */
    public function endsAt(Program $program): Carbon
    {
        $last = $program->sessions()->max('ends_at');

        return $last ? Carbon::parse($last) : Carbon::parse($program->end_date)->endOfDay();
    }

    public function autoOpensAt(Program $program): ?Carbon
    {
        return $program->survey_mode === 'auto' ? $this->endsAt($program)->addHours((int) $program->survey_auto_hours) : null;
    }

    /** @return Collection<int, string> user ids of the trainees (optionally only those who have not answered yet) */
    public function traineeUserIds(Program $program, bool $pendingOnly = false): Collection
    {
        return Registration::with('employee:id,user_id')->where('program_id', $program->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->when($pendingOnly, fn ($q) => $q->whereNotIn('id', Evaluation::where('program_id', $program->id)->select('registration_id')))
            ->get()->map(fn (Registration $r) => $r->employee?->user_id)->filter()->unique()->values();
    }

    /** @return array<string, mixed> */
    public function state(Program $program): array
    {
        $trainees = Registration::where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->count();
        $submitted = Evaluation::where('program_id', $program->id)->count();
        $open = $program->surveyIsOpen();
        $scheduled = $program->survey_mode === 'auto' && ! $program->survey_opened_at ? $this->autoOpensAt($program) : null;

        return [
            'mode' => $program->survey_mode, 'auto_hours' => (int) $program->survey_auto_hours,
            'status' => $program->survey_mode === 'always' ? 'always' : ($open ? 'open' : ($scheduled ? 'scheduled' : 'closed')),
            'is_open' => $open, 'opens_at' => $scheduled?->toIso8601String(), 'ends_at' => $this->endsAt($program)->toIso8601String(),
            'opened_at' => $program->survey_opened_at?->toIso8601String(), 'closed_at' => $program->survey_closed_at?->toIso8601String(),
            'trainees' => $trainees, 'submitted' => $submitted, 'pending' => max(0, $trainees - $submitted),
        ];
    }

    public function configure(Program $program, string $mode, ?int $hours = null): Program
    {
        $program->update(['survey_mode' => $mode, 'survey_auto_hours' => $hours ?? $program->survey_auto_hours]);
        if ($mode === 'always') {
            $program->update(['survey_opened_at' => null, 'survey_closed_at' => null]);
        }

        return $program->refresh();
    }

    /** Opens the survey now and (optionally) tells the trainees who have not answered. */
    public function open(Program $program, ?User $by, bool $notify = true): Program
    {
        $program->update(['survey_mode' => $program->survey_mode === 'always' ? 'manual' : $program->survey_mode, 'survey_opened_at' => now(), 'survey_closed_at' => null]);
        if ($notify) {
            $this->notify($program, $by, pendingOnly: true);
        }

        return $program->refresh();
    }

    public function close(Program $program): Program
    {
        $program->update(['survey_mode' => $program->survey_mode === 'always' ? 'manual' : $program->survey_mode, 'survey_closed_at' => now(), 'survey_opened_at' => $program->survey_opened_at ?? now()->subSecond()]);

        return $program->refresh();
    }

    /** Sends the "survey is open" notification. @return int people notified */
    public function notify(Program $program, ?User $by, bool $pendingOnly = true, array $override = []): int
    {
        $this->templates->flush();
        $ids = $this->traineeUserIds($program, $pendingOnly);
        if ($ids->isEmpty()) {
            return 0;
        }

        return $this->campaigns->send('survey.open', 'survey', $program, $ids, $by, $override, $pendingOnly ? 'pending_survey' : 'trainees')->recipients;
    }

    /** Opens every automatic survey whose time has come. @return int programs opened */
    public function openDue(): int
    {
        $opened = 0;
        Program::where('survey_mode', 'auto')->whereNull('survey_opened_at')->whereNotIn('status', [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED])->get()
            ->each(function (Program $program) use (&$opened) {
                if ($this->autoOpensAt($program)?->lte(now())) {
                    $this->open($program, null, notify: true);
                    $opened++;
                }
            });

        return $opened;
    }
}
