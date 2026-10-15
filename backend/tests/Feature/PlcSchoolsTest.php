<?php

namespace Tests\Feature;

use App\Models\PlcDecision;
use App\Models\Role;
use App\Models\School;
use App\Services\FeatureSettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 2: suggestions with reasons, compatibility policy, proximity fallback, seats, exclusions, internal scope. */
class PlcSchoolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('plc', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function school(array $attrs, int $staff = 2): School
    {
        $school = $this->makeSchool($attrs);
        for ($i = 0; $i < $staff; $i++) {
            $this->makeEmployee(['school_id' => $school->id]);
        }

        return $school;
    }

    public function test_suggestions_rank_by_proven_distance_then_district_and_exclude_with_reasons(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $host = $this->school(['gender' => 'girls', 'region' => 'doha', 'district' => 'west_bay', 'latitude' => 25.3200, 'longitude' => 51.5300]);
        $near = $this->school(['gender' => 'girls', 'region' => 'doha', 'district' => 'west_bay', 'latitude' => 25.3300, 'longitude' => 51.5400, 'name_ar' => 'قريبة']);
        $far = $this->school(['gender' => 'girls', 'region' => 'al_shamal', 'district' => 'ruwais', 'latitude' => 26.1300, 'longitude' => 51.2100, 'name_ar' => 'بعيدة']);
        $boys = $this->school(['gender' => 'boys', 'region' => 'doha', 'district' => 'west_bay', 'latitude' => 25.3250, 'longitude' => 51.5350, 'name_ar' => 'بنين']);
        $mixedNoPos = $this->school(['gender' => 'mixed', 'region' => 'doha', 'district' => 'west_bay', 'name_ar' => 'مختلطة بلا موقع']);
        $otherRegion = $this->school(['gender' => 'girls', 'region' => 'al_wakrah', 'district' => 'wakrah', 'name_ar' => 'منطقة أخرى']);
        $empty = $this->school(['gender' => 'girls', 'region' => 'doha', 'district' => 'west_bay', 'name_ar' => 'بلا موظفين'], 0);

        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة', 'host_school_id' => $host->id, 'start_date' => today()->addDays(10)->toDateString()])->assertCreated()->json('data.id');
        $r = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/schools/suggest")->assertOk()->json('data');

        $this->assertTrue($r['facts']['host_has_position']);
        $this->assertSame('strict', $r['facts']['gender_policy']);
        $names = array_column($r['suggested'], 'name');
        $this->assertSame(['قريبة', 'مختلطة بلا موقع', 'منطقة أخرى'], $names, 'distance first, then district, then elsewhere');
        $this->assertSame('distance_km', $r['suggested'][0]['reasons'][1]['key']);
        $this->assertEqualsWithDelta(1.5, $r['suggested'][0]['distance_km'], 0.4);
        $this->assertNull($r['suggested'][1]['distance_km'], 'no invented distance without coordinates');
        $this->assertContains('same_district', array_column($r['suggested'][1]['reasons'], 'key'));
        $this->assertContains('other_region', array_column($r['suggested'][2]['reasons'], 'key'));
        $this->assertContains('staff', array_column($r['suggested'][0]['reasons'], 'key'));
        $excluded = collect($r['excluded'])->keyBy('name');
        $this->assertSame('gender_mismatch', $excluded['بنين']['excluded_reason']);
        $this->assertSame('beyond_max_distance', $excluded['بعيدة']['excluded_reason']);
        $this->assertSame('no_staff', $excluded['بلا موظفين']['excluded_reason']);
        $this->assertGreaterThan(25, $excluded['بعيدة']['distance_km']);

        // A larger radius brings the far school back; nothing is selected by itself.
        $r2 = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/schools/suggest?max_km=200")->assertOk()->json('data');
        $this->assertContains('بعيدة', array_column($r2['suggested'], 'name'));
        $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/schools")->assertOk()->assertJsonCount(1, 'data');

        // Manual search annotates compatibility; adding with seats; the boys school is refused under the strict policy.
        $search = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/schools/search?gender=boys")->assertOk()->json('data');
        $this->assertFalse($search[0]['gender_ok']);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $boys->id]]])->assertStatus(422)->assertJsonPath('code', 'plc_gender_mismatch');
        $list = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $near->id, 'seats' => 8, 'from_suggestion' => true], ['id' => $mixedNoPos->id]]])->assertOk()->json('data');
        $this->assertCount(3, $list);
        $this->assertSame(8, collect($list)->firstWhere('id', $near->id)['seats']);
        $this->assertNotNull(collect($list)->firstWhere('id', $near->id)['distance_km']);
        $this->assertNull(collect($list)->firstWhere('id', $mixedNoPos->id)['distance_km']);
        $this->asUser($officer)->putJson("/api/v1/admin/plc/workshops/$id/schools/{$near->id}/seats", ['seats' => 12])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools/{$otherRegion->id}/exclude", ['reason' => 'manual', 'note' => 'بعيدة عن المواصلات'])->assertOk();
        $this->asUser($officer)->deleteJson("/api/v1/admin/plc/workshops/$id/schools/{$mixedNoPos->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($officer)->deleteJson("/api/v1/admin/plc/workshops/$id/schools/{$host->id}")->assertStatus(422)->assertJsonPath('code', 'plc_host_remove');
        $detail = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id")->assertOk()->json('data');
        $this->assertSame(['host', 'participant', 'participant'], array_column($detail['schools'], 'role'));
        $this->assertContains('excluded', array_column($detail['schools'], 'status'));
        $this->assertSame(['shared', 'participants_added', 'excluded', 'participant_removed'], PlcDecision::where('subject_id', $id)->orderBy('created_at')->pluck('decision')->all());
        unset($far, $empty);
    }

    public function test_the_gender_policy_is_configurable_and_exceptions_are_recorded(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $officer = $this->makeUser(Role::COORDINATOR);
        $host = $this->school(['gender' => 'boys']);
        $girls = $this->school(['gender' => 'girls', 'name_ar' => 'بنات']);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة', 'host_school_id' => $host->id])->assertCreated()->json('data.id');

        // Only an administrator with plc.settings changes the policy; the setting is validated.
        $this->asUser($officer)->putJson('/api/v1/admin/plc/settings', ['gender_policy' => 'open'])->assertForbidden();
        $this->asUser($admin)->putJson('/api/v1/admin/plc/settings', ['gender_policy' => 'nonsense'])->assertStatus(422);
        $this->asUser($admin)->putJson('/api/v1/admin/plc/settings', ['gender_policy' => 'allow_with_exception', 'max_distance_km' => 40])->assertOk()->assertJsonPath('data.gender_policy', 'allow_with_exception')->assertJsonPath('data.rased_integration', false);
        Cache::flush();

        // Under allow_with_exception the officer is refused, the head needs a reason, and the exception is logged.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $girls->id]]])->assertStatus(422)->assertJsonPath('code', 'plc_gender_exception');
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $girls->id]]])->assertStatus(422)->assertJsonPath('code', 'plc_gender_exception');
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $girls->id]], 'exception_reason' => 'ورشة قيادية مشتركة بقرار الوزارة'])->assertOk()->assertJsonCount(2, 'data');
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('kind', 'exception')->where('decision', 'gender_policy')->exists());
        // The suggestion engine no longer excludes the other category; it flags it.
        $sugg = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/schools/suggest")->json('data');
        // Only staff-less helper schools are excluded now; the other gender category is flagged, not excluded.
        $this->assertSame([], array_values(array_filter($sugg['excluded'], fn ($r) => $r['excluded_reason'] !== 'no_staff')));
        $this->assertContains('gender_exception_needed', array_column($sugg['suggested'][0]['reasons'], 'key'));

        $this->asUser($admin)->putJson('/api/v1/admin/plc/settings', ['gender_policy' => 'open'])->assertOk();
        Cache::flush();
        $other = $this->school(['gender' => 'girls', 'name_ar' => 'بنات ٢']);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $other->id]]])->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_internal_workshops_stay_inside_their_school_unless_the_centre_widens_them(): void
    {
        $deputy = $this->makeUser(Role::ACADEMIC_DEPUTY);
        $emp = $this->makeEmployee([], $deputy);
        $other = $this->school(['gender' => 'mixed', 'name_ar' => 'أخرى']);
        $id = $this->asUser($deputy)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة داخلية'])->assertCreated()->json('data.id');

        $this->asUser($deputy)->putJson("/api/v1/admin/plc/workshops/$id/schools/host", ['host_school_id' => $other->id])->assertStatus(422)->assertJsonPath('code', 'plc_internal_host_fixed');
        $this->asUser($deputy)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $other->id]]])->assertStatus(422)->assertJsonPath('code', 'plc_internal_scope');
        // The deputy's search is limited to the own school; the centre sees everything and may widen the scope (recorded).
        $this->assertSame([$emp->school_id], array_column($this->asUser($deputy)->getJson("/api/v1/admin/plc/workshops/$id/schools/search")->json('data'), 'id'));
        $officer = $this->makeUser(Role::COORDINATOR);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $other->id]]])->assertOk()->assertJsonCount(2, 'data');
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('decision', 'scope_widened')->exists());

        // Changing schools on a submitted workshop restarts its review.
        $this->asUser($deputy)->putJson("/api/v1/admin/plc/workshops/$id", ['start_date' => today()->addDays(5)->toDateString()])->assertOk();
        $this->asUser($deputy)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk()->assertJsonPath('data.state', 'submitted');
        $this->asUser($officer)->deleteJson("/api/v1/admin/plc/workshops/$id/schools/{$other->id}")->assertOk();
        $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id")->assertJsonPath('data.state', 'draft');
    }
}
