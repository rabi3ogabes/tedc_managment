<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProgramSession;
use App\Models\Trainer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Trainer availability and best-match suggestions.
 */
class TrainerService
{
    public function conflicts(string $trainerId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null): Collection
    {
        return ProgramSession::with('program:id,code,title_ar,title_en')
            ->where('trainer_id', $trainerId)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($exceptSessionId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->orderBy('starts_at')->get();
    }

    /**
     * @throws BusinessRuleException when the trainer is inactive or already teaching
     */
    public function assertAssignable(string $trainerId, CarbonInterface $start, CarbonInterface $end, ?string $exceptSessionId = null): void
    {
        $trainer = Trainer::findOrFail($trainerId);
        if ($trainer->status !== 'active') {
            throw new BusinessRuleException(__('messages.trainer.inactive', ['trainer' => $trainer->translate('name')]), 'trainer_inactive');
        }

        $conflicts = $this->conflicts($trainerId, $start, $end, $exceptSessionId);
        if ($conflicts->isNotEmpty()) {
            throw new BusinessRuleException(__('messages.trainer.busy', ['trainer' => $trainer->translate('name')]), 'trainer_conflict', [
                'sessions' => $conflicts->map(fn ($s) => ['id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'), 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()])->all(),
            ]);
        }
    }

    /**
     * Active trainers ranked for a topic: specialization match (60), rating (20) and, when a slot is
     * given, availability (20). Busy trainers are kept but listed after free ones.
     *
     * @param  array{specializations?: string[], sources?: string[], languages?: string[], starts_at?: ?CarbonInterface, ends_at?: ?CarbonInterface}  $need
     */
    public function suggest(array $need, int $limit = 20): Collection
    {
        $wanted = array_values(array_unique(array_map('mb_strtolower', $need['specializations'] ?? [])));
        $start = $need['starts_at'] ?? null;
        $end = $need['ends_at'] ?? null;

        $busy = $start && $end
            ? ProgramSession::whereNotNull('trainer_id')->where('status', '!=', 'cancelled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->pluck('trainer_id')->flip()
            : collect();

        return Trainer::with(['school:id,name_ar,name_en', 'partner:id,name_ar,name_en,type'])
            ->where('status', 'active')
            ->when($need['sources'] ?? null, fn ($q, $v) => $q->whereIn('source', $v))
            ->get()
            ->filter(fn (Trainer $t) => empty($need['languages']) || array_intersect(array_map('mb_strtolower', $need['languages']), array_map('mb_strtolower', $t->languages ?? [])))
            ->map(function (Trainer $trainer) use ($wanted, $busy, $start) {
                $has = array_map('mb_strtolower', $trainer->specializations ?? []);
                $matched = array_values(array_intersect($wanted, $has));
                $match = $wanted ? count($matched) / count($wanted) : 0;
                $isBusy = $busy->has($trainer->id);
                $score = (int) round(60 * $match + 20 * min(5, $trainer->rating) / 5 + ($start ? ($isBusy ? 0 : 20) : 20));

                return ['trainer' => $trainer, 'score' => $score, 'matched_specializations' => $matched, 'available' => ! $isBusy];
            })
            ->filter(fn ($r) => ! $wanted || $r['matched_specializations'])
            ->sortBy([['available', 'desc'], ['score', 'desc']])
            ->take($limit)->values();
    }
}
