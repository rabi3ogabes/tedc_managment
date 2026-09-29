<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Training calendar: working / off days, vacations, exam days and training approvals.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    /**
     * Every date of the range with its resolved status. Feeds both the calendar and the table view.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::in(['workday', 'weekend', ...CalendarDay::TYPES])],
        ]);

        $from = CarbonImmutable::parse($data['from'] ?? today()->startOfMonth());
        $to = CarbonImmutable::parse($data['to'] ?? $from->addMonths(3)->subDay());
        $this->limitSpan($from, $to, CalendarService::MAX_RANGE_DAYS, 'to');

        $result = $this->calendar->range($from, $to);
        $days = $request->filled('kind') ? array_values(array_filter($result['days'], fn ($d) => $d['kind'] === $data['kind'])) : $result['days'];

        return response()->json(['data' => $days, 'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'summary' => $result['summary'], 'weekend' => config('tedc.calendar.weekend')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $from = CarbonImmutable::parse($data['date']);
        $to = CarbonImmutable::parse($data['end_date'] ?? $data['date']);
        $this->limitSpan($from, $to, 370);

        $days = collect();
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $days->push(CalendarDay::updateOrCreate(
                ['date' => $date->toDateString()],
                collect($data)->except(['date', 'end_date'])->all() + ['created_by' => $this->user()->id],
            ));
        }

        return response()->json(['data' => $days->values(), 'meta' => $this->conflicts($from, $to)], 201);
    }

    public function update(Request $request, CalendarDay $day): JsonResponse
    {
        $data = $this->validated($request, true);
        $day->update(collect($data)->except(['date', 'end_date'])->all());

        return response()->json(['data' => $day->refresh(), 'meta' => $this->conflicts($day->date, $day->date)]);
    }

    public function destroy(CalendarDay $day): JsonResponse
    {
        $day->delete();

        return response()->json(null, 204);
    }

    /**
     * Approve training on a closed day (weekend, vacation, exam or normal day).
     */
    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $from = CarbonImmutable::parse($data['date']);
        $to = CarbonImmutable::parse($data['end_date'] ?? $data['date']);
        $this->limitSpan($from, $to, 62);

        $closed = $this->calendar->closedDates($from, $to);
        if ($closed->isEmpty()) {
            throw new BusinessRuleException(__($from->equalTo($to) && CalendarApproval::whereDate('date', $from)->exists() ? 'messages.calendar.already_approved' : 'messages.calendar.not_closed'), 'calendar_not_closed');
        }

        $approved = $this->calendar->approveSpan($from, $to, $data['reason'], $this->user()->id);

        return response()->json(['data' => $this->calendar->range($from, $to, false)['days'], 'meta' => ['approved' => $approved]], 201);
    }

    public function revoke(string $date): JsonResponse
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        CalendarApproval::whereDate('date', $date)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    /** Sessions already scheduled on days that are (now) closed for training. */
    private function conflicts(CarbonInterface $from, CarbonInterface $to): array
    {
        return ['conflicting_sessions' => $this->calendar->conflictingSessions($from, $to)->map(fn ($s) => [
            'id' => $s->id, 'title' => $s->translate('title'), 'starts_at' => $s->starts_at->toIso8601String(),
            'program' => $s->program?->translate('title'), 'program_id' => $s->program_id,
        ])->values()];
    }

    /** Limits how many days one request may touch. */
    private function limitSpan(CarbonInterface $from, CarbonInterface $to, int $max, string $field = 'end_date'): void
    {
        if ($from->diffInDays($to) >= $max) {
            throw ValidationException::withMessages([$field => __('validation.max.numeric', ['attribute' => $field, 'max' => $max])]);
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'date' => [$required, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'type' => [$required, Rule::in(CalendarDay::TYPES)],
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
