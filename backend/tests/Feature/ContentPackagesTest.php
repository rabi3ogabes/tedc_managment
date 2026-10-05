<?php

namespace Tests\Feature;

use App\Models\CaliperEvent;
use App\Models\CourseLesson;
use App\Models\LessonProgress;
use App\Models\Registration;
use App\Models\Role;
use App\Models\XapiStatement;
use App\Services\Content\CaliperService;
use App\Services\Content\PackageToken;
use App\Services\Content\StandardsSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ContentPackagesTest extends TestCase
{
    /** @param array<string, string> $files */
    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'z').'.zip';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();

        return new UploadedFile($path, 'p.zip', 'application/zip', null, true);
    }

    private const SCORM12 = '<?xml version="1.0"?><manifest identifier="m" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"><metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata><organizations default="o"><organization identifier="o"><title>Course 1.2</title><item identifier="i1" identifierref="r1"><title>Lesson one</title></item></organization></organizations><resources><resource identifier="r1" type="webcontent" adlcp:scormtype="sco" href="sco1/index.html"><file href="sco1/index.html"/></resource></resources></manifest>';

    private const SCORM2004 = '<?xml version="1.0"?><manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3"><metadata><schema>ADL SCORM</schema><schemaversion>2004 4th Edition</schemaversion></metadata><organizations default="o"><organization identifier="o"><title>Course 2004</title><item identifier="i1"><title>Module</title><item identifier="i2" identifierref="r1"><title>Page</title></item></item></organization></organizations><resources><resource identifier="r1" type="webcontent" adlcp:scormType="sco" href="a.html"/></resources></manifest>';

    private const CMI5 = '<?xml version="1.0"?><courseStructure xmlns="https://w3id.org/xapi/profiles/cmi5/v1/CourseStructure.xsd"><course id="http://ex.org/course"><title><langstring lang="en-US">cmi5 course</langstring></title></course><au id="http://ex.org/au1" moveOn="CompletedOrPassed" launchMethod="OwnWindow"><title><langstring lang="en-US">AU one</langstring></title><url>au1/index.html</url></au></courseStructure>';

    private const CC = '<?xml version="1.0"?><manifest identifier="cc" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"><metadata><schema>IMS Common Cartridge</schema><schemaversion>1.1.0</schemaversion></metadata><organizations><organization identifier="org" structure="rooted-hierarchy"><item identifier="root"><item identifier="i1" identifierref="r1"><title>Page</title></item></item></organization></organizations><resources><resource identifier="r1" type="webcontent" href="page.html"/></resources></manifest>';

    private function admin(): \App\Models\User
    {
        return $this->makeUser(Role::CENTER_ADMIN);
    }

    public function test_manifests_of_every_standard_are_detected_and_parsed(): void
    {
        Storage::fake('local');
        $a = $this->admin();
        $cases = [
            ['scorm12', ['imsmanifest.xml' => self::SCORM12, 'sco1/index.html' => '<html></html>'], 'Course 1.2', 'sco1/index.html'],
            ['scorm2004', ['imsmanifest.xml' => self::SCORM2004, 'a.html' => '<html></html>'], 'Course 2004', 'a.html'],
            ['cmi5', ['cmi5.xml' => self::CMI5, 'au1/index.html' => '<html></html>'], 'cmi5 course', 'au1/index.html'],
            ['cc', ['imsmanifest.xml' => self::CC, 'page.html' => '<html></html>'], 'Package', 'page.html'],
            ['h5p', ['h5p.json' => json_encode(['title' => 'Quiz', 'mainLibrary' => 'H5P.MultiChoice']), 'content/content.json' => '{}'], 'Quiz', 'h5p.json'],
            ['html5', ['index.html' => '<html></html>'], 'HTML5', 'index.html'],
        ];
        foreach ($cases as [$standard, $files, $title, $href]) {
            $r = $this->asUser($a)->post('/api/v1/admin/packages', ['file' => $this->zip($files)], ['Accept' => 'application/json'])->assertCreated();
            $this->assertSame($standard, $r->json('data.standard'), $standard);
            $this->assertSame($title, $r->json('data.title'), $standard);
            $this->assertContains($href, array_column($r->json('data.entry_points'), 'href'), $standard);
        }
        $this->assertSame('au', \App\Models\ContentPackage::where('standard', 'cmi5')->first()->entry_points[0]['type']);
        $this->assertSame('CompletedOrPassed', \App\Models\ContentPackage::where('standard', 'cmi5')->first()->entry_points[0]['move_on']);
    }

    public function test_unsafe_packages_are_refused(): void
    {
        Storage::fake('local');
        $a = $this->admin();
        $post = fn (array $files) => $this->asUser($a)->post('/api/v1/admin/packages', ['file' => $this->zip($files)], ['Accept' => 'application/json']);
        $post(['imsmanifest.xml' => self::SCORM12, '../evil.html' => 'x'])->assertStatus(422)->assertJsonPath('code', 'package_unsafe');
        $post(['imsmanifest.xml' => self::SCORM12, 'shell.php' => '<?php'])->assertStatus(422)->assertJsonPath('code', 'package_denied');
        $post(['readme.txt' => 'nothing to run'])->assertStatus(422)->assertJsonPath('code', 'package_unknown');
        $this->assertSame(0, \App\Models\ContentPackage::count());
    }

    public function test_the_proxy_serves_files_with_the_right_type_ranges_and_only_with_a_valid_token(): void
    {
        Storage::fake('local');
        $pkg = $this->asUser($this->admin())->post('/api/v1/admin/packages', ['file' => $this->zip(['index.html' => '<html>hello</html>', 'js/app.js' => '0123456789'])], ['Accept' => 'application/json'])->json('data');
        $url = PackageToken::url($pkg['id'], 'js/app.js');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url, ['Range' => 'bytes=2-4'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-4/10');
        $this->get(PackageToken::url($pkg['id']))->assertOk()->assertSee('hello');          // no path = the entry point
        $this->get('/content/bad.token/'.$pkg['id'].'/index.html')->assertForbidden();
        $this->get(str_replace($pkg['id'], '00000000-0000-0000-0000-000000000000', $url))->assertForbidden();
        $expired = '/content/'.PackageToken::make($pkg['id'], null, -10).'/'.$pkg['id'].'/index.html';
        $this->get($expired)->assertForbidden();
        $this->get('/content/'.PackageToken::make($pkg['id']).'/'.$pkg['id'].'/missing.html')->assertNotFound();
    }

    /** @return array{0: \App\Models\User, 1: CourseLesson, 2: Registration} */
    private function packageLesson(string $standard, array $files, array $settings = []): array
    {
        Storage::fake('local');
        $admin = $this->admin();
        $employee = $this->makeEmployee();
        $program = $this->makeProgram(['delivery_mode' => 'online', 'requires_evaluation' => false, 'has_course' => true]);
        $r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $module = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$program->id}/course/modules", ['title_ar' => 'و', 'title_en' => 'U'])->assertCreated()->json('data');
        $lesson = $this->asUser($admin)->postJson("/api/v1/admin/course/modules/{$module['id']}/lessons", ['type' => 'package', 'title_ar' => 'حزمة', 'title_en' => 'Package', 'status' => 'published', 'settings' => $settings])->assertCreated()->json('data');
        $pkg = $this->asUser($admin)->post('/api/v1/admin/packages', ['file' => $this->zip($files)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->asUser($admin)->putJson("/api/v1/admin/course/lessons/{$lesson['id']}/package", ['package_id' => $pkg['id']])->assertOk();

        return [$employee->user, CourseLesson::find($lesson['id']), $r];
    }

    public function test_scorm_12_commit_resume_and_completion_map_onto_the_lesson(): void
    {
        [$user, $lesson, $r] = $this->packageLesson('scorm12', ['imsmanifest.xml' => self::SCORM12, 'sco1/index.html' => '<html></html>']);
        $start = $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/scorm/start")->assertOk()->assertJsonPath('data.standard', 'scorm12');
        $this->assertStringStartsWith('/content/', $start->json('data.launch_url'));
        $id = $start->json('data.attempt_id');

        $this->asUser($user)->putJson("/api/v1/me/scorm/{$id}/commit", ['cmi' => ['core' => ['lesson_status' => 'incomplete', 'lesson_location' => 'p3', 'total_time' => '00:01:30.00'], 'suspend_data' => 'abc']])->assertOk();
        $this->assertSame('in_progress', LessonProgress::where('lesson_id', $lesson->id)->first()->status);
        // Resume: the same attempt returns the saved position and suspend data.
        $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/scorm/start")->assertOk()->assertJsonPath('data.attempt_id', $id)->assertJsonPath('data.suspend_data', 'abc')->assertJsonPath('data.location', 'p3');

        $commit = ['cmi' => ['core' => ['lesson_status' => 'passed', 'score' => ['raw' => '85', 'min' => '0', 'max' => '100']]]];
        $this->asUser($user)->putJson("/api/v1/me/scorm/{$id}/commit", $commit)->assertOk()->assertJsonPath('data.success_status', 'passed');
        $this->asUser($user)->putJson("/api/v1/me/scorm/{$id}/commit", $commit)->assertOk();             // idempotent
        $p = LessonProgress::where('lesson_id', $lesson->id)->first();
        $this->assertSame('completed', $p->status);
        $this->assertEquals(85.0, (float) $p->best_score);
        $this->assertSame(90, \App\Models\ScormAttempt::find($id)->total_time);
        $this->assertTrue(XapiStatement::where('verb', 'http://adlnet.gov/expapi/verbs/completed')->exists());
        $this->assertTrue((bool) $r->fresh()->course_completed);
    }

    public function test_scorm_2004_uses_completion_and_success_and_a_lesson_can_require_a_pass(): void
    {
        [$user, $lesson] = $this->packageLesson('scorm2004', ['imsmanifest.xml' => self::SCORM2004, 'a.html' => '<html></html>'], ['require_pass' => true]);
        $id = $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/scorm/start")->assertOk()->json('data.attempt_id');

        $this->asUser($user)->putJson("/api/v1/me/scorm/{$id}/commit", ['cmi' => ['completion_status' => 'completed', 'success_status' => 'unknown', 'score' => ['scaled' => 0.5], 'total_time' => 'PT2M5S']])->assertOk();
        $this->assertSame('in_progress', LessonProgress::where('lesson_id', $lesson->id)->first()->status);      // completed but not passed
        $this->asUser($user)->putJson("/api/v1/me/scorm/{$id}/commit", ['cmi' => ['completion_status' => 'completed', 'success_status' => 'passed', 'score' => ['scaled' => 0.9], 'total_time' => 'PT2M5S']])->assertOk();
        $this->assertSame('completed', LessonProgress::where('lesson_id', $lesson->id)->first()->status);
        $this->assertSame(125, \App\Models\ScormAttempt::find($id)->total_time);
        $this->assertEquals(90.0, (float) LessonProgress::where('lesson_id', $lesson->id)->first()->best_score);
        $other = $this->makeEmployee();
        $this->asUser($other->user)->putJson("/api/v1/me/scorm/{$id}/commit", ['cmi' => ['completion_status' => 'completed']])->assertNotFound();
    }

    private function lrs(string $key, string $secret, array $headers = []): array
    {
        return ['Authorization' => 'Basic '.base64_encode("{$key}:{$secret}"), 'X-Experience-API-Version' => '1.0.3', 'Content-Type' => 'application/json'] + $headers;
    }

    private function statement(array $over = []): array
    {
        return $over + ['actor' => ['objectType' => 'Agent', 'mbox' => 'mailto:a@example.org'], 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/experienced'], 'object' => ['id' => 'http://example.org/activity/1']];
    }

    public function test_the_lrs_validates_stores_is_idempotent_voids_and_queries(): void
    {
        $cred = app(StandardsSettings::class)->addCredential('tool', 'readwrite');
        $h = $this->lrs($cred['key'], $cred['secret']);

        $this->getJson('/api/v1/xapi/about')->assertOk()->assertJsonPath('version.0', '1.0.3');
        $this->postJson('/api/v1/xapi/statements', $this->statement())->assertStatus(401);
        $this->postJson('/api/v1/xapi/statements', $this->statement(), ['Authorization' => $h['Authorization']])->assertStatus(400);        // version header missing
        $this->postJson('/api/v1/xapi/statements', $this->statement(['verb' => ['id' => 'not an iri']]), $h)->assertStatus(400);
        $this->postJson('/api/v1/xapi/statements', $this->statement(['actor' => ['objectType' => 'Agent', 'mbox' => 'mailto:a@b.c', 'openid' => 'http://x']]), $h)->assertStatus(400);
        $this->postJson('/api/v1/xapi/statements', $this->statement(['result' => ['score' => ['scaled' => 2]]]), $h)->assertStatus(400);

        $id = '6690e6c9-3ef0-4ed3-8b37-7f3964730bee';
        $this->putJson("/api/v1/xapi/statements?statementId={$id}", $this->statement(['id' => $id]), $h)->assertStatus(204);
        $this->putJson("/api/v1/xapi/statements?statementId={$id}", $this->statement(['id' => $id]), $h)->assertStatus(204);                  // same content: fine
        $this->putJson("/api/v1/xapi/statements?statementId={$id}", $this->statement(['id' => $id, 'object' => ['id' => 'http://example.org/other']]), $h)->assertStatus(409);
        $this->postJson('/api/v1/xapi/statements', [$this->statement(['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed']]), $this->statement()], $h)->assertOk()->assertJsonCount(2);

        $this->getJson("/api/v1/xapi/statements?statementId={$id}", $h)->assertOk()->assertJsonPath('id', $id);
        $this->getJson('/api/v1/xapi/statements?verb='.urlencode('http://adlnet.gov/expapi/verbs/completed'), $h)->assertOk()->assertJsonCount(1, 'statements');
        $this->getJson('/api/v1/xapi/statements?agent='.urlencode(json_encode(['mbox' => 'mailto:a@example.org'])), $h)->assertOk()->assertJsonCount(3, 'statements');
        $this->getJson('/api/v1/xapi/statements?limit=2', $h)->assertOk()->assertJsonCount(2, 'statements')->assertJsonPath('more', fn ($m) => $m !== '');

        // Voiding hides the statement; a voiding statement cannot itself be voided.
        $void = ['actor' => ['mbox' => 'mailto:b@example.org'], 'verb' => ['id' => XapiStatementVoid::ID], 'object' => ['objectType' => 'StatementRef', 'id' => $id]];
        $voidId = $this->postJson('/api/v1/xapi/statements', $void, $h)->assertOk()->json('0');
        $this->getJson("/api/v1/xapi/statements?statementId={$id}", $h)->assertStatus(404);
        $this->getJson("/api/v1/xapi/statements?voidedStatementId={$id}", $h)->assertOk();
        $this->postJson('/api/v1/xapi/statements', ['actor' => ['mbox' => 'mailto:b@example.org'], 'verb' => ['id' => XapiStatementVoid::ID], 'object' => ['objectType' => 'StatementRef', 'id' => $voidId]], $h)->assertStatus(400);

        // A read-only credential cannot write.
        $ro = app(StandardsSettings::class)->addCredential('reader', 'read');
        $this->postJson('/api/v1/xapi/statements', $this->statement(), $this->lrs($ro['key'], $ro['secret']))->assertStatus(403);
        $this->getJson('/api/v1/xapi/statements', $this->lrs($ro['key'], $ro['secret']))->assertOk();
    }

    public function test_state_documents_round_trip(): void
    {
        $c = app(StandardsSettings::class)->addCredential('t', 'readwrite');
        $h = $this->lrs($c['key'], $c['secret']);
        $q = '?activityId='.urlencode('http://ex.org/a').'&agent='.urlencode(json_encode(['mbox' => 'mailto:a@b.c'])).'&stateId=s1';
        $this->call('PUT', '/api/v1/xapi/activities/state'.$q, [], [], [], $this->server($h), json_encode(['x' => 1]))->assertStatus(204);
        $this->call('POST', '/api/v1/xapi/activities/state'.$q, [], [], [], $this->server($h), json_encode(['y' => 2]))->assertStatus(204);
        $this->call('GET', '/api/v1/xapi/activities/state'.$q, [], [], [], $this->server($h))->assertOk()->assertJson(['x' => 1, 'y' => 2]);
        $this->call('DELETE', '/api/v1/xapi/activities/state'.$q, [], [], [], $this->server($h))->assertStatus(204);
        $this->call('GET', '/api/v1/xapi/activities/state'.$q, [], [], [], $this->server($h))->assertNotFound();
    }

    private function server(array $headers): array
    {
        $s = [];
        foreach ($headers as $k => $v) {
            $s['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $s['CONTENT_TYPE'] = 'application/json';

        return $s;
    }

    public function test_cmi5_launch_fetch_token_once_and_move_on_completes_the_lesson(): void
    {
        [$user, $lesson, $r] = $this->packageLesson('cmi5', ['cmi5.xml' => self::CMI5, 'au1/index.html' => '<html></html>']);
        $launch = $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/cmi5/launch")->assertOk();
        parse_str(parse_url($launch->json('data.launch_url'), PHP_URL_QUERY), $q);
        $this->assertSame($r->id, $q['registration']);
        $this->assertSame('http://ex.org/au1', $q['activityId']);
        $session = $launch->json('data.session_id');

        $fetch = $this->postJson("/api/v1/xapi/cmi5/fetch/{$session}")->assertOk()->json('auth-token');
        $this->postJson("/api/v1/xapi/cmi5/fetch/{$session}")->assertStatus(400);               // only once
        $h = ['Authorization' => 'Basic '.$fetch, 'X-Experience-API-Version' => '1.0.3'];
        $base = $this->statement(['actor' => json_decode($q['actor'], true), 'object' => ['id' => 'http://ex.org/au1'], 'context' => ['registration' => $r->id]]);

        $this->postJson('/api/v1/xapi/statements', array_replace($base, ['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/initialized']]), $h)->assertOk();
        $this->assertNotSame('completed', LessonProgress::where('lesson_id', $lesson->id)->first()?->status);
        $this->postJson('/api/v1/xapi/statements', array_replace($base, ['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed']]), $h)->assertOk();     // moveOn: CompletedOrPassed
        $this->assertSame('completed', LessonProgress::where('lesson_id', $lesson->id)->first()->status);
        $this->assertSame($r->id, XapiStatement::latest('stored')->first()->registration_id);
    }

    public function test_caliper_events_are_queued_in_an_envelope_and_sent_with_retry_accounting(): void
    {
        [$user, $lesson, $r] = $this->packageLesson('html5', ['index.html' => '<html></html>']);
        $svc = app(CaliperService::class);
        $svc->emit('NavigationEvent', $r, $lesson, 'NavigatedTo');
        $this->assertSame(0, CaliperEvent::count(), 'nothing is queued until Caliper is switched on');

        app(StandardsSettings::class)->update(['caliper' => ['enabled' => true, 'endpoint' => 'https://lrs.test/caliper', 'api_key' => 'k', 'sensor_id' => 'https://tedc.test/sensor']]);
        $svc->emit('NavigationEvent', $r, $lesson, 'NavigatedTo');
        $e = CaliperEvent::first()->event;
        $this->assertSame(CaliperService::CONTEXT, $e['@context']);
        $this->assertSame(['NavigationEvent', 'NavigatedTo'], [$e['type'], $e['action']]);
        $env = $svc->envelope([$e]);
        $this->assertSame(['sensor', 'sendTime', 'dataVersion', 'data'], array_keys($env));

        Http::fake(['lrs.test/*' => Http::sequence()->push('', 500)->push('', 200)]);
        $this->assertSame(['sent' => 0, 'failed' => 1], $svc->flush());
        $this->assertSame(1, CaliperEvent::first()->attempts);
        $this->assertSame(['sent' => 1, 'failed' => 0], $svc->flush());
        $this->assertSame('sent', CaliperEvent::first()->status);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer k') && $req['dataVersion'] === CaliperService::CONTEXT);
    }

    public function test_html5_completion_is_reported_by_the_player_bridge(): void
    {
        [$user, $lesson] = $this->packageLesson('html5', ['index.html' => '<html></html>']);
        $launch = $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/launch")->assertOk();
        $this->assertStringContainsString('/index.html', $launch->json('data.launch_url'));
        $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/complete", ['completed' => true, 'score' => 80])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->asUser($user)->postJson("/api/v1/me/packages/{$lesson->id}/xapi", ['statements' => [['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/answered'], 'object' => ['id' => 'http://ex.org/q1']]]])->assertCreated();
        $this->assertTrue(XapiStatement::where('verb', 'http://adlnet.gov/expapi/verbs/answered')->whereNotNull('registration_id')->exists());
    }
}

final class XapiStatementVoid
{
    public const ID = 'http://adlnet.gov/expapi/verbs/voided';
}
