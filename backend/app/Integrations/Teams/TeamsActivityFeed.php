<?php

namespace App\Integrations\Teams;

use App\Integrations\IntegrationManager;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Notifications\DeliveryPolicy;
use App\Services\ThemeService;
use Illuminate\Support\Str;
use Throwable;

/**
 * TEDC notifications in the Teams activity feed (TEC-12): the "teams" notification channel. Each notification becomes a
 * Graph sendActivityNotification (application permission TeamsActivity.Send) for a person with a known Microsoft identity,
 * with a deep link that opens TEDC's Teams tab (or, if the app is not installed for them, TEDC in the browser). The
 * activity types it uses are declared in the Teams app manifest the Ministry's Teams administrator uploads.
 */
class TeamsActivityFeed
{
    public const MANIFEST_VERSION = '1.29';

    /** Bump when the manifest changes: Teams only accepts an upgrade with a higher version. */
    public const APP_VERSION = '1.0.0';

    /** The personal tab's entity id (the deep link names it). */
    public const ENTITY = 'tedc';

    /**
     * Notification group => the activity type declared in the manifest, with its wording in both languages (shown in the
     * person's Teams notification settings). The text of each notification is the {title} parameter.
     */
    public const TYPES = [
        'session' => 'sessionUpdate', 'attendance' => 'attendanceUpdate', 'certificate' => 'certificateUpdate', 'registration' => 'registrationUpdate',
        'course' => 'courseUpdate', 'survey' => 'surveyRequest', 'task' => 'taskUpdate', 'program' => 'programUpdate', 'account' => 'accountNotice',
        'announcements' => 'announcement', 'system' => 'tedcNotice',
    ];

    private const WORDING = [
        'sessionUpdate' => ['Session reminders and changes', 'تذكيرات الجلسات وتغييراتها'],
        'attendanceUpdate' => ['Attendance', 'الحضور'],
        'certificateUpdate' => ['Certificates', 'الشهادات'],
        'registrationUpdate' => ['Registration decisions', 'قرارات التسجيل'],
        'courseUpdate' => ['E-courses', 'الدورات الإلكترونية'],
        'surveyRequest' => ['Surveys and evaluations', 'الاستبيانات والتقييمات'],
        'taskUpdate' => ['Tasks', 'المهام'],
        'programUpdate' => ['Programme news', 'أخبار البرامج'],
        'accountNotice' => ['Your account', 'حسابك'],
        'announcement' => ['Announcements', 'الإعلانات'],
        'tedcNotice' => ['Other notices', 'إشعارات أخرى'],
    ];

    public function __construct(private readonly IntegrationManager $hub, private readonly TeamsService $teams, private readonly DeliveryPolicy $policy, private readonly ThemeService $theme) {}

    /** The "teams" channel is on: Teams is set up, the feed is switched on and the app id is known. */
    public function enabled(): bool
    {
        $i = $this->hub->get('teams');
        $s = $this->hub->settings('teams');
        if (! $i->enabled || empty($s['activity_feed']) || blank($s['teams_app_id'] ?? null)) {
            return false;
        }

        return $i->driver === 'fake' || (filled($s['tenant_id'] ?? null) && filled($s['client_id'] ?? null) && filled($s['client_secret'] ?? null));
    }

    /** The app id the deep links use; created (and kept) on the first use by the package. */
    public function appId(bool $create = false): ?string
    {
        $id = (string) ($this->hub->settings('teams')['teams_app_id'] ?? '');
        if ($id === '' && $create) {
            $id = (string) Str::uuid();
            $this->hub->update('teams', ['settings' => ['teams_app_id' => $id]]);
        }

        return $id ?: null;
    }

    public function activityType(string $notificationType): string
    {
        return self::TYPES[$this->policy->groupOf($notificationType)] ?? self::TYPES['system'];
    }

    /** A path inside TEDC's web app and nothing else (an absolute or protocol-relative address is never followed). */
    public static function safePath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || strlen($path) > 300 || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return null;
        }

        return $path;
    }

    /** Opens TEDC's Teams tab on the page; where the app is not installed, Teams opens the web address instead. */
    public function deepLink(?string $webPath): string
    {
        $path = self::safePath($webPath) ?? '/portal/notifications';

        return 'https://teams.microsoft.com/l/entity/'.$this->appId().'/'.self::ENTITY.'?'.http_build_query([
            'webUrl' => rtrim((string) config('tedc.web_url'), '/').$path, 'label' => 'TEDC', 'context' => json_encode(['subEntityId' => $path], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Sends one notification to the person's feed. Their Microsoft identity must already be known (their sign-in with
     * Entra ID or SAML): no tenant lookup is made for notifications. A refusal by Teams (the app is not installed for
     * them, say) is that notification's failure and does not count against the Teams integration's health.
     *
     * @return array{status: string, reason: ?string, to: ?string}
     */
    public function deliver(User $user, AppNotification $n, string $locale): array
    {
        $oid = $this->teams->objectId($user, lookup: false);
        if (! $oid) {
            return ['status' => 'skipped', 'reason' => 'no_teams_account', 'to' => null];
        }
        $to = Str::substr($oid, 0, 8).'•••';
        $en = $locale === 'en';
        $title = Str::limit((string) ($en ? ($n->title_en ?: $n->title_ar) : ($n->title_ar ?: $n->title_en)), 200, '…');
        $body = (string) ($en ? ($n->body_en ?: $n->body_ar) : ($n->body_ar ?: $n->body_en));
        $link = is_array($n->data) ? ($n->data['link'] ?? null) : null;
        $payload = [
            'topic' => ['source' => 'text', 'value' => $title, 'webUrl' => $this->deepLink(is_string($link) ? $link : null)],
            'activityType' => $this->activityType((string) $n->type),
            'previewText' => ['content' => Str::limit($body !== '' ? $body : $title, 150, '…')],
            'templateParameters' => [['name' => 'title', 'value' => $title]],
        ];
        $refused = null;
        try {
            $this->hub->call('teams', 'activity_feed', function () use ($oid, $payload, &$refused) {
                try {
                    $this->teams->graph()->sendActivity($oid, $payload);

                    return ['sent' => true];
                } catch (GraphRejected $e) {
                    $refused = $e;

                    return ['refused' => $e->status];
                }
            }, ['type' => $payload['activityType']], 0);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200), 'to' => $to];
        }

        return $refused ? ['status' => 'failed', 'reason' => mb_substr($refused->getMessage(), 0, 200), 'to' => $to] : ['status' => 'sent', 'reason' => null, 'to' => $to];
    }

    // ---- the Teams app (manifest) ----------------------------------------------------------------------

    /** @return list<array{type: string, description: string, templateText: string}> */
    public function activityTypes(string $lang = 'en'): array
    {
        $i = $lang === 'ar' ? 1 : 0;

        return array_values(array_map(fn (string $type) => ['type' => $type, 'description' => self::WORDING[$type][$i], 'templateText' => '{title}'], array_unique(array_values(self::TYPES))));
    }

    /** @return array<string, mixed> the app manifest (Teams schema 1.29) */
    public function manifest(bool $createAppId = false): array
    {
        $s = $this->hub->settings('teams');
        $web = rtrim((string) config('tedc.web_url'), '/');
        $center = $this->theme->centerName();
        $color = (string) ($this->theme->get()['colors']['primary'] ?? '');
        $hosts = array_values(array_unique(array_filter([parse_url($web, PHP_URL_HOST), parse_url(url('/'), PHP_URL_HOST)])));
        $clientId = (string) ($s['client_id'] ?? '');

        $manifest = [
            '$schema' => 'https://developer.microsoft.com/json-schemas/teams/v'.self::MANIFEST_VERSION.'/MicrosoftTeams.schema.json',
            'manifestVersion' => self::MANIFEST_VERSION,
            'version' => self::APP_VERSION,
            'id' => $this->appId($createAppId) ?? '00000000-0000-0000-0000-000000000000',
            'developer' => [
                'name' => mb_substr($center['en'], 0, 32), 'websiteUrl' => $web, 'privacyUrl' => ($s['privacy_url'] ?? '') ?: $web.'/about', 'termsOfUseUrl' => ($s['terms_url'] ?? '') ?: $web.'/about',
            ],
            'name' => ['short' => 'TEDC', 'full' => mb_substr('TEDC — '.$center['en'], 0, 100)],
            'description' => [
                'short' => 'Your training sessions, courses and certificates, with notifications in Teams.',
                'full' => 'TEDC is the training and development portal. In Teams you get your notifications — session reminders and changes, registration decisions, certificates, tasks and surveys — in the activity feed, and a tab that opens the portal.',
            ],
            'icons' => ['outline' => 'outline.png', 'color' => 'color.png'],
            'accentColor' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#8A1538',
            'localizationInfo' => ['defaultLanguageTag' => 'en', 'additionalLanguages' => [['languageTag' => 'ar', 'file' => 'ar.json']]],
            'staticTabs' => [[
                'entityId' => self::ENTITY, 'name' => 'TEDC', 'contentUrl' => url('/api/v1/teams/tab').'?to={subEntityId}&locale={locale}', 'websiteUrl' => $web, 'scopes' => ['personal'],
            ]],
            'validDomains' => $hosts,
            'webApplicationInfo' => ['id' => $clientId, 'resource' => 'api://'.($hosts[0] ?? 'tedc').'/'.$clientId],
            'activities' => ['activityTypes' => $this->activityTypes('en')],
        ];
        if ($clientId === '') {
            unset($manifest['webApplicationInfo']);   // the app registration is not set up yet
        }

        return $manifest;
    }

    /** @return array<string, string> the Arabic strings of the manifest (Teams localization file) */
    public function localization(string $lang = 'ar'): array
    {
        $center = $this->theme->centerName();
        $out = [
            '$schema' => 'https://developer.microsoft.com/json-schemas/teams/v'.self::MANIFEST_VERSION.'/MicrosoftTeams.Localization.schema.json',
            'name.short' => 'TEDC', 'name.full' => mb_substr('TEDC — '.$center['ar'], 0, 100),
            'description.short' => 'جلساتك التدريبية ودوراتك وشهاداتك، مع الإشعارات داخل Teams.',
            'description.full' => 'TEDC هي بوابة التدريب والتطوير. في Teams تصلك إشعاراتك — تذكيرات الجلسات وتغييراتها، وقرارات التسجيل، والشهادات، والمهام، والاستبيانات — في موجز النشاط، مع علامة تبويب تفتح البوابة.',
            'staticTabs[0].name' => 'TEDC',
        ];
        foreach ($this->activityTypes($lang) as $i => $t) {
            $out["activities.activityTypes[{$i}].description"] = $t['description'];
            $out["activities.activityTypes[{$i}].templateText"] = $t['templateText'];
        }

        return $out;
    }
}
