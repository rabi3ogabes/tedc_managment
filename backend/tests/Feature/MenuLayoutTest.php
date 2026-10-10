<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MenuLayoutTest extends TestCase
{
    private function layout(): array
    {
        return [
            'sections' => [
                ['id' => 'lifecycle', 'title_ar' => 'التدريب', 'title_en' => 'Training', 'items' => ['/admin/kits']],
                ['id' => 'c_main', 'title_ar' => 'برامجي', 'title_en' => 'Programs', 'items' => ['/admin/programs', '/admin/kits', '/admin/programs']],
            ],
            'icons' => ['/admin/programs' => 'Rocket'],
        ];
    }

    public function test_the_arrangement_is_saved_cleaned_and_read_by_any_signed_in_user(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->getJson('/api/v1/me/menu-layout')->assertOk()->assertJsonPath('data.sections', []);

        $res = $this->asUser($admin)->putJson('/api/v1/admin/menu-layout', $this->layout())->assertOk();
        // A button sits in one section: the first one that lists it keeps it.
        $res->assertJsonPath('data.sections.0.items', ['/admin/kits'])->assertJsonPath('data.sections.1.items', ['/admin/programs'])->assertJsonPath('data.icons./admin/programs', 'Rocket');

        Cache::flush();
        $this->asUser($this->makeUser())->getJson('/api/v1/me/menu-layout')->assertOk()->assertJsonPath('data.sections.1.title_en', 'Programs');
    }

    public function test_only_people_who_manage_settings_can_change_it_and_bad_input_is_refused(): void
    {
        $this->asUser($this->makeUser())->putJson('/api/v1/admin/menu-layout', $this->layout())->assertForbidden();

        $admin = $this->makeUser(Role::CENTER_ADMIN);
        foreach ([
            ['sections' => [['id' => 'Bad Id', 'items' => []]], 'icons' => []],
            ['sections' => [['id' => 'ok', 'items' => ['https://evil.example']]], 'icons' => []],
            ['sections' => [['id' => 'ok', 'items' => []], ['id' => 'ok', 'items' => []]], 'icons' => []],
            ['sections' => [['id' => 'ok', 'items' => []]], 'icons' => ['/admin/x' => '<script>']],
        ] as $bad) {
            $this->asUser($admin)->putJson('/api/v1/admin/menu-layout', $bad)->assertStatus(422);
        }
    }

    public function test_reset_returns_the_default_arrangement(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/menu-layout', $this->layout())->assertOk();
        $this->asUser($admin)->deleteJson('/api/v1/admin/menu-layout')->assertOk()->assertJsonPath('data.sections', []);
        Cache::flush();
        $this->asUser($admin)->getJson('/api/v1/me/menu-layout')->assertOk()->assertJsonPath('data.sections', []);
    }
}
