<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\GroupTrainer;
use App\Models\PlcDecision;
use App\Models\PlcNomination;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Services\FeatureSettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 3: nominations with seats, waiting list, duplicates, clashes and materialisation; trainers with conflicts, confirmation and replacement. */
class PlcPeopleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('plc', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function trainer(array $attrs = []): Trainer
    {
        return Trainer::create($attrs + ['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4]);
    }

    public function test_nominations_follow_seats_scope_duplicates_and_clashes_and_become_registrations_on_approval(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls']);
        $other = $this->makeSchool(['gender' => 'girls']);
        $outside = $this->makeSchool(['gender' => 'girls']);
        [$a, $b, $c] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id])];
        $o1 = $this->makeEmployee(['school_id' => $other->id]);
        $x = $this->makeEmployee(['school_id' => $outside->id]);
        $start = today()->addDays(10)->toDateString();

        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة', 'host_school_id' => $host->id, 'start_date' => $start, 'capacity' => 3])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schools", ['schools' => [['id' => $other->id, 'seats' => 1]]])->assertOk();

        // A clash: $b already holds a seat in another programme on the same dates.
        $clashing = $this->makeProgram(['start_date' => $start, 'end_date' => $start]);
        Registration::create(['program_id' => $clashing->id, 'employee_id' => $b->id, 'source' => 'center_nomination', 'status' => 'approved', 'attendance_percent' => 0]);

        $eligible = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/people/eligible")->assertOk()->json('data');
        $this->assertCount(4, $eligible, 'host and participating schools only');
        $this->assertCount(1, collect($eligible)->firstWhere('employee_id', $b->id)['clashes']);
        $this->assertNull(collect($eligible)->firstWhere('employee_id', $x->id));

        $r = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id, $x->id, $o1->id]])->assertOk()->json('data');
        $this->assertSame([$a->id, $o1->id], array_column($r['added'], 'employee_id'));
        $this->assertSame(['plc_trainee_clash', 'plc_school_not_participating'], array_column($r['skipped'], 'code'));
        // Duplicate refused; the clash can be overridden with a reason by the centre; seats per school then the capacity put people on the waiting list.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id]])->assertOk()->assertJsonPath('data.skipped.0.code', 'plc_duplicate_nomination');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$b->id], 'force' => true])->assertStatus(422)->assertJsonPath('code', 'plc_force_reason');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$b->id], 'force' => true, 'force_reason' => 'البرنامج الآخر مسائي'])->assertOk()->assertJsonPath('data.added.0.status', 'nominated');
        $o2 = $this->makeEmployee(['school_id' => $other->id]);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$o2->id]])->assertOk()->assertJsonPath('data.added.0.status', 'waitlisted'); // the other school has one seat
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$c->id]])->assertOk()->assertJsonPath('data.added.0.status', 'waitlisted'); // capacity 3 reached
        $this->assertTrue(PlcDecision::where('subject_id', $id)->where('decision', 'clash_overridden')->exists());
        $this->assertSame(1, AppNotification::where('user_id', $a->user_id)->where('type', 'plc.nominated')->count());

        // A school principal decides only for their own school; a reason is required to reject; a freed seat promotes the waiting person of that school.
        $principal = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $other->id], $principal);
        $list = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->assertOk()->json('data');
        $this->assertSame(3, $list['seated']);
        $this->assertSame(2, $list['waitlisted']);
        $nomA = collect($list['rows'])->firstWhere('employee_id', $a->id)['id'];
        $nomO1 = collect($list['rows'])->firstWhere('employee_id', $o1->id)['id'];
        $this->asUser($principal)->postJson("/api/v1/admin/plc/workshops/$id/nominations/$nomA", ['to' => 'approved'])->assertForbidden();
        $this->asUser($principal)->postJson("/api/v1/admin/plc/workshops/$id/nominations/$nomO1", ['to' => 'rejected'])->assertStatus(422)->assertJsonPath('code', 'plc_reason_required');
        $this->asUser($principal)->postJson("/api/v1/admin/plc/workshops/$id/nominations/$nomO1", ['to' => 'rejected', 'reason' => 'في إجازة'])->assertOk();
        $this->assertSame('nominated', PlcNomination::where('employee_id', $o2->id)->value('status'), 'the waiting person of the school was promoted');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/$nomA", ['to' => 'completed'])->assertStatus(422)->assertJsonPath('code', 'plc_nomination_bad_transition');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/$nomA", ['to' => 'approved'])->assertOk();
        $this->assertNull(PlcNomination::find($nomA)->registration_id, 'no registration before the workshop is approved');

        // Approving the workshop materialises approved nominations as approved registrations on the programme engine.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve")->assertOk()->assertJsonPath('data.state', 'approved');
        $nom = PlcNomination::find($nomA)->refresh();
        $this->assertNotNull($nom->registration_id);
        $this->assertSame(Registration::STATUS_APPROVED, $nom->registration->status);
        $this->assertSame(1, Program::find($id)->groups()->count());
        // A nomination approved afterwards registers immediately; withdrawing it withdraws the registration too.
        $nomB = PlcNomination::where('program_id', $id)->where('employee_id', $b->id)->first();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$nomB->id}", ['to' => 'approved'])->assertOk();
        $this->assertSame(Registration::STATUS_APPROVED, $nomB->refresh()->registration->status);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$nomB->id}", ['to' => 'withdrawn', 'reason' => 'اعتذر'])->assertOk();
        $this->assertSame(Registration::STATUS_WITHDRAWN, $nomB->refresh()->registration->status);
    }

    public function test_trainers_are_proposed_checked_for_double_booking_confirmed_and_replaced_with_notice(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $host = $this->makeSchool();
        $start = today()->addDays(10);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة', 'host_school_id' => $host->id, 'start_date' => $start->toDateString(), 'domain' => 'assessment', 'total_hours' => 4])->assertCreated()->json('data.id');
        $lead = $this->trainer(['name_ar' => 'قائد', 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $co = $this->trainer(['name_ar' => 'مساعد', 'specializations' => ['leadership']]);
        $busy = $this->trainer(['name_ar' => 'مشغول']);
        $otherProgram = $this->makeProgram(['start_date' => $start, 'end_date' => $start]);
        $this->makeSession($otherProgram, $start->copy()->setTime(9, 0))->update(['trainer_id' => $busy->id]);

        $sugg = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers/suggest")->assertOk()->json('data');
        $this->assertSame('قائد', $sugg[0]['name'], 'specialisation match and available first');
        $this->assertFalse(collect($sugg)->firstWhere('trainer_id', $busy->id)['available'] ?? true);

        $this->asUser($this->makeUser(Role::ACADEMIC_DEPUTY))->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $lead->id, 'role' => 'lead'])->assertForbidden();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $busy->id, 'role' => 'lead'])->assertStatus(422)->assertJsonPath('code', 'plc_trainer_busy');
        $rows = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $lead->id, 'role' => 'lead'])->assertCreated()->json('data');
        $this->assertSame('proposed', $rows[0]['status']);
        $this->assertEquals(4, $rows[0]['hours']);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $lead->id, 'role' => 'co'])->assertStatus(422)->assertJsonPath('code', 'assignment_duplicate');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $co->id, 'role' => 'co', 'hours' => 2])->assertCreated()->assertJsonCount(2, 'data');
        $this->assertTrue(AppNotification::where('user_id', $lead->user_id)->where('type', 'trainer.assignment_proposed')->exists());

        // Nothing is confirmed until it is confirmed; a replacement after confirmation needs a reason and tells the old trainer.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$rows[0]['id']}/confirm")->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$rows[0]['id']}/confirm")->assertStatus(422)->assertJsonPath('code', 'assignment_decided');
        $replacement = $this->trainer(['name_ar' => 'بديل']);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$rows[0]['id']}/replace", ['trainer_id' => $replacement->id, 'reason' => ''])->assertStatus(422);
        $after = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$rows[0]['id']}/replace", ['trainer_id' => $replacement->id, 'reason' => 'سفر المدرب'])->assertOk()->json('data');
        $this->assertSame(['replaced', 'proposed', 'proposed'], array_column($after, 'status'));
        $this->assertSame('lead', collect($after)->firstWhere('trainer_id', $replacement->id)['role']);
        $this->assertSame('replaced', GroupTrainer::find($rows[0]['id'])->status);
        $this->assertTrue(AppNotification::where('user_id', $lead->user_id)->where('type', 'trainer.assignment_decided')->exists());
        $this->assertSame(['proposed', 'proposed', 'confirmed', 'proposed', 'replaced'], PlcDecision::where('subject_id', $id)->where('kind', 'trainer')->orderBy('created_at')->pluck('decision')->all());
        $this->asUser($officer)->deleteJson("/api/v1/admin/plc/workshops/$id/trainers/{$after[2]['id']}")->assertOk();
    }
}
