<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\ThemeService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The Brand Studio font library (docs/FONTS.md): uploaded families with every weight, used by the website and the mobile app. */
class FontLibraryTest extends TestCase
{
    private function lusail(): array
    {
        $files = [];
        foreach ([300 => 'Light', 400 => 'Regular', 500 => 'Medium', 700 => 'Bold'] as $weight => $name) {
            $files[] = ['weight' => $weight, 'style' => 'normal', 'format' => 'woff2', 'url' => "https://cdn.example.qa/theme/font/Lusail-$name.woff2"];
            $files[] = ['weight' => $weight, 'style' => 'normal', 'format' => 'otf', 'url' => "https://cdn.example.qa/theme/font/Lusail-$name.otf"];
        }

        return ['id' => 'lusail', 'family' => 'Lusail', 'files' => $files];
    }

    private function save(array $typography): TestResponse
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $theme = ThemeService::defaults();
        $theme['typography'] = array_replace($theme['typography'], $typography);

        return $this->asUser($admin)->putJson('/api/v1/admin/theme', $theme);
    }

    public function test_lusail_is_the_main_font_by_default(): void
    {
        $this->getJson('/api/v1/public/theme')->assertOk()
            ->assertJsonPath('data.typography.arabic_family', 'Lusail')
            ->assertJsonPath('data.typography.latin_family', 'Lusail')
            ->assertJsonPath('data.typography.display_family', null)
            ->assertJsonPath('data.typography.library', []);
    }

    public function test_an_uploaded_family_reaches_the_website_and_the_app_with_every_weight(): void
    {
        $family = $this->lusail();
        // Noise the library must drop: a script address, an in-between weight, and the same family twice.
        $family['files'][] = ['weight' => 400, 'format' => 'woff2', 'url' => 'javascript:alert(1)'];
        $family['files'][] = ['weight' => 450, 'format' => 'woff', 'url' => 'https://cdn.example.qa/Lusail-Book.woff'];

        $this->save([
            'arabic_family' => 'Lusail', 'latin_family' => 'Lusail', 'display_family' => 'Lusail',
            'library' => [$family, ['id' => 'again', 'family' => 'lusail', 'files' => [['weight' => 400, 'format' => 'ttf', 'url' => 'https://cdn.example.qa/l.ttf']]]],
        ])->assertOk();

        $library = $this->getJson('/api/v1/public/theme')->assertOk()->json('data.typography.library');
        $this->assertCount(1, $library);
        $this->assertSame('Lusail', $library[0]['family']);
        $this->assertCount(9, $library[0]['files']);          // 4 weights × (woff2 + otf) + the 450 → 500 woff
        $this->assertNotContains('javascript:alert(1)', array_column($library[0]['files'], 'url'));
        $this->assertContains(['weight' => 500, 'style' => 'normal', 'format' => 'woff', 'url' => 'https://cdn.example.qa/Lusail-Book.woff'], $library[0]['files']);

        // The app gets only the files it can register (OpenType), one per weight.
        $files = $this->getJson('/api/v1/public/mobile-config')->assertOk()->json('data.typography.arabic_files');
        $this->assertSame([300, 400, 500, 700], array_column($files, 'weight'));
        $this->assertTrue(collect($files)->every(fn ($f) => str_ends_with($f['url'], '.otf')));
    }

    public function test_saving_without_the_library_keeps_it_and_an_unsafe_name_is_refused(): void
    {
        $this->save(['library' => [$this->lusail()]])->assertOk();
        $theme = ThemeService::defaults();
        unset($theme['typography']['library']);
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->putJson('/api/v1/admin/theme', $theme)->assertOk();
        $this->assertCount(1, $this->getJson('/api/v1/public/theme')->json('data.typography.library'));

        $bad = $this->lusail();
        $bad['family'] = 'X"; } body { display:none';
        $this->save(['library' => [$bad]])->assertStatus(422);
        $this->save(['display_family' => '</style>'])->assertStatus(422);
        $eot = $this->lusail();
        $eot['files'][] = ['weight' => 400, 'format' => 'eot', 'url' => 'https://cdn.example.qa/x.eot'];
        $this->save(['library' => [$eot]])->assertStatus(422);
    }

    public function test_only_administrators_change_the_fonts(): void
    {
        $theme = ThemeService::defaults();
        $theme['typography']['library'] = [$this->lusail()];
        $this->asUser($this->makeUser(Role::EMPLOYEE))->putJson('/api/v1/admin/theme', $theme)->assertForbidden();
    }
}
