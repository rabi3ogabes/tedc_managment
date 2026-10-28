<?php

namespace App\Services;

use App\Models\ProgramSession;
use App\Models\SaberRevision;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Services\Saber\ArabicMatch;
use App\Services\Saber\CalendarImport;
use App\Services\Saber\WorkbookReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «مزامنة القاعات من ملف» (docs/saber/06-rooms-sync.md): the platform's rooms brought in line with a file — the centre's rooms × dates
 * calendar (room names, capacity under them, trainee priority above them) or a plain room list (name, capacity, type, tag, code, building,
 * floor in any order). The preview says which rooms are added, which change and how, and which are no longer in the file; applying does only
 * what was chosen. A room leaving is deleted only when nothing ever used it — otherwise it is made inactive so history, reports and drafts
 * stay whole. Meeting rooms are left alone when the file holds no meeting rooms (the calendar only lists training rooms).
 */
class RoomSync
{
    /** Header names of a room list, per field (compared after Arabic folding). */
    private const LIST_COLUMNS = [
        'name' => ['اسم القاعه', 'القاعه', 'الاسم', 'اسم القاعه بالعربيه', 'name', 'room', 'room name'],
        'name_en' => ['الاسم بالانجليزيه', 'اسم القاعه بالانجليزيه', 'name en', 'english name'],
        'code' => ['الرمز', 'الكود', 'رمز القاعه', 'code'],
        'room_type' => ['النوع', 'نوع القاعه', 'type'],
        'tag' => ['الوسم', 'الفئه', 'الاولويه', 'tag'],
        'capacity' => ['السعه', 'عدد المقاعد', 'المقاعد', 'السعه القصوي', 'capacity', 'seats'],
        'building' => ['المبني', 'building'],
        'floor' => ['الطابق', 'floor'],
    ];

    public const FIELDS = ['name', 'name_en', 'code', 'room_type', 'tag', 'capacity', 'building', 'floor', 'status'];

    public function __construct(private readonly WorkbookReader $reader, private readonly CalendarImport $calendar) {}

    /**
     * The rooms written in the file, one entry per room (the first spelling wins), with only the fields the file actually holds.
     *
     * @return array{source: 'grid'|'list', rooms: list<array<string, mixed>>}
     */
    public function read(string $path, ?string $sheet = null): array
    {
        $rows = $this->reader->rows($path, $sheet);
        $list = $this->listColumns($rows);
        $rooms = [];
        if ($list) {
            [$header, $cols] = $list;
            foreach (array_slice($rows, $header + 1) as $row) {
                $name = $this->text($row[$cols['name']] ?? null);
                if ($name === null) {
                    continue;
                }
                $room = ['name' => $name];
                foreach ($cols as $field => $c) {
                    if ($field === 'name') {
                        continue;
                    }
                    $v = $this->text($row[$c] ?? null);
                    $room[$field] = match ($field) {
                        'capacity' => $v !== null && preg_match('/\d+/', $v, $m) ? min(5000, max(1, (int) $m[0])) : null,
                        'room_type' => $this->type($v),
                        default => $v,
                    };
                }
                $rooms[] = $room;
            }
        } else {
            foreach ($this->calendar->gridRooms($rows)['meta'] as $name => $m) {
                $rooms[] = ['name' => $name, 'capacity' => $m['capacity'], 'tag' => $m['tag']];
            }
        }
        abort_if($rooms === [], 422, __('messages.rooms_sync.no_rooms'));

        $seen = [];

        return ['source' => $list ? 'list' : 'grid', 'rooms' => array_values(array_filter($rooms, function ($r) use (&$seen) {
            $k = ArabicMatch::roomKey($r['name']);

            return $k !== '' && ! isset($seen[$k]) && ($seen[$k] = true);
        }))];
    }

    /**
     * What applying the file would do. Nothing is saved.
     *
     * @param  array{source: 'grid'|'list', rooms: list<array<string, mixed>>}  $file
     * @return array<string, mixed>
     */
    public function plan(array $file): array
    {
        $rooms = TrainingRoom::orderBy('name_ar')->get();
        $taken = [];
        $add = $update = $same = [];
        foreach ($file['rooms'] as $i => $item) {
            $room = $this->match($rooms, $item['name'], $taken);
            if (! $room) {
                $add[] = ['key' => 'a'.$i] + array_merge($item, ['room_type' => $item['room_type'] ?? 'training', 'exists_code' => isset($item['code']) && $this->codeTaken($item['code'])]);

                continue;
            }
            $taken[$room->id] = true;
            $changes = $this->changes($room, $item);
            $row = ['id' => $room->id, 'name' => $room->name_ar, 'room_type' => $room->room_type, 'capacity' => $room->capacity, 'tag' => $room->tag];
            if ($changes) {
                $update[] = $row + ['changes' => $changes];
            } else {
                $same[] = $row;
            }
        }

        // The calendar lists training rooms only: unless the file names meeting rooms, the meeting rooms stay as they are.
        $protectMeetings = ! collect($file['rooms'])->contains(fn ($r) => ($r['room_type'] ?? null) === 'meeting');
        $remove = $kept = [];
        $usage = $this->usage($rooms->reject(fn ($r) => isset($taken[$r->id]))->pluck('id')->all());
        foreach ($rooms as $room) {
            if (isset($taken[$room->id]) || $room->status === 'inactive') {
                continue;
            }
            if ($protectMeetings && $room->room_type === 'meeting') {
                $kept[] = ['id' => $room->id, 'name' => $room->name_ar, 'reason' => 'meeting'];

                continue;
            }
            $u = $usage[$room->id];
            $remove[] = ['id' => $room->id, 'name' => $room->name_ar, 'room_type' => $room->room_type, 'capacity' => $room->capacity, 'tag' => $room->tag,
                'action' => $u['used'] ? 'deactivate' : 'delete', 'upcoming' => $u['upcoming_sessions'] + $u['upcoming_bookings'] > 0 || $u['drafts'] > 0] + $u;
        }

        return [
            'source' => $file['source'], 'file_rooms' => count($file['rooms']),
            'add' => $add, 'update' => $update, 'same' => $same, 'remove' => $remove, 'kept' => $kept,
            'inactive' => $rooms->where('status', 'inactive')->reject(fn ($r) => isset($taken[$r->id]))->count(),
        ];
    }

    /**
     * Applies the chosen part of a plan in one transaction. Removal is decided again here from the room's current use, never from the client.
     *
     * @param  list<array<string, mixed>>  $add
     * @param  list<array{id: string, changes: array<string, mixed>}>  $update
     * @param  list<string>  $remove
     * @return array{added: int, updated: int, deleted: int, deactivated: int, skipped: list<string>}
     */
    public function apply(array $add, array $update, array $remove): array
    {
        return DB::transaction(function () use ($add, $update, $remove) {
            $out = ['added' => 0, 'updated' => 0, 'deleted' => 0, 'deactivated' => 0, 'skipped' => []];
            $rooms = TrainingRoom::lockForUpdate()->get();
            $taken = [];

            foreach ($add as $item) {
                if ($this->match($rooms, $item['name'], $taken)) {
                    $out['skipped'][] = $item['name'];   // added meanwhile (or applied twice)

                    continue;
                }
                $cap = (int) ($item['capacity'] ?? 30);
                $room = TrainingRoom::create([
                    'name_ar' => $item['name'], 'name_en' => $item['name_en'] ?? $item['name'],
                    'code' => isset($item['code']) && ! $this->codeTaken($item['code']) ? $item['code'] : null,
                    'room_type' => in_array($item['room_type'] ?? null, ['training', 'meeting'], true) ? $item['room_type'] : 'training',
                    'tag' => $item['tag'] ?? null, 'building' => $item['building'] ?? null, 'floor' => $item['floor'] ?? null,
                    'capacity' => $cap, 'layout' => 'classroom', 'layouts' => ['classroom' => $cap], 'status' => 'active',
                ]);
                $rooms->push($room);
                $taken[$room->id] = true;
                $out['added']++;
            }

            foreach ($update as $u) {
                $room = $rooms->firstWhere('id', $u['id']);
                if (! $room) {
                    continue;
                }
                $set = [];
                foreach (array_intersect_key($u['changes'], array_flip(self::FIELDS)) as $field => $to) {
                    if ($field === 'name') {
                        // An English name that only copied the Arabic one follows it.
                        $set['name_ar'] = $to;
                        if (in_array($room->name_en, [null, '', $room->name_ar], true)) {
                            $set['name_en'] = $to;
                        }
                    } elseif ($field === 'code' && $this->codeTaken((string) $to, $room->id)) {
                        $out['skipped'][] = "{$room->name_ar}: {$to}";
                    } elseif ($field === 'capacity') {
                        $set['capacity'] = (int) $to;
                        $set['layouts'] = array_merge($room->layouts ?? [], [$room->layout ?: 'classroom' => (int) $to]);
                    } elseif ($field === 'status') {
                        $set['status'] = 'active';
                    } elseif ($field !== 'room_type' || in_array($to, ['training', 'meeting'], true)) {
                        $set[$field] = $to;
                    }
                }
                if ($set) {
                    $room->update($set);
                    $out['updated']++;
                }
            }

            $usage = $this->usage($remove);
            foreach ($rooms->whereIn('id', $remove) as $room) {
                if ($usage[$room->id]['used']) {
                    $room->update(['status' => 'inactive']);
                    $out['deactivated']++;
                } else {
                    $room->delete();
                    $out['deleted']++;
                }
            }

            return $out;
        });
    }

    /**
     * How each room is used: training days, bookings, groups and open SABER+ drafts that point at it, and seating plans or attendance devices.
     * A room with any of them is «used» (made inactive rather than deleted — deleting would cascade its bookings and seating plans).
     *
     * @param  list<string>  $ids
     * @return array<string, array{sessions: int, upcoming_sessions: int, bookings: int, upcoming_bookings: int, groups: int, drafts: int, used: bool}>
     */
    public function usage(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $count = fn ($q, string $col) => $q->whereIn($col, $ids)->groupBy($col)->selectRaw("{$col} as id, count(*) as n")->pluck('n', 'id');
        $sessions = $count(ProgramSession::query(), 'training_room_id');
        $upcoming = $count(ProgramSession::where('starts_at', '>=', now())->where('status', '!=', 'cancelled'), 'training_room_id');
        $bookings = $count(DB::table('room_bookings'), 'room_id');
        $upBookings = $count(DB::table('room_bookings')->where('starts_at', '>=', now())->where('status', '!=', 'cancelled'), 'room_id');
        $groups = $count(TrainingGroup::query(), 'default_room_id');
        $other = collect(['seating_plans' => 'room_id', 'attendance_devices' => 'location_room_id'])
            ->filter(fn ($col, $table) => Schema::hasTable($table))
            ->flatMap(fn ($col, $table) => $count(DB::table($table), $col)->keys())->flip();
        $drafts = [];
        foreach (SaberRevision::whereIn('status', SaberRevision::OPEN)->get(['id', 'state']) as $rev) {
            $refs = collect($rev->state['groups'] ?? [])->flatMap(fn ($g) => array_merge([$g['default_room_id'] ?? null], array_column($g['days'] ?? [], 'room_id')))->filter()->unique();
            foreach ($refs as $id) {
                $drafts[$id] = ($drafts[$id] ?? 0) + 1;
            }
        }

        return collect($ids)->mapWithKeys(function ($id) use ($sessions, $upcoming, $bookings, $upBookings, $groups, $other, $drafts) {
            $u = ['sessions' => (int) ($sessions[$id] ?? 0), 'upcoming_sessions' => (int) ($upcoming[$id] ?? 0), 'bookings' => (int) ($bookings[$id] ?? 0),
                'upcoming_bookings' => (int) ($upBookings[$id] ?? 0), 'groups' => (int) ($groups[$id] ?? 0), 'drafts' => $drafts[$id] ?? 0];

            return [$id => $u + ['used' => array_sum($u) > 0 || isset($other[$id])]];
        })->all();
    }

    /** The changes a file entry brings to a room, field => [from, to]; fields the file does not hold, or leaves empty, are left as they are. */
    private function changes(TrainingRoom $room, array $item): array
    {
        $now = ['name' => $room->name_ar, 'name_en' => $room->name_en, 'code' => $room->code, 'room_type' => $room->room_type, 'tag' => $room->tag,
            'capacity' => $room->capacity, 'building' => $room->building, 'floor' => $room->floor];
        $out = [];
        foreach ($now as $field => $from) {
            $to = $item[$field] ?? null;
            if ($to !== null && $to !== '' && (string) $to !== (string) $from) {
                $out[$field] = ['from' => $from, 'to' => $to] + ($field === 'code' && $this->codeTaken((string) $to, $room->id) ? ['conflict' => true] : []);
            }
        }
        if ($room->status === 'inactive') {
            $out['status'] = ['from' => 'inactive', 'to' => 'active'];
        }

        return $out;
    }

    /** The room a file name stands for: the same name once the article and digits are folded; an exact, active, untaken room first. */
    private function match(Collection $rooms, string $name, array $taken): ?TrainingRoom
    {
        $key = ArabicMatch::roomKey($name);

        return $rooms->filter(fn ($r) => ! isset($taken[$r->id]) && (ArabicMatch::roomKey($r->name_ar) === $key || ArabicMatch::roomKey($r->name_en) === $key))
            ->sortBy(fn ($r) => [trim($r->name_ar) === trim($name) ? 0 : 1, $r->status === 'active' ? 0 : 1])->first();
    }

    /** The header row of a room list and the column of each field it holds; null when the sheet is not a list (a rooms × dates grid). */
    private function listColumns(array $rows): ?array
    {
        $names = collect(self::LIST_COLUMNS)->map(fn ($n) => array_map(fn ($x) => ArabicMatch::normalize($x), $n));
        foreach (array_slice($rows, 0, 10, true) as $r => $row) {
            $cols = [];
            foreach ($row as $c => $v) {
                $h = ArabicMatch::normalize((string) $v);
                $field = $h === '' ? null : $names->search(fn ($n) => in_array($h, $n, true));
                if ($field && ! isset($cols[$field])) {
                    $cols[$field] = $c;
                }
            }
            // A list has a name column and at least one other room field next to it; a calendar header holds room names, not these words.
            if (isset($cols['name']) && count($cols) >= 2) {
                return [$r, $cols];
            }
        }

        return null;
    }

    private function type(?string $v): ?string
    {
        $n = ArabicMatch::normalize($v);

        return match (true) {
            $n === '' => null,
            str_contains($n, 'اجتماع') || str_contains($n, 'meeting') => 'meeting',
            str_contains($n, 'تدريب') || str_contains($n, 'training') => 'training',
            default => null,
        };
    }

    private function codeTaken(string $code, ?string $except = null): bool
    {
        return TrainingRoom::where('code', $code)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();
    }

    private function text(mixed $v): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');

        return $s === '' ? null : $s;
    }
}
