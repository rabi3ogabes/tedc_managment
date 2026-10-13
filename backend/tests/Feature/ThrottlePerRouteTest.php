<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

/**
 * A numeric limit on a route ("throttle:6,1") counts requests to that route. Laravel's own middleware keys the counter
 * by the person alone, so every throttled route shared one counter and a busy page (presence pings, lesson heartbeats)
 * made unrelated low-limit routes answer 429.
 */
class ThrottlePerRouteTest extends TestCase
{
    public function test_a_numeric_limit_counts_each_route_on_its_own(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        for ($i = 0; $i < 6; $i++) {
            $this->asUser($admin)->postJson('/api/v1/admin/assistant/reindex')->assertOk();   // throttle:6,1
        }

        $this->asUser($admin)->getJson('/api/v1/admin/ai/eval')->assertOk();
        $this->asUser($admin)->postJson('/api/v1/admin/ai/eval')->assertCreated();          // another route, its own six

        $this->asUser($admin)->postJson('/api/v1/admin/assistant/reindex')->assertStatus(429);   // the limit still holds on its own route
    }
}
