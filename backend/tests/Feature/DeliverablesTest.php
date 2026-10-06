<?php

namespace Tests\Feature;

use App\Deliverables\DeliverablesBuilder;
use App\Services\RfpRegister;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class DeliverablesTest extends TestCase
{
    private function data(): array
    {
        return app(RfpRegister::class)->build(base_path('../docs/rfp/gap-register.md'));
    }

    public function test_the_brd_has_a_section_per_module_and_every_requirement(): void
    {
        $data = $this->data();
        $brd = app(DeliverablesBuilder::class)->brd($data);
        $this->assertStringContainsString("**{$data['totals']['total']} requirements**", $brd);
        foreach ($data['modules'] as $m) {
            $this->assertStringContainsString("({$m['code']})", $brd);
            foreach ($m['items'] as $i) {
                $this->assertStringContainsString('| '.$i['id'], $brd, $i['id']);
            }
        }
        $this->assertStringContainsString('Approval', $brd);
        $this->assertStringContainsString('Open items', $brd);
    }

    public function test_the_traceability_matrix_links_requirements_to_the_tests_of_their_phase(): void
    {
        $b = app(DeliverablesBuilder::class);
        $map = $b->phaseOfIds("## Open gaps by phase\n\n### Phase 01 — Roles (2)\n\n- [x] **UX-04** — x\n- [ ] **RBA-03** — y\n\n### Phase 02 — Z (1)\n\n- [x] **GRP-01** — z\n\n## Full register\n\n- [x] **ABC-01**\n");
        $this->assertSame(['UX-04' => 1, 'RBA-03' => 1, 'GRP-01' => 2], $map);

        $matrix = $b->traceability($this->data());
        $this->assertGreaterThan(100, substr_count($matrix, 'Test.php'));
        $this->assertStringContainsString('requirements link to at least one automated test file', $matrix);
    }

    public function test_the_test_report_is_honest_about_what_was_not_run(): void
    {
        $r = app(DeliverablesBuilder::class)->testReport($this->data(), null);
        $this->assertStringContainsString('No run was attached', $r);
        $this->assertStringContainsString('UAT has not been executed', $r);
        $this->assertStringContainsString('no load test has been run', $r);
        $with = app(DeliverablesBuilder::class)->testReport($this->data(), ['tests' => 10, 'passed' => 9, 'assertions' => 40, 'failed' => 1]);
        $this->assertStringContainsString('9 of 10 passed', $with);
    }

    public function test_markdown_exports_to_word_and_pdf_with_arabic_and_tables(): void
    {
        $md = "# عنوان · Title\n\nفقرة عربية.\n\nAn English paragraph with **bold** and `code`.\n\n- بند ١\n- Item 2\n\n| ID | البيان |\n|---|---|\n| A-1 | قيمة \\| with pipe |\n";
        $b = app(DeliverablesBuilder::class);
        $docx = $b->docx($md);
        $file = tempnam(sys_get_temp_dir(), 'd');
        file_put_contents($file, $docx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $xml = (string) $zip->getFromName('word/document.xml');
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('فقرة عربية', $xml);
        $this->assertStringContainsString('<w:tbl>', $xml);
        $this->assertStringContainsString('with pipe', $xml);
        $this->assertStringNotContainsString('**', $xml);
        $this->assertStringContainsString('<w:bidi/>', $xml);                 // Arabic paragraphs run right to left
        $this->assertStringContainsString('An English paragraph', $xml);
        $zip->close();
        @unlink($file);
        $this->assertStringStartsWith('%PDF', $b->pdf($md, 'Test'));
    }

    public function test_the_command_writes_the_generated_documents_and_the_exports(): void
    {
        $out = sys_get_temp_dir().'/dlv-'.uniqid();
        $this->assertSame(0, Artisan::call('tedc:deliverables', ['--format' => 'all', '--out' => $out]));
        foreach (['01-as-is-to-be', '02-needs-scope-plan', '03-ux-and-architecture', '04-release-management', '05-training-adoption-plan', '06-test-plan', '07-go-live-handover-sla', 'brd.generated', 'traceability.generated', 'test-report.generated', 'compliance-sheet'] as $name) {
            $this->assertFileExists("{$out}/{$name}.docx");
            $this->assertStringStartsWith('%PDF', (string) file_get_contents("{$out}/{$name}.pdf"));
        }
        File::deleteDirectory($out);
        $this->assertSame(1, Artisan::call('tedc:deliverables', ['--format' => 'rtf']));
        $bad = tempnam(sys_get_temp_dir(), 'r');
        file_put_contents($bad, '{"nope":1}');
        $this->assertSame(1, Artisan::call('tedc:deliverables', ['--results' => $bad]));
        @unlink($bad);
    }

    public function test_every_deliverable_document_has_arabic_and_english_parts_a_version_table_and_an_approval_block(): void
    {
        foreach (glob(base_path('../docs/deliverables/0*.md')) as $f) {
            $md = (string) file_get_contents($f);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $md, basename($f).' has Arabic');
            $this->assertMatchesRegularExpression('/[A-Za-z]{4,} [A-Za-z]{4,}/', $md, basename($f).' has English');
            $this->assertMatchesRegularExpression('/Version|الإصدار/u', $md, basename($f).' version table');
            $this->assertMatchesRegularExpression('/Approval|الاعتماد|Signature|التوقيع/u', $md, basename($f).' approval block');
        }
    }
}
