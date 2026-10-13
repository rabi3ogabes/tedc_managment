<?php

namespace Tests\Feature;

use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LessonProgress;
use App\Models\LtiPlatform;
use App\Models\LtiTool;
use App\Models\LtiToolLaunch;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Lti\Jwt;
use App\Services\Lti\LtiService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TEC-08: TEDC as an LTI tool. TEDC's own platform implementation launches TEDC's tool endpoints (two registrations in one
 * database), so the whole flow — OIDC login, signed id_token, launch, deep linking, AGS and LTI 1.1 outcomes — is proved
 * without a third party.
 */
class LtiToolTest extends TestCase
{
    private const C = 'https://purl.imsglobal.org/spec/lti/claim/';

    private Program $program;

    private CourseLesson $target;

    private CourseModule $module;

    private LtiTool $tool;

    private CourseLesson $link;

    private LtiPlatform $platform;

    private User $learner;

    /** @var list<HttpRequest> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $keys = app(LtiService::class)->keys();
        // The content other platforms open: a lesson of an online programme.
        $this->program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true, 'requires_evaluation' => false]);
        $this->module = CourseModule::create(['program_id' => $this->program->id, 'title_ar' => 'وحدة', 'title_en' => 'Unit', 'sort_order' => 1]);
        $this->target = CourseLesson::create(['program_id' => $this->program->id, 'module_id' => $this->module->id, 'type' => 'article', 'title_ar' => 'أساسيات طرح الأسئلة', 'title_en' => 'Questioning basics',
            'body_ar' => 'نص', 'body_en' => 'Text', 'status' => 'published', 'sort_order' => 1, 'is_required' => true]);
        // Platform side: another programme whose LTI lesson opens TEDC's tool endpoints, for a learner registered in it.
        $other = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true, 'requires_evaluation' => false]);
        $otherModule = CourseModule::create(['program_id' => $other->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 1]);
        $this->tool = LtiTool::create(['name' => 'TEDC as a tool', 'version' => '1.3', 'client_id' => 'tedc-tool', 'deployment_id' => 'dep-1',
            'login_url' => url('/api/v1/lti/tool/login'), 'launch_url' => url('/api/v1/lti/tool/launch'), 'deep_link_url' => url('/api/v1/lti/tool/launch'),
            'public_key' => $keys['public'], 'supports_ags' => true, 'privacy' => ['share_name' => true, 'share_email' => false], 'custom' => ['tedc_target' => 'lesson:'.$this->target->id], 'is_active' => true]);
        $this->link = CourseLesson::create(['program_id' => $other->id, 'module_id' => $otherModule->id, 'type' => 'lti', 'title_ar' => 'رابط', 'title_en' => 'Link', 'status' => 'published', 'sort_order' => 1, 'lti_tool_id' => $this->tool->id]);
        $employee = $this->makeEmployee();
        $this->learner = $employee->user;
        Registration::create(['program_id' => $other->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        // Tool side: TEDC's platform, allowed to reach the first programme.
        $this->platform = LtiPlatform::create(['name' => 'TEDC as a platform', 'version' => '1.3', 'issuer' => app(LtiService::class)->issuer(), 'client_id' => 'tedc-tool', 'deployment_ids' => ['dep-1'],
            'auth_login_url' => url('/api/v1/lti/auth'), 'auth_token_url' => url('/api/v1/lti/token'), 'public_key' => $keys['public'], 'status' => 'active',
            'settings' => ['program_ids' => [$this->program->id], 'trusted_domains' => [], 'roles' => ['learner' => Role::EMPLOYEE, 'instructor' => Role::EMPLOYEE]]]);
    }

    /** The platform starts a launch, the tool answers the OIDC login, the platform signs the id_token. @return array{id_token: string, state: string, action: string} */
    private function signedLaunch(User $as, string $purpose = 'launch'): array
    {
        if ($purpose === 'deep_link') {
            $form = app(LtiService::class)->loginInitiation($this->tool, $as, null, 'deep_link', ['module_id' => $this->link->module_id]);
        } else {
            $form = $this->asUser($as)->postJson("/api/v1/me/lti/{$this->link->id}/launch")->assertOk()->json('data');
        }
        $login = $this->post($form['action'], $form['fields']);
        $login->assertRedirect();
        $auth = $this->get((string) $login->headers->get('Location'))->assertOk()->getContent();
        preg_match('/action="([^"]+)"/', $auth, $a);
        preg_match('/name="id_token" value="([^"]+)"/', $auth, $t);
        preg_match('/name="state" value="([^"]*)"/', $auth, $s);

        return ['action' => html_entity_decode($a[1]), 'id_token' => html_entity_decode($t[1]), 'state' => html_entity_decode($s[1])];
    }

    private function toolLaunch(array $signed): TestResponse
    {
        return $this->post('/api/v1/lti/tool/launch', ['id_token' => $signed['id_token'], 'state' => $signed['state']]);
    }

    /** @return array{0: string, 1: string} the one-time code and where it leads, from the launch page */
    private function handoff(TestResponse $page): array
    {
        $this->assertTrue(preg_match('#/lti/launch\?code=([A-Za-z0-9]+)&amp;to=([^"\']+)#', $page->getContent(), $m) === 1, 'the launch page leads into TEDC');

        return [$m[1], rawurldecode(html_entity_decode($m[2]))];
    }

    /** A fresh tool login (state and nonce) for hand-made id_tokens. @return array{state: string, nonce: string} */
    private function freshLogin(): array
    {
        $login = $this->post('/api/v1/lti/tool/login', ['iss' => $this->platform->issuer, 'login_hint' => 'x', 'target_link_uri' => url('/api/v1/lti/tool/launch'), 'client_id' => 'tedc-tool', 'lti_deployment_id' => 'dep-1']);
        parse_str((string) parse_url((string) $login->headers->get('Location'), PHP_URL_QUERY), $q);

        return ['state' => $q['state'], 'nonce' => $q['nonce']];
    }

    private function craft(array $claims, ?string $privateKey = null): string
    {
        $keys = app(LtiService::class)->keys();

        return Jwt::sign($claims, $privateKey ?? $keys['private'], $keys['kid']);
    }

    private function claims(string $nonce, array $over = []): array
    {
        return array_replace(['iss' => $this->platform->issuer, 'aud' => 'tedc-tool', 'sub' => 'person-1', 'iat' => time(), 'exp' => time() + 300, 'nonce' => $nonce,
            self::C.'message_type' => 'LtiResourceLinkRequest', self::C.'version' => '1.3.0', self::C.'deployment_id' => 'dep-1', self::C.'resource_link' => ['id' => 'rl-1'],
            self::C.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'], self::C.'custom' => ['tedc_target' => 'lesson:'.$this->target->id], 'name' => 'Visiting teacher'], $over);
    }

    public function test_a_loopback_launch_creates_the_learner_enrols_them_and_opens_the_lesson(): void
    {
        $page = $this->toolLaunch($this->signedLaunch($this->learner))->assertOk();
        [$code, $to] = $this->handoff($page);
        // Only the platform's own pages may show the launch page in a frame.
        $csp = (string) $page->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("#frame-ancestors 'self'[^;]* ".preg_quote($this->origin(url('/')), '#').'#', $csp);
        $this->assertNull($page->headers->get('X-Frame-Options'));

        $session = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->assertOk()->json();
        $guest = User::findOrFail($session['user']['id']);
        $this->assertNotSame($this->learner->id, $guest->id);                 // no e-mail shared: a new account, never a guessed one
        $this->assertTrue($guest->hasRole(Role::EMPLOYEE));
        $reg = Registration::where('program_id', $this->program->id)->where('employee_id', $guest->employee->id)->firstOrFail();
        $this->assertSame('lti', $reg->source);
        $this->assertSame("/portal/learn/{$reg->id}/{$this->target->id}", $to);
        $this->assertSame(1, UserIdentity::where('provider', 'lti')->where('user_id', $guest->id)->count());

        // The next launch of the same person maps to the same account.
        [$again] = $this->handoff($this->toolLaunch($this->signedLaunch($this->learner))->assertOk());
        $this->assertSame($guest->id, $this->postJson('/api/v1/auth/sso/exchange', ['code' => $again])->json('user.id'));
        $this->assertSame(2, LtiToolLaunch::where('user_id', $guest->id)->count());
    }

    public function test_an_account_with_the_same_email_is_linked_only_for_a_trusted_domain_or_after_the_person_signs_in(): void
    {
        $this->tool->update(['privacy' => ['share_name' => true, 'share_email' => true]]);

        // Untrusted: no session for the existing account; the person must sign in to link.
        $page = $this->toolLaunch($this->signedLaunch($this->learner))->assertOk()->getContent();
        $this->assertStringNotContainsString('/lti/launch?code=', $page);
        $this->assertTrue(preg_match('#/lti/link\?ticket=([A-Za-z0-9]+)#', $page, $m) === 1);
        $this->assertSame(0, UserIdentity::where('provider', 'lti')->count());
        $this->guest()->postJson('/api/v1/me/lti/tool/link', ['ticket' => $m[1]])->assertUnauthorized();
        $linked = $this->asUser($this->learner)->postJson('/api/v1/me/lti/tool/link', ['ticket' => $m[1]])->assertOk()->json('data');
        $this->assertStringStartsWith('/portal/learn/', $linked['to']);
        $this->assertSame($this->learner->id, UserIdentity::where('provider', 'lti')->value('user_id'));
        $this->asUser($this->learner)->postJson('/api/v1/me/lti/tool/link', ['ticket' => $m[1]])->assertStatus(422);   // a ticket works once

        // Trusted domain: the account is linked straight away.
        UserIdentity::where('provider', 'lti')->delete();
        $this->platform->update(['settings' => array_replace($this->platform->settings, ['trusted_domains' => [Str::after($this->learner->email, '@')]])]);
        [$code] = $this->handoff($this->toolLaunch($this->signedLaunch($this->learner))->assertOk());
        $this->assertSame($this->learner->id, $this->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->json('user.id'));
    }

    public function test_a_staff_account_is_never_signed_in_by_a_launch(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->makeEmployee([], $head);
        Registration::create(['program_id' => $this->link->program_id, 'employee_id' => $head->employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $this->tool->update(['privacy' => ['share_name' => true, 'share_email' => true]]);
        $this->platform->update(['settings' => array_replace($this->platform->settings, ['trusted_domains' => [Str::after($head->email, '@')]])]);

        $page = $this->toolLaunch($this->signedLaunch($head))->assertOk()->getContent();

        $this->assertStringNotContainsString('/lti/launch?code=', $page);
        $this->assertStringContainsString('/login?redirect=', $page);
        $this->assertFalse(Registration::where('program_id', $this->program->id)->where('employee_id', $head->employee->id)->exists());
    }

    public function test_a_platform_opens_only_the_programmes_it_was_given(): void
    {
        $this->platform->update(['settings' => array_replace($this->platform->settings, ['program_ids' => []])]);

        $page = $this->toolLaunch($this->signedLaunch($this->learner))->assertStatus(400)->getContent();
        $this->assertTrue(str_contains($page, __('messages.lti_tool.not_allowed', [], 'ar')) || str_contains($page, __('messages.lti_tool.not_allowed', [], 'en')));
        $this->assertSame(0, LtiToolLaunch::count());
    }

    public function test_a_bad_signature_a_wrong_audience_a_replayed_launch_and_an_unknown_deployment_are_refused(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPrivate);

        $l = $this->freshLogin();
        $this->post('/api/v1/lti/tool/launch', ['id_token' => $this->craft($this->claims($l['nonce']), $otherPrivate), 'state' => $l['state']])->assertStatus(400);
        $l = $this->freshLogin();
        $this->post('/api/v1/lti/tool/launch', ['id_token' => $this->craft($this->claims($l['nonce'], ['aud' => 'someone-else'])), 'state' => $l['state']])->assertStatus(400);
        $l = $this->freshLogin();
        $this->post('/api/v1/lti/tool/launch', ['id_token' => $this->craft($this->claims($l['nonce'], [self::C.'deployment_id' => 'dep-unknown'])), 'state' => $l['state']])->assertStatus(400);
        $l = $this->freshLogin();
        $this->post('/api/v1/lti/tool/launch', ['id_token' => $this->craft($this->claims('another-nonce')), 'state' => $l['state']])->assertStatus(400);

        $l = $this->freshLogin();
        $good = $this->craft($this->claims($l['nonce']));
        $this->post('/api/v1/lti/tool/launch', ['id_token' => $good, 'state' => $l['state']])->assertOk();
        $this->post('/api/v1/lti/tool/launch', ['id_token' => $good, 'state' => $l['state']])->assertStatus(400);   // replayed
        $this->assertSame(1, LtiToolLaunch::count());

        // A platform that is not registered is refused at login.
        $this->post('/api/v1/lti/tool/login', ['iss' => 'https://unknown.example', 'login_hint' => 'x', 'target_link_uri' => url('/api/v1/lti/tool/launch')])->assertStatus(400);
    }

    public function test_deep_linking_returns_a_signed_response_the_platform_accepts(): void
    {
        $instructor = $this->makeUser(Role::TRAINING_HEAD);
        $page = $this->toolLaunch($this->signedLaunch($instructor, 'deep_link'))->assertOk()->getContent();
        $this->assertStringContainsString($this->target->title_ar, $page);   // Arabic unless the platform asks for English
        preg_match('/name="launch" value="([^"]+)"/', $page, $launch);
        preg_match('/name="token" value="([^"]+)"/', $page, $token);

        $this->post('/api/v1/lti/tool/deep-link', ['launch' => $launch[1], 'token' => 'forged', 'items' => ['lesson:'.$this->target->id]])->assertStatus(400);
        $return = $this->post('/api/v1/lti/tool/deep-link', ['launch' => $launch[1], 'token' => $token[1], 'items' => ['lesson:'.$this->target->id]])->assertOk()->getContent();
        $this->assertStringContainsString('action="'.url('/api/v1/lti/deep-link/return').'"', $return);
        preg_match('/name="JWT" value="([^"]+)"/', $return, $jwt);
        $claims = Jwt::verify(html_entity_decode($jwt[1]), app(LtiService::class)->keys()['public']);
        $this->assertSame('tedc-tool', $claims['iss']);
        $this->assertSame('LtiDeepLinkingResponse', $claims[self::C.'message_type']);
        $item = $claims['https://purl.imsglobal.org/spec/lti-dl/claim/content_items'][0];
        $this->assertSame('ltiResourceLink', $item['type']);
        $this->assertSame('lesson:'.$this->target->id, $item['custom']['tedc_target']);

        // The platform side accepts it and adds the lesson.
        $this->post('/api/v1/lti/deep-link/return', ['JWT' => html_entity_decode($jwt[1])])->assertOk();
        $this->assertTrue(CourseLesson::where('module_id', $this->link->module_id)->where('title_en', $this->target->title_en)->exists());
        $this->post('/api/v1/lti/tool/deep-link', ['launch' => $launch[1], 'token' => $token[1], 'items' => ['lesson:'.$this->target->id]])->assertStatus(400);   // once
    }

    public function test_finishing_the_lesson_sends_the_score_back_and_the_platform_accepts_it(): void
    {
        Http::fake(function (HttpRequest $request) {
            $this->sent[] = $request;

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/lti/token') ? Http::response(['access_token' => 'fake', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'x']) : Http::response('', 204);
        });
        [$code, $to] = $this->handoff($this->toolLaunch($this->signedLaunch($this->learner))->assertOk());
        $guest = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->json('user.id');

        $this->asUser(User::findOrFail($guest))->getJson("/api/v1/me/lessons/{$this->target->id}")->assertOk();
        $this->asUser(User::findOrFail($guest))->postJson("/api/v1/me/lessons/{$this->target->id}/complete")->assertOk();

        $token = collect($this->sent)->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/lti/token'));
        $score = collect($this->sent)->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/scores'));
        $this->assertNotNull($token, 'the tool asked the platform for a token');
        $this->assertNotNull($score, 'the tool posted a score');
        $this->assertSame('Completed', $score->data()['activityProgress']);
        $this->assertSame('FullyGraded', $score->data()['gradingProgress']);
        $this->assertSame((string) $this->learner->id, $score->data()['userId']);   // the platform's own id for the person

        // Replayed against TEDC's platform side: the client assertion is valid and the score completes the platform's lesson.
        $real = $this->post('/api/v1/lti/token', $token->data())->assertOk()->json('access_token');
        $this->withHeaders(['Authorization' => "Bearer {$real}"])->postJson(parse_url($score->url(), PHP_URL_PATH), $score->data())->assertNoContent();
        $this->assertSame('completed', LessonProgress::where('lesson_id', $this->link->id)->value('status'));
    }

    public function test_lti_11_launch_signatures_are_checked_and_outcomes_reach_the_platform(): void
    {
        $tool11 = LtiTool::create(['name' => 'TEDC 1.1', 'version' => '1.1', 'consumer_key' => 'k11', 'consumer_secret' => 's11', 'launch_url' => url('/api/v1/lti/tool/launch11'),
            'custom' => ['tedc_target' => 'lesson:'.$this->target->id], 'privacy' => ['share_name' => true, 'share_email' => false], 'is_active' => true]);
        $this->link->update(['lti_tool_id' => $tool11->id]);
        LtiPlatform::create(['name' => 'Old LMS', 'version' => '1.1', 'issuer' => 'lti11:k11', 'client_id' => 'k11', 'consumer_key' => 'k11', 'consumer_secret' => 's11', 'deployment_ids' => [], 'status' => 'active',
            'settings' => ['program_ids' => [$this->program->id], 'trusted_domains' => [], 'roles' => ['learner' => Role::EMPLOYEE, 'instructor' => Role::EMPLOYEE]]]);
        $form = $this->asUser($this->learner)->postJson("/api/v1/me/lti/{$this->link->id}/launch")->assertOk()->json('data');

        $tampered = $form['fields'];
        $tampered['custom_tedc_target'] = 'lesson:'.Str::uuid();
        $this->post($form['action'], $tampered)->assertStatus(400);
        $page = $this->post($form['action'], $form['fields'])->assertOk();
        $this->post($form['action'], $form['fields'])->assertStatus(400);                       // the nonce works once
        [$code] = $this->handoff($page);
        $guest = User::findOrFail($this->postJson('/api/v1/auth/sso/exchange', ['code' => $code])->json('user.id'));

        Http::fake(function (HttpRequest $request) {
            $this->sent[] = $request;

            return Http::response('<imsx_POXEnvelopeResponse/>', 200);
        });
        $this->asUser($guest)->getJson("/api/v1/me/lessons/{$this->target->id}")->assertOk();
        $this->asUser($guest)->postJson("/api/v1/me/lessons/{$this->target->id}/complete")->assertOk();
        $outcome = collect($this->sent)->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/lti/outcomes'));
        $this->assertNotNull($outcome);
        $this->assertStringContainsString('replaceResultRequest', $outcome->body());

        // Replayed against the platform side: the OAuth signature and body hash are accepted and the lesson completes.
        $this->call('POST', '/api/v1/lti/outcomes', [], [], [], ['HTTP_AUTHORIZATION' => $outcome->header('Authorization')[0], 'CONTENT_TYPE' => 'application/xml'], $outcome->body())->assertOk();
        $this->assertSame('completed', LessonProgress::where('lesson_id', $this->link->id)->value('status'));
    }

    public function test_platforms_are_managed_with_lti_manage_and_the_tool_configuration_can_be_copied(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($this->learner)->getJson('/api/v1/admin/lti-platforms')->assertForbidden();

        $config = $this->asUser($admin)->getJson('/api/v1/admin/lti-platforms/tool-config')->assertOk()->json('data');
        $this->assertSame(url('/api/v1/lti/tool/login'), $config['login_url']);
        $this->assertSame(url('/api/v1/lti/tool/launch'), $config['launch_url']);
        $this->assertSame(url('/api/v1/lti/tool/jwks'), $config['jwks_url']);
        $this->assertContains(url('/api/v1/lti/tool/launch'), $config['redirect_uris']);
        $this->assertSame(app(LtiService::class)->jwks()['keys'][0]['kid'], $this->getJson('/api/v1/lti/tool/jwks')->assertOk()->json('keys.0.kid'));

        $created = $this->asUser($admin)->postJson('/api/v1/admin/lti-platforms', ['name' => 'Moodle', 'version' => '1.3', 'issuer' => 'https://moodle.example', 'client_id' => 'abc', 'deployment_ids' => ['1'],
            'auth_login_url' => 'https://moodle.example/mod/lti/auth.php', 'auth_token_url' => 'https://moodle.example/mod/lti/token.php', 'jwks_url' => 'https://moodle.example/mod/lti/certs.php',
            'settings' => ['program_ids' => [$this->program->id], 'trusted_domains' => ['moe.gov.qa'], 'roles' => ['learner' => Role::EMPLOYEE, 'instructor' => Role::TRAINER]]])->assertCreated()->json('data');
        $this->asUser($admin)->postJson('/api/v1/admin/lti-platforms', ['name' => 'Dup', 'version' => '1.3', 'issuer' => 'https://moodle.example', 'client_id' => 'abc', 'deployment_ids' => ['1'],
            'auth_login_url' => 'https://moodle.example/a', 'auth_token_url' => 'https://moodle.example/t', 'jwks_url' => 'https://moodle.example/k'])->assertStatus(422);
        $this->asUser($admin)->putJson("/api/v1/admin/lti-platforms/{$created['id']}", ['settings' => ['roles' => ['learner' => Role::SUPER_ADMIN]]])->assertStatus(422);   // never an administrator role
        Http::fake(['moodle.example/*' => Http::response(['keys' => [app(LtiService::class)->jwks()['keys'][0]]])]);
        $checks = $this->asUser($admin)->postJson("/api/v1/admin/lti-platforms/{$created['id']}/test")->assertOk()->json('data.checks');
        $this->assertTrue(collect($checks)->firstWhere('key', 'jwks')['ok']);
        $this->asUser($admin)->deleteJson("/api/v1/admin/lti-platforms/{$created['id']}")->assertOk();
        $this->assertNull(LtiPlatform::find($created['id']));
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutHeader('Authorization');
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }
}
