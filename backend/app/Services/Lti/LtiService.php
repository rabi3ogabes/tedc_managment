<?php

namespace App\Services\Lti;

use App\Exceptions\BusinessRuleException;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LtiScore;
use App\Models\LtiState;
use App\Models\LtiTool;
use App\Models\Registration;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\CourseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TEDC as an LTI platform: LTI 1.3 (OIDC third-party login, signed id_token, JWKS, Deep Linking 2.0, AGS score passback, NRPS)
 * and LTI 1.1 (OAuth 1.0a signed launch, Basic Outcomes). Nonce and state are single use and expire after ten minutes.
 */
class LtiService
{
    public const CLAIM = 'https://purl.imsglobal.org/spec/lti/claim/';

    public const AGS_SCOPES = ['https://purl.imsglobal.org/spec/lti-ags/scope/lineitem', 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly', 'https://purl.imsglobal.org/spec/lti-ags/scope/score', 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly'];

    public const NRPS_SCOPE = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';

    public function __construct(private readonly CourseService $course) {}

    // ───────────────────────────── platform keys

    /** @return array{kid: string, private: string, public: string} */
    public function keys(): array
    {
        $row = SiteSetting::find('lti_keys');
        if (! $row) {
            $row = $this->rotate();
        }
        $v = $row->value;

        return ['kid' => $v['kid'], 'private' => Crypt::decryptString($v['private']), 'public' => $v['public']];
    }

    public function rotate(): SiteSetting
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'];

        return SiteSetting::updateOrCreate(['key' => 'lti_keys'], ['value' => ['kid' => 'k'.now()->format('YmdHis').Str::lower(Str::random(4)), 'private' => Crypt::encryptString($priv), 'public' => $pub]]);
    }

    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        $k = $this->keys();

        return ['keys' => [Jwt::jwk($k['public'], $k['kid'])]];
    }

    public function issuer(): string
    {
        return rtrim((string) (config('tedc.web_url') ?: config('app.url')), '/');
    }

    // ───────────────────────────── launching a tool (1.3)

    /** Step 1: the platform sends the browser to the tool's OIDC login URL. @return array{method: string, action: string, fields: array<string, string>} */
    public function loginInitiation(LtiTool $tool, User $user, ?CourseLesson $lesson, string $purpose = 'launch', array $context = []): array
    {
        abort_unless($tool->is_active && $tool->version === '1.3', 422);
        $state = LtiState::create(['state' => Str::random(40), 'nonce' => Str::random(32), 'tool_id' => $tool->id, 'user_id' => $user->id, 'lesson_id' => $lesson?->id, 'purpose' => $purpose, 'context' => $context ?: null, 'expires_at' => now()->addMinutes(10)]);

        return ['method' => 'POST', 'action' => (string) $tool->login_url, 'fields' => array_filter([
            'iss' => $this->issuer(), 'login_hint' => $state->id, 'target_link_uri' => $purpose === 'deep_link' ? ($tool->deep_link_url ?: $tool->launch_url) : ($lesson?->settings['lti_launch_url'] ?? $tool->launch_url),
            'client_id' => $tool->client_id, 'lti_deployment_id' => $tool->deployment_id, 'lti_message_hint' => $state->id,
        ])];
    }

    /**
     * Step 2: the tool calls back with the authentication request; the platform answers with the signed id_token (auto-posted form).
     *
     * @return array{redirect_uri: string, id_token: string, state: string}
     */
    public function authenticate(array $q): array
    {
        $s = LtiState::with('tool')->find($q['login_hint'] ?? '');
        $tool = $s?->tool;
        if (! $s || ! $tool || $s->used_at || $s->expires_at->isPast()) {
            throw new RuntimeException('Unknown or expired login.');
        }
        if (($q['client_id'] ?? null) !== $tool->client_id || ($q['response_type'] ?? '') !== 'id_token' || ($q['scope'] ?? '') !== 'openid' || ($q['response_mode'] ?? '') !== 'form_post' || ($q['prompt'] ?? '') !== 'none') {
            throw new RuntimeException('Invalid authentication request.');
        }
        $allowed = array_filter([$tool->launch_url, $tool->deep_link_url, $tool->login_url]);
        if (! in_array($q['redirect_uri'] ?? '', $allowed, true) && ! str_starts_with((string) ($q['redirect_uri'] ?? ''), (string) preg_replace('#\?.*$#', '', (string) $tool->launch_url))) {
            throw new RuntimeException('redirect_uri is not registered for this tool.');
        }
        $s->update(['used_at' => now()]);
        $k = $this->keys();
        $claims = $s->purpose === 'deep_link' ? $this->deepLinkClaims($tool, $s, $q) : $this->launchClaims($tool, $s, $q);
        $claims += ['iss' => $this->issuer(), 'aud' => $tool->client_id, 'sub' => (string) $s->user_id, 'iat' => time(), 'exp' => time() + 300, 'nonce' => $q['nonce'] ?? $s->nonce];

        return ['redirect_uri' => $q['redirect_uri'], 'id_token' => Jwt::sign($claims, $k['private'], $k['kid']), 'state' => (string) ($q['state'] ?? '')];
    }

    /** @return array<string, mixed> */
    private function launchClaims(LtiTool $tool, LtiState $s, array $q): array
    {
        $user = User::findOrFail($s->user_id);
        $lesson = CourseLesson::with('program')->findOrFail($s->lesson_id);
        $program = $lesson->program;
        $instructor = $user->hasPermission('programs.manage');
        $c = [
            self::CLAIM.'message_type' => 'LtiResourceLinkRequest', self::CLAIM.'version' => '1.3.0', self::CLAIM.'deployment_id' => $tool->deployment_id, self::CLAIM.'target_link_uri' => $q['redirect_uri'] ?? $tool->launch_url,
            self::CLAIM.'resource_link' => ['id' => $lesson->id, 'title' => $lesson->title_en],
            self::CLAIM.'roles' => [$instructor ? 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor' : 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
            self::CLAIM.'context' => ['id' => $program->id, 'label' => $program->code, 'title' => $program->title_en, 'type' => ['http://purl.imsglobal.org/vocab/lis/v2/course#CourseOffering']],
            self::CLAIM.'tool_platform' => ['guid' => $this->issuer(), 'name' => config('tedc.name', 'TEDC'), 'product_family_code' => 'tedc'],
            self::CLAIM.'launch_presentation' => ['document_target' => 'iframe', 'return_url' => $this->issuer().'/portal/learn'],
            self::CLAIM.'custom' => (object) ($tool->custom ?? []),
        ];
        if ($tool->privacy['share_name'] ?? true) {
            $c['name'] = $user->name;
        }
        if ($tool->privacy['share_email'] ?? false) {
            $c['email'] = $user->email;
        }
        if ($tool->supports_ags) {
            $base = url("/api/v1/lti/ags/{$lesson->id}");
            $c['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'] = ['scope' => self::AGS_SCOPES, 'lineitems' => "{$base}/lineitems", 'lineitem' => "{$base}/lineitems/1"];
            $c['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice'] = ['context_memberships_url' => url("/api/v1/lti/nrps/{$program->id}/memberships"), 'service_versions' => ['2.0']];
        }

        return $c;
    }

    /** @return array<string, mixed> */
    private function deepLinkClaims(LtiTool $tool, LtiState $s, array $q): array
    {
        return [
            self::CLAIM.'message_type' => 'LtiDeepLinkingRequest', self::CLAIM.'version' => '1.3.0', self::CLAIM.'deployment_id' => $tool->deployment_id, self::CLAIM.'target_link_uri' => $q['redirect_uri'] ?? $tool->deep_link_url,
            self::CLAIM.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
            'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings' => ['deep_link_return_url' => url('/api/v1/lti/deep-link/return'), 'accept_types' => ['ltiResourceLink', 'link'], 'accept_presentation_document_targets' => ['iframe', 'window'], 'accept_multiple' => true, 'auto_create' => true, 'data' => $s->id],
        ];
    }

    // ───────────────────────────── deep linking return

    /** The tool returns selected content items in a signed JWT; each becomes a lesson in the chosen module. @return list<CourseLesson> */
    public function deepLinkReturn(string $jwt): array
    {
        $claims = Jwt::parse($jwt)['claims'];
        $s = LtiState::with('tool')->find($claims['https://purl.imsglobal.org/spec/lti-dl/claim/data'] ?? null);
        if (! $s || $s->purpose !== 'deep_link' || $s->tool->version !== '1.3' || $s->expires_at->addHour()->isPast()) {
            throw new RuntimeException('Unknown deep-linking session.');
        }
        $tool = $s->tool;
        $this->verifyFromTool($jwt, $tool);
        if (($claims['iss'] ?? null) !== $tool->client_id || (is_array($claims['aud'] ?? null) ? ! in_array($this->issuer(), $claims['aud'], true) : ($claims['aud'] ?? null) !== $this->issuer())) {
            throw new RuntimeException('Token issuer or audience does not match.');
        }
        if (($claims[self::CLAIM.'message_type'] ?? '') !== 'LtiDeepLinkingResponse') {
            throw new RuntimeException('Not a deep-linking response.');
        }
        $module = CourseModule::findOrFail($s->context['module_id'] ?? null);
        $created = [];
        foreach ($claims['https://purl.imsglobal.org/spec/lti-dl/claim/content_items'] ?? [] as $item) {
            if (! in_array($item['type'] ?? '', ['ltiResourceLink', 'link'], true)) {
                continue;
            }
            $title = Str::limit((string) ($item['title'] ?? $tool->name), 200, '');
            $isLti = $item['type'] === 'ltiResourceLink';
            $created[] = CourseLesson::create(['program_id' => $module->program_id, 'module_id' => $module->id, 'type' => $isLti ? CourseLesson::LTI : CourseLesson::ARTICLE, 'title_ar' => $title, 'title_en' => $title, 'status' => 'draft',
                'sort_order' => (int) CourseLesson::where('module_id', $module->id)->max('sort_order') + 1, 'lti_tool_id' => $isLti ? $tool->id : null, 'settings' => $isLti ? array_filter(['lti_launch_url' => $item['url'] ?? null, 'lti_custom' => $item['custom'] ?? null]) : null,
                'body_ar' => $isLti ? null : '<p><a href="'.e((string) ($item['url'] ?? '')).'" target="_blank" rel="noopener">'.e($title).'</a></p>', 'body_en' => $isLti ? null : '<p><a href="'.e((string) ($item['url'] ?? '')).'" target="_blank" rel="noopener">'.e($title).'</a></p>']);
        }

        return $created;
    }

    /** The tool's public key: pasted, or fetched from its JWKS URL (cached for an hour). */
    private function verifyFromTool(string $jwt, LtiTool $tool): array
    {
        $kid = Jwt::parse($jwt)['header']['kid'] ?? null;

        return Jwt::verify($jwt, $this->toolPublicKey($tool, $kid));
    }

    public function toolPublicKey(LtiTool $tool, ?string $kid): string
    {
        if ($tool->public_key) {
            return $tool->public_key;
        }
        if (! $tool->jwks_url) {
            throw new RuntimeException('The tool has no key configured.');
        }
        $jwks = Cache::remember('lti.jwks.'.$tool->id, 3600, fn () => Http::timeout(8)->get($tool->jwks_url)->throw()->json());
        $key = collect($jwks['keys'] ?? [])->first(fn ($k) => ($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA') ?? collect($jwks['keys'] ?? [])->first(fn ($k) => ($k['kty'] ?? '') === 'RSA');
        if (! $key) {
            throw new RuntimeException('No matching key in the tool JWKS.');
        }

        return Jwt::pemFromJwk($key);
    }

    // ───────────────────────────── services: token, AGS, NRPS

    /** OAuth2 client credentials with a JWT client assertion signed by the tool. @return array{access_token: string, token_type: string, expires_in: int, scope: string} */
    public function token(array $in): array
    {
        if (($in['grant_type'] ?? '') !== 'client_credentials' || ($in['client_assertion_type'] ?? '') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') {
            throw new RuntimeException('unsupported_grant_type');
        }
        $parsed = Jwt::parse((string) ($in['client_assertion'] ?? ''));
        $tool = LtiTool::where('client_id', $parsed['claims']['iss'] ?? '-')->where('version', '1.3')->where('is_active', true)->first();
        if (! $tool) {
            throw new RuntimeException('invalid_client');
        }
        $claims = Jwt::verify($in['client_assertion'], $this->toolPublicKey($tool, $parsed['header']['kid'] ?? null));
        $aud = (array) ($claims['aud'] ?? []);
        if (($claims['sub'] ?? null) !== $tool->client_id || ! array_filter($aud, fn ($a) => str_starts_with((string) $a, $this->issuer()) || str_starts_with((string) $a, rtrim(url('/'), '/')))) {
            throw new RuntimeException('invalid_client');
        }
        if (! Cache::add('lti.jti.'.($claims['jti'] ?? Str::random(8)).$tool->id, 1, 600)) {
            throw new RuntimeException('replayed assertion');
        }
        $allowed = array_merge(self::AGS_SCOPES, [self::NRPS_SCOPE]);
        $scopes = array_values(array_intersect(explode(' ', (string) ($in['scope'] ?? '')), $allowed));
        $token = Str::random(48);
        Cache::put('lti.token.'.hash('sha256', $token), ['tool' => $tool->id, 'scopes' => $scopes], 3600);

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => implode(' ', $scopes)];
    }

    /** @return array{tool: LtiTool, scopes: list<string>} */
    public function bearer(?string $header, string $scope): array
    {
        $t = Cache::get('lti.token.'.hash('sha256', (string) preg_replace('/^Bearer\s+/i', '', (string) $header)));
        if (! $t || ! in_array($scope, $t['scopes'], true) && ! ($scope === self::AGS_SCOPES[1] && in_array(self::AGS_SCOPES[0], $t['scopes'], true))) {
            throw new BusinessRuleException('Invalid or insufficient token.', 'lti_forbidden');
        }

        return ['tool' => LtiTool::findOrFail($t['tool']), 'scopes' => $t['scopes']];
    }

    /** A score from the tool (AGS): a completed, fully graded score completes the lesson. */
    public function acceptScore(CourseLesson $lesson, LtiTool $tool, array $s): LtiScore
    {
        abort_unless($lesson->lti_tool_id === $tool->id, 404);
        $user = User::findOrFail($s['userId'] ?? null);
        $max = (float) ($s['scoreMaximum'] ?? 100);
        $given = isset($s['scoreGiven']) ? (float) $s['scoreGiven'] : null;
        $row = LtiScore::create(['tool_id' => $tool->id, 'lesson_id' => $lesson->id, 'user_id' => $user->id, 'score_given' => $given, 'score_max' => $max, 'activity_progress' => $s['activityProgress'] ?? null, 'grading_progress' => $s['gradingProgress'] ?? null]);
        $r = Registration::where('program_id', $lesson->program_id)->where('employee_id', $user->employee?->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first();
        if ($r) {
            $done = ($s['activityProgress'] ?? '') === 'Completed' && in_array($s['gradingProgress'] ?? 'FullyGraded', ['FullyGraded', 'NotReady'], true);
            $this->course->markFromPackage($lesson, $r, $done, null, $given !== null && $max > 0 ? round($given / $max * 100, 2) : null);
        }

        return $row;
    }

    // ───────────────────────────── LTI 1.1

    /** A signed OAuth 1.0a launch form. @return array{method: string, action: string, fields: array<string, string>} */
    public function launch11(LtiTool $tool, User $user, CourseLesson $lesson): array
    {
        abort_unless($tool->is_active && $tool->version === '1.1' && $tool->consumer_key && $tool->consumer_secret, 422);
        $program = $lesson->program()->first();
        $f = array_filter([
            'lti_message_type' => 'basic-lti-launch-request', 'lti_version' => 'LTI-1p0', 'resource_link_id' => $lesson->id, 'resource_link_title' => $lesson->title_en, 'user_id' => $user->id,
            'roles' => $user->hasPermission('programs.manage') ? 'Instructor' : 'Learner', 'context_id' => $program->id, 'context_title' => $program->title_en, 'context_label' => $program->code,
            'tool_consumer_instance_guid' => $this->issuer(), 'launch_presentation_document_target' => 'iframe', 'lis_outcome_service_url' => url('/api/v1/lti/outcomes'), 'lis_result_sourcedid' => $lesson->id.'|'.$user->id,
            'lis_person_name_full' => ($tool->privacy['share_name'] ?? true) ? $user->name : null, 'lis_person_contact_email_primary' => ($tool->privacy['share_email'] ?? false) ? $user->email : null,
        ]);
        foreach ($tool->custom ?? [] as $k => $v) {
            $f['custom_'.preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $k))] = (string) $v;
        }

        return ['method' => 'POST', 'action' => $tool->launch_url, 'fields' => $this->oauthSign('POST', $tool->launch_url, $f, $tool->consumer_key, $tool->consumer_secret)];
    }

    /** @param  array<string, string>  $params  @return array<string, string> */
    public function oauthSign(string $method, string $url, array $params, string $key, string $secret, ?string $body = null): array
    {
        $params += ['oauth_consumer_key' => $key, 'oauth_signature_method' => 'HMAC-SHA1', 'oauth_timestamp' => (string) time(), 'oauth_nonce' => Str::random(24), 'oauth_version' => '1.0'];
        if ($body !== null) {
            $params['oauth_body_hash'] = base64_encode(sha1($body, true));
        }
        $params['oauth_signature'] = $this->signature($method, $url, $params, $secret);

        return $params;
    }

    /** @param  array<string, string>  $params */
    public function signature(string $method, string $url, array $params, string $secret): string
    {
        unset($params['oauth_signature']);
        $enc = fn (string $s) => str_replace('%7E', '~', rawurlencode($s));
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = [$enc((string) $k), $enc((string) $v)];
        }
        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $norm = implode('&', array_map(fn ($p) => $p[0].'='.$p[1], $pairs));
        $base = strtoupper($method).'&'.$enc(strtok($url, '?')).'&'.$enc($norm);

        return base64_encode(hash_hmac('sha1', $base, $enc($secret).'&', true));
    }

    /** Verifies a signed request from a tool (Basic Outcomes). The nonce is single use and the timestamp must be recent. */
    public function verify11(string $method, string $url, array $params, ?string $authHeader, ?string $body = null): LtiTool
    {
        if ($authHeader && str_starts_with($authHeader, 'OAuth ')) {
            foreach (explode(',', substr($authHeader, 6)) as $kv) {
                [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
                if (str_starts_with($k, 'oauth_')) {
                    $params[$k] = rawurldecode(trim($v, '"'));
                }
            }
        }
        if ($body !== null && ! hash_equals(base64_encode(sha1($body, true)), (string) ($params['oauth_body_hash'] ?? ''))) {
            throw new RuntimeException('Body hash mismatch.');
        }
        $tool = LtiTool::where('consumer_key', $params['oauth_consumer_key'] ?? '-')->where('version', '1.1')->where('is_active', true)->first();
        if (! $tool || abs(time() - (int) ($params['oauth_timestamp'] ?? 0)) > 300 || ! hash_equals($this->signature($method, $url, $params, (string) $tool->consumer_secret), (string) ($params['oauth_signature'] ?? ''))) {
            throw new RuntimeException('Invalid OAuth signature.');
        }
        if (! Cache::add('lti11.nonce.'.($params['oauth_nonce'] ?? '').$tool->id, 1, 900)) {
            throw new RuntimeException('Replayed nonce.');
        }

        return $tool;
    }

    /** The post-processing for Basic Outcomes replaceResult: sourcedid "lessonId|userId", score 0..1. */
    public function outcome(LtiTool $tool, string $sourcedid, float $score): void
    {
        [$lessonId, $userId] = array_pad(explode('|', $sourcedid, 2), 2, '');
        $lesson = CourseLesson::findOrFail($lessonId);
        $this->acceptScore($lesson, $tool, ['userId' => $userId, 'scoreGiven' => $score * 100, 'scoreMaximum' => 100, 'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded']);
    }
}
