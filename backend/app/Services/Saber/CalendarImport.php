<?php

namespace App\Services\Saber;

use App\Exceptions\BusinessRuleException;
use App\Models\Program;
use App\Models\Role;
use App\Models\SaberRevision;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Models\User;
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
     * @param  array{sheet?: ?string, header_row?: ?int, date_col?: ?int, first_room_col?: ?int}  $layout
     * @return list<array<string, mixed>> cells: date, room, capacity, label
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
                if ($n > $best) {
                    [$best, $header] = [$n, $r];
                }
            }
        }
        $rooms = [];
        foreach ($rows[$header] ?? [] as $c => $name) {
            if ($c >= $first && is_string($name) && trim($name) !== '') {
                $cap = preg_match('/\d+/', (string) ($rows[$header + 1][$c] ?? ''), $m) ? (int) $m[0] : null;
                $rooms[$c] = ['name' => trim(preg_replace('/\s+/u', ' ', $name) ?? $name), 'capacity' => $cap];
            }
        }
        $out = [];
        foreach (array_slice($rows, $header + 1, null, true) as $row) {
            $date = $this->reader->date($row[$dateCol] ?? null);
            if (! $date) {
                continue;
            }
            foreach ($rooms as $c => $room) {
                $label = is_string($row[$c] ?? null) ? trim(preg_replace('/\s+/u', ' ', $row[$c]) ?? '') : '';
                if ($label !== '') {
                    $out[] = ['date' => $date, 'room' => $room['name'], 'capacity' => $room['capacity'], 'label' => $label];
                }
            }
        }

        return $out;
    }

    /**
     * One training day per row, columns chosen by the administrator (1-based): program (required), date (required), group, start, end,
     * room, trainer, supervisor.
     *
     * @param  array<string, ?int>  $columns
     * @return list<array<string, mixed>>
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

        return $out;
    }

    /** @param list<array<string, mixed>> $cells @return array<string, mixed> the plan: groups with their target and verdict, unmatched names, rooms, trainers, supervisors */
    public function plan(array $cells): array
    {
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
        $roomsSeen = [];
        $supsSeen = [];
        $trainersSeen = [];
        foreach (collect($cells)->groupBy(fn ($c) => $c['label'].'|'.($c['group'] ?? '')) as $group) {
            $label = $group->first()['label'];
            $explicit = $group->first()['group'] ?? null;
            $parsed = $this->matchLabel($label, $programs, $supervisors->pluck('name')->all(), $explicit !== null);
            if (! $parsed['program']) {
                $best = collect($programs)->map(fn ($p) => ['name' => $p['name'], 's' => ArabicMatch::nameScore($label, $p['name'])])->sortByDesc('s')->first();
                $unmatched[] = ['label' => $label.($explicit ? " — {$explicit}" : ''), 'count' => $group->count(), 'best' => $best['name'] ?? null, 'score' => round($best['s'] ?? 0, 2)];

                continue;
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
                    'key' => $parsed['program']['id'].'|'.$label.'|'.($explicit ?? '').'|'.$i, 'label' => $label.($explicit ? " — {$explicit}" : ''), 'program_id' => $parsed['program']['id'],
                    'program' => $parsed['program']['name'], 'score' => round($parsed['score'], 2),
                    'group_name' => count($split) > 1 && $parsed['group'] ? "{$parsed['group']} ({$n})" : ($parsed['group'] ?: TrainingGroup::defaultTitle($n, 'ar')),
                    'supervisor' => $supervisor, 'index' => $n,
                    'days' => array_map(fn ($d) => array_filter(['date' => $d['date'], 'room' => $d['room'] ?? null, 'capacity' => $d['capacity'] ?? null, 'trainer' => $d['trainer'] ?? null,
                        'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null], fn ($v) => $v !== null), $days),
                ];
            }
        }
        usort($tracks, fn ($a, $b) => strcmp(ArabicMatch::normalize($a['program']), ArabicMatch::normalize($b['program'])) ?: strcmp($a['group_name'], $b['group_name']));
        $this->targets($tracks);
        usort($unmatched, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'cells' => count($cells), 'tracks' => $tracks, 'unmatched' => $unmatched,
            'rooms' => collect($roomsSeen)->map(fn ($cap, $name) => ['name' => $name, 'capacity' => $cap, 'exists' => $this->findRoom($rooms, $name) !== null])->values()->all(),
            'supervisors' => collect(array_keys($supsSeen))->map(fn ($name) => ['name' => $name, 'user' => $this->best($supervisors, $name)['name'] ?? null])->values()->all(),
            'trainers' => collect(array_keys($trainersSeen))->map(fn ($name) => ['name' => $name, 'match' => $this->best($trainers, $name, 0.85)['name'] ?? null])->values()->all(),
        ];
    }

    /**
     * Writes the chosen groups into the programmes' drafts (rooms missing from the platform are added); a day the rules refuse is reported
     * and the rest goes on. With `submit`, each touched draft is sent for review.
     *
     * @param  list<array<string, mixed>>  $tracks
     * @return array<string, mixed>
     */
    public function apply(array $tracks, bool $submit, User $actor, string $source = 'calendar_import'): array
    {
        $rooms = TrainingRoom::get(['id', 'name_ar', 'name_en']);
        $supervisors = $this->supervisors();
        $trainers = Trainer::where('status', '!=', 'inactive')->get(['id', 'name_ar', 'name_en'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name_ar ?: $t->name_en]);
        $made = ['updated' => 0, 'created' => 0, 'rooms_created' => 0, 'drafts' => 0, 'submitted' => 0, 'failures' => [], 'supervisors_missing' => [], 'trainers_missing' => []];
        $touched = [];

        foreach ($tracks as $t) {
            $program = Program::find($t['program_id']);
            if (! $program) {
                continue;
            }
            $rev = $this->drafts->draft($program, $actor, $source);
            $key = ! empty($t['group_id']) && collect($rev->state['groups'])->contains(fn ($g) => $g['key'] === $t['group_id'] && empty($g['deleted'])) ? $t['group_id'] : null;
            if (! $key) {
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
                        $cap = (int) ($d['capacity'] ?? 0) ?: 25;
                        $room = TrainingRoom::create(['name_ar' => $d['room'], 'name_en' => $d['room'], 'capacity' => $cap, 'status' => 'active', 'room_type' => 'training', 'layout' => 'classroom', 'layouts' => ['classroom' => $cap]]);
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
        $segments = $whole ? [$label] : array_values(array_filter(array_map('trim', preg_split('/\s*[-–—]\s*/u', $label) ?: []), fn ($s) => $s !== ''));
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
