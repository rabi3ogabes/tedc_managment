<?php

namespace Tests\Feature;

use App\Support\PdfFonts;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Tests\TestCase;

/**
 * UX-03: the Ministry's Lusail typeface is licensed, so its files arrive later. `tedc:fonts-check` says what is missing on the web, in PDFs
 * and in the app; once the files are dropped in, PDFs and the app use them with nothing else to change.
 */
class FontsCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tedc-fonts-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/web');
        File::ensureDirectoryExists($this->dir.'/pdf');
        File::ensureDirectoryExists($this->dir.'/app');
        File::put($this->dir.'/pubspec.yaml', "name: tedc\nflutter:\n  fonts:\n    - family: Tajawal\n      fonts:\n        - asset: assets/fonts/Tajawal-400.ttf\n          weight: 400\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function args(array $extra = []): array
    {
        return ['--web' => $this->dir.'/web', '--pdf' => $this->dir.'/pdf', '--app' => $this->dir.'/app', '--pubspec' => $this->dir.'/pubspec.yaml'] + $extra;
    }

    private function woff2(string $name): void
    {
        File::put($this->dir.'/web/'.$name, 'wOF2'.str_repeat("\0", 20_000));
    }

    private function ttf(string $where, string $name): void
    {
        File::put($this->dir.'/'.$where.'/'.$name, "\x00\x01\x00\x00".str_repeat("\0", 20_000));
    }

    public function test_it_reports_what_is_missing_everywhere_and_fails_only_when_asked_to(): void
    {
        $this->artisan('tedc:fonts-check', $this->args())
            ->expectsOutputToContain('Lusail-Regular.woff2')
            ->expectsOutputToContain('missing')
            ->assertSuccessful();

        $this->artisan('tedc:fonts-check', $this->args(['--strict' => true]))->assertFailed();
    }

    public function test_it_passes_once_every_place_has_its_files_and_the_app_declares_the_family(): void
    {
        foreach (['Regular', 'Medium', 'Bold'] as $w) {
            $this->woff2("Lusail-{$w}.woff2");
            $this->ttf('app', "Lusail-{$w}.ttf");
        }
        foreach (['Regular', 'Bold'] as $w) {
            $this->ttf('pdf', "Lusail-{$w}.ttf");
        }
        File::append($this->dir.'/pubspec.yaml', "    - family: Lusail\n      fonts:\n        - asset: assets/fonts/Lusail-Regular.ttf\n          weight: 400\n        - asset: assets/fonts/Lusail-Medium.ttf\n          weight: 500\n        - asset: assets/fonts/Lusail-Bold.ttf\n          weight: 700\n");

        $this->artisan('tedc:fonts-check', $this->args(['--strict' => true]))->assertSuccessful();
    }

    public function test_a_file_that_is_not_a_font_or_too_small_is_reported(): void
    {
        File::put($this->dir.'/web/Lusail-Regular.woff2', '<html>not a font</html>'.str_repeat(' ', 20_000));
        File::put($this->dir.'/web/Lusail-Medium.woff2', 'wOF2tiny');

        $this->artisan('tedc:fonts-check', $this->args(['--strict' => true]))
            ->expectsOutputToContain('not a WOFF2 file')
            ->expectsOutputToContain('too small')
            ->assertFailed();
    }

    public function test_the_app_gets_the_family_declared_when_its_files_are_there_and_never_twice(): void
    {
        $this->artisan('tedc:fonts-check', $this->args(['--register-app' => true]))->expectsOutputToContain('not registered')->assertSuccessful();
        $this->assertStringNotContainsString('Lusail', File::get($this->dir.'/pubspec.yaml'), 'with no files there is nothing to declare: the build would fail on a missing asset');

        foreach (['Regular', 'Medium', 'Bold'] as $w) {
            $this->ttf('app', "Lusail-{$w}.ttf");
        }
        $this->artisan('tedc:fonts-check', $this->args(['--register-app' => true]))->assertSuccessful();
        $this->artisan('tedc:fonts-check', $this->args(['--register-app' => true]))->assertSuccessful();

        $yaml = File::get($this->dir.'/pubspec.yaml');
        $this->assertSame(1, substr_count($yaml, '- family: Lusail'));
        $this->assertStringContainsString('asset: assets/fonts/Lusail-Medium.ttf', $yaml);
        $this->assertStringContainsString('- family: Tajawal', $yaml, 'the fallback family stays');
    }

    public function test_pdfs_use_the_house_font_once_its_files_are_in_the_font_folder(): void
    {
        $dejavu = base_path('vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf');
        if (! is_file($dejavu)) {
            $this->markTestSkipped('mPDF fonts are not installed');
        }
        config(['tedc.fonts.pdf' => $this->dir.'/pdf']);
        $base = ['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'tempDir' => sys_get_temp_dir().'/mpdf-test'];
        $this->assertSame($base, PdfFonts::apply($base), 'without the files nothing changes');

        File::copy($dejavu, $this->dir.'/pdf/Lusail-Regular.ttf');   // a stand-in with Arabic glyphs
        $config = PdfFonts::apply($base);
        $this->assertSame('lusail', $config['default_font']);
        $this->assertFalse($config['autoLangToFont'], 'Arabic text is not handed back to mPDF\'s own font');
        $this->assertSame('Lusail-Regular.ttf', $config['fontdata']['lusail']['R']);
        $this->assertSame('Lusail-Regular.ttf', $config['fontdata']['lusail']['B'], 'no bold file: the regular one stands in');
        $this->assertContains($this->dir.'/pdf', $config['fontDir']);
        $this->assertArrayHasKey('dejavusans', $config['fontdata'], 'the fonts mPDF ships stay available');

        $pdf = new Mpdf($config);
        $pdf->WriteHTML('<p>مرحبا بكم في TEDC — Hello</p>');
        $this->assertStringStartsWith('%PDF', $pdf->Output('', 'S'));
    }
}
