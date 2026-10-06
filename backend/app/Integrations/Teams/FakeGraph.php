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
}
