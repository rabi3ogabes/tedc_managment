<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\Cms\HomeLayouts;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeLayoutsTest extends TestCase
{
    public function test_every_ready_made_layout_can_be_saved_published_and_shown_in_both_languages(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $list = $this->asUser($admin)->getJson('/api/v1/admin/pages/home/layouts')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(6, count($list));
        foreach ($list as $layout) {
            $this->assertNotSame('', $layout['name_ar']);
            $this->assertNotSame('', $layout['name_en']);
            $this->assertNotEmpty($layout['blocks'], $layout['id']);
            $this->asUser($admin)->putJson('/api/v1/admin/pages/home/blocks', ['blocks' => $layout['blocks']])->assertOk();
            $this->asUser($admin)->postJson('/api/v1/admin/pages/home/publish', ['note' => $layout['id']])->assertSuccessful();
            Cache::flush();
            foreach (['ar', 'en'] as $lang) {
                $page = $this->withHeader('X-Locale', $lang)->getJson('/api/v1/public/pages/home?lang='.$lang)->assertOk()->json('data');
                $types = array_column($page['blocks'] ?? $page, 'type');
                $this->assertSame(array_column($layout['blocks'], 'type'), $types, "{$layout['id']} {$lang}");
            }
        }
    }

    public function test_the_layouts_are_for_the_home_page_and_for_administrators(): void
    {
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson('/api/v1/admin/pages/about/layouts')->assertNotFound();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/pages/home/layouts')->assertForbidden();
        $this->assertNotNull(HomeLayouts::find('ramadan'));
        $this->assertNull(HomeLayouts::find('nope'));
    }
}
