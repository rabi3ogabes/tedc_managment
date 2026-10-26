<?php

namespace Tests\Feature;

use App\Models\CalendarApproval;
use App\Models\CalendarRequest;
use App\Models\CalendarWeeklyRule;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\SaberRevision;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use App\Services\CalendarService;
use App\Services\RoomService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** سابر+ phase 2 (docs/saber/02-scheduling-tools.md): plan of the coming days, cancellation and reactivation, closed-day requests, weekly holidays. */
class SaberSchedulingTest extends TestCase
{
    private function room(string $code): TrainingRoom
    {
        return TrainingRoom::create(['code' => $code, 'name_ar' => "قاعة {$code}", 'name_en' => "Room {$code}", 'capacity' => 30, 'status' => 'active']);
    }

    private function trainer(): Trainer
    {
        return Trainer::create(['user_id' => $this->makeUser(Role::TRAINER)->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'status' => 'active', 'source' => 'center']);
    }

    private function program(array $attributes = []): Program
    {
        return $this->makeProgram($attributes + ['total_hours' => 15, 'delivery_mode' => 'in_person']);
    }

    private function sessionOn(Program $program, string $date, array $extra = []): ProgramSession
    {
        $tz = config('app.timezone');

        return $program->sessions()->create($extra + ['training_group_id' => $program->groups()->first()->id, 'title_ar' => 'جلسة', 'title_en' => 'Session', 'mode' => 'in_person',
            'starts_at' => CarbonImmutable::parse("{$date} 08:00", $tz), 'ends_at' => CarbonImmutable::parse("{$date} 13:00", $tz)]);
    }

    private function publish(Program $program): void
    {
        $rev = SaberRevision::where('program_id', $program->id)->whereIn('status', SaberRevision::OPEN)->firstOrFail();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->postJson("/api/v1/admin/saber/reviews/{$rev->id}/publish")->assertOk();
    }

    public function test_the_plan_preview_marks_each_date_free_busy_or_off_and_saves_nothing(): void
    {
        $room = $this->room('P1');
        $this->sessionOn($this->program(), '2026-11-08', ['training_room_id' => $room->id]);   // Sunday, taken by another programme
        $group = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);

        // Sundays from 1 Nov 2026: 1, 8, 15 (and every two weeks: 1, 15, 29).
        $res = $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/plan/preview", ['weekday' => 0, 'every' => 1, 'count' => 3, 'from' => '2026-10-30', 'room_id' => $room->id])->assertOk();
        $this->assertSame(['2026-11-01', '2026-11-08', '2026-11-15'], collect($res->json('data'))->pluck('date')->all());
        $this->assertSame(['free', 'busy', 'free'], collect($res->json('data'))->pluck('status')->all());
        $this->assertSame(3, $res->json('meta.max'));

        $fridays = $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/plan/preview", ['weekday' => 5, 'every' => 2, 'count' => 2, 'from' => '2026-11-01'])->assertOk();
        $this->assertSame(['2026-11-06', '2026-11-20'], collect($fridays->json('data'))->pluck('date')->all());
        $this->assertSame(['off', 'off'], collect($fridays->json('data'))->pluck('status')->all());
        $this->assertSame(0, $group->sessions()->count());
    }

    public function test_applying_a_plan_replaces_the_days_and_skips_a_taken_room_with_a_notice(): void
    {
        $room = $this->room('P2');
        $trainer = $this->trainer();
        $this->sessionOn($this->program(), '2026-11-08', ['training_room_id' => $room->id]);
        $group = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $this->asUser($coordinator)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/1", ['date' => '2026-11-02'])->assertOk();

        $res = $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/plan/apply", ['dates' => ['2026-11-15', '2026-11-01', '2026-11-08'], 'room_id' => $room->id, 'trainer_id' => $trainer->id])->assertOk();

        $this->assertSame(['2026-11-01', '2026-11-08', '2026-11-15'], collect($res->json('data.days'))->pluck('date')->all());
        $this->assertSame([$room->id, null, $room->id], collect($res->json('data.days'))->pluck('room_id')->all());
        $this->assertContains('warning', collect($res->json('notices'))->pluck('type')->all());
        $this->assertSame(0, $group->sessions()->count(), 'the plan is a draft until published');
        $this->publish($group->program);
        $this->assertSame(3, $group->sessions()->count(), 'the old day was replaced');

        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/plan/apply", ['dates' => ['2026-11-06']])->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');
    }

    public function test_cancelling_a_group_frees_its_room_and_the_last_group_takes_the_programme_and_reactivation_restores_both(): void
    {
        Storage::fake('local');
        $room = $this->room('C1');
        $program = $this->program();
        $group = $program->groups()->first();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($head)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/1", ['date' => '2026-11-01', 'room_id' => $room->id])->assertOk();
        $this->publish($program);

        $this->asUser($head)->post("/api/v1/admin/saber/groups/{$group->id}/cancel", ['reason' => 'طلب الإدارة', 'requested_by' => 'إدارة التطوير', 'attachment' => UploadedFile::fake()->create('letter.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();

        $group->refresh();
        $this->assertSame(TrainingGroup::CANCELLED, $group->status);
        $this->assertSame('طلب الإدارة', $group->status_reason);
        $this->assertNotNull($group->cancel_attachment_path);
        $this->assertSame('cancelled', $group->sessions()->first()->status);
        $this->assertTrue(app(RoomService::class)->isFree($room->id, CarbonImmutable::parse('2026-11-01 08:00'), CarbonImmutable::parse('2026-11-01 13:00')), 'the room is free again');
        $this->assertSame(Program::STATUS_CANCELLED, $program->fresh()->status, 'its only group took the programme with it');

        $list = $this->asUser($head)->getJson('/api/v1/admin/saber/cancelled')->assertOk();
        $this->assertSame($program->id, $list->json('data.programs.0.id'));

        $this->asUser($head)->postJson("/api/v1/admin/saber/groups/{$group->id}/reactivate")->assertForbidden();   // super administrator only
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->postJson("/api/v1/admin/saber/groups/{$group->id}/reactivate")->assertOk();

        $this->assertNotSame(TrainingGroup::CANCELLED, $group->fresh()->status);
        $this->assertNotSame(Program::STATUS_CANCELLED, $program->fresh()->status);
        $session = $group->sessions()->first();
        $this->assertSame('scheduled', $session->status);
        $this->assertSame($room->id, $session->training_room_id, 'the room came back');
    }

    public function test_reactivating_restores_a_day_without_a_room_that_was_taken_meanwhile(): void
    {
        $room = $this->room('C2');
        $program = $this->program();
        $group = $program->groups()->first();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($head)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/1", ['date' => '2026-11-01', 'room_id' => $room->id])->assertOk();
        $this->publish($program);
        $this->asUser($head)->postJson("/api/v1/admin/saber/programs/{$program->id}/cancel", ['reason' => 'تأجيل الخطة', 'requested_by' => 'المدير'])->assertOk();
        $this->sessionOn($this->program(), '2026-11-01', ['training_room_id' => $room->id]);   // someone else takes the freed room

        $res = $this->asUser($this->makeUser(Role::SUPER_ADMIN))->postJson("/api/v1/admin/saber/programs/{$program->id}/reactivate")->assertOk();

        $this->assertSame('warning', $res->json('notices.0.type'));
        $this->assertNull($group->sessions()->first()->training_room_id);
        $this->assertSame('scheduled', $group->sessions()->first()->status);
        $this->assertNull($program->fresh()->cancel_reason);
    }

    public function test_a_closed_day_request_is_decided_by_the_calendar_approver_and_opens_the_day(): void
    {
        $group = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $approver = $this->makeUser(Role::SUPER_ADMIN);

        $id = $this->asUser($coordinator)->postJson('/api/v1/admin/saber/offday-requests', ['date' => '2026-11-06', 'group_id' => $group->id, 'day_idx' => 1, 'reason' => 'ورشة طارئة'])->assertCreated()->json('data.id');
        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/offday-requests', ['date' => '2026-11-06', 'group_id' => $group->id, 'reason' => 'مرة أخرى'])->assertUnprocessable()->assertJsonPath('code', 'already_requested');
        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/offday-requests', ['date' => '2026-11-02', 'reason' => 'يوم عادي'])->assertUnprocessable()->assertJsonPath('code', 'calendar_not_closed');
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/offday-requests/{$id}/decide", ['approve' => true])->assertForbidden();

        $this->asUser($approver)->getJson('/api/v1/admin/saber/offday-requests')->assertOk()->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.group.id', $group->id);
        $this->asUser($approver)->postJson("/api/v1/admin/saber/offday-requests/{$id}/decide", ['approve' => true, 'note' => 'موافق'])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertTrue(CalendarApproval::whereDate('date', '2026-11-06')->exists());
        $this->asUser($coordinator)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/1", ['date' => '2026-11-06'])->assertOk();
        $this->asUser($approver)->postJson("/api/v1/admin/saber/offday-requests/{$id}/decide", ['approve' => false])->assertUnprocessable();
    }

    public function test_the_calendar_approver_can_approve_at_once(): void
    {
        $approver = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($approver)->postJson('/api/v1/admin/saber/offday-requests', ['date' => '2026-11-07', 'reason' => 'يوم مفتوح', 'instant' => true])->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, CalendarRequest::where('status', 'approved')->count());
        $this->assertTrue(CalendarApproval::whereDate('date', '2026-11-07')->exists());
    }

    public function test_a_weekly_holiday_closes_that_weekday_for_training_and_may_allow_events(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $group = $this->program()->groups()->first();

        $this->asUser($admin)->postJson('/api/v1/admin/saber/weekly-rules', ['weekday' => 4, 'title_ar' => 'الخميس إجازة', 'allow_events' => true])->assertCreated();   // Thursday
        $this->asUser($admin)->getJson('/api/v1/admin/saber/weekly-rules')->assertOk()->assertJsonPath('data.rules.0.weekday', 4);
        $this->asUser($admin)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/1", ['date' => '2026-11-05'])->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');

        $day = $this->asUser($admin)->getJson("/api/v1/admin/saber/availability?date=2026-11-05&program_id={$group->program_id}&group={$group->id}&idx=1")->assertOk();
        $this->assertFalse($day->json('data.day.training_allowed'));
        $this->assertSame('الخميس إجازة', $day->json('data.day.weekly.title'));
        $this->assertTrue($day->json('data.day.allow_events'));
        $this->assertTrue(app(CalendarService::class)->eventsAllowed(CarbonImmutable::parse('2026-11-05')));

        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson('/api/v1/admin/saber/weekly-rules', ['weekday' => 3])->assertForbidden();
        $this->asUser($admin)->deleteJson('/api/v1/admin/saber/weekly-rules/'.CalendarWeeklyRule::first()->id)->assertNoContent();
        $this->asUser($admin)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/1", ['date' => '2026-11-05'])->assertOk();
    }

    public function test_the_details_window_and_survey_links(): void
    {
        $program = $this->program(['title_ar' => 'برنامج التفاصيل']);
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $this->asUser($coordinator)->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['survey_link' => 'https://forms.example.com/s/1', 'survey_edit_link' => 'https://forms.example.com/e/1'])->assertOk()
            ->assertJsonPath('data.survey_link', 'https://forms.example.com/s/1');
        $this->asUser($coordinator)->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['survey_link' => 'not a link'])->assertUnprocessable();
        $this->asUser($this->makeUser(Role::LOGISTICS_OFFICER))->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['survey_link' => null])->assertForbidden();

        $res = $this->asUser($coordinator)->getJson("/api/v1/admin/saber/programs/{$program->id}/details")->assertOk();
        $this->assertSame('برنامج التفاصيل', $res->json('data.title'));
        $this->assertSame(0, $res->json('data.groups.0.registrations'));
        $this->assertCount(3, $res->json('data.groups.0.days'));
    }
}
