<?php

namespace Tests\Feature;

use App\Models\ContentImport;
use App\Models\CourseLesson;
use App\Models\LtiTool;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Role;
use App\Services\Assessment\QuestionBankService;
use App\Services\Assessment\QuestionTypes;
use App\Services\Content\QtiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class QtiAndCartridgeTest extends TestCase
{
    private function bank(): QuestionBank
    {
        return QuestionBank::create(['title_ar' => 'ب', 'title_en' => 'B', 'visibility' => 'center']);
    }

    private function make(QuestionBank $bank, string $type, array $payload, string $stem): Question
    {
        return app(QuestionBankService::class)->create($bank, ['type' => $type, 'stem_ar' => $stem, 'payload' => $payload, 'points' => 1], $this->makeUser(Role::TRAINER))['question'];
    }

    public function test_supported_question_types_survive_a_qti_21_round_trip(): void
    {
        $src = $this->bank();
        $originals = [
            $this->make($src, 'single_choice', ['options' => [['id' => 'a', 'text_ar' => 'ألف', 'correct' => false], ['id' => 'b', 'text_ar' => 'باء', 'correct' => true]]], 'سؤال اختيار'),
            $this->make($src, 'multiple_select', ['options' => [['id' => 'a', 'text_ar' => 'x', 'correct' => true], ['id' => 'b', 'text_ar' => 'y', 'correct' => false], ['id' => 'c', 'text_ar' => 'z', 'correct' => true]], 'partial' => true], 'سؤال متعدد'),
            $this->make($src, 'true_false', ['correct' => false], 'صح أم خطأ'),
            $this->make($src, 'short_answer', ['accepted' => ['الدوحة', 'Doha'], 'case_sensitive' => false], 'عاصمة قطر'),
            $this->make($src, 'numeric', ['value' => 3.5, 'tolerance' => 0], 'رقم'),
            $this->make($src, 'essay', ['rubric' => []], 'مقال'),
            $this->make($src, 'ordering', ['items' => [['id' => 'i1', 'text' => 'أولاً'], ['id' => 'i2', 'text' => 'ثانياً'], ['id' => 'i3', 'text' => 'ثالثاً']]], 'رتّب'),
            $this->make($src, 'matching', ['pairs' => [['id' => 'p1', 'left' => 'قطر', 'right' => 'الدوحة'], ['id' => 'p2', 'left' => 'مصر', 'right' => 'القاهرة']]], 'طابق'),
            $this->make($src, 'fill_blanks', ['text_ar' => 'عاصمة قطر {{1}} وعاصمة مصر {{2}}', 'blanks' => [['id' => 'b1', 'accepted' => ['الدوحة']], ['id' => 'b2', 'accepted' => ['القاهرة']]]], 'املأ'),
            $this->make($src, 'dropdown', ['template_ar' => 'الشمس {{1}}', 'blanks' => [['id' => 'b1', 'options' => [['id' => 'o1', 'text' => 'نجم'], ['id' => 'o2', 'text' => 'كوكب']], 'correct' => 'o1']]], 'اختر'),
        ];
        $categorization = $this->make($src, 'categorization', ['buckets' => [['id' => 'b1', 'name' => 'أ'], ['id' => 'b2', 'name' => 'ب']], 'items' => [['id' => 'i1', 'text' => 'x', 'bucket' => 'b1'], ['id' => 'i2', 'text' => 'y', 'bucket' => 'b2']]], 'تصنيف');

        $export = app(QtiService::class)->export(array_merge($originals, [$categorization]));
        $this->assertSame([['id' => $categorization->id, 'type' => 'categorization', 'reason' => 'unsupported_in_qti']], $export['skipped']);

        $dst = $this->bank();
        $r = app(QtiService::class)->import($dst, $export['zip'], $this->makeUser(Role::TRAINER));
        $this->assertSame(10, $r['created']);
        $this->assertSame([], $r['errors']);
        $this->assertSame([], $r['unsupported']);

        foreach ($originals as $o) {
            $copy = Question::where('bank_id', $dst->id)->where('type', $o->type)->first();
            $this->assertNotNull($copy, $o->type);
            $a = QuestionTypes::get($o->type)->correctAnswer($o->payload);
            $b = QuestionTypes::get($copy->type)->correctAnswer($copy->payload);
            if (in_array($o->type, ['single_choice', 'multiple_select'], true)) {
                $a = array_map(fn ($id) => $o->payload['options'][array_search($id, array_column($o->payload['options'], 'id'), true)]['text_ar'], (array) $a);
                $b = array_map(fn ($id) => $copy->payload['options'][array_search($id, array_column($copy->payload['options'], 'id'), true)]['text_ar'], (array) $b);
                sort($a);
                sort($b);
            }
            if ($o->type === 'fill_blanks' || $o->type === 'dropdown') {
                $a = array_values($a);
                $b = array_values($b);
            }
            if ($o->type === 'matching') {
                $a = array_map(fn ($p) => $p['left'].'→'.$p['right'], $o->payload['pairs']);
                $b = array_map(fn ($p) => $p['left'].'→'.$p['right'], $copy->payload['pairs']);
                sort($a);
                sort($b);
            }
            if ($o->type === 'ordering') {
                $a = array_column($o->payload['items'], 'text');
                $b = array_column($copy->payload['items'], 'text');
            }
            $this->assertEquals($a, $b, "type {$o->type} lost its answer key");
            $this->assertSame($o->stem_ar, $copy->stem_ar, $o->type);
        }
    }

    public function test_unsupported_interactions_and_qti_12_items_are_handled_and_reported(): void
    {
        $bank = $this->bank();
        $user = $this->makeUser(Role::TRAINER);
        $gap = '<?xml version="1.0"?><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="g" title="Gap"><itemBody><gapMatchInteraction responseIdentifier="R"><gapText identifier="a" matchMax="1">x</gapText></gapMatchInteraction></itemBody></assessmentItem>';
        $r = app(QtiService::class)->import($bank, $gap, $user);
        $this->assertSame(0, $r['created']);
        $this->assertSame('gapMatchInteraction', $r['unsupported'][0]['interaction']);

        $v12 = '<?xml version="1.0"?><questestinterop><assessment ident="a" title="t"><section ident="s"><item ident="i1" title="Capital"><presentation><material><mattext texttype="text/plain">Capital of Qatar?</mattext></material><response_lid ident="R" rcardinality="Single"><render_choice><response_label ident="A"><material><mattext>Doha</mattext></material></response_label><response_label ident="B"><material><mattext>Riyadh</mattext></material></response_label></render_choice></response_lid></presentation><resprocessing><respcondition><conditionvar><varequal respident="R">A</varequal></conditionvar><setvar action="Set">1</setvar></respcondition></resprocessing></item></section></assessment></questestinterop>';
        $r = app(QtiService::class)->import($bank, $v12, $user);
        $this->assertSame(1, $r['created']);
        $q = Question::where('bank_id', $bank->id)->first();
        $this->assertSame('single_choice', $q->type);
        $this->assertSame('Capital of Qatar?', $q->stem_ar);
        $this->assertTrue(collect($q->payload['options'])->firstWhere('id', 'A')['correct']);

        $this->assertSame(0, app(QtiService::class)->import($bank, '<not xml', $user)['created']);
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="x" title="&e;"><itemBody><extendedTextInteraction responseIdentifier="R"><prompt>&e;</prompt></extendedTextInteraction></itemBody></assessmentItem>';
        app(QtiService::class)->import($bank, $xxe, $user);
        $this->assertSame(0, Question::where('stem_ar', 'like', '%root:%')->count(), 'external entities must never be read');
    }

    public function test_qti_endpoints_import_and_export_banks(): void
    {
        $trainer = $this->makeUser(Role::TRAINER);
        $bank = $this->bank();
        $this->make($bank, 'true_false', ['correct' => true], 'سؤال');
        $zip = $this->asUser($trainer)->get("/api/v1/admin/question-banks/{$bank->id}/qti/export")->assertOk()->assertHeader('Content-Type', 'application/zip')->getContent();
        $path = tempnam(sys_get_temp_dir(), 'q').'.zip';
        file_put_contents($path, $zip);
        $other = $this->bank();
        $this->asUser($trainer)->post("/api/v1/admin/question-banks/{$other->id}/qti/import", ['file' => new UploadedFile($path, 'q.zip', 'application/zip', null, true)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.created', 1);
        $this->assertSame(1, Question::where('bank_id', $other->id)->count());
    }

    private function cartridge(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cc').'.zip';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('imsmanifest.xml', '<?xml version="1.0"?><manifest identifier="cc" xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imscp_v1p1"><metadata><schema>IMS Common Cartridge</schema><schemaversion>1.1.0</schemaversion></metadata><organizations><organization identifier="org" structure="rooted-hierarchy"><item identifier="root"><title>Course X</title>'
            .'<item identifier="m1"><title>Module 1</title><item identifier="i1" identifierref="r1"><title>Reading</title></item><item identifier="i2" identifierref="r2"><title>Handout</title></item><item identifier="i3" identifierref="r3"><title>Useful site</title></item></item>'
            .'<item identifier="i4" identifierref="r4"><title>Quiz</title></item><item identifier="i5" identifierref="r5"><title>Simulation</title></item><item identifier="i6" identifierref="r6"><title>Chat</title></item></item></organization></organizations>'
            .'<resources><resource identifier="r1" type="webcontent" href="web/read.html"><file href="web/read.html"/></resource><resource identifier="r2" type="webcontent" href="files/handout.pdf"><file href="files/handout.pdf"/></resource><resource identifier="r3" type="imswl_xmlv1p1" href="links/site.xml"><file href="links/site.xml"/></resource>'
            .'<resource identifier="r4" type="imsqti_xmlv2p1" href="qti/q1.xml"><file href="qti/q1.xml"/></resource><resource identifier="r5" type="imsbasiclti_xmlv1p0" href="lti/sim.xml"><file href="lti/sim.xml"/></resource><resource identifier="r6" type="imsdt_xmlv1p1" href="dt/chat.xml"><file href="dt/chat.xml"/></resource></resources></manifest>');
        $z->addFromString('web/read.html', '<html><head><script>alert(1)</script></head><body><h1>Intro</h1><p onclick="x()">Text <a href="javascript:evil()">link</a></p><script>steal()</script><iframe src="http://evil.test"></iframe></body></html>');
        $z->addFromString('files/handout.pdf', '%PDF-1.4 fake');
        $z->addFromString('links/site.xml', '<wl xmlns="http://www.imsglobal.org/xsd/imsccv1p1/imswl_v1p1"><title>Useful site</title><url href="https://example.org/useful"/></wl>');
        $z->addFromString('qti/q1.xml', '<assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" identifier="q1" title="Q"><responseDeclaration identifier="R" cardinality="single" baseType="identifier"><correctResponse><value>a</value></correctResponse></responseDeclaration><itemBody><choiceInteraction responseIdentifier="R" maxChoices="1"><prompt>Pick</prompt><simpleChoice identifier="a">Yes</simpleChoice><simpleChoice identifier="b">No</simpleChoice></choiceInteraction></itemBody></assessmentItem>');
        $z->addFromString('lti/sim.xml', '<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"><title>Physics simulation</title><launch_url>https://sim.test/launch</launch_url></cartridge_basiclti_link>');
        $z->addFromString('dt/chat.xml', '<topic><title>Chat</title></topic>');
        $z->close();

        return new UploadedFile($path, 'cc.imscc.zip', 'application/zip', null, true);
    }

    public function test_a_common_cartridge_is_previewed_and_imported_in_full_or_in_part(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();
        $pkg = $this->asUser($admin)->post('/api/v1/admin/packages', ['file' => $this->cartridge()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.standard', 'cc')->json('data');

        $tree = $this->asUser($admin)->getJson("/api/v1/admin/packages/{$pkg['id']}/cc/preview")->assertOk()->json('data.tree');
        $becomes = collect($tree)->pluck('becomes', 'title');
        $this->assertEquals(['Reading' => 'lesson', 'Handout' => 'material', 'Useful site' => 'link', 'Quiz' => 'bank', 'Simulation' => 'lti', 'Chat' => 'discussion', 'Module 1' => 'folder'], $becomes->only(['Reading', 'Handout', 'Useful site', 'Quiz', 'Simulation', 'Chat', 'Module 1'])->all());

        // Selective import: only the module (its children come with it) and the quiz.
        $part = $this->asUser($admin)->postJson("/api/v1/admin/packages/{$pkg['id']}/cc/import", ['program_id' => $program->id, 'item_ids' => ['m1', 'i4']])->assertCreated();
        $this->assertSame([1, 1, 1, 1, 0], [$part->json('data.lessons'), $part->json('data.materials'), $part->json('data.links'), $part->json('data.questions'), $part->json('data.lti')]);
        $this->assertSame(0, LtiTool::count());

        $full = $this->asUser($admin)->postJson("/api/v1/admin/packages/{$pkg['id']}/cc/import", ['program_id' => $program->id])->assertCreated();
        $this->assertSame(1, $full->json('data.lti'));
        $this->assertSame('discussion_forums_arrive_with_phase_14', collect($full->json('data.skipped'))->firstWhere('item', 'Chat')['reason']);
        $this->assertFalse(LtiTool::first()->is_active);
        $this->assertSame('https://sim.test/launch', LtiTool::first()->launch_url);
        $this->assertSame(2, Material::where('program_id', $program->id)->count());
        $this->assertSame(2, ContentImport::where('kind', 'cc')->count());

        // Pages are cleaned: scripts, frames, handlers and javascript: links are gone.
        $page = CourseLesson::where('title_en', 'Reading')->first()->body_ar;
        $this->assertStringContainsString('<h1>Intro</h1>', $page);
        foreach (['<script', 'iframe', 'onclick', 'javascript:', 'alert(', 'steal('] as $bad) {
            $this->assertStringNotContainsString($bad, $page);
        }
    }
}
