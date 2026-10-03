<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\School;
use App\Services\QatarSchools;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NationalSchoolsTest extends TestCase
{
    public function test_the_bundled_national_list_imports_once_and_places_every_school_inside_qatar(): void
    {
        $this->artisan('tedc:schools-sync')->assertSuccessful();
        $total = School::where('source', 'like', 'moe_%')->count();
        $this->assertGreaterThan(700, $total);
        $this->artisan('tedc:schools-sync')->assertSuccessful();
        $this->assertSame($total, School::where('source', 'like', 'moe_%')->count(), 'running it again updates, never duplicates');

        $this->assertSame(0, School::where('source', 'like', 'moe_%')->where(fn ($q) => $q->whereNull('latitude')->orWhereNotBetween('latitude', [24, 27])->orWhereNotBetween('longitude', [50, 52.5]))->count());
        foreach (['government', 'private'] as $type) {
            $this->assertTrue(School::where('type', $type)->where('source', 'like', 'moe_%')->exists());
        }
        $this->assertSame([], array_diff(School::pluck('region')->unique()->all(), School::REGIONS));
        $this->assertSame([], array_diff(School::pluck('stage')->unique()->all(), School::STAGES));
    }

    public function test_the_map_lists_schools_for_administrators_and_the_sync_falls_back_to_the_bundled_copy(): void
    {
        $this->artisan('tedc:schools-sync');
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $member = $this->makeEmployee()->user;
        $partner = School::where('source', 'moe_gov')->first();
        $partner->update(['is_partner' => true]);

        $map = $this->asUser($admin)->getJson('/api/v1/admin/schools/map')->assertOk()->json('data');
        $this->assertGreaterThan(700, count($map['schools']));
        $this->assertNotNull($map['synced_at']);
        $this->assertEqualsCanonicalizing(['id', 'code', 'name_ar', 'name_en', 'type', 'stage', 'gender', 'region', 'district', 'lat', 'lng', 'phone', 'email', 'address', 'website', 'curriculum', 'partner', 'staff', 'source'], array_keys($map['schools'][0]));
        $this->asUser($member)->getJson('/api/v1/admin/schools/map')->assertForbidden();

        // Ministry unreachable: the shipped copy is used and the center's own settings (partner flag) survive.
        Http::fake(['myschools.edu.gov.qa/*' => Http::response('down', 503)]);
        $this->asUser($admin)->postJson('/api/v1/admin/schools/sync')->assertOk()->assertJsonPath('data.from', 'bundled')->assertJsonPath('data.created', 0);
        $this->assertTrue($partner->fresh()->is_partner);
        $this->asUser($member)->postJson('/api/v1/admin/schools/sync')->assertForbidden();
    }

    public function test_live_layers_are_normalised(): void
    {
        $gov = ['attributes' => ['IndependentSchools.ARABICNAME' => 'مدرسة اختبار', 'IndependentSchools.ENGLISHNAME' => 'Test', 'IndependentSchools.IK' => '1_1_00', 'IndependentSchools.MNCP_NAME' => 'بلدية الوكرة',
            'IndependentSchools.LEVEL_NAME' => 'ثانوي', 'IndependentSchools.GENDER_NAME' => 'بنات', 'NSIS_SCHOOL_VIEW.PHONE' => '44000000', 'NSIS_SCHOOL_VIEW.EMAIL_ADDR' => 'bad email'], 'geometry' => ['x' => 51.5, 'y' => 25.1]];
        $outside = ['attributes' => ['IndependentSchools.ARABICNAME' => 'خارج', 'IndependentSchools.IK' => '2_1_00'], 'geometry' => ['x' => 10, 'y' => 10]];
        $private = ['attributes' => ['OBJECTID' => 9, 'NAME' => 'Private One', 'ANAME' => 'خاصة', 'CATEGORY' => 'Kindergarten + Primary', 'MNCP_NAME' => 'بلدية الدوحه', 'PHONE' => 44001122], 'geometry' => ['x' => 51.5, 'y' => 25.3]];
        $rows = app(QatarSchools::class)->normalize([0 => [$gov, $gov, $outside], 1 => [$private], 2 => [$gov]]);

        $this->assertCount(3, $rows, 'duplicates and positions outside Qatar are dropped');
        $this->assertSame(['al_wakrah', 'secondary', 'girls', null], [$rows[0]['region'], $rows[0]['stage'], $rows[0]['gender'], $rows[0]['email']]);
        $this->assertSame(['private', 'multi', 'doha', '44001122'], [$rows[1]['type'], $rows[1]['stage'], $rows[1]['region'], $rows[1]['phone']]);
    }
}
