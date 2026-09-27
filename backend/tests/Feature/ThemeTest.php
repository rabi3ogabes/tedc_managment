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
            ->assertJsonPath('data.colors.primary', '#0B1F3A')
            ->assertJsonPath('data.pattern.type', 'dots');
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

        $this->asUser($admin)->postJson('/api/v1/admin/theme/reset')->assertOk()->assertJsonPath('data.colors.primary', '#0B1F3A');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => 'theme']);
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
