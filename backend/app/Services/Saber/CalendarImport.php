<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\CalendarDay;
use App\Models\Program;
use App\Models\Role;
use App\Models\SaberRevision;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Models\User;
use App\Services\ProgramCatalogSettings;
use App\Services\TrainingDaySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Schedules into SABER+ drafts (docs/saber/04-data-tools.md D1, 05-review-and-flexible-import.md). Two shapes of workbook, read by
 * {@see WorkbookReader} with the columns the administrator chose:
 *  - a rooms × dates grid (rooms across a header row, a date column, «<البرنامج> - <المجموعة أو المشرف>» in each booked cell);
 *  - one training day per row (programme, group, date, times, room, trainer, supervisor in any columns).
 * Every programme name is matched to an existing programme (never created); cells of one programme and group become one planned group
 * (parallel rooms on one date are parallel groups). Applying writes the programmes' drafts — never the live programmes — and can send them
 * for review. Missing rooms are added; trainers and supervisors are matched by name to the platform's.
 */
class CalendarImport
{
    private const THRESHOLD = 0.7;

    public function __construct(private readonly Drafts $drafts, private readonly WorkbookReader $reader) {}

    /**
     * A rooms × dates grid: the room header row (found or chosen), the capacity under it and the trainee priority above it (رجال / نساء /
     * الكل → the room's tag), the date column, and each booked cell. Merged areas are read too: a label written once beside a week of dates
     * (left of the date column) is a calendar period; a label spread across the rooms is a centre-wide event, not a booking.
     *
     * @param  array{sheet?: ?string, header_row?: ?int, date_col?: ?int, first_room_col?: ?int}  $layout
     * @return array{cells: list<array<string, mixed>>, periods: list<array<string, mixed>>, rooms_meta: array<string, array{capacity: ?int, tag: ?string}>}
     */
    public function gridCells(string $path, array $layout = []): array
    {
        $rows = $this->reader->rows($path, $layout['sheet'] ?? null);
        $first = (int) ($layout['first_room_col'] ?? 8) - 1;
        $dateCol = (int) ($layout['date_col'] ?? 4) - 1;
        // The room-header row has the most distinct texts across the room columns (capacity rows repeat a few values).
        $header = isset($layout['header_row']) ? (int) $layout['header_row'] - 1 : null;
        if ($header === null) {
            $best = 0;
            $header = 2;
            foreach (array_slice($rows, 0, 16, true) as $r => $row) {
                $n = count(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', array_slice($row, $first)))));
                // The room row is the one with capacities («25 متدرب») under it: that wins a tie with the trainee-priority row above.
                $n += 0.5 * count(array_filter(array_slice($rows[$r + 1] ?? [], $first), fn ($v) => (bool) preg_match('/^\s*\d+\s*(متدرب|متدربا|مقعد|مقاعد)?\s*$/u', (string) $v)));
                if ($n > $best) {
                    [$best, $header] = [$n, $r];
                }
            }
        }
        $rooms = [];
        $meta = [];
        foreach ($rows[$header] ?? [] as $c => $name) {
            if ($c >= $first && is_string($name) && trim($name) !== '') {
                $roomName = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
                $cap = preg_match('/\d+/', (string) ($rows[$header + 1][$c] ?? ''), $m) ? (int) $m[0] : null;
                $tag = $header > 0 ? trim((string) ($rows[$header - 1][$c] ?? '')) : '';
                $rooms[$c] = ['name' => $roomName, 'capacity' => $cap];
                $meta[$roomName] = ['capacity' => $cap, 'tag' => $tag !== '' && mb_strlen($tag) <= 20 && ! preg_match('/\d/', $tag) ? $tag : null];
            }
        }
        $dateOf = [];
        foreach ($rows as $r => $row) {
            if ($r > $header && ($date = $this->reader->date($row[$dateCol] ?? null))) {
                $dateOf[$r] = $date;
            }
        }

        // Merged areas: periods beside the dates, events across the rooms.
        $periods = [];
        $covered = [];
        foreach ($this->reader->merges($path, $layout['sheet'] ?? null) as $m) {
            $dates = array_values(array_filter(array_map(fn ($r) => $dateOf[$r] ?? null, range($m['r1'], $m['r2']))));
            if ($m['value'] === '' || $dates === []) {
                continue;
            }
            if ($m['c2'] < $dateCol) {
                $periods[] = ['label' => $m['value'], 'from' => min($dates), 'to' => max($dates), 'kind' => 'period'];
            } elseif ($m['c1'] >= $first && $m['c2'] > $m['c1']) {
                $periods[] = ['label' => $m['value'], 'from' => min($dates), 'to' => max($dates), 'kind' => 'event'];
                for ($r = $m['r1']; $r <= $m['r2']; $r++) {
                    for ($c = $m['c1']; $c <= $m['c2']; $c++) {
                        $covered["{$r}:{$c}"] = true;
                    }
                }
            }
        }
        // A label left of the dates on a single row (not merged) is a one-day period.
        foreach ($dateOf as $r => $date) {
            for ($c = 0; $c < $dateCol; $c++) {
                $v = trim((string) ($rows[$r][$c] ?? ''));
                if ($v !== '' && ! is_numeric($v) && ! collect($periods)->contains(fn ($p) => $p['label'] === $v && $p['from'] <= $date && $p['to'] >= $date)) {
                    $periods[] = ['label' => $v, 'from' => $date, 'to' => $date, 'kind' => 'period'];
                }
            }
        }

        $cells = [];
        foreach ($dateOf as $r => $date) {
            foreach ($rooms as $c => $room) {
                if (isset($covered["{$r}:{$c}"])) {
                    continue;
                }
                $label = is_string($rows[$r][$c] ?? null) ? trim(preg_replace('/\s+/u', ' ', $rows[$r][$c]) ?? '') : '';
                if ($label !== '') {
                    $cells[] = ['date' => $date, 'room' => $room['name'], 'capacity' => $room['capacity'], 'label' => $label];
                }
            }
        }

        // A label in most rooms on one date (يوم المعلم، اليوم الرياضي) is a centre-wide event; so is a cell repeating a period's name.
        $wide = max(3, (int) ceil(count($rooms) * 0.6));
        foreach (collect($cells)->groupBy(fn ($c) => $c['label'].'|'.$c['date']) as $group) {
            if ($group->count() >= $wide) {
                $periods[] = ['label' => $group->first()['label'], 'from' => $group->first()['date'], 'to' => $group->first()['date'], 'kind' => 'event'];
            }
        }
        $eventLabels = collect($periods)->map(fn ($p) => ArabicMatch::normalize($p['label']))->unique()->all();
        $cells = array_values(array_filter($cells, fn ($c) => ! in_array(ArabicMatch::normalize($c['label']), $eventLabels, true)));

        return ['cells' => $cells, 'periods' => $this->mergePeriods($periods), 'rooms_meta' => $meta];
    }

    /**
     * One training day per row, columns chosen by the administrator (1-based): program (required), date (required), group, start, end,
     * room, trainer, supervisor.
     *
     * @param  array<string, ?int>  $columns
     * @return array{cells: list<array<string, mixed>>}
     */
    public function rowCells(string $path, ?string $sheet, int $headerRow, array $columns): array
    {
        $rows = $this->reader->rows($path, $sheet);
        $col = fn (array $row, string $f) => isset($columns[$f]) && $columns[$f] ? ($row[$columns[$f] - 1] ?? null) : null;
        $out = [];
        foreach (array_slice($rows, $headerRow, null, true) as $row) {
            $program = trim(preg_replace('/\s+/u', ' ', (string) $col($row, 'program')) ?? '');
            $date = $this->reader->date($col($row, 'date'));
            if ($program === '' || ! $date) {
                continue;
            }
            $out[] = [
                'date' => $date, 'label' => $program, 'group' => $this->text($col($row, 'group')), 'room' => $this->text($col($row, 'room')), 'capacity' => null,
                'trainer' => $this->text($col($row, 'trainer')), 'supervisor' => $this->text($col($row, 'supervisor')),
                'start_time' => $this->reader->time($col($row, 'start')), 'end_time' => $this->reader->time($col($row, 'end')),
            ];
        }

        return ['cells' => $out];
    }

    /**
     * The plan: groups of existing programmes with their target and verdict; for names that match no programme, proposals to create them as
     * draft programmes (one per name, with its groups); calendar periods and events found in the file; room reservations for people; rooms,
     * trainers and supervisors not in the platform.
     *
     * @param  array{cells: list<array<string, mixed>>, periods?: list<array<string, mixed>>, rooms_meta?: array<string, mixed>}  $input
     * @return array<string, mixed>
     */
    public function plan(array $input): array
    {
        $cells = $input['cells'];
        if ($cells === []) {
            throw new BusinessRuleException(__('messages.saber.import.no_cells'), 'no_cells');
        }
        $programs = Program::where('status', '!=', Program::STATUS_CANCELLED)->get(['id', 'title_ar', 'title_en'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->title_ar ?: $p->title_en])->filter(fn ($p) => $p['name'])->values()->all();
        $supervisors = $this->supervisors();
        $trainers = Trainer::where('status', '!=', 'inactive')->get(['id', 'name_ar', 'name_en'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name_ar ?: $t->name_en]);
        $rooms = TrainingRoom::get(['id', 'name_ar', 'name_en']);

        $tracks = [];
        $unmatched = [];
        $reservations = [];
        $proposals = [];
        $roomsSeen = [];
        $supsSeen = [];
        $trainersSeen = [];
        foreach (collect($cells)->groupBy(fn ($c) => $c['label'].'|'.($c['group'] ?? '')) as $group) {
            $label = $group->first()['label'];
            $explicit = $group->first()['group'] ?? null;
            $parsed = $this->matchLabel($label, $programs, $supervisors->pluck('name')->all(), $explicit !== null);
            $proposal = null;
            if (! $parsed['program']) {
                // A person's standing room reservation («أ.فلانة - …») is not a programme.
                if ($explicit === null && preg_match('/^\s*(أ|د|م)\s*[.\/]/u', $label)) {
                    $reservations[] = ['label' => $label, 'count' => $group->count(), 'rooms' => $group->pluck('room')->filter()->unique()->values()->all()];

                    continue;
                }
                $p = $this->propose($label, $explicit !== null, $supervisors->pluck('name')->all());
                if ($p['name'] === '') {
                    $unmatched[] = ['label' => $label, 'count' => $group->count(), 'best' => null, 'score' => 0];

                    continue;
                }
                $best = collect($programs)->map(fn ($x) => ['name' => $x['name'], 's' => ArabicMatch::nameScore($p['name'], $x['name'])])->sortByDesc('s')->first();
                $proposal = ArabicMatch::normalize($p['name']);
                $proposals[$proposal] ??= ['key' => $proposal, 'name' => $p['name'], 'labels' => [], 'cells' => 0, 'closest' => $best['name'] ?? null, 'closest_score' => round($best['s'] ?? 0, 2)];
                $proposals[$proposal]['labels'][] = $label;
                $proposals[$proposal]['cells'] += $group->count();
                $parsed = ['program' => ['id' => null, 'name' => $p['name']], 'score' => 0.0, 'group' => $p['group'], 'supervisor' => $p['supervisor']];
            }
            if ($explicit !== null) {
                $parsed['group'] = preg_match('/^\d+$/', $explicit) ? TrainingGroup::defaultTitle((int) $explicit, 'ar') : $explicit;
            }
            $supervisor = $group->pluck('supervisor')->filter()->first() ?? $parsed['supervisor'];
            foreach ($group as $c) {
                if (! empty($c['room'])) {
                    $roomsSeen[$c['room']] ??= $c['capacity'] ?? null;
                }
                if (! empty($c['trainer'])) {
                    $trainersSeen[$c['trainer']] = true;
                }
            }
            if ($supervisor) {
                $supsSeen[$supervisor] = true;
            }
            $split = $explicit !== null ? [$group->values()->all()] : $this->splitTracks($group->values()->all());
            foreach ($split as $i => $days) {
                usort($days, fn ($a, $b) => strcmp($a['date'], $b['date']));
                $n = preg_match('/(\d+)\s*$/u', $parsed['group'], $m) && count($split) === 1 ? (int) $m[1] : $i + 1;
                $tracks[] = [
                    'key' => ($parsed['program']['id'] ?? 'new:'.$proposal).'|'.$label.'|'.($explicit ?? '').'|'.$i, 'label' => $label.($explicit ? " — {$explicit}" : ''),
                    'program_id' => $parsed['program']['id'], 'proposal' => $proposal, 'program' => $parsed['program']['name'], 'score' => round($parsed['score'], 2),
                    'group_name' => count($split) > 1 && $parsed['group'] ? "{$parsed['group']} ({$n})" : ($parsed['group'] ?: TrainingGroup::defaultTitle($n, 'ar')),
                    'supervisor' => $supervisor, 'index' => $n,
                    'days' => array_map(fn ($d) => array_filter(['date' => $d['date'], 'room' => $d['room'] ?? null, 'capacity' => $d['capacity'] ?? null, 'trainer' => $d['trainer'] ?? null,
                        'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null], fn ($v) => $v !== null), $days),
                ];
            }
        }
        usort($tracks, fn ($a, $b) => strcmp(ArabicMatch::normalize($a['program']), ArabicMatch::normalize($b['program'])) ?: ($a['index'] <=> $b['index']));
        $this->targets($tracks);
        foreach ($proposals as $k => $p) {
            $mine = collect($tracks)->where('proposal', $k);
            $proposals[$k]['groups'] = $mine->count();
            $proposals[$k]['days'] = (int) $mine->map(fn ($t) => count($t['days']))->max();
            $proposals[$k]['first'] = $mine->flatMap(fn ($t) => array_column($t['days'], 'date'))->min();
        }
        usort($unmatched, fn ($a, $b) => $b['count'] <=> $a['count']);
        $periods = collect($input['periods'] ?? [])->map(function ($p) use ($cells) {
            $inside = collect($cells)->filter(fn ($c) => $c['date'] >= $p['from'] && $c['date'] <= $p['to'])->count();

            return $p + ['type' => $this->periodType($p['label']), 'booked' => $inside, 'exists' => CalendarDay::whereBetween('date', [$p['from'], $p['to']])->exists()];
        })->values()->all();

        return [
            'cells' => count($cells), 'tracks' => $tracks, 'unmatched' => $unmatched, 'reservations' => $reservations,
            'proposals' => collect($proposals)->sortBy('first')->values()->all(), 'periods' => $periods,
            'rooms' => collect($roomsSeen)->map(fn ($cap, $name) => ['name' => $name, 'capacity' => $cap, 'tag' => $input['rooms_meta'][$name]['tag'] ?? null, 'exists' => $this->findRoom($rooms, $name) !== null])->values()->all(),
            'supervisors' => collect(array_keys($supsSeen))->map(fn ($name) => ['name' => $name, 'user' => $this->best($supervisors, $name)['name'] ?? null])->values()->all(),
            'trainers' => collect(array_keys($trainersSeen))->map(fn ($name) => ['name' => $name, 'match' => $this->best($trainers, $name, 0.85)['name'] ?? null])->values()->all(),
        ];
    }

    /**
     * Writes the chosen groups into the programmes' drafts. Names chosen from the proposals become draft programmes first (with their days
     * per group); chosen calendar periods are added to the training calendar first (so a booking inside one is reported); rooms missing from
     * the platform are added with their capacity and tag. A day the rules refuse is reported and the rest goes on. With `submit`, each touched
     * draft is sent for review.
     *
     * @param  list<array<string, mixed>>  $tracks
     * @param  list<array{key: string, name: string, days?: ?int}>  $create
     * @param  list<array{label: string, from: string, to: string, type: string}>  $periods
     * @param  array<string, array{capacity?: ?int, tag?: ?string}>  $roomsMeta
     * @return array<string, mixed>
     */
    public function apply(array $tracks, bool $submit, User $actor, string $source = 'calendar_import', array $create = [], array $periods = [], array $roomsMeta = []): array
    {
        $rooms = TrainingRoom::get(['id', 'name_ar', 'name_en']);
        $supervisors = $this->supervisors();
        $trainers = Trainer::where('status', '!=', 'inactive')->get(['id', 'name_ar', 'name_en'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name_ar ?: $t->name_en]);
        $made = ['updated' => 0, 'created' => 0, 'programs_created' => 0, 'calendar_days' => 0, 'rooms_created' => 0, 'drafts' => 0, 'submitted' => 0, 'failures' => [], 'supervisors_missing' => [], 'trainers_missing' => []];
        $touched = [];

        // 1 · calendar periods
        foreach ($periods as $p) {
            for ($d = CarbonImmutable::parse($p['from']); $d->lte(CarbonImmutable::parse($p['to'])); $d = $d->addDay()) {
                if (in_array($d->dayOfWeek, config('tedc.calendar.weekend', [5, 6]), true) || CalendarDay::whereDate('date', $d->toDateString())->exists()) {
                    continue;
                }
                CalendarDay::create(['date' => $d->toDateString(), 'type' => in_array($p['type'], CalendarDay::TYPES, true) ? $p['type'] : CalendarDay::NORMAL,
                    'title_ar' => $p['label'], 'title_en' => $p['label'], 'created_by' => $actor->id]);
                $made['calendar_days']++;
            }
        }

        // 2 · programmes that did not exist, as draft programmes
        $newPrograms = [];
        $firstGroup = [];
        $dayHours = app(TrainingDaySettings::class)->hours();
        foreach ($create as $c) {
            $existing = Program::where('title_ar', $c['name'])->first();
            if ($existing) {
                $newPrograms[$c['key']] = $existing->id;

                continue;
            }
            $days = max(1, (int) ($c['days'] ?? 1));
            $program = Program::create([
                'code' => app(ProgramCatalogSettings::class)->nextCode(null), 'title_ar' => $c['name'], 'title_en' => $c['name'], 'status' => Program::STATUS_DRAFT,
                'delivery_mode' => 'in_person', 'training_days' => $days, 'total_hours' => $days * $dayHours, 'capacity' => 25, 'min_attendance_percent' => 80,
                'registration_modes' => Program::MODES, 'created_by' => $actor->id,
            ]);
            $rev = $this->drafts->draft($program, $actor, 'new_program');
            $newPrograms[$c['key']] = $program->id;
            $firstGroup[$program->id] = $rev->state['groups'][0]['key'] ?? null;
            $made['programs_created']++;
        }

        // 3 · the groups' days
        foreach ($tracks as $t) {
            $programId = $t['program_id'] ?? (isset($t['proposal']) ? ($newPrograms[$t['proposal']] ?? null) : null);
            $program = $programId ? Program::find($programId) : null;
            if (! $program) {
                continue;
            }
            $rev = $this->drafts->draft($program, $actor, $source);
            $key = ! empty($t['group_id']) && collect($rev->state['groups'])->contains(fn ($g) => $g['key'] === $t['group_id'] && empty($g['deleted'])) ? $t['group_id'] : null;
            if (! $key && ! empty($firstGroup[$program->id])) {
                // A programme created by this import already has its first group: the first group of the file takes it.
                $key = $firstGroup[$program->id];
                unset($firstGroup[$program->id]);
                $this->drafts->updateGroup($rev, $key, ['title' => $t['group_name']], $actor);
                $made['created']++;
            } elseif (! $key) {
                $key = $this->drafts->addGroup($rev, $actor, ['title' => $this->freeTitle($rev->state, $t['group_name'])])['key'];
                $made['created']++;
            } else {
                $made['updated']++;
            }
            $days = [];
            foreach ($t['days'] as $d) {
                $roomId = null;
                if (! empty($d['room'])) {
                    $room = $this->findRoom($rooms, $d['room']);
                    if (! $room) {
                        $cap = (int) ($d['capacity'] ?? $roomsMeta[$d['room']]['capacity'] ?? 0) ?: 25;
                        $room = TrainingRoom::create(['name_ar' => $d['room'], 'name_en' => $d['room'], 'capacity' => $cap, 'status' => 'active', 'room_type' => 'training',
                            'tag' => $roomsMeta[$d['room']]['tag'] ?? null, 'layout' => 'classroom', 'layouts' => ['classroom' => $cap]]);
                        $rooms->push($room);
                        $made['rooms_created']++;
                    }
                    $roomId = $room->id;
                }
                $trainerId = null;
                if (! empty($d['trainer'])) {
                    $trainerId = $this->best($trainers, $d['trainer'], 0.85)['id'] ?? null;
                    if (! $trainerId) {
                        $made['trainers_missing'][$d['trainer']] = true;
                    }
                }
                $days[] = ['date' => $d['date'], 'room_id' => $roomId, 'trainer_id' => $trainerId, 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null];
            }
            try {
                $result = $this->drafts->replaceDays($rev->fresh(), $key, $days, $actor);
                foreach ($result['failures'] as $f) {
                    $made['failures'][] = ['program' => $program->title_ar, 'group' => $t['group_name'], 'date' => $f['date'], 'message' => $f['message']];
                }
            } catch (BusinessRuleException $e) {
                $made['failures'][] = ['program' => $program->title_ar, 'group' => $t['group_name'], 'date' => null, 'message' => $e->getMessage()];
            }
            if (! empty($t['supervisor'])) {
                $user = $this->best($supervisors, $t['supervisor']);
                if ($user) {
                    $this->drafts->updateGroup($rev->fresh(), $key, ['supervisor_id' => $user['id']], $actor);
                } else {
                    $made['supervisors_missing'][$t['supervisor']] = true;
                }
            }
            $touched[$program->id] = $rev->id;
        }
        $made['drafts'] = count($touched);
        if ($submit) {
            foreach ($touched as $revId) {
                $rev = SaberRevision::find($revId);
                if ($rev && $rev->status === SaberRevision::DRAFT) {
                    $this->drafts->submit($rev, $actor);
                    $made['submitted']++;
                }
            }
        }
        $made['supervisors_missing'] = array_keys($made['supervisors_missing']);
        $made['trainers_missing'] = array_keys($made['trainers_missing']);

        return $made;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * «الباقة الثالثة للمعلمين - معلمون ومنسقون - 1» → programme + group «معلمون ومنسقون - 1»; «شروق الشمس - انجي» → programme + supervisor.
     * With an explicit group column, the whole text is the programme name.
     *
     * @return array{program: ?array{id: string, name: string}, score: float, group: string, supervisor: ?string}
     */
    private function matchLabel(string $label, array $programs, array $knownSupervisors, bool $whole): array
    {
        $segments = $whole ? [$label] : $this->segments($label);
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
     * «البرنامج - المجموعة - 1» split on dashes; a number glued to the end («تمهين 1»، «الفعّال5») is the group number too.
     *
     * @return list<string>
     */
    private function segments(string $label): array
    {
        $segments = array_values(array_filter(array_map('trim', preg_split('/\s*[-–—]\s*/u', $label) ?: []), fn ($s) => $s !== ''));
        $last = end($segments);
        if ($last !== false && preg_match('/^(.*\D)\s*(\d{1,2})$/u', $last, $m) && trim($m[1]) !== '' && ! preg_match('/[(\[]/u', $m[1])) {
            array_splice($segments, -1, 1, [trim($m[1]), $m[2]]);
        }

        return $segments;
    }

    /**
     * A programme name for a label that matches none: the label without its trailing group number and without a trailing person
     * («… - أ.اسم» or a single-word name) — «الباقة الثانية - تربية بدنية - 3» → «الباقة الثانية - تربية بدنية», group 3.
     *
     * @return array{name: string, group: string, supervisor: ?string}
     */
    private function propose(string $label, bool $whole, array $knownSupervisors): array
    {
        // A note in brackets before the name («(اضافة 3 طاولات) …») is not part of it.
        $label = trim(preg_replace('/^\s*\([^)]*\)\s*/u', '', $label) ?? $label);
        $segments = $whole ? [$label] : $this->segments($label);
        $group = '';
        if (count($segments) > 1 && preg_match('/^\d+$/', end($segments))) {
            $group = TrainingGroup::defaultTitle((int) array_pop($segments), 'ar');
        }
        // The requesting department («- ادارة المناهج») is not part of the name either.
        if (count($segments) > 1 && preg_match('/^(ادارة|إدارة|قسم)\s/u', end($segments))) {
            array_pop($segments);
        }
        $supervisor = null;
        if (count($segments) > 1) {
            $last = end($segments);
            $known = collect($knownSupervisors)->first(fn ($s) => ArabicMatch::similarity($s, $last) >= 0.8);
            if ($known || preg_match('/^\s*(أ|د)\s*[.\/]/u', $last) || count(preg_split('/\s+/u', trim($last)) ?: []) === 1) {
                $supervisor = $known ?: trim($last);
                array_pop($segments);
            }
        }

        // Unicode-aware: trim() works on bytes and would cut Arabic letters that share a byte with «،».
        return ['name' => preg_replace('/^[\s،,\-]+|[\s،,\-]+$/u', '', implode(' - ', $segments)) ?? '', 'group' => $group, 'supervisor' => $supervisor];
    }

    /** Exams → exam days, holidays and Eid → vacation, the rest (Ramadan, sports day, events) → closed for training. */
    private function periodType(string $label): string
    {
        $n = ArabicMatch::normalize($label);

        return match (true) {
            str_contains($n, 'اختبار') => CalendarDay::EXAM,
            str_contains($n, 'اجازه') || str_contains($n, 'عيد') || str_contains($n, 'عطله') => CalendarDay::VACATION,
            default => CalendarDay::NORMAL,
        };
    }

    /** Same label on consecutive or overlapping dates → one period. @return list<array<string, mixed>> */
    private function mergePeriods(array $periods): array
    {
        usort($periods, fn ($a, $b) => strcmp($a['from'], $b['from']));
        $out = [];
        foreach ($periods as $p) {
            $i = count($out) - 1;
            if ($i >= 0 && ArabicMatch::normalize($out[$i]['label']) === ArabicMatch::normalize($p['label'])
                && CarbonImmutable::parse($out[$i]['to'])->addDays(3)->toDateString() >= $p['from']) {
                $out[$i]['to'] = max($out[$i]['to'], $p['to']);
                $out[$i]['kind'] = $out[$i]['kind'] === 'period' ? 'period' : $p['kind'];

                continue;
            }
            $out[] = $p;
        }

        return $out;
    }

    /** One label in several rooms on one date means parallel groups: each track takes one room per date, keeping its room when it can. */
    private function splitTracks(array $days): array
    {
        $tracks = [];
        foreach (collect($days)->groupBy('date')->sortKeys() as $date => $rows) {
            $used = [];
            foreach ($rows as $row) {
                $idx = null;
                foreach ($tracks as $i => $t) {
                    if (! isset($used[$i]) && (end($t)['room'] ?? null) === ($row['room'] ?? null) && ! collect($t)->contains('date', $date)) {
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
                $tracks[$idx][] = $row;
            }
        }

        return $tracks;
    }

    /** Each track's group in the programme's plan (same name, else same number, else a new group) and whether the plan already matches. */
    private function targets(array &$tracks): void
    {
        $rooms = TrainingRoom::get(['id', 'name_ar', 'name_en']);
        $claimed = [];
        $states = [];
        foreach ($tracks as &$t) {
            if (empty($t['program_id'])) {
                $t['group_id'] = null;
                $t['target'] = null;
                $t['verdict'] = 'new';
                $t['validated'] = false;

                continue;
            }
            $program = Program::find($t['program_id']);
            $states[$t['program_id']] ??= ($this->drafts->open($program)?->state ?? $this->drafts->live($program));
            $candidates = collect($states[$t['program_id']]['groups'])->reject(fn ($g) => ! empty($g['deleted']) || ($g['status'] ?? null) === TrainingGroup::CANCELLED || isset($claimed[$g['key']]));
            $target = $candidates->first(fn ($g) => $g['title'] && $this->sameName($g['title'], $t['group_name']))
                ?? $candidates->first(fn ($g) => (int) $g['number'] === (int) $t['index'])
                ?? ((int) $t['index'] === 1 && $candidates->count() === 1 && empty($candidates->first()['days']) ? $candidates->first() : null);
            if ($target) {
                $claimed[$target['key']] = true;
            }
            $saved = collect($target['days'] ?? [])->map(fn ($d) => $d['date'].'|'.($d['room_id'] ?? ''))->sort()->values()->all();
            $wanted = collect($t['days'])->map(fn ($d) => $d['date'].'|'.(! empty($d['room']) ? ($this->findRoom($rooms, $d['room'])?->id ?? 'new') : ''))->sort()->values()->all();
            $t['group_id'] = $target['key'] ?? null;
            $t['target'] = $target['title'] ?? null;
            $t['verdict'] = ! $target ? 'new' : ($saved === $wanted ? 'match' : 'diff');
            $t['validated'] = false;
        }
    }

    /** Close names with the same numbers: «المجموعة 2» is not «المجموعة 1». */
    private function sameName(string $a, string $b): bool
    {
        preg_match_all('/\d+/', $a, $x);
        preg_match_all('/\d+/', $b, $y);

        return $x[0] === $y[0] && ArabicMatch::similarity($a, $b) >= 0.85;
    }

    private function freeTitle(array $state, string $title): ?string
    {
        return collect($state['groups'])->contains(fn ($g) => empty($g['deleted']) && trim((string) $g['title']) === trim($title)) ? null : $title;
    }

    private function findRoom(Collection $rooms, string $name): ?TrainingRoom
    {
        $n = ArabicMatch::normalize($name);

        return $rooms->first(fn ($r) => ArabicMatch::normalize($r->name_ar) === $n || ArabicMatch::normalize($r->name_en) === $n);
    }

    private function supervisors(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::COORDINATOR, Role::TRAINING_HEAD]))->get(['id', 'name', 'name_ar'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name_ar ?: $u->name]);
    }

    private function best(Collection $people, string $name, float $min = 0.8): ?array
    {
        return $people->map(fn ($s) => $s + ['s' => ArabicMatch::similarity($s['name'], $name)])->filter(fn ($s) => $s['s'] >= $min)->sortByDesc('s')->first();
    }

    private function text(mixed $v): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');

        return $s === '' ? null : $s;
    }
}
