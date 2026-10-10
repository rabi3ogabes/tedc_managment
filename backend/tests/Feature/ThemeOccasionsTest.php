<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\ThemeOccasions;
use App\Services\ThemeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ThemeOccasionsTest extends TestCase
{
    private function admin()
    {
        return $this->makeUser(Role::CENTER_ADMIN);
    }

    public function test_the_standard_occasions_are_added_once_and_keep_an_administrators_edits(): void
    {
        $admin = $this->admin();
        $this->asUser($admin)->postJson('/api/v1/admin/theme/occasions/standard', ['year' => 2027])->assertOk();
        $base = app(ThemeService::class)->base();
        $ids = array_column($base['occasions'], 'id');
        foreach (['ramadan_2027', 'eid_fitr_2027', 'eid_adha_2027', 'national_day', 'teachers_day', 'sports_day_2027', 'graduation'] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertCount(7, $base['occasions']);

        $edited = $base;
        $edited['occasions'][0]['name_en'] = 'Ramadan (edited)';
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $edited)->assertOk();
        $this->asUser($admin)->postJson('/api/v1/admin/theme/occasions/standard', ['year' => 2027])->assertOk();
        $after = app(ThemeService::class)->base();
        $this->assertCount(7, $after['occasions']);                                               // nothing is added twice
        $this->assertSame('Ramadan (edited)', $after['occasions'][0]['name_en']);               // and an edit survives
        $this->asUser($admin)->postJson('/api/v1/admin/theme/occasions/standard', ['year' => 2028])->assertOk();
        $this->assertCount(11, app(ThemeService::class)->base()['occasions']);                    // the next year adds the four that move
    }

    public function test_an_occasion_is_laid_over_the_theme_only_while_it_is_on_and_never_saved_into_it(): void
    {
        $admin = $this->admin();
        $this->asUser($admin)->postJson('/api/v1/admin/theme/occasions/standard', ['year' => 2027])->assertOk();
        $themes = app(ThemeService::class);
        $normal = $themes->get(Carbon::create(2027, 4, 10))['colors']['primary'];
        $ramadan = $themes->get(Carbon::create(2027, 2, 20));
        $this->assertSame('#1B1F4B', $ramadan['colors']['primary']);
        $this->assertSame('ramadan_2027', $ramadan['active_occasion']['id']);
        $this->assertSame('crescent', $ramadan['loading']['style']);
        $this->assertNotSame('#1B1F4B', $normal);
        $this->assertNull($themes->get(Carbon::create(2027, 4, 10))['active_occasion']);
        $this->assertSame($normal, $themes->base()['colors']['primary']);                         // the saved theme is untouched

        // National Day repeats every year and Teachers' Day likewise; New Year wrap is handled.
        $this->assertSame('national_day', $themes->get(Carbon::create(2031, 12, 18))['active_occasion']['id']);
        $this->assertSame('teachers_day', $themes->get(Carbon::create(2030, 10, 5))['active_occasion']['id']);
        $this->assertTrue(ThemeOccasions::covers(['enabled' => true, 'recurring' => true, 'starts_on' => '2026-12-28', 'ends_on' => '2026-01-03'], Carbon::create(2029, 1, 1)));
        $this->assertFalse(ThemeOccasions::covers(['enabled' => false, 'recurring' => true, 'starts_on' => '2026-12-14', 'ends_on' => '2026-12-20'], Carbon::create(2029, 12, 15)));
    }

    public function test_the_public_theme_shows_the_occasion_and_the_studio_can_ask_for_the_saved_theme(): void
    {
        $admin = $this->admin();
        $this->asUser($admin)->postJson('/api/v1/admin/theme/occasions/standard', ['year' => (int) now()->year])->assertOk();
        // Make one occasion cover today so the test does not depend on the date it runs.
        $base = app(ThemeService::class)->base();
        $base['occasions'][0]['starts_on'] = now()->subDay()->toDateString();
        $base['occasions'][0]['ends_on'] = now()->addDay()->toDateString();
        $base['occasions'][0]['recurring'] = false;
        $base['occasions'][0]['enabled'] = true;
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $base)->assertOk();
        Cache::flush();
        $public = $this->getJson('/api/v1/public/theme')->assertOk()->json();
        $this->assertNotNull($public['data']['active_occasion']);
        $studio = $this->asUser($admin)->getJson('/api/v1/public/theme?base=1')->json();
        $this->assertNotSame($public['data']['colors']['primary'], $studio['meta']['base']['colors']['primary']);
    }

    public function test_the_loading_page_is_validated_and_reaches_the_mobile_config(): void
    {
        $admin = $this->admin();
        $theme = app(ThemeService::class)->base();
        $theme['loading'] = ['style' => 'bar', 'message_ar' => 'أهلًا بك', 'message_en' => 'Welcome', 'background' => '#112233', 'accent' => '#ddaa00', 'show_name' => false];
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $theme)->assertOk()->assertJsonPath('data.loading.style', 'bar');
        $theme['loading']['style'] = 'spinning-cat';
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $theme)->assertStatus(422);
        $theme['loading'] = ['style' => 'bar', 'background' => 'red'];
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $theme)->assertStatus(422);
        Cache::flush();
        $cfg = $this->getJson('/api/v1/public/mobile-config')->assertOk()->json('data');
        $this->assertSame('bar', $cfg['loading']['style']);
        $this->assertSame('أهلًا بك', $cfg['loading']['message_ar']);
        $this->assertArrayHasKey('occasion', $cfg);
        $this->assertSame(['arabic_family', 'latin_family', 'arabic_font_url', 'latin_font_url'], array_keys($cfg['typography']));
    }

    public function test_the_navigation_style_defaults_to_classic_is_validated_and_reaches_the_mobile_config(): void
    {
        $admin = $this->admin();
        $this->getJson('/api/v1/public/mobile-config')->assertOk()->assertJsonPath('data.navigation.style', 'classic');

        $theme = app(ThemeService::class)->base();
        foreach (['floating', 'center_fab', 'neumorphism', 'glass', 'outline'] as $style) {
            $theme['navigation'] = ['style' => $style];
            $this->asUser($admin)->putJson('/api/v1/admin/theme', $theme)->assertOk()->assertJsonPath('data.navigation.style', $style);
        }
        Cache::flush();
        $this->getJson('/api/v1/public/mobile-config')->assertOk()->assertJsonPath('data.navigation.style', 'outline');

        $theme['navigation'] = ['style' => 'rainbow'];
        $this->asUser($admin)->putJson('/api/v1/admin/theme', $theme)->assertStatus(422);
    }

    public function test_an_occasion_with_bad_dates_or_colours_is_refused(): void
    {
        $admin = $this->admin();
        $theme = app(ThemeService::class)->base();
        $o = ['id' => 'x', 'name_ar' => 'س', 'name_en' => 'X', 'enabled' => true, 'recurring' => false, 'starts_on' => '2027-03-10', 'ends_on' => '2027-03-01', 'patch' => []];
        $this->asUser($admin)->putJson('/api/v1/admin/theme', ['occasions' => [$o]] + $theme)->assertStatus(422);
        $o['ends_on'] = '2027-03-12';
        $o['patch'] = ['colors' => ['primary' => 'blue']];
        $this->asUser($admin)->putJson('/api/v1/admin/theme', ['occasions' => [$o]] + $theme)->assertStatus(422);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/theme/occasions/standard')->assertForbidden();
    }

    public function test_the_studio_can_read_the_standard_list_without_saving_it(): void
    {
        $admin = $this->admin();
        $list = $this->asUser($admin)->getJson('/api/v1/admin/theme/occasions/standard?year=2027')->assertOk()->json('data');
        $this->assertCount(7, $list);
        $this->assertSame([], app(ThemeService::class)->base()['occasions']);
        $this->assertSame('2027-02-08', collect($list)->firstWhere('id', 'ramadan_2027')['starts_on']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/theme/occasions/standard')->assertForbidden();
    }
}
