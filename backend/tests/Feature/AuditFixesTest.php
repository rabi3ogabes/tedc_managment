<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Services\FileStorage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regression tests for problems found by walking every screen of the finished platform. */
class AuditFixesTest extends TestCase
{
    public function test_the_room_calendar_is_not_swallowed_by_the_single_room_route(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->getJson('/api/v1/admin/rooms/calendar?from=2026-10-04&to=2026-10-10')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_role_names_for_pickers_are_offered_without_permission_lists(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $r = $this->asUser($coordinator)->getJson('/api/v1/admin/role-options')->assertOk();
        $this->assertGreaterThan(5, count($r->json('data')));
        $this->assertArrayNotHasKey('permissions', $r->json());
        $this->assertArrayNotHasKey('permissions', $r->json('data.0'));
        $this->asUser($coordinator)->getJson('/api/v1/admin/roles')->assertForbidden();                 // the full list stays with user administrators
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/role-options')->assertForbidden();
    }

    public function test_the_error_log_stays_with_the_system_administrator_by_design(): void
    {
        $this->assertFalse(Permission::where('slug', 'logs.manage')->exists());   // no role can be granted it: only the system administrator passes the check
    }

    public function test_a_school_administrator_can_fill_the_program_filter_of_the_registration_screens(): void
    {
        $school = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeProgram();
        $this->asUser($school)->getJson('/api/v1/admin/programs?per_page=100')->assertOk();
        $this->asUser($school)->getJson('/api/v1/admin/programs/'.$this->makeProgram()->id)->assertForbidden();   // details stay with those who manage programs
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/programs')->assertForbidden();
    }

    public function test_a_storage_bucket_that_was_never_created_is_created_once_and_the_write_repeated(): void
    {
        config(['tedc.storage_driver' => 'supabase', 'tedc.supabase.url' => 'https://project.example.supabase.co', 'tedc.supabase.service_key' => 'k', 'tedc.supabase.buckets.documents' => 'documents']);
        Http::fake([
            '*/storage/v1/object/documents/*' => Http::sequence()->push(['message' => 'Bucket not found'], 400)->push(['Key' => 'documents/a.txt'], 200),
            '*/storage/v1/bucket' => Http::response(['name' => 'documents'], 200),
        ]);
        app(FileStorage::class)->put('documents', 'a.txt', 'hello', 'text/plain');
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/storage/v1/bucket') && $r['id'] === 'documents' && $r['public'] === false);
    }

    public function test_a_real_storage_error_is_still_an_error(): void
    {
        config(['tedc.storage_driver' => 'supabase', 'tedc.supabase.url' => 'https://project.example.supabase.co', 'tedc.supabase.service_key' => 'k', 'tedc.supabase.buckets.documents' => 'documents']);
        Http::fake(['*' => Http::response(['message' => 'Payload too large'], 413)]);
        $this->expectException(RequestException::class);
        app(FileStorage::class)->put('documents', 'a.txt', 'hello', 'text/plain');
    }

    public function test_validation_messages_name_the_field_in_arabic(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $r = $this->withHeaders(['X-Locale' => 'ar', 'Accept-Language' => 'ar'])->asUser($admin)->postJson('/api/v1/admin/trainers', ['source' => 'center', 'currency' => 'x', 'hourly_rate' => 'abc'])->assertStatus(422);
        $text = json_encode(array_values(array_merge(...array_values($r->json('errors')))), JSON_UNESCAPED_UNICODE);   // the messages, not the field keys
        $this->assertStringNotContainsString('currency', $text);
        $this->assertStringNotContainsString('hourly_rate', $text);
        $this->assertStringContainsString('العملة', $text);
    }
}
