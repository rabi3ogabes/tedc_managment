<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Guards against two bugs that unit tests never see: an API route hidden behind a wildcard sibling, and a menu entry that points to a page the web app does not serve. */
class RoutingIntegrityTest extends TestCase
{
    public function test_no_api_route_is_hidden_behind_an_unconstrained_wildcard_sibling(): void
    {
        $global = Route::getPatterns();
        $list = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => ['m' => $r->methods()[0], 'u' => $r->uri(), 'w' => $r->wheres])->values();
        $hidden = [];
        foreach ($list as $i => $a) {
            $sa = explode('/', $a['u']);
            foreach ($list->slice($i + 1) as $b) {
                if ($a['m'] !== $b['m']) {
                    continue;
                }
                $sb = explode('/', $b['u']);
                if (count($sa) !== count($sb)) {
                    continue;
                }
                $shadow = false;
                $same = true;
                foreach ($sa as $k => $seg) {
                    if (preg_match('/^\{(\w+)\??\}$/', $seg, $m)) {
                        if (str_starts_with($sb[$k], '{')) {
                            continue;
                        }
                        if (isset($a['w'][$m[1]]) || isset($global[$m[1]])) {
                            $same = false;
                            break;
                        }
                        $shadow = true;
                    } elseif ($seg !== $sb[$k]) {
                        $same = false;
                        break;
                    }
                }
                if ($same && $shadow) {
                    $hidden[] = "{$a['m']} {$a['u']} hides {$b['u']}";
                }
            }
        }
        $this->assertSame([], $hidden);
    }

    public function test_every_menu_entry_of_the_web_app_leads_to_a_page_that_exists(): void
    {
        $web = base_path('../web/src');
        if (! is_file("{$web}/App.tsx")) {
            $this->markTestSkipped('The web sources are not next to the backend.');
        }
        $app = (string) file_get_contents("{$web}/App.tsx");
        $routes = ['admin' => [], 'portal' => []];
        $scope = null;
        foreach (preg_split('/\R/', $app) as $line) {
            if (preg_match('/<Route path="(admin|portal)" element=/', $line, $m)) {
                $scope = $m[1];
            } elseif ($scope && preg_match('/^\s{8}<\/Route>/', $line)) {
                $scope = null;
            } elseif ($scope && preg_match('/<Route path="([^"]+)"/', $line, $m)) {
                $routes[$scope][] = $m[1];
            }
        }
        $this->assertGreaterThan(50, count($routes['admin']));
        $this->assertGreaterThan(15, count($routes['portal']));

        $layout = (string) file_get_contents("{$web}/components/admin/AdminLayout.tsx");
        preg_match_all("~to: '/(admin|portal)(?:/([^'?]*))?(?:\?[^']*)?'~", $layout, $found, PREG_SET_ORDER);
        $missing = [];
        foreach ($found as $f) {
            $path = $f[2] ?? '';
            $wild = collect($routes[$f[1]])->filter(fn ($r) => str_ends_with($r, '/*'))->map(fn ($r) => substr($r, 0, -2));
            if ($path === '' || in_array($path, $routes[$f[1]], true) || $wild->contains(fn ($w) => $path === $w || str_starts_with($path, "{$w}/"))) {
                continue;
            }
            $missing[] = "/{$f[1]}/{$path}";
        }
        $this->assertGreaterThan(80, count($found));
        $this->assertSame([], array_values(array_unique($missing)), 'menu entries without a page');
    }
}
