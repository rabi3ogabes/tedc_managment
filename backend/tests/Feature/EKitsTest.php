<?php

namespace Tests\Feature;

use App\Learning\EKits\EKitBuilder;
use App\Learning\EKits\EKitSource;
use App\Learning\EKits\ScormExporter;
use App\Models\Assessment;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Registration;
use App\Models\Role;
use App\Services\Content\ManifestParser;
use App\Services\CourseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class EKitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProgramCategory::create(['name_ar' => 'عام', 'name_en' => 'General', 'slug' => 'general']);
    }

    public function test_the_sources_are_complete_in_both_languages(): void
    {
        $kits = EKitSource::all();
        $this->assertCount(2, $kits);                                              // the RFP asks for two kits
        foreach ($kits as $kit) {
            $this->assertGreaterThanOrEqual(4, count($kit['chapters']));
            $this->assertGreaterThanOrEqual(5, count($kit['final']));
            foreach ($kit['chapters'] as $c) {
                $this->assertNotEmpty($c['check'], 'a knowledge check per chapter');
            }
        }
        $bad = $kits[0];
        unset($bad['chapters'][0]['title_en']);
        $this->expectException(RuntimeException::class);
        EKitSource::validate($bad);
    }

    public function test_a_question_with_a_wrong_correct_index_is_rejected(): void
    {
        $kit = EKitSource::all()[0];
        $kit['final'][0]['correct'] = 9;
        $this->expectExceptionMessage('valid correct answer');
        EKitSource::validate($kit);
    }

    public function test_html_becomes_the_plain_text_the_portal_shows(): void
    {
        $text = EKitSource::plain('<h3>Title</h3><p>One &amp; two</p><ul><li>a</li><li>b</li></ul>');
        $this->assertSame("# Title\n\nOne & two\n\n- a\n- b", $text);
    }

    public function test_building_publishes_an_e_course_once_and_rebuild_replaces_it(): void
    {
        $kit = EKitSource::all()[0];
        $first = app(EKitBuilder::class)->build($kit);
        $this->assertTrue($first['created']);
        $p = $first['program'];
        $this->assertTrue((bool) $p->has_course);
        $this->assertSame(count($kit['chapters']) + 1, $p->courseModules()->count());
        $this->assertSame(count($kit['chapters']) * 2 + 1, $p->courseLessons()->count());      // article + check per chapter, then the final
        $this->assertSame(count($kit['final']), Question::whereIn('bank_id', QuestionBank::where('program_id', $p->id)->pluck('id'))->count());

        $a = Assessment::where('program_id', $p->id)->first();
        $this->assertNotNull($a->lesson_id);
        $this->assertSame('random', $a->sections()->first()->selection);                       // drawn from the bank, a retake is a different paper

        $again = app(EKitBuilder::class)->build($kit);
        $this->assertFalse($again['created']);
        $this->assertSame(count($kit['chapters']) * 2 + 1, $p->courseLessons()->count());
        $this->assertTrue(app(EKitBuilder::class)->build($kit, true)['created']);
        $this->assertSame(count($kit['chapters']) * 2 + 1, $p->courseLessons()->count());
        $this->assertSame(1, Assessment::where('program_id', $p->id)->count());
    }

    public function test_a_trainee_completes_a_published_e_kit_end_to_end_and_gets_the_certificate(): void
    {
        $kit = EKitSource::all()[0];
        $program = app(EKitBuilder::class)->build($kit)['program'];
        $employee = $this->makeEmployee();
        $user = $employee->user;
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => Registration::SOURCE_CENTER, 'status' => Registration::STATUS_APPROVED, 'approved_at' => now()]);
        $this->asUser($user)->getJson("/api/v1/me/registrations/{$registration->id}/course")->assertOk()->assertJsonCount(count($kit['chapters']) + 1, 'data.modules');

        foreach ($program->courseLessons()->where('status', 'published')->orderBy('sort_order')->get() as $lesson) {
            if ($lesson->type === 'quiz') {
                $answers = $lesson->questions->mapWithKeys(fn ($q) => [$q->id => collect($q->options)->where('correct', true)->pluck('id')->all()])->all();
                $this->asUser($user)->postJson("/api/v1/me/lessons/{$lesson->id}/quiz", ['answers' => $answers])->assertOk()->assertJsonPath('data.passed', true);
            } else {
                $position = 0.0;
                $done = [];
                for ($i = 0; $i < 8 && ! ($done['completed'] ?? false); $i++) {
                    $this->travel(20)->seconds();
                    $done = app(CourseService::class)->heartbeat($lesson, $registration->fresh(), $position, $position + 20);
                    $position = $done['position'];
                }
            }
        }
        $registration->refresh();
        $this->assertTrue($registration->course_completed);
        $this->assertSame('issued', $registration->certificate_status);
    }

    public function test_the_scorm_package_is_a_valid_2004_package_in_each_language(): void
    {
        $kit = EKitSource::all()[1];
        foreach (['ar', 'en'] as $lang) {
            $bytes = app(ScormExporter::class)->export($kit, $lang);
            $file = tempnam(sys_get_temp_dir(), 'z');
            file_put_contents($file, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($file));
            $manifest = (string) $zip->getFromName('imsmanifest.xml');
            $this->assertNotSame('', $manifest);
            $this->assertNotFalse(simplexml_load_string($manifest), 'the manifest is well-formed XML');
            $this->assertSame('scorm2004', ManifestParser::kind($manifest));
            $parsed = ManifestParser::ims($manifest, 'scorm2004');
            $this->assertSame($kit['title_'.$lang], $parsed['title']);
            $this->assertSame('2004 4th Edition', $parsed['version']);
            $this->assertCount(count($kit['chapters']) + 1, $parsed['entry_points']);
            foreach ($parsed['entry_points'] as $item) {
                $this->assertNotNull($zip->getFromName($item['href']), $item['href'].' is in the package');
                $this->assertSame('sco', $item['type']);
            }
            foreach (['shared/scorm.js', 'shared/style.css'] as $f) {
                $this->assertNotFalse($zip->getFromName($f), $f);
            }
            $final = (string) $zip->getFromName('final.html');
            $this->assertStringContainsString('lang="'.$lang.'"', $final);
            $this->assertStringContainsString($lang === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $final);
            $this->assertStringContainsString('cmi.score.scaled', $final);
            $this->assertStringContainsString('cmi.success_status', $final);
            $this->assertStringContainsString('class="skip"', $final);                     // keyboard users can skip to the content
            $this->assertStringContainsString('aria-live', $final);
            $this->assertStringNotContainsString('<script>alert', $final);
            $zip->close();
            @unlink($file);
        }
    }

    public function test_the_command_builds_and_exports_and_the_admin_can_download(): void
    {
        $dir = sys_get_temp_dir().'/ekits-'.uniqid();
        $this->assertSame(0, Artisan::call('tedc:ekits-build', ['--export' => $dir]));
        $this->assertSame(2, Program::whereIn('code', ['EKIT-PORTAL', 'EKIT-QUESTIONING'])->count());
        $this->assertCount(4, File::files($dir));
        File::deleteDirectory($dir);
        $this->assertSame(1, Artisan::call('tedc:ekits-build', ['--only' => 'NOPE']));

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $list = $this->asUser($admin)->getJson('/api/v1/admin/ekits')->assertOk()->json('data');
        $this->assertSame([true, true], array_column($list, 'published'));
        $r = $this->asUser($admin)->get('/api/v1/admin/ekits/EKIT-PORTAL/scorm?lang=en')->assertOk();
        $this->assertStringStartsWith('PK', $r->getContent());
        $this->asUser($admin)->getJson('/api/v1/admin/ekits/NOPE/scorm')->assertStatus(422);
        $this->asUser($admin)->postJson('/api/v1/admin/ekits/EKIT-PORTAL/build')->assertOk()->assertJsonPath('data.created', false);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/ekits')->assertForbidden();
    }
}
