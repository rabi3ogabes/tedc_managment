<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Program;
use App\Models\Role;
use App\Models\SaberRevision;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** سابر+ review workflow (docs/saber/05-review-and-flexible-import.md): drafts, review in the Programmes page, publish, return, discard. */
class SaberReviewTest extends TestCase
{
    private function room(string $code): TrainingRoom
    {
        return TrainingRoom::create(['code' => $code, 'name_ar' => "قاعة {$code}", 'name_en' => "Room {$code}", 'capacity' => 30, 'status' => 'active']);
    }

    private function day(Program $p, string $groupId, int $idx, array $patch, $user)
    {
        return $this->asUser($user)->putJson("/api/v1/admin/saber/programs/{$p->id}/groups/{$groupId}/days/{$idx}", $patch);
    }

    public function test_a_programme_created_in_saber_is_a_draft_until_the_reviewer_publishes_it(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $room = $this->room('N1');

        $row = $this->asUser($coordinator)->postJson('/api/v1/admin/saber/programs', ['title' => 'برنامج سابر الجديد', 'delivery_mode' => 'in_person', 'total_hours' => 10, 'groups' => 2, 'kit_ready' => false])
            ->assertCreated()->json('data');
        $program = Program::find($row['id']);
        $this->assertSame(Program::STATUS_DRAFT, $program->status);
        $this->assertSame('draft', $row['draft']['status']);
        $this->assertCount(2, $row['groups']);
        $this->assertSame(1, $program->groups()->count(), 'the second group exists only in the draft');
        $this->asUser($coordinator)->postJson('/api/v1/admin/saber/programs', ['title' => 'برنامج سابر الجديد', 'delivery_mode' => 'online', 'total_hours' => 5, 'groups' => 1])->assertUnprocessable();

        $second = $row['groups'][1]['id'];
        $this->day($program, $second, 1, ['date' => '2026-11-01', 'room_id' => $room->id], $coordinator)->assertOk();
        $this->day($program, $second, 2, ['date' => '2026-11-02'], $coordinator)->assertOk();

        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$program->id}/draft/submit")->assertOk()->assertJsonPath('data.draft.status', 'submitted');
        $this->assertTrue(AppNotification::where('user_id', $head->id)->where('type', 'saber.revision_submitted')->exists(), 'reviewers are told');

        $this->asUser($coordinator)->getJson('/api/v1/admin/saber/reviews')->assertForbidden();
        $list = $this->asUser($head)->getJson('/api/v1/admin/saber/reviews')->assertOk();
        $rev = $list->json('data.0.id');
        $this->assertSame($program->id, $list->json('data.0.program.id'));
        $diff = $this->asUser($head)->getJson("/api/v1/admin/saber/reviews/{$rev}")->assertOk()->json('data.diff');
        $this->assertTrue($diff['new_program']);
        $this->assertSame('added', collect($diff['groups'])->firstWhere('key', $second)['kind']);
        $this->assertSame('قاعة N1', collect($diff['groups'])->firstWhere('key', $second)['days'][0]['new']['room']);

        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/reviews/{$rev}/publish")->assertForbidden();
        $this->asUser($head)->postJson("/api/v1/admin/saber/reviews/{$rev}/publish", ['note' => 'معتمد'])->assertOk()->assertJsonPath('data.changed_days', 2);

        $program->refresh();
        $this->assertSame(Program::STATUS_PUBLISHED, $program->status);
        $this->assertSame(2, $program->groups()->count());
        $this->assertSame(2, $program->sessions()->count());
        $this->assertSame($room->id, $program->sessions()->orderBy('starts_at')->first()->training_room_id);
        $this->assertSame('published', SaberRevision::find($rev)->status);
        $this->assertTrue(AppNotification::where('user_id', $coordinator->id)->where('type', 'saber.revision_published')->exists());
        $this->assertNull($this->asUser($coordinator)->getJson('/api/v1/admin/saber/programs?q=سابر')->json('data.0.draft'));
    }

    public function test_publishing_is_all_or_nothing_and_a_clash_found_at_publishing_is_reported(): void
    {
        $room = $this->room('N2');
        $program = $this->makeProgram(['title_ar' => 'برنامج التعارض', 'total_hours' => 10, 'delivery_mode' => 'in_person']);
        $group = $program->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->day($program, $group->id, 1, ['date' => '2026-11-01'], $coordinator)->assertOk();
        $this->day($program, $group->id, 2, ['date' => '2026-11-02', 'room_id' => $room->id], $coordinator)->assertOk();
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$program->id}/draft/submit")->assertOk();

        // Meanwhile another programme takes the room live.
        $other = $this->makeProgram(['delivery_mode' => 'in_person']);
        $tz = config('app.timezone');
        $other->sessions()->create(['training_group_id' => $other->groups()->first()->id, 'title_ar' => 'جلسة', 'title_en' => 'Session', 'mode' => 'in_person', 'training_room_id' => $room->id,
            'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', $tz), 'ends_at' => CarbonImmutable::parse('2026-11-02 13:00', $tz)]);

        $rev = SaberRevision::where('program_id', $program->id)->first();
        $res = $this->asUser($head)->postJson("/api/v1/admin/saber/reviews/{$rev->id}/publish")->assertUnprocessable()->assertJsonPath('code', 'publish_failed');
        $this->assertSame('2026-11-02', $res->json('details.failures.0.date'));
        $this->assertSame(0, $program->sessions()->count(), 'nothing was applied');
        $this->assertSame('submitted', $rev->fresh()->status);
    }

    public function test_the_reviewer_returns_a_draft_with_a_note_and_the_planner_resubmits(): void
    {
        $program = $this->makeProgram(['total_hours' => 10, 'delivery_mode' => 'in_person']);
        $group = $program->groups()->first();
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->day($program, $group->id, 1, ['date' => '2026-11-01'], $coordinator)->assertOk();
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$program->id}/draft/submit")->assertOk();
        $rev = SaberRevision::where('program_id', $program->id)->first();

        $this->asUser($head)->postJson("/api/v1/admin/saber/reviews/{$rev->id}/return", [])->assertUnprocessable();
        $this->asUser($head)->postJson("/api/v1/admin/saber/reviews/{$rev->id}/return", ['note' => 'غيّر التاريخ إلى الأسبوع التالي'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertTrue(AppNotification::where('user_id', $coordinator->id)->where('type', 'saber.revision_returned')->exists());
        $row = $this->asUser($coordinator)->getJson('/api/v1/admin/saber/programs')->json('data.0.draft');
        $this->assertSame('غيّر التاريخ إلى الأسبوع التالي', $row['review_note']);

        $this->day($program, $group->id, 1, ['date' => '2026-11-08'], $coordinator)->assertOk();
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$program->id}/draft/submit")->assertOk()->assertJsonPath('data.draft.status', 'submitted');
        $this->assertSame(1, SaberRevision::where('program_id', $program->id)->count(), 'one draft per programme');
    }

    public function test_discarding_leaves_the_live_programme_and_removes_a_programme_that_only_existed_as_a_draft(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $program = $this->makeProgram(['total_hours' => 10, 'delivery_mode' => 'in_person']);
        $group = $program->groups()->first();
        $this->day($program, $group->id, 1, ['date' => '2026-11-01'], $coordinator)->assertOk();
        $this->asUser($this->makeUser(Role::COORDINATOR))->deleteJson("/api/v1/admin/saber/programs/{$program->id}/draft")->assertForbidden();   // not the author, not a reviewer
        $this->asUser($coordinator)->deleteJson("/api/v1/admin/saber/programs/{$program->id}/draft")->assertOk();
        $this->assertSame('discarded', SaberRevision::where('program_id', $program->id)->first()->status);
        $this->assertNotNull($program->fresh());

        $draftOnly = $this->asUser($coordinator)->postJson('/api/v1/admin/saber/programs', ['title' => 'فكرة برنامج', 'delivery_mode' => 'online', 'total_hours' => 5, 'groups' => 1])->assertCreated()->json('data.id');
        $this->asUser($coordinator)->postJson("/api/v1/admin/saber/programs/{$draftOnly}/draft/submit")->assertOk();
        $rev = SaberRevision::where('program_id', $draftOnly)->first();
        $this->asUser($head)->postJson("/api/v1/admin/saber/reviews/{$rev->id}/discard", ['note' => 'مكرر'])->assertOk();
        $this->assertNull(Program::find($draftOnly), 'a programme that only existed as a SABER+ draft goes with it');
        $this->assertTrue(AppNotification::where('user_id', $coordinator->id)->where('type', 'saber.revision_discarded')->exists());
    }

    public function test_the_supervisor_and_days_per_group_are_planned_too(): void
    {
        $program = $this->makeProgram(['total_hours' => 10, 'delivery_mode' => 'in_person', 'training_days' => 2]);
        $supervisor = $this->makeUser(Role::COORDINATOR, ['name_ar' => 'المشرف سالم']);
        $head = $this->makeUser(Role::TRAINING_HEAD);

        $this->asUser($head)->patchJson("/api/v1/admin/saber/programs/{$program->id}", ['coordinator_id' => $supervisor->id, 'training_days' => 4])->assertOk()
            ->assertJsonPath('data.supervisor.id', $supervisor->id)->assertJsonPath('data.days', 4);
        $this->assertNull($program->fresh()->coordinator_id);
        $diff = $this->asUser($head)->getJson("/api/v1/admin/saber/programs/{$program->id}/draft")->assertOk()->json('data.diff.program');
        $this->assertSame(['training_days', 'coordinator_id'], array_column($diff, 'field'));
        $this->assertSame('المشرف سالم', collect($diff)->firstWhere('field', 'coordinator_id')['new']);

        $this->asUser($head)->postJson('/api/v1/admin/saber/reviews/'.SaberRevision::where('program_id', $program->id)->first()->id.'/publish')->assertOk();
        $this->assertSame($supervisor->id, $program->fresh()->coordinator_id);
        $this->assertSame(4, $program->fresh()->training_days);
    }
}
