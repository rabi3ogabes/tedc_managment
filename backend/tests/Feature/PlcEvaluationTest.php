<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\EvaluationAssignment;
use App\Models\ImpactSurvey;
use App\Models\PlcCommunity;
use App\Models\PlcDecision;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\AttendanceService;
use App\Services\FeatureSettings;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 6: the suggestion rules, timing per session and trainer, deduplication, delivery tracking, impact follow-ups, the close and the exception. */
class PlcEvaluationTest extends TestCase
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

    public function test_evaluations_follow_the_rules_with_targets_dedupe_tracking_impact_and_the_close(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls']);
        [$a, $b, $c] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id])];
        $room = TrainingRoom::create(['code' => 'R6', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 20, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'مدربة الورشة', 'name_en' => 'Workshop trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $community = PlcCommunity::create(['code' => 'C-'.uniqid(), 'title_ar' => 'مجتمع التقويم', 'title_en' => 'Assessment community', 'status' => 'active', 'lead_user_id' => $head->id, 'host_school_id' => $host->id, 'created_by' => $officer->id]);

        // The policy: session forms on (multi-session workshops), the workshop form mandatory, 60 % closes.
        $rules = $this->asUser($admin)->putJson('/api/v1/admin/plc/settings', ['evaluation_rules' => ['session' => ['enabled' => true, 'due_days' => 2], 'close_response_rate' => 60]])->assertOk()->json('data.evaluation_rules');
        $this->assertTrue($rules['session']['enabled']);
        $this->assertTrue($rules['workshop']['mandatory']);
        $this->assertSame(['rased'], $rules['impact_sources']);

        $d1 = $this->day(10);
        $d2 = $this->day(12);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة التقويم', 'host_school_id' => $host->id, 'start_date' => $d1->toDateString(), 'end_date' => $d2->toDateString(), 'capacity' => 10, 'delivery_mode' => 'in_person', 'source' => 'rased', 'source_ref' => 'R-77', 'community_id' => $community->id, 'min_attendance_percent' => 50])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id, $c->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $trainer->id, 'role' => 'lead'])->assertSuccessful();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $s1 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d1->toIso8601String(), 'ends_at' => $d1->copy()->addHours(3)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer->id]])->assertCreated()->json('data.id');
        $s2 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d2->toIso8601String(), 'ends_at' => $d2->copy()->addHours(2)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer->id]])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertOk()->assertJsonPath('data.state', 'scheduled');

        // Nothing goes out before the workshop has started; the plan already shows every instrument with its reason.
        $this->asUser($a->user)->getJson("/api/v1/admin/plc/workshops/$id/evaluation")->assertForbidden();
        $plan = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/evaluation")->assertOk()->json('data');
        $this->assertSame(['workshop', 'trainer', 'session', 'session', 'community'], array_column($plan['instruments'], 'instrument'));
        $this->assertSame(['waiting', 'waiting', 'waiting', 'waiting', 'waiting'], array_column($plan['instruments'], 'status'));
        $this->assertSame(5, collect($plan['instruments'])->firstWhere('instrument', 'community')['targets']); // three trainees, the workshop's coordinator and the community's lead
        $this->assertTrue($plan['impact']['applies']);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/send", ['instruments' => ['workshop']])->assertStatus(422)->assertJsonPath('code', 'plc_evaluation_state');
        $this->assertSame(0, EvaluationAssignment::where('program_id', $id)->count());

        // Session 1 ends: the session form goes to the two who attended it; the trainer's form waits for the trainer's last session.
        $this->travelTo($d1->copy()->addMinutes(5));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $code = app(AttendanceService::class)->currentQr(ProgramSession::find($s1))['payload'];
        $this->asUser($a->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code])->assertOk();
        $this->asUser($b->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code])->assertOk();
        $this->travelTo($d1->copy()->addHours(4));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('in_progress', PlcWorkshop::find($id)->workflow_state);
        $session1 = EvaluationAssignment::where('program_id', $id)->where('program_session_id', $s1)->get();
        $this->assertCount(2, $session1);
        $this->assertSame('plc.session', $session1->first()->rule_key);
        $this->assertEqualsCanonicalizing([$a->user_id, $b->user_id], $session1->pluck('respondent_user_id')->all());
        $this->assertSame(0, EvaluationAssignment::where('program_id', $id)->whereNotNull('trainer_id')->count());

        // Session 2 ends: the workshop completes; the workshop, trainer and community forms go out; impact follow-ups are scheduled for those who completed.
        $this->travelTo($d2->copy()->addMinutes(5));
        $code2 = app(AttendanceService::class)->currentQr(ProgramSession::find($s2))['payload'];
        $this->asUser($a->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code2])->assertOk();
        $this->asUser($b->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code2])->assertOk();
        $this->travelTo($d2->copy()->addHours(3));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $w = PlcWorkshop::find($id);
        $this->assertSame('awaiting_evaluation', $w->workflow_state);
        $this->assertSame(3, EvaluationAssignment::where('program_id', $id)->where('rule_key', 'plc.workshop')->count());
        $this->assertSame(3, EvaluationAssignment::where('program_id', $id)->where('trainer_id', $trainer->id)->where('rule_key', 'plc.trainer')->count());
        $this->assertSame(5, EvaluationAssignment::where('program_id', $id)->where('rule_key', 'plc.community')->count());
        $this->assertSame(2, EvaluationAssignment::where('program_id', $id)->where('program_session_id', $s2)->count());
        $this->assertSame(Registration::STATUS_COMPLETED, Registration::where('program_id', $id)->where('employee_id', $a->id)->value('status'));
        $this->assertSame(Registration::STATUS_APPROVED, Registration::where('program_id', $id)->where('employee_id', $c->id)->value('status')); // below the minimum attendance
        $this->assertSame(2, ImpactSurvey::where('program_id', $id)->where('stage_days', 45)->count());
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('kind', 'evaluation')->where('decision', 'sent')->exists());

        // Dedupe: sending again creates nothing; the tracking view shows the delivery evidence and hides scores below the minimum.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/send", ['instruments' => ['workshop', 'trainer']])->assertOk()->assertJsonPath('data.sent', 0);
        $tracking = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/evaluation")->assertOk()->json('data');
        $workshop = collect($tracking['instruments'])->firstWhere('instrument', 'workshop');
        $this->assertSame(['sent', 3, 3, 0], [$workshop['status'], $workshop['assigned'], $workshop['notified'], $workshop['submitted']]);
        $this->assertSame(1, AppNotification::where('user_id', $a->user_id)->where('type', 'evaluation.assigned')->where('data->assignment_id', EvaluationAssignment::where('program_id', $id)->where('rule_key', 'plc.workshop')->where('respondent_user_id', $a->user_id)->value('id'))->count());
        $this->assertFalse($tracking['completion']['complete']);
        $this->assertSame('workshop', $tracking['completion']['blocking'][0]['instrument']);
        $this->assertSame(2, $tracking['impact']['trainee']['scheduled']);
        $this->assertTrue($tracking['can']['send'] && $tracking['can']['close'] && ! $tracking['can']['exception']);

        // The trainee sees which trainer and which session each form is about.
        $mine = $this->asUser($a->user)->getJson('/api/v1/me/evaluations')->assertOk()->json('data');
        $this->assertSame('trainer', collect($mine)->firstWhere('rule_key', 'plc.trainer')['target']['kind']);
        $this->assertSame('مدربة الورشة', collect($mine)->firstWhere('rule_key', 'plc.trainer')['target']['name_ar']);
        $this->assertSame('session', collect($mine)->firstWhere('rule_key', 'plc.session')['target']['kind']);

        // Closing needs the mandatory instrument complete, or a documented exception by the centre.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/close")->assertStatus(422)->assertJsonPath('code', 'plc_evaluation_incomplete');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/close", ['exception' => true, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'plc_exception_permission');
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/close", ['exception' => true])->assertStatus(422)->assertJsonPath('code', 'plc_reason_required');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/remind", ['instrument' => 'workshop'])->assertOk()->assertJsonPath('data.reminded', 3);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/remind", ['instrument' => 'workshop'])->assertOk()->assertJsonPath('data.reminded', 0); // once a day

        // Two of three answer the workshop form: 66 % ≥ 60 %, so the clock closes the workshop.
        $answers = ['objectives' => 5, 'content' => 4, 'methods' => 5, 'organisation' => 4, 'apply' => 5, 'overall' => 5, 'strengths' => 'التعاون'];
        foreach ([$a, $b] as $e) {
            $aid = EvaluationAssignment::where('program_id', $id)->where('rule_key', 'plc.workshop')->where('respondent_user_id', $e->user_id)->value('id');
            $this->asUser($e->user)->postJson("/api/v1/me/evaluations/$aid", ['answers' => $answers])->assertSuccessful();
        }
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('closed', PlcWorkshop::find($id)->workflow_state);
        $final = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/evaluation")->assertOk()->json('data');
        $this->assertSame('complete', collect($final['instruments'])->firstWhere('instrument', 'workshop')['status']);
        $this->assertNull(collect($final['instruments'])->firstWhere('instrument', 'workshop')['score']); // two answers are below the anonymity minimum of three
        $this->assertTrue(collect($final['instruments'])->firstWhere('instrument', 'workshop')['score_hidden']);
        $this->assertStringContainsString($a->user->displayName(), $this->asUser($officer)->get("/api/v1/admin/plc/workshops/$id/evaluation/export")->assertOk()->getContent());
    }

    public function test_a_cancelled_workshop_expires_its_unanswered_forms_and_the_exception_closes_an_incomplete_one(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls']);
        [$a, $b] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id])];
        $room = TrainingRoom::create(['code' => 'R7', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 20, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $d1 = $this->day(5);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة قصيرة', 'host_school_id' => $host->id, 'start_date' => $d1->toDateString(), 'end_date' => $d1->toDateString(), 'capacity' => 10, 'delivery_mode' => 'in_person', 'source' => 'centre'])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $trainer->id, 'role' => 'lead'])->assertSuccessful();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d1->toIso8601String(), 'ends_at' => $d1->copy()->addHours(2)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer->id]])->assertCreated();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertOk();

        // Started, so the coordinator may send the workshop form by hand (single session: the session form does not apply, no community, no impact for a centre workshop).
        $this->travelTo($d1->copy()->addMinutes(10));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $sent = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/send", ['instruments' => ['workshop', 'session', 'community']])->assertOk()->json('data');
        $this->assertSame(2, $sent['sent']);
        $this->assertSame(['off', 'not_applicable'], [collect($sent['instruments'])->firstWhere('instrument', 'session')['status'], collect($sent['instruments'])->firstWhere('instrument', 'community')['status']]);
        $this->assertFalse($sent['impact']['applies']);

        // The workshop completes with nobody answering; the centre closes it with a documented exception.
        $this->travelTo($d1->copy()->addHours(3));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('awaiting_evaluation', PlcWorkshop::find($id)->workflow_state);
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/close", ['exception' => true, 'reason' => 'انتهى الفصل الدراسي'])->assertOk()->assertJsonPath('data.workshop.state', 'closed');
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('kind', 'exception')->where('decision', 'evaluation_waived')->exists());

        // A cancelled workshop: unanswered forms expire.
        $d3 = $this->day(8);
        $id2 = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة ثانية', 'host_school_id' => $host->id, 'start_date' => $d3->toDateString(), 'end_date' => $d3->toDateString(), 'capacity' => 10, 'delivery_mode' => 'in_person', 'source' => 'centre'])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/nominations", ['employee_ids' => [$a->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id2/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $trainer2 = Trainer::create(['name_ar' => 'مدرب ثانٍ', 'name_en' => 'Second trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/trainers", ['trainer_id' => $trainer2->id, 'role' => 'lead'])->assertSuccessful();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id2/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/sessions", ['starts_at' => $d3->toIso8601String(), 'ends_at' => $d3->copy()->addHours(2)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer2->id]])->assertCreated();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id2/approve", ['note' => 'موافق'])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/schedule")->assertOk();
        $this->travelTo($d3->copy()->addMinutes(10));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/evaluation/send", ['instruments' => ['workshop']])->assertOk()->assertJsonPath('data.sent', 1);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id2/cancel", ['reason' => 'ظرف طارئ'])->assertOk();
        $this->assertSame('expired', EvaluationAssignment::where('program_id', $id2)->where('rule_key', 'plc.workshop')->value('status'));
    }
}
