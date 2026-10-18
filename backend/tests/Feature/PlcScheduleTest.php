<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CalendarDay;
use App\Models\PlcDecision;
use App\Models\PlcSessionTrainer;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\FeatureSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 4: sessions per delivery mode, calendar and resource conflicts, alternatives, the scheduled transition with invitations, changes after invitations, the calendar. */
class PlcScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('plc', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function trainer(): Trainer
    {
        return Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
    }

    /** The n-th training day from today at 09:00 (Fridays and Saturdays skipped). Counted one training day at a time, so two offsets never meet on one date, whatever today's weekday. */
    private function day(int $offset = 10): Carbon
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

    public function test_sessions_are_planned_against_the_calendar_rooms_trainers_and_each_other_then_scheduled_with_invitations(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls']);
        $other = $this->makeSchool(['gender' => 'girls']);
        [$a, $b] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $other->id])];
        $room = TrainingRoom::create(['code' => 'R1', 'name_ar' => 'قاعة ١', 'name_en' => 'Room 1', 'capacity' => 20, 'status' => 'active']);
        $small = TrainingRoom::create(['code' => 'R2', 'name_ar' => 'قاعة صغيرة', 'name_en' => 'Small', 'capacity' => 1, 'status' => 'active']);
        $lead = $this->trainer();
        $co = $this->trainer();
        $d1 = $this->day(10);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة التقويم', 'host_school_id' => $host->id, 'start_date' => $d1->toDateString(), 'end_date' => $d1->toDateString(), 'capacity' => 10, 'delivery_mode' => 'in_person'])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $other->id, 'seats' => 2]]])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $lead->id, 'role' => 'lead'])->assertSuccessful();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $co->id, 'role' => 'co'])->assertSuccessful();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $unassigned = $this->trainer();

        // A session may be drafted before approval, but not scheduled; the overview says what is missing.
        $s1 = ['starts_at' => $d1->toIso8601String(), 'ends_at' => $d1->copy()->addHours(3)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$lead->id]];
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", array_replace($s1, ['trainer_ids' => [$unassigned->id]]))->assertStatus(422)->assertJsonPath('code', 'plc_trainer_not_assigned');
        $sid = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", $s1)->assertCreated()->json('data.id');
        $o = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/sessions")->assertOk()->json('data');
        $this->assertTrue($o['readiness']['complete']);
        $this->assertFalse($o['can']['schedule'], 'not approved yet');
        $this->assertSame('lead', $o['sessions'][0]['trainers'][0]['role']);
        $this->assertSame($lead->id, ProgramSession::find($sid)->trainer_id, 'the engine keeps the lead trainer on the session');

        // The calendar, the room, the trainers and the workshop's own sessions are respected; a remote session needs its link to be complete.
        $closed = $this->day(14);
        CalendarDay::create(['date' => $closed->toDateString(), 'type' => 'exam', 'title_ar' => 'امتحانات', 'title_en' => 'Exams']);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $closed->toIso8601String(), 'ends_at' => $closed->copy()->addHours(2)->toIso8601String(), 'trainer_ids' => [$lead->id]])->assertStatus(422)->assertJsonPath('code', 'calendar_closed');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", array_replace($s1, ['starts_at' => $d1->copy()->addHour()->toIso8601String()]))->assertStatus(422)->assertJsonPath('code', 'plc_session_overlap');
        $d2 = $this->day(12);
        $busy = $this->makeProgram(['start_date' => $d2->toDateString(), 'end_date' => $d2->toDateString()]);
        ProgramSession::create(['program_id' => $busy->id, 'sequence' => 1, 'title_ar' => 'أخرى', 'title_en' => 'Other', 'starts_at' => $d2, 'ends_at' => $d2->copy()->addHours(2), 'training_room_id' => $room->id, 'trainer_id' => $lead->id, 'status' => 'scheduled']);
        $s2 = ['starts_at' => $d2->toIso8601String(), 'ends_at' => $d2->copy()->addHours(2)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$lead->id, $co->id]];
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", $s2)->assertStatus(422)->assertJsonPath('code', 'room_conflict');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", array_replace($s2, ['training_room_id' => null, 'location_text' => 'مختبر المدرسة']))->assertStatus(422)->assertJsonPath('code', 'trainer_conflict');
        $alt = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions/alternatives", ['starts_at' => $s2['starts_at'], 'ends_at' => $s2['ends_at'], 'training_room_id' => $room->id, 'trainer_ids' => [$lead->id]])->assertOk()->json('data');
        $this->assertNotEmpty($alt['slots']);
        $this->assertNotContains($closed->toDateString(), array_column($alt['slots'], 'date'), 'closed days are never suggested');
        $this->assertSame(0, ProgramSession::where('program_id', $id)->count() - 1, 'alternatives are never applied by themselves');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", array_replace($s2, ['training_room_id' => null, 'location_text' => 'مختبر المدرسة', 'force' => true]))->assertStatus(422)->assertJsonPath('code', 'plc_force_reason');
        $sid2 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", array_replace($s2, ['training_room_id' => null, 'location_text' => 'مختبر المدرسة', 'force' => true, 'force_reason' => 'الجلسة الأخرى مسجلة ولا تحتاج حضوره']))->assertCreated()->json('data.id');
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('decision', 'trainer_double_booking_overridden')->exists());
        $this->assertSame(2, PlcSessionTrainer::where('program_session_id', $sid2)->count());
        $d3 = $this->day(16);
        $sid3 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d3->toIso8601String(), 'ends_at' => $d3->copy()->addHours(2)->toIso8601String(), 'mode' => 'online', 'trainer_ids' => [$co->id]])->assertCreated()->json('data.id');
        $o = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/sessions")->assertOk()->json('data');
        $this->assertFalse($o['readiness']['complete']);
        $this->assertSame(['link'], collect($o['readiness']['missing'])->pluck('issue')->all());
        $this->assertSame($d3->toDateString(), PlcWorkshop::find($id)->program->end_date->toDateString(), 'the programme dates stretch to hold the sessions');
        $this->assertSame('trainer', collect($o['conflicts'])->firstWhere('session_id', $sid2)['issues'][0]['kind'], 'the overridden trainer clash stays visible in the conflicts panel');
        $this->asUser($officer)->putJson("/api/v1/admin/plc/workshops/$id/sessions/$sid3", ['starts_at' => $d3->toIso8601String(), 'ends_at' => $d3->copy()->addHours(2)->toIso8601String(), 'mode' => 'online', 'online_url' => 'https://teams.test/j/1', 'online_passcode' => '4411', 'trainer_ids' => [$co->id]])->assertOk();
        $o = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/sessions")->assertOk()->json('data');
        $this->assertTrue($o['readiness']['complete']);
        $this->assertTrue($o['sessions'][2]['online']['protected']);

        // Scheduling needs approval first; then the state moves and the seated people and trainers are invited.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertStatus(422)->assertJsonPath('code', 'plc_bad_transition');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk()->assertJsonPath('data.state', 'approved');
        $r = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertOk()->json('data');
        $this->assertSame('scheduled', $r['state']);
        $this->assertSame(2, $r['trainees']);
        $this->assertSame(2, $r['trainers']);
        $this->assertSame(1, AppNotification::where('user_id', $a->user_id)->where('type', 'plc.scheduled')->count());
        $this->assertSame(1, AppNotification::where('user_id', $lead->user_id)->where('type', 'plc.scheduled')->count());
        $this->assertSame(2, Registration::where('program_id', $id)->where('status', 'approved')->count());

        // After the invitations a change needs a reason, is recorded and announced; removing a session cancels it.
        $this->asUser($officer)->putJson("/api/v1/admin/plc/workshops/$id/sessions/$sid", array_replace($s1, ['starts_at' => $d1->copy()->addHour()->toIso8601String(), 'ends_at' => $d1->copy()->addHours(4)->toIso8601String()]))->assertStatus(422)->assertJsonPath('code', 'plc_reason_required');
        $this->asUser($officer)->putJson("/api/v1/admin/plc/workshops/$id/sessions/$sid", array_replace($s1, ['starts_at' => $d1->copy()->addHour()->toIso8601String(), 'ends_at' => $d1->copy()->addHours(4)->toIso8601String(), 'reason' => 'طلب المدرسة المستضيفة']))->assertOk();
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('kind', 'session')->where('decision', 'updated')->where('reason', 'طلب المدرسة المستضيفة')->exists());
        $this->assertSame(1, AppNotification::where('user_id', $b->user_id)->where('type', 'plc.schedule_changed')->count());
        $this->asUser($officer)->deleteJson("/api/v1/admin/plc/workshops/$id/sessions/$sid3", ['reason' => 'تعذّر المدرب'])->assertOk();
        $this->assertSame('cancelled', ProgramSession::find($sid3)->status);
        $this->assertSame(1, AppNotification::where('user_id', $a->user_id)->where('type', 'plc.session_cancelled')->count());

        // The calendar lists the live sessions inside the span, with the closed day, and honours the filters.
        $from = $d1->copy()->startOfMonth()->toDateString();
        $to = $d3->copy()->endOfMonth()->toDateString();
        $cal = $this->asUser($officer)->getJson("/api/v1/admin/plc/calendar?from=$from&to=$to")->assertOk()->json('data');
        $this->assertCount(2, $cal['sessions']);
        $this->assertFalse(collect($cal['days'])->firstWhere('date', $closed->toDateString())['training_allowed']);
        $this->assertCount(1, $this->asUser($officer)->getJson("/api/v1/admin/plc/calendar?from=$from&to=$to&trainer_id={$co->id}")->json('data.sessions'));
        $this->assertCount(2, $this->asUser($officer)->getJson("/api/v1/admin/plc/calendar?from=$from&to=$to&school_id={$other->id}")->json('data.sessions'));
        $this->assertCount(0, $this->asUser($officer)->getJson("/api/v1/admin/plc/calendar?from=$from&to=$to&mode=online")->json('data.sessions'));
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/admin/plc/calendar?from=$from&to=$to")->assertForbidden();
    }
}
