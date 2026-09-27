<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\ThemeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive(ThemeService::defaults(), $overrides);
    }

    public function test_public_theme_returns_defaults(): void
    {
        $this->getJson('/api/v1/public/theme')->assertOk()
            ->assertJsonPath('data.preset', 'qatar_gov')
            ->assertJsonPath('data.colors.primary', '#8A1538')   // Al Adaam
            ->assertJsonPath('data.colors.accent', '#A29475')    // Dune
            ->assertJsonPath('data.typography.arabic_family', 'Qatar Sans')
            ->assertJsonPath('data.pattern.type', 'serrated');
    }

    public function test_admin_updates_and_resets_theme(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        $this->asUser($admin)->putJson('/api/v1/admin/theme', $this->payload([
            'preset' => 'qatar_maroon',
            'colors' => ['primary' => '#5A0F2E', 'accent' => '#D4AF37'],
            'pattern' => ['type' => 'islamic_star', 'opacity' => 30],
            'banners' => ['hero_images' => ['https://cdn.example.qa/doha.jpg']],
        ]))->assertOk()->assertJsonPath('data.colors.primary', '#5A0F2E');

        $this->getJson('/api/v1/public/theme')
            ->assertJsonPath('data.preset', 'qatar_maroon')
            ->assertJsonPath('data.pattern.type', 'islamic_star')
            ->assertJsonPath('data.banners.hero_images.0', 'https://cdn.example.qa/doha.jpg')
            ->assertJsonCount(4, 'data.banners.hero_images');

        $this->asUser($admin)->postJson('/api/v1/admin/theme/reset')->assertOk()->assertJsonPath('data.colors.primary', '#8A1538');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => 'theme']);
    }

    public function test_identity_logos_and_fonts_are_saved(): void
    {
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/theme', $this->payload([
            'identity' => ['logo_ar' => 'https://cdn.example.qa/moehe-ar.png', 'logo_en' => 'https://cdn.example.qa/moehe-en.png'],
            'typography' => ['arabic_family' => 'Qatar Sans', 'latin_family' => 'Qatar Sans', 'arabic_font_url' => 'https://cdn.example.qa/QatarSans.woff2'],
        ]))->assertOk();

        $this->getJson('/api/v1/public/theme')
            ->assertJsonPath('data.identity.logo_ar', 'https://cdn.example.qa/moehe-ar.png')
            ->assertJsonPath('data.typography.arabic_font_url', 'https://cdn.example.qa/QatarSans.woff2');
    }

    public function test_font_family_names_cannot_inject_css(): void
    {
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/theme', $this->payload([
            'typography' => ['arabic_family' => "X'; } body { display:none", 'latin_family' => 'Qatar Sans'],
        ]))->assertStatus(422)->assertJsonValidationErrors('typography.arabic_family');
    }

    public function test_font_and_svg_upload_rules(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($admin)->post('/api/v1/admin/theme/assets', ['file' => UploadedFile::fake()->create('QatarSans-Regular.woff2', 40, 'font/woff2'), 'kind' => 'font'], ['Accept' => 'application/json'])
            ->assertCreated();
        // SVG can carry scripts: not accepted for public assets.
        $this->asUser($admin)->post('/api/v1/admin/theme/assets', ['file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'), 'kind' => 'logo'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_theme_validation_rejects_invalid_colors(): void
    {
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))
            ->putJson('/api/v1/admin/theme', $this->payload(['colors' => ['primary' => 'red; background:url(x)']]))
            ->assertStatus(422)->assertJsonValidationErrors('colors.primary');
    }

    public function test_only_authorized_roles_manage_theme(): void
    {
        $this->asUser($this->makeUser(Role::COORDINATOR))->putJson('/api/v1/admin/theme', $this->payload())->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/theme/reset')->assertForbidden();
    }

    public function test_banner_upload_returns_public_url(): void
    {
        Storage::fake('public');

        $url = $this->asUser($this->makeUser(Role::SUPER_ADMIN))
            ->post('/api/v1/admin/theme/assets', ['file' => UploadedFile::fake()->image('hero.jpg', 1600, 900), 'kind' => 'hero'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.url');

        $this->assertStringContainsString('theme/hero/', $url);
    }
}
