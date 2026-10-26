<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\TrainingGroupService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * «استيراد تقويم القاعات التدريبية» (docs/saber/04-data-tools.md, D1). The workbook has one sheet of rooms × dates: a header row of room
 * names (from column H), the capacity under it («25 متدرب»), the date in column D and, in each booked cell, «<البرنامج> - <المجموعة أو
 * المشرف>». Every label is matched to an existing programme (never created), cells of one label become one or more groups (parallel rooms on
 * one date are parallel groups), and applying writes those groups' days through the workbench rules. Missing rooms are added; supervisors are
 * matched to the platform's supervisors by name.
 */
class CalendarImport
{
    private const THRESHOLD = 0.7;

    public function __construct(private readonly Workbench $bench, private readonly TrainingGroupService $groups) {}

    /** @return array<string, mixed> the plan: tracks (with their target group and verdict), unmatched labels, rooms, supervisors */
    public function preview(string $path): array
    {
        $cells = $this->cells($path);
        if ($cells === []) {
            throw new BusinessRuleException(__('messages.saber.import.no_cells'), 'no_cells');
        }
        $programs = Program::where('status', '!=', Program::STATUS_CANCELLED)->get(['id', 'title_ar', 'title_en', 'training_days', 'total_hours'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->title_ar ?: $p->title_en])->filter(fn ($p) => $p['name'])->values()->all();
        $supervisors = $this->supervisors();
        $rooms = TrainingRoom::get(['id', 'name_ar', 'name_en']);

        $byLabel = collect($cells)->groupBy('label');
        $tracks = [];
        $unmatched = [];
        $roomsSeen = [];
        $supsSeen = [];
        foreach ($byLabel as $label => $group) {
            $parsed = $this->matchLabel((string) $label, $programs, $supervisors->pluck('name')->all());
            if (! $parsed['program']) {
                $best = collect($programs)->map(fn ($p) => ['name' => $p['name'], 's' => ArabicMatch::nameScore((string) $label, $p['name'])])->sortByDesc('s')->first();
                $unmatched[] = ['label' => $label, 'count' => $group->count(), 'best' => $best['name'] ?? null, 'score' => round($best['s'] ?? 0, 2)];

                continue;
            }
            foreach ($group as $c) {
                $roomsSeen[$c['room']] ??= $c['capacity'];
            }
            if ($parsed['supervisor']) {
                $supsSeen[$parsed['supervisor']] = true;
            }
            $split = $this->splitTracks($group->map(fn ($c) => ['date' => $c['date'], 'room' => $c['room']])->all());
            foreach ($split as $i => $days) {
                usort($days, fn ($a, $b) => strcmp($a['date'], $b['date']));
                $n = preg_match('/(\d+)\s*$/u', $parsed['group'], $m) && count($split) === 1 ? (int) $m[1] : $i + 1;
                $tracks[] = [
                    'key' => $parsed['program']['id'].'|'.$label.'|'.$i, 'label' => $label, 'program_id' => $parsed['program']['id'], 'program' => $parsed['program']['name'],
                    'score' => round($parsed['score'], 2), 'group_name' => count($split) > 1 && $parsed['group'] ? "{$parsed['group']} ({$n})" : ($parsed['group'] ?: TrainingGroup::defaultTitle($n, 'ar')),
                    'supervisor' => $parsed['supervisor'], 'index' => $n, 'days' => $days,
                ];
            }
        }
        usort($tracks, fn ($a, $b) => strcmp(ArabicMatch::normalize($a['program']), ArabicMatch::normalize($b['program'])) ?: strcmp($a['group_name'], $b['group_name']));
        $this->targets($tracks, $rooms);
        usort($unmatched, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'cells' => count($cells), 'tracks' => $tracks, 'unmatched' => $unmatched,
            'rooms' => collect($roomsSeen)->map(fn ($cap, $name) => ['name' => $name, 'capacity' => $cap, 'exists' => $this->findRoom($rooms, $name) !== null])->values()->all(),
            'supervisors' => collect(array_keys($supsSeen))->map(fn ($name) => ['name' => $name, 'user' => $this->findSupervisor($supervisors, $name)['name'] ?? null])->values()->all(),
        ];
    }

    /**
     * Writes the chosen tracks: rooms added when missing, each track's group found or created, its days replaced (a day refused by the
     * calendar or a clash is reported and the rest goes on), its supervisor set when known, and — when asked and allowed — the groups that
     * went through without a problem approved.
     *
     * @param  list<array{program_id: string, group_id?: ?string, group_name: string, supervisor?: ?string, days: list<array{date: string, room: string}>}>  $tracks
     * @return array<string, mixed>
     */
    public function apply(array $tracks, bool $approve, User $actor): array
    {
        $rooms = TrainingRoom::get(['id', 'name_ar', 'name_en']);
        $supervisors = $this->supervisors();
        $made = ['updated' => 0, 'created' => 0, 'rooms_created' => 0, 'approved' => 0, 'failures' => [], 'supervisors_missing' => []];
        $canApprove = $approve && $actor->hasPermission('saber.group.validate');

        foreach ($tracks as $t) {
            $program = Program::find($t['program_id']);
            if (! $program) {
                continue;
            }
            $roomIds = [];
            foreach ($t['days'] as $d) {
                $room = $this->findRoom($rooms, $d['room']);
                if (! $room) {
                    $room = TrainingRoom::create(['name_ar' => $d['room'], 'name_en' => $d['room'], 'capacity' => (int) ($d['capacity'] ?? 0) ?: 25, 'status' => 'active', 'room_type' => 'training', 'layout' => 'classroom', 'layouts' => ['classroom' => (int) ($d['capacity'] ?? 0) ?: 25]]);
                    $rooms->push($room);
                    $made['rooms_created']++;
                }
                $roomIds[$d['date']] = $room->id;
            }
            $failures = [];
            DB::transaction(function () use ($t, $program, $roomIds, $supervisors, $actor, &$made, &$failures, $canApprove) {
                $group = ! empty($t['group_id']) ? TrainingGroup::where('program_id', $program->id)->find($t['group_id']) : null;
                if (! $group) {
                    $title = TrainingGroup::where('program_id', $program->id)->where('title_ar', $t['group_name'])->exists() ? null : $t['group_name'];
                    $group = $this->groups->create($program, ['title_ar' => $title], $actor)['group'];
                    $group->update(['color' => $this->bench->nextColour($program)]);
                    $made['created']++;
                } else {
                    $made['updated']++;
                }
                if ($group->sessions()->whereHas('attendance')->exists()) {
                    $failures[] = ['group' => $t['group_name'], 'program' => $program->title_ar, 'date' => null, 'message' => __('messages.saber.plan.has_attendance')];

                    return;
                }
                $group->sessions()->get()->each->delete();
                $group->update(['default_room_id' => null]);
                $dates = array_keys($roomIds);
                sort($dates);
                foreach ($dates as $i => $date) {
                    try {
                        $this->bench->setDay($group->fresh(), $i + 1, ['date' => $date, 'room_id' => $roomIds[$date]], $actor);
                    } catch (BusinessRuleException $e) {
                        $failures[] = ['group' => $t['group_name'], 'program' => $program->title_ar, 'date' => $date, 'message' => $e->getMessage()];
                        if ($e->errorCode !== 'calendar_closed' && $e->errorCode !== 'outside_training_day') {
                            try {
                                $this->bench->setDay($group->fresh(), $i + 1, ['date' => $date, 'room_id' => null], $actor);
                            } catch (BusinessRuleException) {
                                // the date itself is refused: reported above
                            }
                        }
                    }
                }
                if (! empty($t['supervisor'])) {
                    $user = $this->findSupervisor($supervisors, $t['supervisor']);
                    if ($user) {
                        $group->update(['supervisor_id' => $user['id']]);
                    } else {
                        $made['supervisors_missing'][$t['supervisor']] = true;
                    }
                }
                if ($canApprove && $failures === []) {
                    $group->update(['validated_at' => now(), 'validated_by' => $actor->id, 'validated_by_name' => $actor->displayName('ar')]);
                    $made['approved']++;
                }
            });
            $made['failures'] = [...$made['failures'], ...$failures];
        }
        $made['supervisors_missing'] = array_keys($made['supervisors_missing']);

        return $made;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array{date: string, room: string, capacity: ?int, label: string}> */
    private function cells(string $path): array
    {
        $book = IOFactory::createReaderForFile($path)->setReadDataOnly(true)->load($path);
        $sheet = collect($book->getWorksheetIterator())->first(fn (Worksheet $s) => str_contains($s->getTitle(), 'تقويم')) ?? $book->getSheet(0);
        $lastRow = $sheet->getHighestDataRow();
        $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $at = fn (int $r, int $c) => $sheet->getCell([$c, $r])->getValue();

        // The room-header row has the most distinct texts across the room columns (capacity rows repeat a few values).
        $header = 3;
        $best = 0;
        for ($r = 1; $r <= min($lastRow, 16); $r++) {
            $seen = [];
            for ($c = 8; $c <= $lastCol; $c++) {
                $v = $at($r, $c);
                if (is_string($v) && trim($v) !== '') {
                    $seen[trim($v)] = true;
                }
            }
            if (count($seen) > $best) {
                [$best, $header] = [count($seen), $r];
            }
        }
        $rooms = [];
        for ($c = 8; $c <= $lastCol; $c++) {
            $name = $at($header, $c);
            if (is_string($name) && trim($name) !== '') {
                $cap = preg_match('/\d+/', (string) $at($header + 1, $c), $m) ? (int) $m[0] : null;
                $rooms[$c] = ['name' => trim(preg_replace('/\s+/u', ' ', $name) ?? $name), 'capacity' => $cap];
            }
        }
        $out = [];
        for ($r = $header + 1; $r <= $lastRow; $r++) {
            $date = $this->date($at($r, 4));
            if (! $date) {
                continue;
            }
            foreach ($rooms as $c => $room) {
                $v = $at($r, $c);
                $label = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v) ?? $v) : '';
                if ($label !== '') {
                    $out[] = ['date' => $date, 'room' => $room['name'], 'capacity' => $room['capacity'], 'label' => $label];
                }
            }
        }

        return $out;
    }

    private function date(mixed $v): ?string
    {
        if ($v instanceof DateTimeInterface) {
            return CarbonImmutable::instance($v)->toDateString();
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        if (is_string($v) && preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', trim($v), $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v))) {
            return trim($v);
        }

        return null;
    }

    /**
     * «الباقة الثالثة للمعلمين - معلمون ومنسقون - 1» → programme + group «معلمون ومنسقون - 1»; «شروق الشمس - انجي» → programme + supervisor.
     *
     * @param  list<array{id: string, name: string}>  $programs
     * @param  list<string>  $knownSupervisors
     * @return array{program: ?array{id: string, name: string}, score: float, group: string, supervisor: ?string}
     */
    private function matchLabel(string $label, array $programs, array $knownSupervisors): array
    {
        $segments = array_values(array_filter(array_map('trim', preg_split('/\s*[-–—]\s*/u', $label) ?: []), fn ($s) => $s !== ''));
        $maxK = count($segments);
        while ($maxK > 1 && preg_match('/^\d+$/', $segments[$maxK - 1])) {
            $maxK--;
        }
        $best = null;
        for ($k = $maxK; $k >= 1; $k--) {
            $candidate = implode(' ', array_slice($segments, 0, $k));
            foreach ($programs as $p) {
                $score = ArabicMatch::nameScore($candidate, $p['name']);
                if (! $best || $score > $best['score'] + 0.02 || (abs($score - $best['score']) <= 0.02 && $k > $best['k'])) {
                    $best = ['score' => $score, 'k' => $k, 'program' => $p];
                }
            }
        }
        if (! $best || $best['score'] < self::THRESHOLD) {
            return ['program' => null, 'score' => $best['score'] ?? 0.0, 'group' => '', 'supervisor' => null];
        }
        $rest = array_slice($segments, $best['k']);
        $text = trim(implode(' - ', $rest));
        $group = $text;
        $supervisor = null;
        if ($text !== '') {
            $known = collect($knownSupervisors)->first(fn ($s) => ArabicMatch::similarity($s, $text) >= 0.8);
            if ($known) {
                [$supervisor, $group] = [$known, ''];
            } elseif (! preg_match('/\d/u', $text) && count($rest) === 1 && count(preg_split('/\s+/u', $text) ?: []) <= 3) {
                [$supervisor, $group] = [$text, ''];
            } elseif (preg_match('/^\d+$/', $text)) {
                $group = TrainingGroup::defaultTitle((int) $text, 'ar');
            }
        }

        return ['program' => $best['program'], 'score' => $best['score'], 'group' => $group, 'supervisor' => $supervisor];
    }

    /**
     * One label in several rooms on one date means parallel groups: each track takes one room per date, keeping the same room when it can.
     *
     * @param  list<array{date: string, room: string}>  $days
     * @return list<list<array{date: string, room: string}>>
     */
    private function splitTracks(array $days): array
    {
        $byDate = collect($days)->groupBy('date')->sortKeys();
        $tracks = [];
        foreach ($byDate as $date => $rows) {
            $used = [];
            foreach ($rows as $row) {
                $idx = null;
                foreach ($tracks as $i => $t) {
                    if (! isset($used[$i]) && end($t)['room'] === $row['room'] && ! collect($t)->contains('date', $date)) {
                        $idx = $i;
                        break;
                    }
                }
                if ($idx === null) {
                    foreach ($tracks as $i => $t) {
                        if (! isset($used[$i]) && ! collect($t)->contains('date', $date)) {
                            $idx = $i;
                            break;
                        }
                    }
                }
                if ($idx === null) {
                    $tracks[] = [];
                    $idx = count($tracks) - 1;
                }
                $used[$idx] = true;
                $tracks[$idx][] = ['date' => $date, 'room' => $row['room']];
            }
        }

        return $tracks;
    }

    /** Gives each track the group it updates (same name, else same number, else none = a new group) and says whether it already matches. */
    private function targets(array &$tracks, Collection $rooms): void
    {
        $tz = config('app.timezone');
        $claimed = [];
        $pool = TrainingGroup::with(['sessions' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('starts_at')])
            ->whereIn('program_id', collect($tracks)->pluck('program_id')->unique())->where('status', '!=', TrainingGroup::CANCELLED)->get()->groupBy('program_id');
        foreach ($tracks as &$t) {
            $candidates = ($pool[$t['program_id']] ?? collect())->reject(fn ($g) => isset($claimed[$g->id]));
            $target = $candidates->first(fn ($g) => $g->title_ar && ArabicMatch::similarity($g->title_ar, $t['group_name']) >= 0.85)
                ?? $candidates->first(fn ($g) => $g->sequence === $t['index'])
                ?? ($candidates->count() === 1 && $candidates->first()->sessions->isEmpty() ? $candidates->first() : null);
            if ($target) {
                $claimed[$target->id] = true;
            }
            $saved = $target ? $target->sessions->map(fn ($s) => $s->starts_at->timezone($tz)->toDateString().'|'.($s->training_room_id ?? ''))->sort()->values()->all() : [];
            $wanted = collect($t['days'])->map(fn ($d) => $d['date'].'|'.($this->findRoom($rooms, $d['room'])?->id ?? 'new'))->sort()->values()->all();
            $t['group_id'] = $target?->id;
            $t['target'] = $target ? ($target->title_ar ?: TrainingGroup::defaultTitle($target->sequence, 'ar')) : null;
            $t['verdict'] = ! $target ? 'new' : ($saved === $wanted ? 'match' : 'diff');
            $t['validated'] = (bool) $target?->validated_at;
        }
    }

    private function findRoom(Collection $rooms, string $name): ?TrainingRoom
    {
        $n = ArabicMatch::normalize($name);

        return $rooms->first(fn ($r) => ArabicMatch::normalize($r->name_ar) === $n || ArabicMatch::normalize($r->name_en) === $n);
    }

    /** @return Collection<int, array{id: string, name: string}> */
    private function supervisors(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::COORDINATOR, Role::TRAINING_HEAD]))->get(['id', 'name', 'name_ar'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name_ar ?: $u->name]);
    }

    private function findSupervisor(Collection $supervisors, string $name): ?array
    {
        return $supervisors->map(fn ($s) => $s + ['s' => ArabicMatch::similarity($s['name'], $name)])->filter(fn ($s) => $s['s'] >= 0.8)->sortByDesc('s')->first();
    }
}
