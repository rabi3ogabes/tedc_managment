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
}
