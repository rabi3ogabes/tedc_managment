<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SiteSetting;
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
        $audit = AuditLog::where('auditable_type', (new SiteSetting)->getMorphClass())->latest()->first();
        $this->assertNotNull($audit);
        $this->assertNull($audit->auditable_id);
        $this->assertSame('theme', $audit->new_values['key'] ?? null);
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

    public function test_an_uploaded_image_becomes_the_background_pattern_with_tint_and_repeat(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $png = UploadedFile::fake()->image('tile.png', 120, 120);
        $url = $this->asUser($admin)->post('/api/v1/admin/theme/assets', ['kind' => 'pattern', 'file' => $png], ['Accept' => 'application/json'])->assertCreated()->json('data.url');

        $this->asUser($admin)->putJson('/api/v1/admin/theme', $this->payload(['pattern' => ['type' => 'custom', 'image' => $url, 'opacity' => 35, 'size' => 80, 'tint' => '#8a1538', 'repeat' => 'cover']]))->assertOk();
        $this->getJson('/api/v1/public/theme')->assertJsonPath('data.pattern.type', 'custom')->assertJsonPath('data.pattern.tint', '#8a1538')->assertJsonPath('data.pattern.repeat', 'cover');
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $this->payload(['pattern' => ['repeat' => 'spiral']]))->assertStatus(422);
    }

    public function test_svg_patterns_are_accepted_only_when_they_are_clean(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $svg = fn (string $body) => UploadedFile::fake()->createWithContent('p.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20">'.$body.'</svg>');
        $send = fn ($file) => $this->asUser($admin)->post('/api/v1/admin/theme/assets', ['kind' => 'pattern', 'file' => $file], ['Accept' => 'application/json']);

        $send($svg('<circle cx="10" cy="10" r="4" fill="#000"/>'))->assertCreated();
        $send($svg('<script>alert(1)</script>'))->assertStatus(422)->assertJsonPath('code', 'unsafe_svg');
        $send($svg('<circle cx="1" cy="1" r="1" onload="x()"/>'))->assertStatus(422);
        $send($svg('<image href="https://evil.example/x.png"/>'))->assertStatus(422);
    }
}
