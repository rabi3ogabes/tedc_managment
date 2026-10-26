<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\SaberRevision;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** سابر+ workbench (docs/saber/01-workbench.md): programmes with their groups and days, planned in drafts through شاهين's scheduling rules. */
class SaberWorkbenchTest extends TestCase
{
    private function room(string $code): TrainingRoom
    {
        return TrainingRoom::create(['code' => $code, 'name_ar' => "قاعة {$code}", 'name_en' => "Room {$code}", 'capacity' => 30, 'status' => 'active']);
    }

    private function trainer(): Trainer
    {
        return Trainer::create(['user_id' => $this->makeUser(Role::TRAINER)->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'status' => 'active', 'source' => 'center', 'validated_at' => now()]);
    }

    /** A 15-hour programme: three training days per group. */
    private function program(array $attributes = []): Program
    {
        return $this->makeProgram($attributes + ['total_hours' => 15, 'delivery_mode' => 'in_person']);
    }

    private function day(TrainingGroup $group, int $idx, array $patch, $user)
    {
        return $this->asUser($user)->putJson("/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}/days/{$idx}", $patch);
    }

    private function publish(Program $program): void
    {
        $rev = SaberRevision::where('program_id', $program->id)->whereIn('status', SaberRevision::OPEN)->firstOrFail();
        $this->asUser($this->makeUser(Role::TRAINING_HEAD))->postJson("/api/v1/admin/saber/reviews/{$rev->id}/publish")->assertOk();
    }

    public function test_the_workbench_lists_programmes_with_their_groups_days_and_lights(): void
    {
        $program = $this->program(['title_ar' => 'برنامج القيادة']);
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $overview = $this->asUser($coordinator)->getJson('/api/v1/admin/saber/overview')->assertOk()->assertJsonPath('data.settings.group_delete_enabled', false);
        $this->assertTrue($overview->json('data.can')['saber.day.edit']);
        $this->assertFalse($overview->json('data.can')['saber.group.validate']);
        $this->assertFalse($overview->json('data.can')['saber.publish']);
        $res = $this->asUser($coordinator)->getJson('/api/v1/admin/saber/programs?q=القيادة')->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($program->id, $res->json('data.0.id'));
        $this->assertSame(3, $res->json('data.0.days'));
        $this->assertCount(1, $res->json('data.0.groups'));
        $this->assertCount(3, $res->json('data.0.groups.0.days'));
        $this->assertSame(['total' => 3, 'dates' => 0, 'rooms' => 0, 'trainers' => 0], $res->json('data.0.lights'));
        $this->assertNull($res->json('data.0.draft'));

        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/saber/programs')->assertForbidden();
    }

    public function test_days_are_planned_in_a_draft_with_room_and_trainer_spreading_and_reach_the_live_programme_only_when_published(): void
    {
        $program = $this->program();
        $group = $program->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $i => $date) {
            $this->day($group, $i + 1, ['date' => $date], $coordinator)->assertOk();
        }
        $room = $this->room('S1');
        $trainer = $this->trainer();

        $res = $this->day($group, 2, ['room_id' => $room->id], $coordinator)->assertOk();
        $this->assertSame('success', $res->json('notices.0.type'));
        $res = $this->day($group, 1, ['trainer_id' => $trainer->id], $coordinator)->assertOk();
        $this->assertSame('success', $res->json('notices.0.type'));
        $this->assertSame([$room->id, $room->id, $room->id], array_column($res->json('data.days'), 'room_id'));
        $this->assertSame([$trainer->id, $trainer->id, $trainer->id], array_column($res->json('data.days'), 'trainer_id'));
        $this->assertTrue($res->json('data.changed'));
        $this->assertSame(0, ProgramSession::count(), 'nothing reaches the live programme before review');
        $this->assertSame('draft', $this->asUser($coordinator)->getJson('/api/v1/admin/saber/programs')->json('data.0.draft.status'));

        $this->publish($program);

        $sessions = $group->sessions()->orderBy('sequence')->get();
        $this->assertSame([1, 2, 3], $sessions->pluck('sequence')->all());
        $this->assertSame([$room->id, $room->id, $room->id], $sessions->pluck('training_room_id')->all());
        $this->assertSame([$trainer->id, $trainer->id, $trainer->id], $sessions->pluck('trainer_id')->all());
        $this->assertSame($room->id, $group->fresh()->default_room_id);
        $this->assertSame('08:00', $sessions[0]->starts_at->timezone(config('app.timezone'))->format('H:i'));
    }

    public function test_an_explicit_room_clash_is_refused_and_an_inherited_one_is_cleared_with_a_notice(): void
    {
        $room = $this->room('S2');
        $other = $this->program();
        $tz = config('app.timezone');
        $other->sessions()->create(['training_group_id' => $other->groups()->first()->id, 'title_ar' => 'جلسة', 'title_en' => 'Session', 'mode' => 'in_person', 'training_room_id' => $room->id,
            'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', $tz), 'ends_at' => CarbonImmutable::parse('2026-11-02 13:00', $tz)]);

        $program = $this->program();
        $group = $program->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $this->day($group, 1, ['date' => '2026-11-01', 'room_id' => $room->id], $coordinator)->assertOk();

        $this->day($group, 2, ['date' => '2026-11-02', 'room_id' => $room->id], $coordinator)->assertUnprocessable()->assertJsonPath('code', 'room_conflict');
        $res = $this->day($group, 1, ['date' => '2026-11-02'], $coordinator)->assertOk();

        $this->assertSame('warning', $res->json('notices.0.type'));
        $this->assertNull($res->json('data.days.0.room_id'));
        $this->assertSame('2026-11-02', $res->json('data.days.0.date'));
    }

    public function test_two_drafts_cannot_plan_the_same_room_at_the_same_time(): void
    {
        $room = $this->room('S5');
        $a = $this->program()->groups()->first();
        $b = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $this->day($a, 1, ['date' => '2026-11-01', 'room_id' => $room->id], $coordinator)->assertOk();

        $this->day($b, 1, ['date' => '2026-11-01', 'room_id' => $room->id], $coordinator)->assertUnprocessable()->assertJsonPath('code', 'room_conflict');
        $busy = $this->asUser($coordinator)->getJson("/api/v1/admin/saber/availability?date=2026-11-01&program_id={$b->program_id}&group={$b->id}&idx=1")->assertOk();
        $this->assertArrayHasKey($room->id, $busy->json('data.busy_rooms'));
    }

    public function test_a_closed_day_and_a_time_outside_the_training_day_are_refused(): void
    {
        $group = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);

        $this->day($group, 1, ['date' => '2026-11-06'], $coordinator)->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');   // Friday
        $this->day($group, 1, ['date' => '2026-11-01', 'start_time' => '07:00'], $coordinator)->assertUnprocessable();
        $this->day($group, 2, ['room_id' => $this->room('S3')->id], $coordinator)->assertUnprocessable()->assertJsonPath('code', 'date_first');
        $this->assertSame(0, ProgramSession::count());
    }

    public function test_only_the_head_of_training_approves_a_group_and_pressing_again_withdraws_it(): void
    {
        $group = $this->program()->groups()->first();
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $this->asUser($this->makeUser(Role::COORDINATOR))->postJson("/api/v1/admin/saber/groups/{$group->id}/validate")->assertForbidden();
        $this->asUser($head)->postJson("/api/v1/admin/saber/groups/{$group->id}/validate")->assertOk()->assertJsonPath('data.validated_by_name', $head->name_ar);
        $this->assertNotNull($group->fresh()->validated_at);
        $this->asUser($head)->postJson("/api/v1/admin/saber/groups/{$group->id}/validate")->assertOk()->assertJsonPath('data.validated_at', null);
    }

    public function test_groups_are_added_renamed_renumbered_and_deleted_in_the_draft(): void
    {
        $program = $this->program();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $first = $program->groups()->first();
        $first->update(['color' => '#8A1538']);

        $added = $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$program->id}/groups")->assertCreated();
        $this->assertSame('#0E7490', $added->json('data.color'));
        $this->assertFalse($added->json('data.live'));
        $id = $added->json('data.id');
        $this->assertSame(1, $program->groups()->count(), 'the new group exists only in the draft');

        $this->asUser($coordinator)->patchJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$id}", ['sequence' => $first->sequence])->assertUnprocessable();
        $this->asUser($coordinator)->patchJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$id}", ['sequence' => 7, 'title_ar' => 'مجموعة الصباح', 'term' => 'second'])->assertOk()
            ->assertJsonPath('data.number', 7)->assertJsonPath('data.title', 'مجموعة الصباح')->assertJsonPath('data.term_effective', 'second');

        $this->asUser($coordinator)->deleteJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$id}")->assertForbidden();
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/saber/settings', ['group_delete_enabled' => true])->assertOk()->assertJsonPath('data.group_delete_enabled', true);
        $this->asUser($coordinator)->deleteJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$id}")->assertOk();
        $this->assertCount(1, $this->asUser($coordinator)->getJson('/api/v1/admin/saber/programs')->json('data.0.groups'));
        $this->asUser($coordinator)->deleteJson("/api/v1/admin/saber/programs/{$program->id}/groups/{$first->id}")->assertUnprocessable()->assertJsonPath('code', 'last_group');
    }

    public function test_kit_readiness_and_term_filters_follow_the_planned_dates(): void
    {
        $ready = $this->program(['title_ar' => 'أ برنامج جاهز', 'start_date' => '2027-03-01', 'end_date' => '2027-03-02']);
        $notReady = $this->program(['title_ar' => 'ب برنامج غير جاهز']);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($head)->patchJson("/api/v1/admin/saber/programs/{$ready->id}", ['kit_ready' => true])->assertOk()->assertJsonPath('data.kit_ready', true);
        $this->assertTrue($ready->fresh()->kit_ready, 'kit readiness is a property of the live programme');
        $this->day($notReady->groups()->first(), 1, ['date' => '2026-11-01'], $head)->assertOk();   // November → first term

        $titles = fn (string $query) => collect($this->asUser($head)->getJson("/api/v1/admin/saber/programs?{$query}")->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['أ برنامج جاهز', 'ب برنامج غير جاهز'], $titles(''));
        $this->assertSame(['أ برنامج جاهز'], $titles('kit_not_ready=0'));
        $this->assertSame(['ب برنامج غير جاهز'], $titles('kit_ready=0'));
        $this->assertSame(['أ برنامج جاهز'], $titles('term_first=0'), 'a group without dates stays visible while a term is shown');
        $this->assertSame(['ب برنامج غير جاهز'], $titles('from=2026-10-01&to=2026-11-30'));
    }

    public function test_resetting_a_day_and_a_group_empties_them_in_the_draft(): void
    {
        $group = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $i => $date) {
            $this->day($group, $i + 1, ['date' => $date], $coordinator)->assertOk();
        }
        $base = "/api/v1/admin/saber/programs/{$group->program_id}/groups/{$group->id}";

        $res = $this->asUser($coordinator)->deleteJson("{$base}/days/2")->assertOk()->assertJsonPath('data.days.1.date', null);
        $this->assertCount(2, array_filter(array_column($res->json('data.days'), 'date')));
        $this->asUser($coordinator)->postJson("{$base}/reset")->assertOk()->assertJsonPath('reset', 2);
    }

    public function test_availability_flags_taken_rooms_busy_trainers_and_closed_days(): void
    {
        $room = $this->room('S4');
        $trainer = $this->trainer();
        $other = $this->program();
        $tz = config('app.timezone');
        $other->sessions()->create(['training_group_id' => $other->groups()->first()->id, 'title_ar' => 'جلسة', 'title_en' => 'Session', 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_id' => $trainer->id,
            'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', $tz), 'ends_at' => CarbonImmutable::parse('2026-11-02 13:00', $tz)]);
        $group = $this->program()->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $q = fn ($date) => "/api/v1/admin/saber/availability?date={$date}&program_id={$group->program_id}&group={$group->id}&idx=1";

        $res = $this->asUser($coordinator)->getJson($q('2026-11-02'))->assertOk();
        $this->assertArrayHasKey($room->id, $res->json('data.busy_rooms'));
        $this->assertSame([$trainer->id], $res->json('data.busy_trainers'));
        $this->assertTrue($res->json('data.day.training_allowed'));

        $this->asUser($coordinator)->getJson($q('2026-11-06'))->assertOk()->assertJsonPath('data.day.training_allowed', false);
        $this->asUser($coordinator)->getJson($q('2026-11-03'))->assertOk()->assertJsonPath('data.busy_trainers', []);
    }
}
