<?php

namespace App\Integrations\Teams;

use Illuminate\Support\Carbon;

/** What TEDC needs from Microsoft Graph for Teams. Two implementations: the real one (HTTP) and a fake one for tests and training. */
interface GraphApi
{
    /** @return array{id: string, join_url: string} */
    public function createMeeting(string $organizerUpn, string $subject, Carbon $start, Carbon $end, string $lobby): array;

    public function updateMeeting(string $organizerUpn, string $meetingId, string $subject, Carbon $start, Carbon $end): void;

    public function deleteMeeting(string $organizerUpn, string $meetingId): void;

    /**
     * Participants of a finished meeting, with the intervals they were in the call.
     *
     * @return list<array{email: ?string, name: ?string, role: ?string, total_seconds: int, intervals: list<array{join: string, leave: string, seconds: int}>}>
     */
    public function attendance(string $organizerUpn, string $meetingId): array;

    /** @return array{id: string, channel_id: ?string, web_url: ?string, drive_id: ?string, folder_item_id: ?string} */
    public function createTeam(string $name, string $description, string $ownerUpn): array;

    /** Members now in the team: membership id => e-mail. @return array<string, string> */
    public function members(string $teamId): array;

    public function addMember(string $teamId, string $email, bool $owner = false): void;

    public function removeMember(string $teamId, string $membershipId): void;

    public function uploadFile(string $driveId, string $folderItemId, string $name, string $content, string $mime): ?string;

    public function ping(): bool;

    // ---- town halls: the organiser's delegated permission (Graph has no application permission for them) ----

    /**
     * A town hall, created as a draft (solutions/virtualEvents/townhalls).
     *
     * @param  list<string>  $attendeeIds  Entra object ids of the people invited
     * @return string the town hall's id
     */
    public function createTownhall(string $subject, string $description, Carbon $start, Carbon $end, array $attendeeIds, bool $inviteOnly): string;

    /** Makes a draft town hall visible to its audience (and creates its session). */
    public function publishTownhall(string $townhallId): void;

    /** @return list<array{id: string, join_url: ?string}> the town hall's sessions (one for a single event) */
    public function townhallSessions(string $townhallId): array;

    /** @param  list<string>|null  $attendeeIds  null leaves the invitations as they are */
    public function updateTownhall(string $townhallId, string $subject, Carbon $start, Carbon $end, ?array $attendeeIds = null): void;

    public function cancelTownhall(string $townhallId): void;

    /**
     * Participants of a finished town hall (all its sessions, or the one given), as attendance() reports them.
     *
     * @return list<array{email: ?string, name: ?string, role: ?string, total_seconds: int, intervals: list<array{join: string, leave: string, seconds: int}>}>
     */
    public function townhallAttendance(string $townhallId, ?string $sessionId = null): array;

    // ---- people, the activity feed and what the tenant granted ----

    /** The Entra object id of a person in the tenant (by UPN or e-mail), or null when there is none. */
    public function userId(string $upn): ?string;

    /** One notification in a person's Teams activity feed (sendActivityNotification). @param  array<string, mixed>  $payload */
    public function sendActivity(string $userId, array $payload): void;

    /**
     * What the tenant granted TEDC: the application permissions of its app, and the organiser's delegated ones (null when
     * no organiser is connected).
     *
     * @return array{application: list<string>, delegated: list<string>|null}
     */
    public function granted(): array;
}
