<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_a_missing_record_never_names_an_internal_class(): void
    {
        $res = $this->getJson('/api/v1/public/programs/'.str_repeat('0', 8).'-0000-4000-8000-000000000000')->assertNotFound();

        $this->assertStringNotContainsString('App\\Models', $res->getContent());
        $this->assertStringNotContainsString('No query results', $res->getContent());
    }

    public function test_admin_reference_lists_are_for_staff_not_for_ordinary_trainees(): void
    {
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/lookups')->assertForbidden();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/lookups')->assertOk();
        $this->asUser($this->makeUser(Role::SCHOOL_ADMIN))->getJson('/api/v1/admin/lookups')->assertOk();
    }
}
