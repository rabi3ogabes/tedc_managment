<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\PlcDecision;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\AttendanceService;
use App\Services\FeatureSettings;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 5: the rotating code, channels, late and left-early under the PLC policy, revocation, corrections, locking, the clock, the report. */
class PlcAttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        app(FeatureSettings::class)->set('plc', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    /** The n-th training day from today at 09:00 (Fridays and Saturdays skipped). Counted one training day at a time, so two offsets never meet on one date, whatever today's weekday. */
    private function day(int $offset): Carbon
    {
        $d = today()->setTime(9, 0);
        for ($i = 0; $i < $offset; $i++) {
            $d->addDay();
            while (in_array($d->dayOfWeek, [5, 6], true)) {
                $d->addDay();
            }
        }

        return $d;
    }

    public function test_attendance_runs_on_codes_and_joins_with_the_plc_policy_corrections_locks_and_the_clock(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls']);
        [$a, $b, $c] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id])];
        $room = TrainingRoom::create(['code' => 'R1', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 20, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $d1 = $this->day(10);
        $d2 = $this->day(12);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة', 'host_school_id' => $host->id, 'start_date' => $d1->toDateString(), 'end_date' => $d2->toDateString(), 'capacity' => 10, 'delivery_mode' => 'in_person'])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id, $c->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $trainer->id, 'role' => 'lead'])->assertSuccessful();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $s1 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d1->toIso8601String(), 'ends_at' => $d1->copy()->addHours(3)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer->id]])->assertCreated()->json('data.id');
        $s2 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d2->toIso8601String(), 'ends_at' => $d2->copy()->addHours(2)->toIso8601String(), 'mode' => 'hybrid', 'training_room_id' => $room->id, 'online_url' => 'https://teams.test/j/2', 'online_passcode' => '1122', 'trainer_ids' => [$trainer->id]])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertOk()->assertJsonPath('data.state', 'scheduled');
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/admin/plc/workshops/$id/sessions/$s1/attendance")->assertForbidden();

        // The clock: ready a day before the first session.
        $this->travelTo($d1->copy()->subHours(30));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('scheduled', PlcWorkshop::find($id)->workflow_state);
        $this->travelTo($d1->copy()->subHours(20));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('ready', PlcWorkshop::find($id)->workflow_state);

        // Session 1: a late check-in under the PLC policy (15 minutes), an early leave, a revoked code, a correction.
        $this->travelTo($d1->copy()->addMinutes(20));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('in_progress', PlcWorkshop::find($id)->workflow_state);
        $code = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s1/attendance/code")->assertOk()->json('data');
        $this->asUser($a->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code['payload']])->assertOk()->assertJsonPath('data.action', 'check_in')->assertJsonPath('data.attendance.status', 'late');
        $this->assertSame('in_person', Attendance::where('program_session_id', $s1)->where('employee_id', $a->id)->value('channel'));
        $roster = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/sessions/$s1/attendance")->assertOk()->json('data');
        $this->assertSame([3, 1, 1, 2], [$roster['counts']['expected'], $roster['counts']['present'], $roster['counts']['late'], $roster['counts']['pending']]);
        $this->assertNotNull($roster['qr']);
        $old = $code['payload'];
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s1/attendance/revoke", ['reason' => 'صُوّر الرمز'])->assertOk();
        $this->asUser($b->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $old])->assertStatus(422)->assertJsonPath('code', 'invalid_qr');
        $fresh = app(AttendanceService::class)->currentQr(ProgramSession::find($s1))['payload'];
        $this->asUser($b->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $fresh])->assertOk()->assertJsonPath('data.action', 'check_in');
        $this->travelTo($d1->copy()->addHours(2));
        $this->asUser($a->user)->postJson('/api/v1/me/attendance/scan', ['payload' => app(AttendanceService::class)->currentQr(ProgramSession::find($s1))['payload'], 'intent' => 'check_out'])->assertOk()->assertJsonPath('data.action', 'check_out')->assertJsonPath('data.attendance.status', 'left_early');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s1/attendance/correct", ['registration_id' => $roster['rows'][2]['registration_id'], 'status' => 'excused'])->assertStatus(422)->assertJsonPath('code', 'plc_correction_reason');
        $cReg = collect($roster['rows'])->firstWhere('employee_id', $c->id)['registration_id'];
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s1/attendance/correct", ['registration_id' => $cReg, 'status' => 'excused', 'reason' => 'إجازة مرضية'])->assertOk();
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('kind', 'attendance')->where('decision', 'corrected')->exists());
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('decision', 'code_revoked')->exists());

        // Session 2 (hybrid): one channel per person; the join is the attendance for the remote one.
        $this->travelTo($d2->copy()->subMinutes(5));
        $this->asUser($b->user)->postJson("/api/v1/me/sessions/$s2/join")->assertOk();
        $this->assertSame('remote', Attendance::where('program_session_id', $s2)->where('employee_id', $b->id)->value('channel'));
        $this->travelTo($d2->copy()->addMinutes(5));
        $code2 = app(AttendanceService::class)->currentQr(ProgramSession::find($s2))['payload'];
        $this->asUser($b->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code2])->assertStatus(422)->assertJsonPath('code', 'channel_taken');
        $this->asUser($a->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code2])->assertOk()->assertJsonPath('data.attendance.status', 'present');
        $this->asUser($a->user)->postJson("/api/v1/me/sessions/$s2/join")->assertStatus(422)->assertJsonPath('code', 'channel_taken');
        $roster2 = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/sessions/$s2/attendance")->assertOk()->json('data');
        $this->assertSame([1, 1], [$roster2['counts']['in_person'], $roster2['counts']['remote']]);

        // The clock completes the workshop after the last session and locks every record: absentees are written down.
        $this->travelTo($d2->copy()->addHours(3));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $w = PlcWorkshop::find($id);
        $this->assertSame('awaiting_evaluation', $w->workflow_state); // completed by the clock, then straight into the evaluation state (Phase 6)
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('kind', 'transition')->where('decision', 'completed')->exists());
        $this->assertNotNull(ProgramSession::find($s2)->attendance_locked_at);
        $this->assertSame('absent', Attendance::where('program_session_id', $s2)->where('employee_id', $c->id)->value('status'));
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s2/attendance/correct", ['registration_id' => $cReg, 'status' => 'present', 'reason' => 'كان حاضراً'])->assertOk(); // the centre may still correct after the lock
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s2/attendance/unlock", [])->assertStatus(422)->assertJsonPath('code', 'plc_reason_required');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/$s2/attendance/unlock", ['reason' => 'مراجعة'])->assertOk()->assertJsonPath('data.session.locked_at', null);

        // The report and its export.
        $report = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/attendance")->assertOk()->json('data');
        $this->assertCount(2, $report['sessions']);
        $this->assertSame(['left_early', 'present'], array_column(collect($report['rows'])->firstWhere('name', $a->user->displayName())['cells'], 'status'));
        $this->assertSame(3, $report['sessions'][1]['present']);
        $this->assertGreaterThan(0, (float) $report['overall']['percent']);
        $this->assertStringContainsString($a->user->displayName(), $this->asUser($officer)->get("/api/v1/admin/plc/workshops/$id/attendance/export")->assertOk()->getContent());
    }
}
