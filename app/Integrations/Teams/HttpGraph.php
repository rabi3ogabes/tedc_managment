<?php

namespace App\Integrations\Teams;

use App\Integrations\IntegrationUnavailable;
use App\Support\Supabase;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Graph with application permissions (client credentials) and, for town halls, the organiser's delegated
 * permission (a refresh token from "Connect the organiser account", rotated as Entra hands out new ones). Tokens are
 * cached until just before they expire.
 */
class HttpGraph implements GraphApi
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    private const TOWNHALLS = self::BASE.'/solutions/virtualEvents/townhalls';

    /** What the organiser account delegates to TEDC. */
    public const ORGANIZER_SCOPES = ['https://graph.microsoft.com/VirtualEvent.ReadWrite', 'https://graph.microsoft.com/OnlineMeetingArtifact.Read.All', 'https://graph.microsoft.com/User.Read', 'offline_access'];

    /**
     * @param  array<string, mixed>  $s  the Teams integration settings
     * @param  (Closure(string): void)|null  $keepRefreshToken  stores the organiser's new refresh token
     */
    public function __construct(private readonly array $s, private readonly ?Closure $keepRefreshToken = null) {}

    /** The organiser's delegated token, from the stored refresh token. */
    private function delegatedToken(): string
    {
        $refresh = (string) ($this->s['organizer_refresh_token'] ?? '');
        if ($refresh === '') {
            throw new IntegrationUnavailable('The Teams organiser account is not connected.', 'organizer_not_connected');
        }
        $key = 'graph.delegated.'.md5(($this->s['tenant_id'] ?? '').($this->s['client_id'] ?? '').$refresh);
        if ($token = Cache::get($key)) {
            return $token;
        }
        $r = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15)->asForm()->post('https://login.microsoftonline.com/'.($this->s['tenant_id'] ?? '').'/oauth2/v2.0/token', [
            'grant_type' => 'refresh_token', 'client_id' => $this->s['client_id'] ?? '', 'client_secret' => $this->s['client_secret'] ?? '', 'refresh_token' => $refresh, 'scope' => implode(' ', self::ORGANIZER_SCOPES),
        ]);
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new IntegrationUnavailable('The organiser account must be connected again ('.($r->json('error') ?: 'HTTP '.$r->status()).').', 'organizer_not_connected');
        }
        $next = (string) $r->json('refresh_token');
        if ($next !== '' && $next !== $refresh && $this->keepRefreshToken) {
            ($this->keepRefreshToken)($next);
            $key = 'graph.delegated.'.md5(($this->s['tenant_id'] ?? '').($this->s['client_id'] ?? '').$next);
        }
        Cache::put($key, $r->json('access_token'), max(60, (int) $r->json('expires_in', 3600) - 300));

        return (string) $r->json('access_token');
    }

    private function asOrganizer(): PendingRequest
    {
        return Http::withOptions(['verify' => Supabase::caBundle()])->timeout(30)->withToken($this->delegatedToken())->acceptJson();
    }

    /** @return array<string, mixed> the claims of a token TEDC just received from Entra (read, not verified: it came straight from the token endpoint) */
    public static function claims(string $jwt): array
    {
        $part = explode('.', $jwt)[1] ?? '';

        return json_decode((string) base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4)), true) ?: [];
    }

    private function token(): string
    {
        $key = 'graph.token.'.md5(($this->s['tenant_id'] ?? '').($this->s['client_id'] ?? ''));

        return Cache::remember($key, 3000, function () {
            $r = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15)->asForm()->post('https://login.microsoftonline.com/'.($this->s['tenant_id'] ?? '').'/oauth2/v2.0/token', [
                'grant_type' => 'client_credentials', 'client_id' => $this->s['client_id'] ?? '', 'client_secret' => $this->s['client_secret'] ?? '', 'scope' => 'https://graph.microsoft.com/.default',
            ]);
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new RuntimeException('Microsoft Graph refused the app credentials.');
            }

            return $r->json('access_token');
        });
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['verify' => Supabase::caBundle()])->timeout(30)->withToken($this->token())->acceptJson();
    }

    private function check(Response $r): Response
    {
        if (! $r->successful()) {
            $message = 'Graph: '.($r->json('error.message') ?: 'HTTP '.$r->status());
            // Throttling and server errors are outages to retry; other client errors are Graph refusing this request.
            throw $r->clientError() && $r->status() !== 429 ? new GraphRejected($message, $r->status()) : new RuntimeException($message);
        }

        return $r;
    }

    public function createMeeting(string $organizerUpn, string $subject, Carbon $start, Carbon $end, string $lobby): array
    {
        $r = $this->check($this->http()->post(self::BASE.'/users/'.rawurlencode($organizerUpn).'/onlineMeetings', [
            'startDateTime' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'endDateTime' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'subject' => mb_substr($subject, 0, 120),
            'lobbyBypassSettings' => ['scope' => $lobby === 'everyone' ? 'everyone' : 'organization', 'isDialInBypassEnabled' => false], 'allowAttendeeToEnableMic' => true,
        ]));

        return ['id' => (string) $r->json('id'), 'join_url' => (string) $r->json('joinWebUrl')];
    }

    public function updateMeeting(string $organizerUpn, string $meetingId, string $subject, Carbon $start, Carbon $end): void
    {
        $this->check($this->http()->patch(self::BASE.'/users/'.rawurlencode($organizerUpn).'/onlineMeetings/'.rawurlencode($meetingId), [
            'startDateTime' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'endDateTime' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'subject' => mb_substr($subject, 0, 120),
        ]));
    }

    public function deleteMeeting(string $organizerUpn, string $meetingId): void
    {
        $r = $this->http()->delete(self::BASE.'/users/'.rawurlencode($organizerUpn).'/onlineMeetings/'.rawurlencode($meetingId));
        if (! $r->successful() && $r->status() !== 404) {
            $this->check($r);
        }
    }

    public function attendance(string $organizerUpn, string $meetingId): array
    {
        $base = self::BASE.'/users/'.rawurlencode($organizerUpn).'/onlineMeetings/'.rawurlencode($meetingId).'/attendanceReports';
        $reports = (array) $this->check($this->http()->get($base))->json('value');
        $people = [];
        foreach ($reports as $report) {
            $records = (array) $this->check($this->http()->get($base.'/'.rawurlencode((string) $report['id']).'/attendanceRecords'))->json('value');
            foreach ($records as $rec) {
                $email = strtolower((string) ($rec['emailAddress'] ?? ''));
                $key = $email ?: strtolower((string) ($rec['identity']['displayName'] ?? uniqid()));
                $people[$key] ??= ['email' => $email ?: null, 'name' => $rec['identity']['displayName'] ?? null, 'role' => $rec['role'] ?? null, 'total_seconds' => 0, 'intervals' => []];
                // A person who leaves and comes back appears with several intervals, and more than one report can exist for a meeting.
                foreach ((array) ($rec['attendanceIntervals'] ?? []) as $iv) {
                    $people[$key]['intervals'][] = ['join' => (string) ($iv['joinDateTime'] ?? ''), 'leave' => (string) ($iv['leaveDateTime'] ?? ''), 'seconds' => (int) ($iv['durationInSeconds'] ?? 0)];
                }
                $people[$key]['total_seconds'] += (int) ($rec['totalAttendanceInSeconds'] ?? 0);
            }
        }

        return array_values($people);
    }

    public function createTeam(string $name, string $description, string $ownerUpn): array
    {
        $r = $this->check($this->http()->post(self::BASE.'/teams', [
            '@odata.type' => '#microsoft.graph.team', 'template@odata.bind' => "https://graph.microsoft.com/v1.0/teamsTemplates('standard')", 'displayName' => mb_substr($name, 0, 120), 'description' => mb_substr($description, 0, 1000),
            'members' => [['@odata.type' => '#microsoft.graph.aadUserConversationMember', 'roles' => ['owner'], 'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('{$ownerUpn}')"]],
        ]));
        // Creation is asynchronous: the new team's id is in the Location header: /teams('<id>')/operations('<op>').
        preg_match("#teams\\('([^']+)'\\)#", (string) $r->header('Location'), $m);
        $teamId = $m[1] ?? (string) $r->json('id');
        if ($teamId === '') {
            throw new RuntimeException('Graph did not return the new team.');
        }
        $channel = $this->http()->get(self::BASE."/teams/{$teamId}/primaryChannel");
        $folder = $channel->successful() ? $this->http()->get(self::BASE."/teams/{$teamId}/channels/".$channel->json('id').'/filesFolder') : null;

        return ['id' => $teamId, 'channel_id' => $channel->json('id'), 'web_url' => $channel->json('webUrl'), 'drive_id' => $folder?->json('parentReference.driveId'), 'folder_item_id' => $folder?->json('id')];
    }

    public function members(string $teamId): array
    {
        $out = [];
        foreach ((array) $this->check($this->http()->get(self::BASE."/teams/{$teamId}/members"))->json('value') as $m) {
            $out[(string) $m['id']] = strtolower((string) ($m['email'] ?? ''));
        }

        return $out;
    }

    public function addMember(string $teamId, string $email, bool $owner = false): void
    {
        $this->check($this->http()->post(self::BASE."/teams/{$teamId}/members", ['@odata.type' => '#microsoft.graph.aadUserConversationMember', 'roles' => $owner ? ['owner'] : [], 'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('{$email}')"]));
    }

    public function removeMember(string $teamId, string $membershipId): void
    {
        $this->check($this->http()->delete(self::BASE."/teams/{$teamId}/members/".rawurlencode($membershipId)));
    }

    public function uploadFile(string $driveId, string $folderItemId, string $name, string $content, string $mime): ?string
    {
        $r = $this->check(Http::withOptions(['verify' => Supabase::caBundle()])->timeout(60)->withToken($this->token())->withBody($content, $mime)->put(self::BASE."/drives/{$driveId}/items/{$folderItemId}:/".rawurlencode($name).':/content'));

        return $r->json('webUrl');
    }

    public function ping(): bool
    {
        return $this->token() !== '';
    }

    /** @return array{dateTime: string, timeZone: string} */
    private function when(Carbon $t): array
    {
        return ['dateTime' => $t->copy()->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
    }

    /** @param  list<string>  $ids  @return list<array<string, string>> */
    private function invited(array $ids): array
    {
        return array_values(array_map(fn ($id) => ['@odata.type' => '#microsoft.graph.communicationsUserIdentity', 'id' => $id, 'tenantId' => (string) ($this->s['tenant_id'] ?? '')], array_unique($ids)));
    }

    public function createTownhall(string $subject, string $description, Carbon $start, Carbon $end, array $attendeeIds, bool $inviteOnly): string
    {
        $r = $this->check($this->asOrganizer()->post(self::TOWNHALLS, [
            'displayName' => mb_substr($subject, 0, 250), 'description' => ['contentType' => 'text', 'content' => mb_substr($description, 0, 2000)],
            'startDateTime' => $this->when($start), 'endDateTime' => $this->when($end), 'audience' => 'organization', 'isInviteOnly' => $inviteOnly,
            'invitedAttendees' => $this->invited($attendeeIds), 'settings' => ['isAttendeeEmailNotificationEnabled' => false],
        ]));
        $id = (string) $r->json('id');
        if ($id === '') {
            throw new RuntimeException('Graph did not return the new town hall.');
        }

        return $id;
    }

    public function publishTownhall(string $townhallId): void
    {
        $this->check($this->asOrganizer()->post(self::TOWNHALLS.'/'.rawurlencode($townhallId).'/publish'));
    }

    public function townhallSessions(string $townhallId): array
    {
        return array_values(array_map(fn ($x) => ['id' => (string) $x['id'], 'join_url' => $x['joinWebUrl'] ?? null],
            (array) $this->check($this->asOrganizer()->get(self::TOWNHALLS.'/'.rawurlencode($townhallId).'/sessions'))->json('value')));
    }

    public function updateTownhall(string $townhallId, string $subject, Carbon $start, Carbon $end, ?array $attendeeIds = null): void
    {
        $this->check($this->asOrganizer()->patch(self::TOWNHALLS.'/'.rawurlencode($townhallId), array_filter([
            'displayName' => mb_substr($subject, 0, 250), 'startDateTime' => $this->when($start), 'endDateTime' => $this->when($end),
            'invitedAttendees' => $attendeeIds === null ? null : $this->invited($attendeeIds),
        ], fn ($v) => $v !== null)));
    }

    public function cancelTownhall(string $townhallId): void
    {
        $r = $this->asOrganizer()->post(self::TOWNHALLS.'/'.rawurlencode($townhallId).'/cancel');
        if (! $r->successful() && $r->status() !== 404) {
            $this->check($r);
        }
    }

    public function townhallAttendance(string $townhallId, ?string $sessionId = null): array
    {
        $sessions = $sessionId ? [$sessionId] : array_column($this->townhallSessions($townhallId), 'id');
        $people = [];
        foreach ($sessions as $sid) {
            $base = self::TOWNHALLS.'/'.rawurlencode($townhallId).'/sessions/'.rawurlencode($sid).'/attendanceReports';
            foreach ((array) $this->check($this->asOrganizer()->get($base))->json('value') as $report) {
                foreach ((array) $this->check($this->asOrganizer()->get($base.'/'.rawurlencode((string) $report['id']).'/attendanceRecords'))->json('value') as $rec) {
                    $email = strtolower((string) ($rec['emailAddress'] ?? ''));
                    $key = $email ?: strtolower((string) ($rec['identity']['id'] ?? $rec['identity']['displayName'] ?? uniqid()));
                    $people[$key] ??= ['email' => $email ?: null, 'name' => $rec['identity']['displayName'] ?? null, 'role' => $rec['role'] ?? null, 'total_seconds' => 0, 'intervals' => []];
                    foreach ((array) ($rec['attendanceIntervals'] ?? []) as $iv) {
                        $people[$key]['intervals'][] = ['join' => (string) ($iv['joinDateTime'] ?? ''), 'leave' => (string) ($iv['leaveDateTime'] ?? ''), 'seconds' => (int) ($iv['durationInSeconds'] ?? 0)];
                    }
                    $people[$key]['total_seconds'] += (int) ($rec['totalAttendanceInSeconds'] ?? 0);
                }
            }
        }

        return array_values($people);
    }

    public function userId(string $upn): ?string
    {
        $r = $this->http()->get(self::BASE.'/users/'.rawurlencode($upn), ['$select' => 'id']);
        if ($r->status() === 404) {
            return null;
        }

        return $this->check($r)->json('id') ?: null;
    }

    public function sendActivity(string $userId, array $payload): void
    {
        $this->check($this->http()->post(self::BASE.'/users/'.rawurlencode($userId).'/teamwork/sendActivityNotification', $payload));
    }

    public function granted(): array
    {
        $delegated = null;
        if (filled($this->s['organizer_refresh_token'] ?? null)) {
            try {
                $delegated = preg_split('/\s+/', trim((string) (self::claims($this->delegatedToken())['scp'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            } catch (\Throwable) {
                $delegated = [];
            }
        }

        return ['application' => array_values((array) (self::claims($this->token())['roles'] ?? [])), 'delegated' => $delegated];
    }
}
