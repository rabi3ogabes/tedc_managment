<?php

namespace App\Integrations\Teams;

use App\Support\Supabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Microsoft Graph with application permissions (client credentials). The token is cached until just before it expires. */
class HttpGraph implements GraphApi
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    /** @param  array<string, mixed>  $s  the Teams integration settings */
    public function __construct(private readonly array $s) {}

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
            throw new RuntimeException('Graph: '.($r->json('error.message') ?: 'HTTP '.$r->status()));
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
}
