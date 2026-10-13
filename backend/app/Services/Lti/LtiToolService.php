<?php

namespace App\Services\Lti;

use App\Auth\AuthService;
use App\Models\AuditLog;
use App\Models\CourseLesson;
use App\Models\Employee;
use App\Models\LessonProgress;
use App\Models\LtiPlatform;
use App\Models\LtiState;
use App\Models\LtiToolLaunch;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SsoState;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * TEDC as an LTI tool (TEC-08): other LMSs launch TEDC programmes and lessons. LTI 1.3 — OIDC third-party login, a
 * signed id_token checked against the platform's keys (issuer, audience, nonce, time, deployment), Deep Linking 2.0 to
 * choose content, AGS to send grades back and NRPS to read the roster — and LTI 1.1 (OAuth 1.0a launch, Basic Outcomes).
 *
 * Accounts: a launch maps to a TEDC account through the platform's own id for the person. An account whose e-mail
 * matches is never taken over: it is linked straight away only when the platform is trusted for that e-mail domain,
 * otherwise only after the person signs in to TEDC and confirms. Accounts that hold any role beyond learner or trainer
 * are never signed in by a launch. Everyone else is created on first launch, as a learner (or the role the platform's
 * settings give instructors), and enrolled in the programme the platform may reach.
 */
class LtiToolService
{
    private const C = LtiService::CLAIM;

    private const AGS = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';

    private const NRPS = 'https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice';

    private const DL = 'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings';

    public const SCORE_SCOPE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

    /** Roles a launch may give an account it creates, and the only roles of accounts it may sign in. */
    public const LAUNCH_ROLES = [Role::EMPLOYEE, Role::TRAINER];

    public function __construct(private readonly LtiService $lti, private readonly AuthService $auth) {}

    /** @return array{login_url: string, launch_url: string, deep_link_url: string, launch11_url: string, jwks_url: string, redirect_uris: list<string>} */
    public function urls(): array
    {
        $launch = url('/api/v1/lti/tool/launch');

        return ['login_url' => url('/api/v1/lti/tool/login'), 'launch_url' => $launch, 'deep_link_url' => $launch, 'launch11_url' => url('/api/v1/lti/tool/launch11'), 'jwks_url' => url('/api/v1/lti/tool/jwks'), 'redirect_uris' => [$launch]];
    }

    // ───────────────────────────── LTI 1.3: login and launch

    /** Step 1 (OIDC third-party initiated login): the browser goes on to the platform's authorisation endpoint. */
    public function login(array $q): string
    {
        $platforms = LtiPlatform::where('issuer', (string) ($q['iss'] ?? ''))->where('version', '1.3')->where('status', 'active')
            ->when(filled($q['client_id'] ?? null), fn ($w) => $w->where('client_id', (string) $q['client_id']))->get();
        if ($platforms->count() !== 1) {
            throw new LtiToolException('unknown_platform');
        }
        $p = $platforms->first();
        if (filled($q['lti_deployment_id'] ?? null) && ! in_array((string) $q['lti_deployment_id'], (array) $p->deployment_ids, true)) {
            throw new LtiToolException('unknown_deployment');
        }
        $target = (string) ($q['target_link_uri'] ?? '');
        if (! str_starts_with($target, rtrim(url('/'), '/').'/')) {
            throw new LtiToolException('wrong_target');
        }
        $state = LtiState::create(['state' => Str::random(48), 'nonce' => Str::random(40), 'platform_id' => $p->id, 'purpose' => 'tool_login', 'context' => ['target_link_uri' => $target], 'expires_at' => now()->addMinutes(10)]);

        return $p->auth_login_url.(str_contains((string) $p->auth_login_url, '?') ? '&' : '?').http_build_query(array_filter([
            'scope' => 'openid', 'response_type' => 'id_token', 'client_id' => $p->client_id, 'redirect_uri' => $this->urls()['launch_url'], 'login_hint' => (string) ($q['login_hint'] ?? ''),
            'state' => $state->state, 'response_mode' => 'form_post', 'nonce' => $state->nonce, 'prompt' => 'none', 'lti_message_hint' => $q['lti_message_hint'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /** Step 2: the platform posts the signed id_token; every claim that matters is checked before anything happens. */
    public function launch(string $idToken, string $stateValue): LtiToolLaunch
    {
        $state = LtiState::where('state', $stateValue)->where('purpose', 'tool_login')->first();
        if (! $state || $state->used_at || $state->expires_at->isPast()) {
            throw new LtiToolException('expired');
        }
        $state->update(['used_at' => now()]);   // single use, whatever happens next
        $p = LtiPlatform::find($state->platform_id);
        if (! $p || ! $p->active()) {
            throw new LtiToolException('unknown_platform');
        }
        try {
            $claims = Jwt::verify($idToken, $this->platformKey($p, Jwt::parse($idToken)['header']['kid'] ?? null));
        } catch (Throwable) {
            throw new LtiToolException('not_verified');
        }
        $aud = (array) ($claims['aud'] ?? []);
        $iat = (int) ($claims['iat'] ?? 0);
        $problem = match (true) {
            ($claims['iss'] ?? null) !== $p->issuer => 'issuer',
            ! in_array($p->client_id, $aud, true) || (count($aud) > 1 && ($claims['azp'] ?? null) !== $p->client_id) => 'audience',
            ! hash_equals($state->nonce, (string) ($claims['nonce'] ?? '')) => 'nonce',
            $iat > time() + 60 || $iat < time() - 600 => 'time',
            ! in_array((string) ($claims[self::C.'deployment_id'] ?? ''), (array) $p->deployment_ids, true) => 'deployment',
            ($claims[self::C.'version'] ?? '') !== '1.3.0' => 'version',
            ! in_array($claims[self::C.'message_type'] ?? '', ['LtiResourceLinkRequest', 'LtiDeepLinkingRequest'], true) => 'message type',
            blank($claims['sub'] ?? null) => 'person',
            default => null,
        };
        if ($problem !== null) {
            throw new LtiToolException('not_verified', $problem);
        }
        $roles = array_map('strval', (array) ($claims[self::C.'roles'] ?? []));
        $base = ['platform_id' => $p->id, 'sub' => (string) $claims['sub'], 'context_id' => $claims[self::C.'context']['id'] ?? null,
            'claims' => array_filter(['name' => $claims['name'] ?? trim(($claims['given_name'] ?? '').' '.($claims['family_name'] ?? '')), 'email' => $claims['email'] ?? null, 'roles' => $roles,
                'locale' => $claims[self::C.'launch_presentation']['locale'] ?? null, 'return_url' => $claims[self::C.'launch_presentation']['return_url'] ?? null])];

        if ($claims[self::C.'message_type'] === 'LtiDeepLinkingRequest') {
            $s = (array) ($claims[self::DL] ?? []);
            if (! $this->instructor($roles) || blank($s['deep_link_return_url'] ?? null)) {
                throw new LtiToolException('instructors_only');
            }

            return LtiToolLaunch::create($base + ['message_type' => 'LtiDeepLinkingRequest', 'deep_link' => ['return_url' => (string) $s['deep_link_return_url'], 'data' => $s['data'] ?? null,
                'accept_multiple' => (bool) ($s['accept_multiple'] ?? true), 'deployment_id' => (string) $claims[self::C.'deployment_id']]]);
        }

        $target = $this->target((string) ($claims[self::C.'custom']['tedc_target'] ?? $this->queryTarget((string) ($claims[self::C.'target_link_uri'] ?? ''))), $p);
        $ags = (array) ($claims[self::AGS] ?? []);
        $launch = LtiToolLaunch::create($base + ['message_type' => 'LtiResourceLinkRequest', 'resource_link_id' => $claims[self::C.'resource_link']['id'] ?? null,
            'ags_lineitem' => $ags['lineitem'] ?? null, 'ags_lineitems' => $ags['lineitems'] ?? null, 'ags_scopes' => $ags['scope'] ?? null, 'nrps_url' => $claims[self::NRPS]['context_memberships_url'] ?? null]);
        $this->resolve($launch, $p, $target, $this->instructor($roles));

        return $launch;
    }

    // ───────────────────────────── LTI 1.1

    /** An OAuth 1.0a signed launch from an older LMS (the raw form fields, as signed). */
    public function launch11(array $params, string $url): LtiToolLaunch
    {
        $p = LtiPlatform::where('consumer_key', (string) ($params['oauth_consumer_key'] ?? '-'))->where('version', '1.1')->where('status', 'active')->first();
        if (! $p || abs(time() - (int) ($params['oauth_timestamp'] ?? 0)) > 300 || ! hash_equals($this->lti->signature('POST', $url, $params, (string) $p->consumer_secret), (string) ($params['oauth_signature'] ?? ''))) {
            throw new LtiToolException('not_verified');
        }
        if (! Cache::add('lti11.tool.nonce.'.$p->id.'.'.($params['oauth_nonce'] ?? ''), 1, 900)) {
            throw new LtiToolException('replayed');
        }
        if (($params['lti_message_type'] ?? '') !== 'basic-lti-launch-request' || blank($params['user_id'] ?? null)) {
            throw new LtiToolException('not_verified', 'message');
        }
        $roles = array_map('trim', explode(',', (string) ($params['roles'] ?? '')));
        $target = $this->target((string) ($params['custom_tedc_target'] ?? ''), $p);
        $launch = LtiToolLaunch::create(['platform_id' => $p->id, 'sub' => (string) $params['user_id'], 'message_type' => 'basic-lti-launch-request', 'context_id' => $params['context_id'] ?? null,
            'resource_link_id' => $params['resource_link_id'] ?? null, 'outcome_url' => $params['lis_outcome_service_url'] ?? null, 'outcome_sourcedid' => $params['lis_result_sourcedid'] ?? null,
            'claims' => array_filter(['name' => $params['lis_person_name_full'] ?? null, 'email' => $params['lis_person_contact_email_primary'] ?? null, 'roles' => $roles, 'locale' => $params['launch_presentation_locale'] ?? null])]);
        $this->resolve($launch, $p, $target, $this->instructor($roles));

        return $launch;
    }

    // ───────────────────────────── accounts, targets, enrolment

    /** Who the launch is, and what it opens. A launch for an existing account the platform may not claim waits with a ticket. */
    private function resolve(LtiToolLaunch $launch, LtiPlatform $p, ?array $target, bool $instructor): void
    {
        $launch->fill(['target_type' => $target[0] ?? null, 'target_id' => $target[1]->id ?? null]);
        $user = $this->account($p, $launch, $instructor);
        if ($user === null) {
            $launch->fill(['ticket' => Str::random(48)])->save();

            return;
        }
        $launch->fill(['user_id' => $user->id])->save();
        if ($this->mayStartSession($user)) {
            $this->enrol($launch, $user);   // staff accounts are not enrolled by a launch: they sign in themselves
        }
    }

    private function account(LtiPlatform $p, LtiToolLaunch $launch, bool $instructor): ?User
    {
        $subject = $this->subject($p, $launch->sub);
        $identity = UserIdentity::where('provider', 'lti')->where('subject', $subject)->first();
        if ($identity && ($user = User::find($identity->user_id))) {
            $identity->update(['last_login_at' => now()]);

            return $user;
        }
        $email = strtolower(trim((string) ($launch->claims['email'] ?? '')));
        if ($email !== '' && ($existing = User::where('email', $email)->first())) {
            if (! in_array(Str::after($email, '@'), $p->trustedDomains(), true)) {
                return null;   // never taken over: the person signs in to link
            }
            $this->link($existing, $p, $launch->sub, $email);

            return $existing;
        }
        $name = trim((string) ($launch->claims['name'] ?? '')) ?: 'LTI learner';
        $user = DB::transaction(function () use ($p, $email, $name, $subject, $instructor) {
            $user = User::create(['name' => $name, 'name_ar' => $name, 'email' => $email !== '' ? $email : 'lti-'.substr(hash('sha256', $subject), 0, 24).'@users.lti.invalid', 'password' => Str::random(40), 'status' => 'active']);
            $user->roles()->attach(Role::where('slug', $this->roleFor($p, $instructor))->value('id'));
            Employee::create(['user_id' => $user->id, 'employee_no' => 'LTI-'.strtoupper(Str::random(8)), 'status' => 'active']);
            UserIdentity::create(['user_id' => $user->id, 'provider' => 'lti', 'subject' => $subject, 'upn' => $email ?: null, 'last_login_at' => now()]);

            return $user;
        });
        AuditLog::create(['user_id' => null, 'action' => 'lti_account_created', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => ['platform' => $p->name, 'launch' => $launch->id]]);

        return $user;
    }

    /** Links the signed-in person's account to a waiting launch and opens its target. @return array{to: string} */
    public function confirmLink(User $user, string $ticket): array
    {
        $launch = LtiToolLaunch::with('platform')->where('ticket', $ticket)->whereNull('user_id')->where('created_at', '>', now()->subMinutes(30))->first();
        if (! $launch) {
            throw new LtiToolException('link_expired');
        }
        $this->link($user, $launch->platform, $launch->sub, $launch->claims['email'] ?? null);
        $launch->update(['user_id' => $user->id, 'ticket' => null]);
        AuditLog::create(['user_id' => $user->id, 'action' => 'lti_account_linked', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'new_values' => ['platform' => $launch->platform->name, 'launch' => $launch->id]]);

        return ['to' => $this->enrol($launch, $user)];
    }

    private function link(User $user, LtiPlatform $p, string $sub, ?string $email): void
    {
        UserIdentity::updateOrCreate(['provider' => 'lti', 'subject' => $this->subject($p, $sub)], ['user_id' => $user->id, 'upn' => $email, 'last_login_at' => now()]);
    }

    /** Enrols the person in the target's programme (online programmes the platform may reach) and says where to go in TEDC. */
    private function enrol(LtiToolLaunch $launch, User $user): string
    {
        $target = $launch->target_type ? $this->find($launch->target_type, (string) $launch->target_id) : null;
        if (! $target) {
            return '/portal';
        }
        $program = $target instanceof CourseLesson ? $target->program : $target;
        if (! $program->has_course) {
            return '/programs/'.rawurlencode((string) $program->code);
        }
        $employee = $user->employee ?? Employee::create(['user_id' => $user->id, 'employee_no' => 'LTI-'.strtoupper(Str::random(8)), 'status' => 'active']);
        $reg = Registration::where('program_id', $program->id)->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first()
            ?? Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => Registration::SOURCE_LTI, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
        $launch->update(['registration_id' => $reg->id]);

        return "/portal/learn/{$reg->id}".($target instanceof CourseLesson ? "/{$target->id}" : '');
    }

    /** Whether a launch may sign this account in: only learners and trainers, never staff or administrators. */
    public function mayStartSession(User $user): bool
    {
        return $user->status === 'active' && $user->roles()->whereNotIn('slug', self::LAUNCH_ROLES)->doesntExist();
    }

    /** A one-time code the web app exchanges for the session (as with single sign-on), and where it leads. */
    public function handoff(User $user, string $to): string
    {
        $session = $this->auth->issueForUser($user, 'lti');
        $data = $session;
        unset($data['user']);
        $code = Str::random(48);
        SsoState::create(['state' => $code, 'nonce' => $user->id, 'verifier' => '-', 'kind' => 'code', 'session' => ['enc' => Crypt::encryptString(json_encode($data))], 'expires_at' => now()->addSeconds(90)]);

        return rtrim((string) config('tedc.web_url'), '/').'/lti/launch?'.http_build_query(['code' => $code, 'to' => $to]);
    }

    /** Where a launch leads, for a person who must sign in themselves. */
    public function signInUrl(string $to): string
    {
        return rtrim((string) config('tedc.web_url'), '/').'/login?'.http_build_query(['redirect' => $to]);
    }

    public function linkUrl(LtiToolLaunch $launch): string
    {
        return rtrim((string) config('tedc.web_url'), '/').'/lti/link?'.http_build_query(['ticket' => $launch->ticket]);
    }

    /** Where in TEDC a finished launch leads. */
    public function destination(LtiToolLaunch $launch): string
    {
        $target = $launch->target_type ? $this->find($launch->target_type, (string) $launch->target_id) : null;
        if (! $target) {
            return '/portal';
        }
        $program = $target instanceof CourseLesson ? $target->program : $target;
        if (! $launch->registration_id) {
            return '/programs/'.rawurlencode((string) $program->code);
        }

        return "/portal/learn/{$launch->registration_id}".($target instanceof CourseLesson ? "/{$target->id}" : '');
    }

    /** "lesson:<id>" or "program:<id or code>", inside the programmes the platform may reach. @return array{0: string, 1: CourseLesson|Program}|null */
    private function target(string $ref, LtiPlatform $p): ?array
    {
        if ($ref === '') {
            return null;
        }
        [$type, $key] = array_pad(explode(':', $ref, 2), 2, '');
        $model = $this->find($type, $key);
        if (! $model) {
            throw new LtiToolException('missing_content');
        }
        $program = $model instanceof CourseLesson ? $model->program : $model;
        if (! in_array($program->id, $p->programIds(), true) || ($model instanceof CourseLesson && $model->status !== 'published')) {
            throw new LtiToolException('not_allowed');
        }

        return [$type, $model];
    }

    private function find(string $type, string $key): CourseLesson|Program|null
    {
        return match ($type) {
            'lesson' => Str::isUuid($key) ? CourseLesson::with('program')->find($key) : null,
            'program' => Str::isUuid($key) ? Program::find($key) : Program::where('code', $key)->first(),
            default => null,
        };
    }

    private function queryTarget(string $uri): string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);

        return (string) ($q['target'] ?? '');
    }

    /** @param  list<string>  $roles */
    private function instructor(array $roles): bool
    {
        foreach ($roles as $r) {
            if (preg_match('/(membership#(Instructor|ContentDeveloper|Administrator|Mentor)|institution\/person#(Instructor|Administrator|Faculty)|^(Instructor|Administrator|ContentDeveloper|urn:lti:role:ims\/lis\/Instructor))/i', $r)) {
                return true;
            }
        }

        return false;
    }

    private function roleFor(LtiPlatform $p, bool $instructor): string
    {
        $role = (string) ($p->settings['roles'][$instructor ? 'instructor' : 'learner'] ?? Role::EMPLOYEE);

        return in_array($role, self::LAUNCH_ROLES, true) ? $role : Role::EMPLOYEE;
    }

    private function subject(LtiPlatform $p, string $sub): string
    {
        return $p->id.'|'.(strlen($sub) <= 150 ? $sub : 'sha256:'.hash('sha256', $sub));
    }

    public function platformKey(LtiPlatform $p, ?string $kid): string
    {
        if ($p->public_key) {
            return $p->public_key;
        }
        if (! $p->jwks_url) {
            throw new RuntimeException('The platform has no key configured.');
        }
        $jwks = Cache::remember('lti.platform.jwks.'.$p->id, 3600, fn () => Http::timeout(8)->get($p->jwks_url)->throw()->json());
        $key = collect($jwks['keys'] ?? [])->first(fn ($k) => ($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA') ?? ($kid === null ? collect($jwks['keys'] ?? [])->first(fn ($k) => ($k['kty'] ?? '') === 'RSA') : null);
        if (! $key) {
            Cache::forget('lti.platform.jwks.'.$p->id);   // rotated keys: fetched again next time
            throw new RuntimeException('No matching key in the platform JWKS.');
        }

        return Jwt::pemFromJwk($key);
    }

    // ───────────────────────────── deep linking

    /** What an instructor may choose: the published lessons of the programmes the platform may reach. @return list<array{program: Program, lessons: list<CourseLesson>}> */
    public function catalogue(LtiToolLaunch $launch): array
    {
        $out = [];
        foreach (Program::whereIn('id', $launch->platform->programIds())->orderBy('title_en')->get() as $program) {
            $lessons = CourseLesson::where('program_id', $program->id)->where('status', 'published')->orderBy('sort_order')->get()->reject(fn (CourseLesson $l) => $l->isRemedial())->values()->all();
            $out[] = ['program' => $program, 'lessons' => $lessons];
        }

        return $out;
    }

    public function selectionToken(LtiToolLaunch $launch): string
    {
        return hash_hmac('sha256', 'deep-link|'.$launch->id, (string) config('app.key'));
    }

    /**
     * The signed LtiDeepLinkingResponse with the chosen programmes and lessons, to post back to the platform. Each item
     * launches TEDC with its target in the custom parameters and asks the platform for a grade column.
     *
     * @param  list<string>  $items  "program:<id>" | "lesson:<id>"
     * @return array{url: string, jwt: string}
     */
    public function deepLinkResponse(string $launchId, string $token, array $items): array
    {
        $launch = Str::isUuid($launchId) ? LtiToolLaunch::with('platform')->find($launchId) : null;
        if (! $launch || $launch->message_type !== 'LtiDeepLinkingRequest' || ! hash_equals($this->selectionToken($launch), $token) || $launch->used_at || $launch->created_at->lt(now()->subHour())) {
            throw new LtiToolException('selection_expired');
        }
        $p = $launch->platform;
        $chosen = [];
        foreach (array_slice(array_values(array_unique($items)), 0, ($launch->deep_link['accept_multiple'] ?? true) ? 50 : 1) as $ref) {
            [$type, $model] = $this->target((string) $ref, $p) ?? [null, null];
            if (! $model) {
                continue;
            }
            $title = $model instanceof CourseLesson ? (string) ($model->title_en ?: $model->title_ar) : (string) ($model->title_en ?: $model->title_ar);
            $chosen[] = ['type' => 'ltiResourceLink', 'title' => $title, 'url' => $this->urls()['launch_url'], 'custom' => ['tedc_target' => "{$type}:{$model->id}"],
                'lineItem' => ['scoreMaximum' => 100, 'label' => $title, 'resourceId' => "{$type}:{$model->id}"], 'window' => ['targetName' => '_blank']];
        }
        $launch->update(['used_at' => now()]);
        $k = $this->lti->keys();
        $claims = ['iss' => $p->client_id, 'aud' => $p->issuer, 'iat' => time(), 'exp' => time() + 300, 'nonce' => Str::random(24),
            self::C.'message_type' => 'LtiDeepLinkingResponse', self::C.'version' => '1.3.0', self::C.'deployment_id' => (string) ($launch->deep_link['deployment_id'] ?? ''),
            'https://purl.imsglobal.org/spec/lti-dl/claim/content_items' => $chosen];
        if (($launch->deep_link['data'] ?? null) !== null) {
            $claims['https://purl.imsglobal.org/spec/lti-dl/claim/data'] = $launch->deep_link['data'];
        }

        return ['url' => (string) $launch->deep_link['return_url'], 'jwt' => Jwt::sign($claims, $k['private'], $k['kid'])];
    }

    // ───────────────────────────── grades back (AGS, Basic Outcomes) and the roster (NRPS)

    /** A lesson the person finished: its score goes to every launch that opened it and asked for grades. */
    public function lessonFinished(LessonProgress $progress): void
    {
        $userId = Employee::whereKey($progress->employee_id)->value('user_id');
        if (! $userId) {
            return;
        }
        $score = (float) ($progress->best_score ?? 100);
        foreach (LtiToolLaunch::with('platform')->where('user_id', $userId)->where('target_type', 'lesson')->where('target_id', $progress->lesson_id)->get() as $launch) {
            $this->sendScore($launch, $score, true);
        }
    }

    /** A course the person completed: the course result goes to launches that opened the programme. */
    public function courseFinished(Registration $r): void
    {
        $userId = Employee::whereKey($r->employee_id)->value('user_id');
        foreach ($userId ? LtiToolLaunch::with('platform')->where('user_id', $userId)->where('target_type', 'program')->where('target_id', $r->program_id)->get() : [] as $launch) {
            $this->sendScore($launch, (float) ($r->course_percent ?? 100), true);
        }
    }

    /** Sends one grade back; a failure is recorded on the launch and never stops the learner. */
    public function sendScore(LtiToolLaunch $launch, float $percent, bool $completed): bool
    {
        try {
            if ($launch->outcome_url && $launch->outcome_sourcedid) {
                $this->outcome11($launch, max(0.0, min(1.0, $percent / 100)));
            } elseif ($launch->ags_lineitem && in_array(self::SCORE_SCOPE, (array) $launch->ags_scopes, true)) {
                $this->ags($launch, $percent, $completed);
            } else {
                return false;
            }
            $launch->update(['last_score_at' => now(), 'last_score_error' => null]);

            return true;
        } catch (Throwable $e) {
            report($e);
            $launch->update(['last_score_error' => mb_substr($e->getMessage(), 0, 300)]);

            return false;
        }
    }

    private function ags(LtiToolLaunch $launch, float $percent, bool $completed): void
    {
        $url = (string) $launch->ags_lineitem;
        $scores = str_contains($url, '?') ? preg_replace('/\?/', '/scores?', $url, 1) : rtrim($url, '/').'/scores';
        Http::timeout(15)->withToken($this->accessToken($launch->platform, [self::SCORE_SCOPE]))->withHeaders(['Content-Type' => 'application/vnd.ims.lis.v1.score+json'])
            ->post((string) $scores, ['userId' => $launch->sub, 'scoreGiven' => round(max(0.0, min(100.0, $percent)), 2), 'scoreMaximum' => 100, 'activityProgress' => $completed ? 'Completed' : 'InProgress',
                'gradingProgress' => $completed ? 'FullyGraded' : 'Pending', 'timestamp' => now()->toIso8601String()])->throw();
    }

    private function outcome11(LtiToolLaunch $launch, float $score): void
    {
        $p = $launch->platform;
        $xml = '<?xml version="1.0" encoding="UTF-8"?><imsx_POXEnvelopeRequest xmlns="http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0"><imsx_POXHeader><imsx_POXRequestHeaderInfo><imsx_version>V1.0</imsx_version>'
            .'<imsx_messageIdentifier>'.Str::uuid().'</imsx_messageIdentifier></imsx_POXRequestHeaderInfo></imsx_POXHeader><imsx_POXBody><replaceResultRequest><resultRecord><sourcedGUID><sourcedId>'
            .htmlspecialchars((string) $launch->outcome_sourcedid, ENT_XML1).'</sourcedId></sourcedGUID><result><resultScore><language>en</language><textString>'.number_format($score, 4, '.', '')
            .'</textString></resultScore></result></resultRecord></replaceResultRequest></imsx_POXBody></imsx_POXEnvelopeRequest>';
        $oauth = $this->lti->oauthSign('POST', (string) $launch->outcome_url, [], (string) $p->consumer_key, (string) $p->consumer_secret, $xml);
        $header = 'OAuth '.implode(', ', array_map(fn ($k, $v) => $k.'="'.rawurlencode((string) $v).'"', array_keys($oauth), $oauth));
        Http::timeout(15)->withHeaders(['Authorization' => $header])->withBody($xml, 'application/xml')->post((string) $launch->outcome_url)->throw();
    }

    /** The platform's members of a course (NRPS), for a launch that offered the service. @return list<array<string, mixed>> */
    public function roster(LtiToolLaunch $launch): array
    {
        if (! $launch->nrps_url) {
            throw new LtiToolException('no_roster');
        }
        $res = Http::timeout(15)->withToken($this->accessToken($launch->platform, [LtiService::NRPS_SCOPE]))->accept('application/vnd.ims.lti-nrps.v2.membershipcontainer+json')->get((string) $launch->nrps_url)->throw()->json();

        return array_values(array_map(fn ($m) => ['user_id' => (string) ($m['user_id'] ?? ''), 'name' => $m['name'] ?? null, 'email' => $m['email'] ?? null, 'roles' => (array) ($m['roles'] ?? []), 'status' => $m['status'] ?? 'Active'], (array) ($res['members'] ?? [])));
    }

    /** OAuth 2 client credentials with a JWT client assertion signed by TEDC's key; cached until shortly before it expires. @param  list<string>  $scopes */
    private function accessToken(LtiPlatform $p, array $scopes): string
    {
        $key = 'lti.tool.token.'.$p->id.'.'.md5(implode(' ', $scopes));
        if ($cached = Cache::get($key)) {
            return (string) $cached;
        }
        $k = $this->lti->keys();
        $assertion = Jwt::sign(['iss' => $p->client_id, 'sub' => $p->client_id, 'aud' => $p->auth_token_url, 'iat' => time(), 'exp' => time() + 300, 'jti' => (string) Str::uuid()], $k['private'], $k['kid']);
        $res = Http::asForm()->timeout(15)->post((string) $p->auth_token_url, ['grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion, 'scope' => implode(' ', $scopes)])->throw()->json();
        $token = (string) ($res['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('The platform did not give an access token.');
        }
        Cache::put($key, $token, max(30, (int) ($res['expires_in'] ?? 3600) - 60));

        return $token;
    }
}
