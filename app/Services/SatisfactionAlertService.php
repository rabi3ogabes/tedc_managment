<?php

namespace App\Services;

use App\Models\Evaluation;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SatisfactionAlert;
use App\Models\TrainingGroup;
use App\Models\User;

/** The RFP rule: when enough trainees have answered and the average satisfaction is low, tell the leadership and the supervisor — once per group. */
class SatisfactionAlertService
{
    public function __construct(private readonly EvaluationSettings $settings, private readonly NotificationService $notifications) {}

    /** @return array{responses: int, expected: int, response_rate: float, average: ?float} */
    public function stats(Program $program, ?TrainingGroup $group): array
    {
        $expected = Registration::where('program_id', $program->id)->when($group, fn ($q) => $q->where('training_group_id', $group->id))->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->count();
        $evals = Evaluation::whereHas('registration', fn ($q) => $q->where('program_id', $program->id)->when($group, fn ($w) => $w->where('training_group_id', $group->id)))->get();

        return ['responses' => $evals->count(), 'expected' => $expected, 'response_rate' => $expected ? round($evals->count() / $expected * 100, 1) : 0.0, 'average' => $evals->isNotEmpty() ? round((float) $evals->avg('satisfaction_score'), 1) : null];
    }

    public function check(Registration $registration): ?SatisfactionAlert
    {
        $registration->loadMissing('program', 'trainingGroup');

        return $this->evaluate($registration->program, $registration->trainingGroup);
    }

    public function evaluate(Program $program, ?TrainingGroup $group): ?SatisfactionAlert
    {
        $rule = $this->settings->get('alerts');
        if (! $rule['is_active'] || SatisfactionAlert::where('program_id', $program->id)->where('group_id', $group?->id)->exists()) {
            return null;
        }
        $s = $this->stats($program, $group);
        if ($s['average'] === null || $s['response_rate'] < $rule['min_response_rate'] || $s['average'] >= $rule['threshold']) {
            return null;
        }

        $alert = SatisfactionAlert::create(['program_id' => $program->id, 'group_id' => $group?->id, 'response_rate' => $s['response_rate'], 'average' => $s['average'], 'threshold' => $rule['threshold'], 'notified_at' => now()]);
        $label = $group ? "{$program->title_ar} — {$group->code}" : $program->title_ar;
        $labelEn = $group ? "{$program->title_en} — {$group->code}" : $program->title_en;
        foreach ($this->recipients($program, (array) $rule['recipients']) as $uid) {
            $this->notifications->send($uid, 'satisfaction.low_alert', ['ar' => 'تنبيه: رضا منخفض عن برنامج', 'en' => 'Alert: low satisfaction'],
                ['ar' => "متوسط الرضا في «{$label}» {$s['average']}٪ (نسبة الاستجابة {$s['response_rate']}٪) وهو أقل من {$rule['threshold']}٪.", 'en' => "Average satisfaction in \"{$labelEn}\" is {$s['average']}% (response rate {$s['response_rate']}%), below {$rule['threshold']}%."], ['program_id' => $program->id, 'group_id' => $group?->id]);
        }

        return $alert;
    }

    /** The hourly safety net: every group with answers is checked again. */
    public function sweep(): int
    {
        $n = 0;
        Program::whereHas('registrations.evaluation')->each(function (Program $p) use (&$n) {
            $groups = TrainingGroup::where('program_id', $p->id)->get();
            foreach ($groups->isEmpty() ? [null] : $groups as $g) {
                $n += (int) (bool) $this->evaluate($p, $g);
            }
        });

        return $n;
    }

    /** Groups ranked by average satisfaction (the leadership dashboard uses this). @return array{top: list<array<string, mixed>>, bottom: list<array<string, mixed>>} */
    public function ranking(int $limit = 20): array
    {
        $rows = [];
        TrainingGroup::with('program:id,code,title_ar,title_en')->get()->each(function (TrainingGroup $g) use (&$rows) {
            $s = $this->stats($g->program, $g);
            if ($s['average'] !== null) {
                $rows[] = ['group_id' => $g->id, 'group' => $g->code, 'program' => $g->program->title_ar, 'program_en' => $g->program->title_en] + $s;
            }
        });
        usort($rows, fn ($a, $b) => $b['average'] <=> $a['average']);

        return ['top' => array_slice($rows, 0, $limit), 'bottom' => array_slice(array_reverse($rows), 0, $limit)];
    }

    /** @param list<string> $roles @return list<string> */
    private function recipients(Program $program, array $roles): array
    {
        $ids = [];
        $slugs = array_values(array_intersect($roles, [Role::CENTER_LEADERSHIP, Role::PLANNING_HEAD, Role::CENTER_ADMIN]));
        if ($slugs) {
            $ids = User::whereHas('roles', fn ($q) => $q->whereIn('slug', $slugs))->where('status', 'active')->pluck('id')->all();
        }
        if (in_array('program_coordinator', $roles, true) && $program->coordinator_id) {
            $ids[] = $program->coordinator_id;
        }

        return array_values(array_unique($ids));
    }
}
