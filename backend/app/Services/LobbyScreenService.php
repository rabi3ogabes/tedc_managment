<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use Illuminate\Support\Carbon;

/**
 * What the lobby screen shows right now: today's programs (one row per session: program, time, room, floor and
 * coordinator) split into pages, followed by the images the administrator added — each with its own time and transition.
 */
class LobbyScreenService
{
    public function __construct(private readonly LobbyScreenSettings $settings, private readonly ThemeService $theme) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $s = $this->settings->all();
        $tz = config('app.timezone');
        $now = Carbon::now($tz);
        $today = $now->toDateString();

        $sessions = $s['show_programs'] ? ProgramSession::with(['program:id,code,title_ar,title_en,coordinator_id,status,delivery_mode', 'program.coordinator:id,name,name_ar', 'room:id,code,name_ar,name_en,floor,building'])
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$now->copy()->startOfDay()->utc(), $now->copy()->endOfDay()->utc()])
            ->whereHas('program', fn ($q) => $q->whereNotIn('status', [Program::STATUS_DRAFT, Program::STATUS_CANCELLED, Program::STATUS_ARCHIVED]))
            ->orderBy('starts_at')->get() : collect();

        $attending = Registration::whereIn('program_id', $sessions->pluck('program_id')->unique())->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->selectRaw('program_id, count(*) n')->groupBy('program_id')->pluck('n', 'program_id');

        $rows = $sessions->map(fn (ProgramSession $x) => [
            'id' => $x->id,
            'program' => ['code' => $x->program->code, 'title_ar' => $x->program->title_ar, 'title_en' => $x->program->title_en],
            'starts_at' => $x->starts_at->toIso8601String(), 'ends_at' => $x->ends_at->toIso8601String(),
            'state' => $x->ends_at->lt($now) ? 'ended' : ($x->starts_at->lte($now) ? 'live' : 'upcoming'),
            'mode' => $x->mode, 'platform' => $x->online_platform,
            'room' => $x->room ? ['code' => $x->room->code, 'name_ar' => $x->room->name_ar, 'name_en' => $x->room->name_en, 'floor' => $x->room->floor, 'building' => $x->room->building] : null,
            'coordinator' => $x->program->coordinator ? ['name_ar' => $x->program->coordinator->name_ar ?: $x->program->coordinator->name, 'name_en' => $x->program->coordinator->name] : null,
            'attendees' => (int) ($attending[$x->program_id] ?? 0),
        ])->sortBy(fn ($r) => [['live' => 0, 'upcoming' => 1, 'ended' => 2][$r['state']], $r['starts_at']])->values();

        $identity = $this->theme->get()['identity'] ?? [];
        $visible = fn (array $slide) => $slide['enabled'] && (! $slide['from'] || $slide['from'] <= $today) && (! $slide['to'] || $slide['to'] >= $today) && ! empty($slide['path']);

        return [
            'generated_at' => $now->toIso8601String(),
            'date' => $today,
            'settings' => array_intersect_key($s, array_flip(['enabled', 'show_programs', 'programs_seconds', 'programs_per_slide', 'slide_seconds', 'transition', 'transition_ms', 'show_clock', 'show_progress', 'language'])),   // never the token or file paths
            'center' => ['name_ar' => $this->theme->centerName()['ar'], 'name_en' => $this->theme->centerName()['en']],
            'programs' => $rows,
            'pages' => max(1, (int) ceil($rows->count() / max(1, (int) $s['programs_per_slide']))),
            'slides' => collect($s['slides'])->filter($visible)->map(fn (array $slide) => [
                'id' => $slide['id'], 'title' => $slide['title'], 'url' => FileStorage::publicUrl($slide['path']),
                'seconds' => $slide['seconds'] ?? (int) $s['slide_seconds'], 'transition' => $slide['transition'] ?? $s['transition'],
            ])->values()->all(),
            'brand' => ['logo' => $identity['logo_ar'] ?? null],
        ];
    }
}
