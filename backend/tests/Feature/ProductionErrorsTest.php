<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Role;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regression tests for errors that came out of the error log. */
class ProductionErrorsTest extends TestCase
{
    public function test_an_unreachable_or_badly_certified_ministry_server_is_not_an_error_in_the_log(): void
    {
        // cURL error 60: the ministry's server sends its certificate without the intermediate one.
        Http::fake(['myschools.edu.gov.qa/*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)')]);
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($admin)->postJson('/api/v1/admin/schools/sync')->assertOk()->assertJsonPath('data.from', 'bundled');

        $this->assertSame(0, ErrorLog::count(), 'a handled fallback must not fill the error log');
    }

    public function test_the_kit_stats_ignore_a_stale_or_unknown_kit_type(): void
    {
        // An old browser still remembers the removed type "standard" and sends it.
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($admin)->getJson('/api/v1/admin/kits/stats?delivery=standard')->assertOk()->assertJsonStructure(['data' => ['by_delivery' => ['in_person', 'online', 'hybrid']]]);
    }
}
