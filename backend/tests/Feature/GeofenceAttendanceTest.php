<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingRoom;
use App\Services\AttendanceService;
use App\Services\AttendanceSettings;
use App\Services\GeoFence;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GeofenceAttendanceTest extends TestCase
{
    /** Venue in Doha; ~0.001° of latitude is about 111 m. */
    private const LAT = 25.2854;

    private const LNG = 51.5310;

    private function setup_(bool $withRoom = true): array
    {
        $program = $this->makeProgram();
        $employee = $this->makeEmployee();
        $registration = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $session = $this->makeSession($program, now()->subMinutes(5));
        if ($withRoom) {
            $room = TrainingRoom::create(['name_ar' => 'قاعة', 'name_en' => 'Hall A', 'capacity' => 30, 'latitude' => self::LAT, 'longitude' => self::LNG]);
            $session->update(['training_room_id' => $room->id]);
        }

        return [$employee->user, $session->fresh(), $registration];
    }

    private function scan($user, ProgramSession $session, array $location = []): TestResponse
    {
        $payload = app(AttendanceService::class)->currentQr($session)['payload'];

        return $this->asUser($user)->postJson('/api/v1/me/attendance/scan', ['payload' => $payload] + $location);
    }

    public function test_check_in_at_the_venue_is_verified_and_stores_the_position(): void
    {
        [$user, $session] = $this->setup_();

        $this->scan($user, $session, ['latitude' => self::LAT + 0.0003, 'longitude' => self::LNG, 'accuracy' => 12])
            ->assertOk()->assertJsonPath('data.action', 'check_in')->assertJsonPath('data.attendance.location_status', 'verified');

        $row = Attendance::first();
        $this->assertSame('verified', $row->location_status);
        $this->assertEqualsWithDelta(33, $row->distance_m, 3);
        $this->assertSame(12, $row->accuracy_m);
    }

    public function test_check_in_away_from_the_venue_is_refused_with_the_distance(): void
    {
        [$user, $session] = $this->setup_();

        $this->scan($user, $session, ['latitude' => self::LAT + 0.01, 'longitude' => self::LNG, 'accuracy' => 10])
            ->assertStatus(422)->assertJsonPath('code', 'outside_venue')->assertJsonPath('details.radius', 150);
        $this->assertSame(0, Attendance::count());
    }

    public function test_location_is_required_when_the_venue_is_known(): void
    {
        [$user, $session] = $this->setup_();

        $this->scan($user, $session)->assertStatus(422)->assertJsonPath('code', 'location_required');
    }

    public function test_mock_locations_and_imprecise_fixes_are_refused(): void
    {
        [$user, $session] = $this->setup_();

        $this->scan($user, $session, ['latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 5, 'mocked' => true])
            ->assertStatus(422)->assertJsonPath('code', 'mock_location');
        $this->scan($user, $session, ['latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 900])
            ->assertStatus(422)->assertJsonPath('code', 'low_accuracy');
    }

    public function test_check_out_is_also_location_checked(): void
    {
        [$user, $session] = $this->setup_();
        $here = ['latitude' => self::LAT, 'longitude' => self::LNG, 'accuracy' => 8];

        $this->scan($user, $session, $here)->assertOk();
        $this->travel(30)->minutes();
        $this->scan($user, $session, ['latitude' => self::LAT + 0.02] + $here)->assertStatus(422)->assertJsonPath('code', 'outside_venue');
        $this->scan($user, $session, $here)->assertOk()->assertJsonPath('data.action', 'check_out');
    }

    public function test_rooms_without_coordinates_and_disabled_checks_do_not_block(): void
    {
        [$user, $session] = $this->setup_(withRoom: false);
        $this->scan($user, $session)->assertOk()->assertJsonPath('data.attendance.location_status', 'no_venue');

        [$user2, $session2] = $this->setup_();
        app(AttendanceSettings::class)->update(['geofence_enabled' => false]);
        $this->scan($user2, $session2)->assertOk()->assertJsonPath('data.attendance.location_status', 'disabled');
    }

    public function test_radius_is_managed_by_administrators(): void
    {
        [$user, $session] = $this->setup_();
        $admin = $this->makeUser(Role::SUPER_ADMIN);

        $this->asUser($admin)->putJson('/api/v1/admin/settings/attendance', ['radius_m' => 40])->assertOk()->assertJsonPath('data.settings.radius_m', 40);
        $this->scan($user, $session, ['latitude' => self::LAT + 0.0009, 'longitude' => self::LNG, 'accuracy' => 5])
            ->assertStatus(422)->assertJsonPath('code', 'outside_venue');
        $this->asUser($admin)->getJson('/api/v1/admin/settings/attendance')->assertOk()->assertJsonPath('data.rooms.located', TrainingRoom::whereNotNull('latitude')->count());
        $this->asUser($user)->putJson('/api/v1/admin/settings/attendance', ['radius_m' => 999])->assertForbidden();
    }

    public function test_haversine_distance_is_accurate(): void
    {
        $this->assertEqualsWithDelta(111.2, GeoFence::distance(25.0, 51.0, 25.001, 51.0), 0.5);
        $this->assertEqualsWithDelta(0.0, GeoFence::distance(25.0, 51.0, 25.0, 51.0), 0.001);
    }

    public function test_attendance_nudges_are_sent_once_to_participants_who_have_not_checked_in(): void
    {
        [$user, $session, $registration] = $this->setup_();
        $session->update(['starts_at' => now()->subMinutes(20), 'ends_at' => now()->addHour()]);

        $this->artisan('tedc:attendance-nudges')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'session.attendance_missed')->count());

        $this->artisan('tedc:attendance-nudges')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->where('type', 'session.attendance_missed')->count(), 'nudges are sent once per session');
    }
}
