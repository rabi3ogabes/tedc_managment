<?php

namespace Tests\Feature;

use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LessonProgress;
use App\Models\LtiState;
use App\Models\LtiTool;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\Lti\Jwt;
use App\Services\Lti\LtiService;
use Tests\TestCase;

class LtiTest extends TestCase
{
    private string $toolPriv = '';

    private string $toolPub = '';

    private function toolKeys(): void
    {
        $r = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($r, $this->toolPriv);
        $this->toolPub = openssl_pkey_get_details($r)['key'];
    }

    /** @return array{0: User, 1: CourseLesson, 2: LtiTool, 3: Registration} */
    private function setup13(array $tool = []): array
    {
        $this->toolKeys();
        $employee = $this->makeEmployee();
        $program = $this->makeProgram(['delivery_mode' => 'online', 'requires_evaluation' => false, 'has_course' => true]);
        $r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 1]);
        $t = LtiTool::create($tool + ['name' => 'Tool', 'version' => '1.3', 'client_id' => 'client-1', 'deployment_id' => 'dep-1', 'login_url' => 'https://tool.test/login', 'launch_url' => 'https://tool.test/launch', 'deep_link_url' => 'https://tool.test/deeplink', 'public_key' => $this->toolPub, 'supports_ags' => true, 'privacy' => ['share_name' => true, 'share_email' => false]]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'lti', 'title_ar' => 'أداة', 'title_en' => 'Tool lesson', 'status' => 'published', 'sort_order' => 1, 'lti_tool_id' => $t->id]);

        return [$employee->user, $lesson, $t, $r];
    }

    private function authParams(array $fields, array $over = []): array
    {
        return $over + ['scope' => 'openid', 'response_type' => 'id_token', 'response_mode' => 'form_post', 'prompt' => 'none', 'client_id' => $fields['client_id'], 'redirect_uri' => $fields['target_link_uri'], 'login_hint' => $fields['login_hint'], 'lti_message_hint' => $fields['lti_message_hint'], 'state' => 'st-1', 'nonce' => 'nonce-1'];
    }

    private function idToken(string $html): string
    {
        preg_match('/name="id_token" value="([^"]+)"/', $html, $m);

        return html_entity_decode($m[1] ?? '');
    }

    public function test_the_lti_13_launch_flow_signs_an_id_token_the_tool_can_verify_with_the_jwks(): void
    {
        [$user, $lesson, $tool] = $this->setup13();
        $form = $this->asUser($user)->postJson("/api/v1/me/lti/{$lesson->id}/launch")->assertOk()->json('data');
        $this->assertSame('https://tool.test/login', $form['action']);
        $this->assertSame('client-1', $form['fields']['client_id']);
        $this->assertSame('dep-1', $form['fields']['lti_deployment_id']);

        $res = $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($form['fields'])));
        $res->assertOk();
        $this->assertStringContainsString('action="https://tool.test/launch"', $res->getContent());
        $token = $this->idToken($res->getContent());

        // The tool fetches the platform JWKS and verifies the token.
        $jwk = $this->getJson('/api/v1/lti/jwks')->assertOk()->json('keys.0');
        $claims = Jwt::verify($token, Jwt::pemFromJwk($jwk));
        $this->assertSame('client-1', $claims['aud']);
        $this->assertSame('nonce-1', $claims['nonce']);
        $this->assertSame((string) $user->id, $claims['sub']);
        $c = 'https://purl.imsglobal.org/spec/lti/claim/';
        $this->assertSame('LtiResourceLinkRequest', $claims[$c.'message_type']);
        $this->assertSame('1.3.0', $claims[$c.'version']);
        $this->assertSame('dep-1', $claims[$c.'deployment_id']);
        $this->assertSame($lesson->id, $claims[$c.'resource_link']['id']);
        $this->assertContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Learner', $claims[$c.'roles']);
        $this->assertSame($user->name, $claims['name']);
        $this->assertArrayNotHasKey('email', $claims);                       // privacy: the e-mail is not shared
        $this->assertStringEndsWith("/lti/ags/{$lesson->id}/lineitems", $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']['lineitems']);

        // State is single use.
        $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($form['fields'])))->assertStatus(400);
    }

    public function test_the_authentication_request_is_validated(): void
    {
        [$user, $lesson] = $this->setup13();
        $mk = fn () => $this->asUser($user)->postJson("/api/v1/me/lti/{$lesson->id}/launch")->json('data.fields');
        $f = $mk();
        $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($f, ['client_id' => 'someone-else'])))->assertStatus(400);
        $f = $mk();
        $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($f, ['redirect_uri' => 'https://evil.test/steal'])))->assertStatus(400);
        $f = $mk();
        $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($f, ['prompt' => 'login'])))->assertStatus(400);
        $f = $mk();
        LtiState::find($f['login_hint'])->update(['expires_at' => now()->subMinute()]);
        $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($f)))->assertStatus(400);
        $this->get('/api/v1/lti/auth?'.http_build_query(['login_hint' => 'nope']))->assertStatus(400);
    }

    private function accessToken(LtiTool $tool, string $scope, array $over = []): array
    {
        $assertion = Jwt::sign(['iss' => 'client-1', 'sub' => 'client-1', 'aud' => url('/api/v1/lti/token'), 'iat' => time(), 'exp' => time() + 60, 'jti' => uniqid('j', true)] + $over, $this->toolPriv, 'tool-key');

        return $this->postJson('/api/v1/lti/token', ['grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion, 'scope' => $scope])->json();
    }

    public function test_ags_score_passback_completes_the_lesson_and_scopes_are_enforced(): void
    {
        [$user, $lesson, $tool, $r] = $this->setup13();
        $bad = $this->postJson('/api/v1/lti/token', ['grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => Jwt::sign(['iss' => 'client-1', 'sub' => 'client-1', 'aud' => url('/api/v1/lti/token'), 'exp' => time() + 60], $this->toolPriv, 'x').'x', 'scope' => 'x']);
        $bad->assertStatus(400);

        $lineScope = implode(' ', [LtiService::AGS_SCOPES[1]]);
        $readOnly = $this->accessToken($tool, $lineScope);
        $this->getJson("/api/v1/lti/ags/{$lesson->id}/lineitems", ['Authorization' => 'Bearer '.$readOnly['access_token']])->assertOk()->assertJsonPath('0.scoreMaximum', 100);
        $payload = ['userId' => $user->id, 'scoreGiven' => 42, 'scoreMaximum' => 50, 'activityProgress' => 'Completed', 'gradingProgress' => 'FullyGraded'];
        $this->postJson("/api/v1/lti/ags/{$lesson->id}/lineitems/1/scores", $payload, ['Authorization' => 'Bearer '.$readOnly['access_token']])->assertStatus(422)->assertJsonPath('code', 'lti_forbidden');
        $this->postJson("/api/v1/lti/ags/{$lesson->id}/lineitems/1/scores", $payload)->assertStatus(422);

        $tok = $this->accessToken($tool, implode(' ', LtiService::AGS_SCOPES));
        $this->assertStringContainsString('score', $tok['scope']);
        $this->postJson("/api/v1/lti/ags/{$lesson->id}/lineitems/1/scores", $payload, ['Authorization' => 'Bearer '.$tok['access_token']])->assertStatus(204);
        $p = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertSame('completed', $p->status);
        $this->assertEquals(84.0, (float) $p->best_score);                   // 42 of 50
        $this->assertDatabaseHas('lti_scores', ['lesson_id' => $lesson->id, 'activity_progress' => 'Completed']);

        // A tool cannot score a lesson that belongs to another tool.
        $other = $this->setup13(['client_id' => 'client-2', 'name' => 'Other'])[1];
        $this->postJson("/api/v1/lti/ags/{$other->id}/lineitems/1/scores", $payload, ['Authorization' => 'Bearer '.$tok['access_token']])->assertStatus(404);
    }

    public function test_the_client_assertion_is_checked_and_cannot_be_replayed(): void
    {
        [, , $tool] = $this->setup13();
        $assertion = Jwt::sign(['iss' => 'client-1', 'sub' => 'client-1', 'aud' => url('/api/v1/lti/token'), 'iat' => time(), 'exp' => time() + 60, 'jti' => 'same'], $this->toolPriv, 'k');
        $body = ['grant_type' => 'client_credentials', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion, 'scope' => LtiService::NRPS_SCOPE];
        $this->postJson('/api/v1/lti/token', $body)->assertOk();
        $this->postJson('/api/v1/lti/token', $body)->assertStatus(400);                               // same jti
        $expired = Jwt::sign(['iss' => 'client-1', 'sub' => 'client-1', 'aud' => url('/api/v1/lti/token'), 'exp' => time() - 600, 'jti' => 'old'], $this->toolPriv, 'k');
        $this->postJson('/api/v1/lti/token', ['client_assertion' => $expired] + $body)->assertStatus(400);
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($wrongKey, $pem);
        $forged = Jwt::sign(['iss' => 'client-1', 'sub' => 'client-1', 'aud' => url('/api/v1/lti/token'), 'exp' => time() + 60, 'jti' => 'f'], $pem, 'k');
        $this->postJson('/api/v1/lti/token', ['client_assertion' => $forged] + $body)->assertStatus(400);
    }

    public function test_nrps_lists_members_and_respects_privacy(): void
    {
        [$user, $lesson, $tool, $r] = $this->setup13();
        $tok = $this->accessToken($tool, LtiService::NRPS_SCOPE);
        $res = $this->getJson("/api/v1/lti/nrps/{$lesson->program_id}/memberships", ['Authorization' => 'Bearer '.$tok['access_token']])->assertOk();
        $this->assertSame($user->id, $res->json('members.0.user_id'));
        $this->assertSame($user->name, $res->json('members.0.name'));
        $this->assertNull($res->json('members.0.email'));
    }

    public function test_deep_linking_creates_lessons_from_the_tools_signed_response(): void
    {
        [, $lesson, $tool] = $this->setup13();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $module = CourseModule::first();
        $form = $this->asUser($admin)->postJson("/api/v1/admin/lti-tools/{$tool->id}/deep-link", ['module_id' => $module->id])->assertOk()->json('data');
        $this->assertSame('https://tool.test/deeplink', $form['fields']['target_link_uri']);

        $res = $this->get('/api/v1/lti/auth?'.http_build_query($this->authParams($form['fields'])))->assertOk();
        $claims = Jwt::verify($this->idToken($res->getContent()), $this->platformPem());
        $c = 'https://purl.imsglobal.org/spec/lti/claim/';
        $this->assertSame('LtiDeepLinkingRequest', $claims[$c.'message_type']);
        $settings = $claims['https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings'];

        $response = Jwt::sign(['iss' => 'client-1', 'aud' => $claims['iss'], 'iat' => time(), 'exp' => time() + 120, 'nonce' => 'n2', $c.'message_type' => 'LtiDeepLinkingResponse', $c.'version' => '1.3.0', $c.'deployment_id' => 'dep-1',
            'https://purl.imsglobal.org/spec/lti-dl/claim/data' => $settings['data'],
            'https://purl.imsglobal.org/spec/lti-dl/claim/content_items' => [['type' => 'ltiResourceLink', 'title' => 'Simulation 1', 'url' => 'https://tool.test/launch?r=1'], ['type' => 'link', 'title' => 'Reading', 'url' => 'https://tool.test/read'], ['type' => 'file', 'title' => 'ignored', 'url' => 'https://x.test/f']]], $this->toolPriv, 'tool-key');
        $before = CourseLesson::count();
        $this->post('/api/v1/lti/deep-link/return', ['JWT' => $response])->assertOk();
        $this->assertSame($before + 2, CourseLesson::count());                         // the unsupported "file" item is skipped
        $made = CourseLesson::where('title_en', 'Simulation 1')->first();
        $this->assertSame(['lti', $tool->id, 'https://tool.test/launch?r=1'], [$made->type, $made->lti_tool_id, $made->settings['lti_launch_url']]);
        $this->assertSame('article', CourseLesson::where('title_en', 'Reading')->value('type'));

        // A response signed by somebody else is refused.
        $r = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($r, $forgedKey);
        $bad = Jwt::sign(['iss' => 'client-1', 'aud' => $claims['iss'], 'exp' => time() + 120, $c.'message_type' => 'LtiDeepLinkingResponse', 'https://purl.imsglobal.org/spec/lti-dl/claim/data' => $settings['data'], 'https://purl.imsglobal.org/spec/lti-dl/claim/content_items' => []], $forgedKey, 'x');
        $this->post('/api/v1/lti/deep-link/return', ['JWT' => $bad])->assertStatus(400);
    }

    private function platformPem(): string
    {
        return Jwt::pemFromJwk($this->getJson('/api/v1/lti/jwks')->json('keys.0'));
    }

    public function test_lti_11_launch_is_signed_and_basic_outcomes_complete_the_lesson(): void
    {
        [$user, $lesson, $tool] = $this->setup13(['version' => '1.1', 'client_id' => null, 'consumer_key' => 'ck', 'consumer_secret' => 'shh', 'login_url' => null, 'public_key' => null]);
        $form = $this->asUser($user)->postJson("/api/v1/me/lti/{$lesson->id}/launch")->assertOk()->json('data');
        $f = $form['fields'];
        $this->assertSame('basic-lti-launch-request', $f['lti_message_type']);
        $this->assertSame('Learner', $f['roles']);
        $lti = app(LtiService::class);
        $this->assertSame($lti->signature('POST', $form['action'], $f, 'shh'), $f['oauth_signature']);       // what the tool recomputes
        $this->assertNotSame($lti->signature('POST', $form['action'], $f, 'wrong'), $f['oauth_signature']);

        $xml = '<?xml version="1.0"?><imsx_POXEnvelopeRequest xmlns="http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0"><imsx_POXBody><replaceResultRequest><resultRecord><sourcedGUID><sourcedId>'.$f['lis_result_sourcedid'].'</sourcedId></sourcedGUID><result><resultScore><language>en</language><textString>0.8</textString></resultScore></result></resultRecord></replaceResultRequest></imsx_POXBody></imsx_POXEnvelopeRequest>';
        $url = url('/api/v1/lti/outcomes');
        $oauth = $lti->oauthSign('POST', $url, [], 'ck', 'shh', $xml);
        $header = 'OAuth '.implode(', ', array_map(fn ($k, $v) => $k.'="'.rawurlencode($v).'"', array_keys($oauth), $oauth));
        $send = fn (string $auth) => $this->call('POST', $url, [], [], [], ['HTTP_AUTHORIZATION' => $auth, 'CONTENT_TYPE' => 'application/xml'], $xml);

        $send($header)->assertOk();
        $p = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertSame('completed', $p->status);
        $this->assertEquals(80.0, (float) $p->best_score);
        $send($header)->assertStatus(401);                                              // replayed nonce
        $tampered = $lti->oauthSign('POST', $url, [], 'ck', 'WRONG', $xml);
        $send('OAuth '.implode(', ', array_map(fn ($k, $v) => $k.'="'.rawurlencode($v).'"', array_keys($tampered), $tampered)))->assertStatus(401);
    }

    public function test_the_jwk_pem_conversion_round_trips(): void
    {
        $this->toolKeys();
        $jwk = Jwt::jwk($this->toolPub, 'k1');
        $pem = Jwt::pemFromJwk($jwk);
        $token = Jwt::sign(['exp' => time() + 60, 'x' => 1], $this->toolPriv, 'k1');
        $this->assertSame(1, Jwt::verify($token, $pem)['x']);
        $this->expectExceptionMessage('Token expired');
        Jwt::verify(Jwt::sign(['exp' => time() - 600], $this->toolPriv, 'k1'), $pem);
    }
}
