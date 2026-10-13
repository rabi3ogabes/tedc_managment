<?php

namespace Tests\Feature;

use App\Models\Forecast;
use App\Models\RiskFlag;
use App\Models\Role;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/** RPT-11: forecasts and risk lists export to Excel, PDF and Word like every other report (bilingual, right to left). */
class ForecastExportTest extends TestCase
{
    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = $this->makeUser(Role::PLANNING_HEAD);
        Forecast::create(['dimension' => 'competency', 'label' => 'التقويم التكويني', 'year' => 2027, 'value' => 380, 'low' => 320, 'high' => 440, 'model' => 'ets',
            'explanation' => 'اتجاه صاعد خلال ثلاث سنوات', 'history' => ['years' => [2024, 2025, 2026], 'values' => [220, 290, 340], 'signals' => []]]);
        Forecast::create(['dimension' => 'school', 'label' => 'مدرسة الوكرة', 'year' => 2027, 'value' => 40, 'low' => 30, 'high' => 55, 'model' => 'naive',
            'explanation' => 'طلبات ثابتة', 'history' => ['years' => [2026], 'values' => [41], 'signals' => []]]);
        RiskFlag::create(['type' => 'group_underfill', 'subject_type' => 'group', 'subject_id' => '01a0f178-0000-7000-8000-000000000001', 'label' => 'مجموعة القيادة G1', 'score' => 0.82, 'reasons' => ['المقاعد المشغولة 20%', 'البداية بعد 5 أيام']]);
    }

    /** @return list<string> every non-empty cell of every sheet */
    private function cells(string $bytes, ?bool &$rtl = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'f').'.xlsx';
        file_put_contents($path, $bytes);
        $book = IOFactory::load($path);
        @unlink($path);
        $rtl = $book->getSheet(0)->getRightToLeft();
        $out = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                foreach ($row as $cell) {
                    if ($cell !== null && $cell !== '') {
                        $out[] = (string) $cell;
                    }
                }
            }
        }

        return $out;
    }

    public function test_forecasts_export_to_excel_in_arabic_right_to_left_with_the_explanations(): void
    {
        $res = $this->asUser($this->planner)->get('/api/v1/admin/forecasts/export/xlsx?year=2027&lang=ar')->assertOk();

        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $cells = $this->cells($res->getContent(), $rtl);
        $this->assertTrue($rtl);
        $this->assertTrue(collect($cells)->contains(fn ($c) => str_contains($c, 'توقعات') && str_contains($c, '2027')));
        $this->assertContains('التفسير', $cells);
        $this->assertContains('اتجاه صاعد خلال ثلاث سنوات', $cells);
        $this->assertContains('التقويم التكويني', $cells);
    }

    public function test_forecasts_export_to_pdf_and_word(): void
    {
        $pdf = $this->asUser($this->planner)->get('/api/v1/admin/forecasts/export/pdf?year=2027')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $docx = $this->asUser($this->planner)->get('/api/v1/admin/forecasts/export/docx?year=2027&lang=ar')->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'd');
        file_put_contents($path, $docx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('<w:bidi', $xml);
        $this->assertStringContainsString('اتجاه صاعد خلال ثلاث سنوات', $xml);
    }

    public function test_risks_export_with_their_reasons_and_english_works_too(): void
    {
        $cells = $this->cells($this->asUser($this->planner)->get('/api/v1/admin/risks/export/xlsx?lang=en')->assertOk()->getContent(), $rtl);

        $this->assertFalse($rtl);
        $this->assertContains('Reasons', $cells);
        $this->assertTrue(collect($cells)->contains(fn ($c) => str_contains($c, 'المقاعد المشغولة 20%')));
        $this->assertContains('مجموعة القيادة G1', $cells);
    }

    public function test_the_permission_and_the_format_are_enforced(): void
    {
        $this->asUser($this->makeUser(Role::EMPLOYEE))->get('/api/v1/admin/forecasts/export/xlsx')->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->get('/api/v1/admin/risks/export/pdf')->assertForbidden();
        $this->asUser($this->planner)->get('/api/v1/admin/forecasts/export/exe')->assertNotFound();
    }
}
