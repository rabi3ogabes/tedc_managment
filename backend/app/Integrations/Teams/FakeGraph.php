<?php

namespace App\Integrations\Teams;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** A Teams that lives in the cache: meetings, teams and members behave like the real thing, and attendance comes from `fake_attendance` in the settings. */
class FakeGraph implements GraphApi
{
    /** @param  array<string, mixed>  $s */
    public function __construct(private readonly array $s = []) {}

    public function createMeeting(string $organizerUpn, string $subject, Carbon $start, Carbon $end, string $lobby): array
    {
        $id = 'fake-'.Str::uuid();
        Cache::forever("fakegraph.meeting.{$id}", compact('organizerUpn', 'subject', 'lobby') + ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()]);

        return ['id' => $id, 'join_url' => "https://teams.example/l/meetup-join/{$id}"];
    }

    public function updateMeeting(string $organizerUpn, string $meetingId, string $subject, Carbon $start, Carbon $end): void
    {
        $m = Cache::get("fakegraph.meeting.{$meetingId}", []);
        Cache::forever("fakegraph.meeting.{$meetingId}", ['subject' => $subject, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String()] + $m);
    }

    public function deleteMeeting(string $organizerUpn, string $meetingId): void
    {
        Cache::forget("fakegraph.meeting.{$meetingId}");
    }

    public function attendance(string $organizerUpn, string $meetingId): array
    {
        $all = is_string($this->s['fake_attendance'] ?? null) ? (json_decode($this->s['fake_attendance'], true) ?: []) : ($this->s['fake_attendance'] ?? []);

        return $all[$meetingId] ?? $all['*'] ?? [];
    }

    public function createTeam(string $name, string $description, string $ownerUpn): array
    {
        $id = 'fake-team-'.Str::uuid();
        Cache::forever("fakegraph.team.{$id}", [Str::uuid()->toString() => strtolower($ownerUpn)]);

        return ['id' => $id, 'channel_id' => 'fake-channel', 'web_url' => "https://teams.example/l/team/{$id}", 'drive_id' => 'fake-drive', 'folder_item_id' => 'fake-folder'];
    }

    public function members(string $teamId): array
    {
        return Cache::get("fakegraph.team.{$teamId}", []);
    }

    public function addMember(string $teamId, string $email, bool $owner = false): void
    {
        $m = $this->members($teamId);
        $m[Str::uuid()->toString()] = strtolower($email);
        Cache::forever("fakegraph.team.{$teamId}", $m);
    }

    public function removeMember(string $teamId, string $membershipId): void
    {
        $m = $this->members($teamId);
        unset($m[$membershipId]);
        Cache::forever("fakegraph.team.{$teamId}", $m);
    }

    public function uploadFile(string $driveId, string $folderItemId, string $name, string $content, string $mime): ?string
    {
        $files = Cache::get("fakegraph.files.{$driveId}", []);
        $files[$name] = strlen($content);
        Cache::forever("fakegraph.files.{$driveId}", $files);

        return "https://sharepoint.example/{$driveId}/{$name}";
    }

    public function ping(): bool
    {
        return true;
    }

    public function createTownhall(string $subject, string $description, Carbon $start, Carbon $end, array $attendeeIds, bool $inviteOnly): string
    {
        $id = 'fake-townhall-'.Str::uuid();
        Cache::forever("fakegraph.townhall.{$id}", ['subject' => $subject, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'attendees' => array_values($attendeeIds), 'invite_only' => $inviteOnly, 'status' => 'draft']);

        return $id;
    }

    public function publishTownhall(string $townhallId): void
    {
        Cache::forever("fakegraph.townhall.{$townhallId}", ['status' => 'published'] + Cache::get("fakegraph.townhall.{$townhallId}", []));
    }

    public function townhallSessions(string $townhallId): array
    {
        return [['id' => 'fake-session-'.substr(md5($townhallId), 0, 12), 'join_url' => "https://teams.example/l/townhall/{$townhallId}"]];
    }

    public function updateTownhall(string $townhallId, string $subject, Carbon $start, Carbon $end, ?array $attendeeIds = null): void
    {
        $t = Cache::get("fakegraph.townhall.{$townhallId}", []);
        Cache::forever("fakegraph.townhall.{$townhallId}", ['subject' => $subject, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String()] + ($attendeeIds === null ? [] : ['attendees' => array_values($attendeeIds)]) + $t);
    }

    public function cancelTownhall(string $townhallId): void
    {
        Cache::forever("fakegraph.townhall.{$townhallId}", ['status' => 'canceled'] + Cache::get("fakegraph.townhall.{$townhallId}", []));
    }

    public function townhallAttendance(string $townhallId, ?string $sessionId = null): array
    {
        return $this->attendance('', $townhallId);
    }

    public function userId(string $upn): ?string
    {
        return 'fake-oid-'.substr(md5(strtolower($upn)), 0, 16);
    }

    public function sendActivity(string $userId, array $payload): void
    {
        $sent = Cache::get('fakegraph.activity', []);
        $sent[] = ['user' => $userId] + $payload;
        Cache::forever('fakegraph.activity', array_slice($sent, -50));
    }

    public function granted(): array
    {
        $listed = is_string($this->s['fake_permissions'] ?? null) ? json_decode($this->s['fake_permissions'], true) : ($this->s['fake_permissions'] ?? null);
        $names = is_array($listed) ? array_values($listed) : array_column(GraphPermissions::all(), 'permission');

        return ['application' => $names, 'delegated' => $names];
    }
}
