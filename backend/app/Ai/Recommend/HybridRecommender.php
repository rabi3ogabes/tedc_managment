<?php

namespace App\Ai\Recommend;

use App\Ai\AiPolicy;
use App\Models\AiRecommendationEvent;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\ItemSimilarity;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use App\Services\RecommendationEngine;
use Illuminate\Support\Collection;

/**
 * "For you": the rule engine's explainable score (skill gaps, role fit, school needs, career stage) blended with what people like this person did and rated —
 * colleagues who completed the same programmes, ratings from the same job, how the person's own behaviour leans, and calendar clashes.
 * The administrator sets the weights, and can switch on an A/B comparison against the rules alone. Every result says why.
 */
class HybridRecommender
{
    public function __construct(private readonly RecommendationEngine $rules, private readonly AiPolicy $policy) {}

    public function variantFor(User $user): string
    {
        $p = $this->policy->all()['recommendations'];

        return $p['ab_test'] && (crc32($user->id) % 100) >= (int) $p['ab_share'] ? 'rules' : 'hybrid';
    }

    /** @return array{variant: string, items: list<array<string, mixed>>} */
    public function forUser(User $user, int $limit = 6): array
    {
        $emp = Employee::where('user_id', $user->id)->first();
        $variant = $this->variantFor($user);
        if (! $emp) {
            return ['variant' => $variant, 'items' => []];
        }
        $base = $this->rules->forEmployee($emp, 30);
        $dismissed = AiRecommendationEvent::where('user_id', $user->id)->whereIn('event', ['dismissed', 'disliked'])->where('created_at', '>=', now()->subDays(30))->pluck('item_id')->all();
        $base = $base->reject(fn ($r) => in_array($r['program']->id, $dismissed, true))->values();

        $items = $variant === 'rules' ? $this->plain($base) : $this->blend($emp, $base);
        $items = array_slice($items, 0, $limit);
        $this->recordShown($user, $items, $variant);

        return ['variant' => $variant, 'items' => $items];
    }

    /** @param  Collection<int, array{program: Program, score: float, reasons: array<int, string>}>  $base @return list<array<string, mixed>> */
    private function plain(Collection $base): array
    {
        return $base->map(fn ($r) => $this->row($r['program'], $r['score'], $r['reasons'], ['rules' => $r['score']]))->values()->all();
    }

    /** @param  Collection<int, array{program: Program, score: float, reasons: array<int, string>}>  $base @return list<array<string, mixed>> */
    private function blend(Employee $emp, Collection $base): array
    {
        $w = $this->policy->all()['recommendations']['weights'];
        $done = Registration::where('employee_id', $emp->id)->whereIn('status', [Registration::STATUS_COMPLETED, Registration::STATUS_APPROVED])->pluck('program_id')->all();
        $sim = $done ? ItemSimilarity::where('item_type', 'program')->whereIn('item_id', $done)->get()->groupBy('other_id') : collect();
        $categories = Program::whereIn('id', $done)->pluck('category_id')->filter()->countBy()->all();
        $clicked = AiRecommendationEvent::where('user_id', $emp->user_id)->whereIn('event', ['clicked', 'liked'])->pluck('item_id');
        foreach (Program::whereIn('id', $clicked)->pluck('category_id')->filter() as $c) {
            $categories[$c] = ($categories[$c] ?? 0) + 0.5;
        }
        $catMax = max(1, ...array_values($categories ?: [1]));
        $mine = Registration::with('program:id,title_ar,title_en,start_date,end_date')->where('employee_id', $emp->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_PENDING])->get();
        $peerRatings = $this->peerRatings($emp, $base->pluck('program.id')->all());
        $overall = Evaluation::whereIn('program_id', $base->pluck('program.id'))->selectRaw('program_id, avg(satisfaction_score) as score, count(*) as n')->groupBy('program_id')->get()->keyBy('program_id');
        $sum = max(1, array_sum($w));

        $rows = $base->map(function ($r) use ($w, $sim, $categories, $catMax, $mine, $peerRatings, $overall, $sum) {
            /** @var Program $p */
            $p = $r['program'];
            $reasons = $r['reasons'];
            $c = ['rules' => (float) $r['score'], 'gap' => 0.0, 'peers' => 0.0, 'behaviour' => 0.0, 'rating' => 0.0];

            $s = $sim->get($p->id);
            if ($s && $s->isNotEmpty()) {
                $best = $s->sortByDesc('score')->first();
                $c['peers'] = min(100, $best->score * 100);
                $reasons[] = __('ai.peers', ['n' => $best->support]);
            }
            if ($p->category_id && isset($categories[$p->category_id])) {
                $c['behaviour'] = min(100, $categories[$p->category_id] / $catMax * 100);
                $reasons[] = __('ai.behaviour');
            }
            $pr = $peerRatings[$p->id] ?? null;
            $o = $overall->get($p->id);
            if ($pr && $pr['n'] >= 3) {
                $c['rating'] = min(100, $pr['avg']);
                $reasons[] = __('ai.peer_rating', ['n' => $pr['n'], 'avg' => round($pr['avg'] / 20, 1)]);
            } elseif ($o && $o->n >= 3) {
                $c['rating'] = min(100, (float) $o->score);
            }
            // The rule engine's skill-gap part already sits in 'rules'; the blend lets the administrator weigh it again on its own.
            $c['gap'] = (float) $r['score'];
            $penalty = 0.0;
            foreach ($mine as $m) {
                if ($m->program && $p->start_date && $p->end_date && $m->program->start_date && $m->program->end_date && $p->start_date <= $m->program->end_date && $p->end_date >= $m->program->start_date) {
                    $penalty = 15.0;
                    $reasons[] = __('ai.clash', ['program' => $m->program->translate('title')]);
                    break;
                }
            }
            $score = max(0, min(100, array_sum(array_map(fn ($k) => $c[$k] * ($w[$k] ?? 0), array_keys($c))) / $sum - $penalty));

            return $this->row($p, round($score, 1), array_values(array_unique($reasons)), $c + ['penalty' => $penalty]);
        })->sortByDesc('score')->values()->all();

        return $rows;
    }

    /** Ratings by colleagues with the same job title. @param  list<string>  $programIds @return array<string, array{avg: float, n: int}> */
    private function peerRatings(Employee $emp, array $programIds): array
    {
        if (! $emp->job_title_id || ! $programIds) {
            return [];
        }
        $rows = Evaluation::query()->join('employees', 'employees.id', '=', 'evaluations.employee_id')->where('employees.job_title_id', $emp->job_title_id)->whereIn('evaluations.program_id', $programIds)
            ->selectRaw('evaluations.program_id as pid, avg(evaluations.satisfaction_score) as score, count(*) as n')->groupBy('evaluations.program_id')->get();

        return $rows->mapWithKeys(fn ($r) => [$r->pid => ['avg' => (float) $r->score, 'n' => (int) $r->n]])->all();
    }

    /** @param  list<string>  $reasons @param  array<string, float>  $components @return array<string, mixed> */
    private function row(Program $p, float $score, array $reasons, array $components): array
    {
        return ['id' => $p->id, 'type' => 'program', 'title_ar' => $p->title_ar, 'title_en' => $p->title_en, 'summary_ar' => $p->summary_ar, 'summary_en' => $p->summary_en, 'hours' => (float) $p->total_hours, 'start_date' => $p->start_date?->toDateString(),
            'score' => round($score, 1), 'reasons' => $reasons, 'components' => $components];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function recordShown(User $user, array $items, string $variant): void
    {
        $today = AiRecommendationEvent::where('user_id', $user->id)->where('event', 'shown')->where('created_at', '>=', now()->startOfDay())->pluck('item_id')->all();
        foreach ($items as $i) {
            if (! in_array($i['id'], $today, true)) {
                AiRecommendationEvent::create(['user_id' => $user->id, 'item_type' => 'program', 'item_id' => $i['id'], 'event' => 'shown', 'variant' => $variant, 'score' => $i['score'], 'reasons' => $i['reasons'], 'created_at' => now()]);
            }
        }
    }

    public function feedback(User $user, string $itemId, string $event, ?string $note = null): AiRecommendationEvent
    {
        $last = AiRecommendationEvent::where('user_id', $user->id)->where('item_id', $itemId)->where('event', 'shown')->latest('created_at')->first();

        return AiRecommendationEvent::create(['user_id' => $user->id, 'item_type' => 'program', 'item_id' => $itemId, 'event' => $event, 'variant' => $last?->variant ?? $this->variantFor($user), 'score' => $last?->score, 'reasons' => $last?->reasons, 'note' => $note ? mb_substr(strip_tags($note), 0, 200) : null, 'created_at' => now()]);
    }

    /** Someone who registers for a programme they were shown counts as a conversion. */
    public function registered(Registration $r): void
    {
        $user = Employee::whereKey($r->employee_id)->value('user_id');
        if (! $user) {
            return;
        }
        $shown = AiRecommendationEvent::where('user_id', $user)->where('item_id', $r->program_id)->where('event', 'shown')->where('created_at', '>=', now()->subDays(30))->latest('created_at')->first();
        if ($shown && ! AiRecommendationEvent::where('user_id', $user)->where('item_id', $r->program_id)->where('event', 'enrolled')->exists()) {
            AiRecommendationEvent::create(['user_id' => $user, 'item_type' => 'program', 'item_id' => $r->program_id, 'event' => 'enrolled', 'variant' => $shown->variant, 'score' => $shown->score, 'reasons' => $shown->reasons, 'created_at' => now()]);
        }
    }

    /** Shown / clicked / enrolled by variant, so the two can be compared. @return array<string, array<string, int|float>> */
    public function stats(int $days = 30): array
    {
        $out = [];
        foreach (['hybrid', 'rules'] as $v) {
            $q = fn ($e) => AiRecommendationEvent::where('variant', $v)->where('event', $e)->where('created_at', '>=', now()->subDays($days))->count();
            $shown = $q('shown');
            $clicked = $q('clicked');
            $enrolled = $q('enrolled');
            $out[$v] = ['shown' => $shown, 'clicked' => $clicked, 'enrolled' => $enrolled, 'dismissed' => $q('dismissed'), 'click_rate' => $shown ? round($clicked / $shown * 100, 1) : 0, 'enrol_rate' => $shown ? round($enrolled / $shown * 100, 1) : 0];
        }

        return $out;
    }
}
