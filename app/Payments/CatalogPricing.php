<?php

namespace App\Payments;

use App\Models\Employee;
use App\Models\PriceList;
use App\Models\Program;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\FeatureSettings;
use Illuminate\Support\Str;

/** The price shown on the catalogue: "free for you", the price for this person, or the range for a visitor. Nothing is shown while payments are off. */
class CatalogPricing
{
    public function __construct(private readonly PricingService $pricing, private readonly FeatureSettings $features) {}

    /** @return array<string, mixed>|null */
    public function forProgram(Program $program, ?User $user, bool $withGroups = false): ?array
    {
        if (! $this->features->enabled('payments')) {
            return null;
        }
        $employee = $user ? Employee::where('user_id', $user->id)->first() : null;
        $groups = $program->relationLoaded('groups') ? $program->groups : TrainingGroup::where('program_id', $program->id)->get();
        $hasList = PriceList::where('is_active', true)->where(fn ($q) => $q->where('program_id', $program->id)->orWhereIn('group_id', $groups->pluck('id')))->exists();
        if (! $hasList) {
            return ['paid' => false, 'free_for_you' => false, 'price' => 0.0, 'from' => 0.0, 'to' => 0.0, 'currency' => 'QAR'];
        }
        $rows = $groups->map(fn (TrainingGroup $g) => ['group_id' => $g->id] + $this->pricing->display($g, $employee));
        $all = $rows->isEmpty() ? [$this->fromProgramList($program, $employee)] : $rows->all();
        $mine = array_values(array_filter(array_column($all, 'price'), fn ($p) => $p !== null));

        return ['paid' => (bool) array_filter($all, fn ($r) => $r['paid']), 'free_for_you' => (bool) array_filter($all, fn ($r) => $r['free_for_you']) && ! array_filter($all, fn ($r) => $r['paid']), 'price' => $mine ? min($mine) : 0.0,
            'from' => min(array_column($all, 'from')), 'to' => max(array_column($all, 'to')), 'currency' => $all[0]['currency'] ?? 'QAR'] + ($withGroups ? ['groups' => $rows->keyBy('group_id')->all()] : []);
    }

    /** @return array<string, mixed> */
    private function fromProgramList(Program $program, ?Employee $employee): array
    {
        $g = new TrainingGroup(['program_id' => $program->id]);
        $g->id = (string) Str::uuid();

        return $this->pricing->display($g, $employee);
    }
}
