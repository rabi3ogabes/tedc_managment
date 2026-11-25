<?php

namespace App\Services;

use App\Models\PresenceSession;
use App\Models\User;
use App\Support\GeoLocator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Who is online now": heartbeats from the web dashboard / portal and the mobile app, grouped into sessions.
 * Users are split into the administration team (anyone with dashboard access) and app users (members).
 */
class PresenceService
{
    /** A user counts as online while their last heartbeat is younger than this. */
    public const ONLINE_SECONDS = 120;

    /** A visit continues while heartbeats arrive less than this far apart. */
    public const SESSION_GAP_MINUTES = 10;

    /** Idle for longer than this = "idle" instead of "active". */
    public const ACTIVE_WITHIN_SECONDS = 60;

    public static function isStaff(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasPermission('dashboard.view') || $user->hasPermission('kits.view');
    }

    /** @param  array{platform: string, path?: ?string, idle_seconds?: ?int, app_version?: ?string}  $data */
    public function heartbeat(User $user, array $data, ?string $userAgent = null, ?Request $request = null): ?PresenceSession
    {
        $now = now();
        $settings = app(PresenceSettings::class);
        $geo = $request && $settings->all()['locations'] ? GeoLocator::fromRequest($request) : null;
        $platform = $data['platform'];
        $idle = max(0, (int) ($data['idle_seconds'] ?? 0));
        $path = isset($data['path']) ? mb_substr($data['path'], 0, 191) : null;

        $session = PresenceSession::where('user_id', $user->id)->where('platform', $platform)
            ->where('last_seen_at', '>=', $now->copy()->subMinutes(self::SESSION_GAP_MINUTES))->latest('last_seen_at')->first();

        // The administrator switched live presence off: nothing is recorded (the idle lock below still works).
        if (! $settings->enabled()) {
            if (! $user->locked_at) {
                $user->forceFill(['last_active_at' => $now->copy()->subSeconds($idle)])->saveQuietly();
            }

            return null;
        }

        if ($session) {
            $session->update(['last_seen_at' => $now, 'hits' => $session->hits + 1, 'idle_seconds' => $idle, 'last_path' => $path ?? $session->last_path] + ($geo && $geo['country'] ? $geo : []));
        } else {
            $user->loadMissing('roles');
            $session = PresenceSession::create([
                'user_id' => $user->id, 'platform' => $platform, 'team' => self::isStaff($user) ? 'staff' : 'members',
                'role_label' => $user->roles->map(fn ($r) => app()->getLocale() === 'en' ? $r->name_en : $r->name_ar)->take(2)->implode('، ') ?: null,
                'started_at' => $now, 'last_seen_at' => $now, 'idle_seconds' => $idle, 'last_path' => $path,
                'device' => $platform === 'web' ? self::browser($userAgent) : self::phone($userAgent), 'app_version' => $data['app_version'] ?? null,
                'source' => GeoLocator::source($platform, $userAgent),
            ] + ($geo ?? []));
        }

        // Real user activity (not the polling of an open tab) feeds the idle lock of the administration team.
        if (! $user->locked_at) {
            $user->forceFill(['last_active_at' => $now->copy()->subSeconds($idle)])->saveQuietly();
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public function live(): array
    {
        $now = now();
        $online = PresenceSession::with('user:id,name,name_ar,email,locked_at')
            ->where('last_seen_at', '>=', $now->copy()->subSeconds(self::ONLINE_SECONDS))->orderByDesc('last_seen_at')->get();

        $users = $online->map(fn (PresenceSession $s) => [
            'session_id' => $s->id, 'user_id' => $s->user_id, 'name' => $s->user?->displayName() ?? '—', 'email' => $s->user?->email,
            'role' => $s->role_label, 'team' => $s->team, 'platform' => $s->platform, 'device' => $s->device, 'path' => $s->last_path,
            'started_at' => $s->started_at->toIso8601String(), 'last_seen_at' => $s->last_seen_at->toIso8601String(), 'minutes' => $s->minutes(),
            'status' => $s->user?->locked_at ? 'locked' : ($s->idle_seconds <= self::ACTIVE_WITHIN_SECONDS ? 'active' : 'idle'),
            'hits' => $s->hits, 'source' => $s->source ?? ($s->platform === 'mobile' ? 'app' : 'desktop'),
            'country' => $s->country, 'region' => $s->region, 'city' => $s->city, 'lat' => $s->lat !== null ? (float) $s->lat : null, 'lng' => $s->lng !== null ? (float) $s->lng : null,
        ])->values();

        $distinct = fn (Collection $rows) => $rows->pluck('user_id')->unique()->count();
        $recent = PresenceSession::with('user:id,name,name_ar')->where('last_seen_at', '<', $now->copy()->subSeconds(self::ONLINE_SECONDS))
            ->where('last_seen_at', '>=', $now->copy()->subHour())->orderByDesc('last_seen_at')->limit(30)->get()
            ->unique('user_id')->take(8)->map(fn (PresenceSession $s) => [
                'user_id' => $s->user_id, 'name' => $s->user?->displayName() ?? '—', 'team' => $s->team, 'platform' => $s->platform,
                'last_seen_at' => $s->last_seen_at->toIso8601String(), 'minutes' => $s->minutes(),
            ])->values();

        $todayRows = PresenceSession::where('started_at', '>=', $now->copy()->startOfDay())->get();

        $placed = $users->filter(fn ($u) => $u['lat'] !== null && $u['lng'] !== null);

        return [
            'enabled' => true,
            'settings' => app(PresenceSettings::class)->all(),
            'generated_at' => $now->toIso8601String(),
            // People grouped by place (a city, or the same coordinates), for the map.
            'places' => $placed->groupBy(fn ($u) => $u['lat'].','.$u['lng'])->map(fn ($g) => [
                'lat' => $g->first()['lat'], 'lng' => $g->first()['lng'], 'country' => $g->first()['country'], 'city' => $g->first()['city'], 'count' => $g->pluck('user_id')->unique()->count(),
                'staff' => $g->where('team', 'staff')->pluck('user_id')->unique()->count(), 'sources' => $g->countBy('source'),
                'users' => $g->take(6)->map(fn ($u) => ['name' => $u['name'], 'team' => $u['team'], 'source' => $u['source']])->values(),
            ])->values(),
            'by_source' => collect(['app', 'desktop', 'mobile_web', 'tablet'])->mapWithKeys(fn ($k) => [$k => $users->where('source', $k)->pluck('user_id')->unique()->count()]),
            'by_country' => $users->whereNotNull('country')->groupBy('country')->map(fn ($g, $c) => ['country' => $c, 'count' => $g->pluck('user_id')->unique()->count()])->sortByDesc('count')->values(),
            'unlocated' => $users->whereNull('lat')->pluck('user_id')->unique()->count(),
            'online' => [
                'total' => $distinct($online), 'staff' => $distinct($online->where('team', 'staff')), 'members' => $distinct($online->where('team', 'members')),
                'web' => $distinct($online->where('platform', 'web')), 'mobile' => $distinct($online->where('platform', 'mobile')),
                'active' => $users->where('status', 'active')->pluck('user_id')->unique()->count(),
            ],
            'users' => $users,
            'top_pages' => $online->whereNotNull('last_path')->groupBy('last_path')->map(fn ($g, $path) => ['path' => $path, 'count' => $g->pluck('user_id')->unique()->count()])->sortByDesc('count')->take(6)->values(),
            'recent' => $recent,
            'timeline' => $this->timeline($now, 30, 1),
            'today' => [
                'unique_users' => $todayRows->pluck('user_id')->unique()->count(), 'sessions' => $todayRows->count(),
                'avg_minutes' => $todayRows->isEmpty() ? 0 : round($todayRows->avg(fn (PresenceSession $s) => $s->minutes()), 1),
                'peak' => collect($this->timeline($now, (int) min(144, max(1, ceil($now->copy()->startOfDay()->diffInMinutes($now) / 10))), 10))->max(fn ($p) => $p['staff'] + $p['members']) ?? 0,
            ],
        ];
    }

    /** Distinct users online per bucket, for the live chart. @return list<array{t: string, staff: int, members: int}> */
    public function timeline(Carbon $now, int $buckets, int $minutes): array
    {
        $from = $now->copy()->subMinutes($buckets * $minutes);
        $sessions = PresenceSession::where('last_seen_at', '>=', $from)->where('started_at', '<=', $now)->get(['user_id', 'team', 'started_at', 'last_seen_at']);
        $points = [];
        for ($i = $buckets - 1; $i >= 0; $i--) {
            $end = $now->copy()->subMinutes($i * $minutes);
            $start = $end->copy()->subMinutes($minutes);
            $active = $sessions->filter(fn ($s) => $s->started_at <= $end && $s->last_seen_at->copy()->addSeconds(self::ONLINE_SECONDS) >= $start);
            $points[] = [
                't' => $end->toIso8601String(),
                'staff' => $active->where('team', 'staff')->pluck('user_id')->unique()->count(),
                'members' => $active->where('team', 'members')->pluck('user_id')->unique()->count(),
            ];
        }

        return $points;
    }

    /** @return Collection<int, PresenceSession> */
    public function sessions(Carbon $from, Carbon $to, ?string $team = null, ?string $platform = null): Collection
    {
        return PresenceSession::with('user:id,name,name_ar,email')->whereBetween('started_at', [$from, $to])
            ->when($team, fn ($q, $v) => $q->where('team', $v))->when($platform, fn ($q, $v) => $q->where('platform', $v))
            ->orderBy('started_at')->limit(50000)->get();
    }

    /** @return array<string, mixed> */
    public function summary(Collection $sessions): array
    {
        $minutes = fn (Collection $rows) => (int) $rows->sum(fn (PresenceSession $s) => $s->minutes());
        $tz = config('app.timezone');

        return [
            'sessions' => $sessions->count(), 'unique_users' => $sessions->pluck('user_id')->unique()->count(),
            'total_minutes' => $minutes($sessions), 'avg_minutes' => $sessions->isEmpty() ? 0 : round($minutes($sessions) / $sessions->count(), 1),
            'by_team' => ['staff' => $sessions->where('team', 'staff')->pluck('user_id')->unique()->count(), 'members' => $sessions->where('team', 'members')->pluck('user_id')->unique()->count()],
            'by_platform' => ['web' => $sessions->where('platform', 'web')->count(), 'mobile' => $sessions->where('platform', 'mobile')->count()],
            'by_day' => $sessions->groupBy(fn (PresenceSession $s) => $s->started_at->timezone($tz)->toDateString())
                ->map(fn ($g, $day) => ['day' => $day, 'sessions' => $g->count(), 'users' => $g->pluck('user_id')->unique()->count()])->values(),
            'by_hour' => collect(range(0, 23))->map(fn ($h) => ['hour' => $h, 'sessions' => $sessions->filter(fn (PresenceSession $s) => (int) $s->started_at->timezone($tz)->format('G') === $h)->count()])->all(),
            'top_pages' => $sessions->whereNotNull('last_path')->groupBy('last_path')->map(fn ($g, $p) => ['path' => $p, 'sessions' => $g->count()])->sortByDesc('sessions')->take(8)->values(),
            'top_users' => $sessions->groupBy('user_id')->map(fn ($g) => ['name' => $g->first()->user?->displayName() ?? '—', 'team' => $g->first()->team, 'sessions' => $g->count(), 'minutes' => $minutes($g)])->sortByDesc('minutes')->take(8)->values(),
        ];
    }

    public static function browser(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => null,
        };

        return $os ? "{$browser} · {$os}" : $browser;
    }

    public static function phone(?string $ua): ?string
    {
        return $ua && str_contains($ua, 'iPhone') ? 'iPhone' : ($ua && str_contains($ua, 'Android') ? 'Android' : 'Mobile app');
    }
}
