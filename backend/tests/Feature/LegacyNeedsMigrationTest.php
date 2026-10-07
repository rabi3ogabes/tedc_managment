<?php

namespace Tests\Feature;

use App\Models\InstitutionalRequest;
use App\Models\Role;
use App\Models\TrainingNeed;
use App\Needs\LegacyNeedsMigrator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LegacyNeedsMigrationTest extends TestCase
{
    private function need(array $extra = []): TrainingNeed
    {
        $need = TrainingNeed::create($extra + ['school_id' => $this->makeSchool()->id, 'skill_name' => 'إدارة الصف', 'employees_count' => 6, 'priority' => 'high', 'reason' => 'ضعف المهارة', 'target_group' => 'المعلمون', 'status' => 'submitted']);
        $need->forceFill(['created_at' => Carbon::parse('2026-03-01 10:00'), 'updated_at' => Carbon::parse('2026-03-05 10:00')])->saveQuietly();

        return $need->refresh();
    }

    public function test_old_school_needs_become_needs_requests_with_their_history(): void
    {
        $user = $this->makeUser(Role::SCHOOL_ADMIN);
        $reviewer = $this->makeUser(Role::CENTER_ADMIN);
        $a = $this->need(['submitted_by' => $user->id, 'priority' => 'critical', 'status' => 'approved', 'reviewed_by' => $reviewer->id, 'review_notes' => 'نوافق']);
        $b = $this->need(['priority' => 'low', 'status' => 'under_review', 'skill_name' => 'التقويم']);
        $c = $this->need(['status' => 'fulfilled']);
        $d = $this->need(['status' => 'rejected', 'review_notes' => 'مكرر']);

        $result = app(LegacyNeedsMigrator::class)->run();
        $this->assertSame(['found' => 4, 'created' => 4, 'skipped' => 0], $result);

        $ra = InstitutionalRequest::where('legacy_need_id', $a->id)->first();
        $this->assertSame('accepted', $ra->status);
        $this->assertSame(5, $ra->need_degree);
        $this->assertSame($user->id, $ra->requested_by);
        $this->assertSame($reviewer->id, $ra->reviewer_id);
        $this->assertSame('نوافق', $ra->review_note);
        $this->assertSame($a->school_id, $ra->entity_id);
        $this->assertSame($a->school->name_ar, $ra->entity_name);
        $this->assertSame(6, $ra->employees_count);
        $this->assertNull($ra->cycle_id);
        $this->assertSame('ضعف المهارة', $ra->objectives[0]);
        $this->assertStringContainsString('المعلمون', $ra->objectives[1]);
        $this->assertSame('2026-03-01 10:00', $ra->created_at->format('Y-m-d H:i'));      // the move does not make an old request look new
        $this->assertSame('2026-03-05 10:00', $ra->updated_at->format('Y-m-d H:i'));

        $this->assertSame(['submitted', 2], [InstitutionalRequest::where('legacy_need_id', $b->id)->value('status'), InstitutionalRequest::where('legacy_need_id', $b->id)->value('need_degree')]);
        $this->assertSame('merged', InstitutionalRequest::where('legacy_need_id', $c->id)->value('status'));
        $this->assertSame('rejected', InstitutionalRequest::where('legacy_need_id', $d->id)->value('status'));
        $this->assertSame(4, TrainingNeed::count());                                       // the old rows stay: other code still reads them
    }

    public function test_the_move_is_repeatable_and_a_dry_run_changes_nothing(): void
    {
        $this->need();
        $this->assertSame(['found' => 1, 'created' => 1, 'skipped' => 0], app(LegacyNeedsMigrator::class)->run(true));
        $this->assertSame(0, InstitutionalRequest::count());
        app(LegacyNeedsMigrator::class)->run();
        $this->need(['skill_name' => 'جديدة']);
        $this->assertSame(['found' => 2, 'created' => 1, 'skipped' => 1], app(LegacyNeedsMigrator::class)->run());
        $this->assertSame(2, InstitutionalRequest::count());
        $this->artisan('tedc:needs-migrate-legacy')->expectsOutputToContain('Copied 0 of 2')->assertSuccessful();
    }

    public function test_a_needs_survey_need_for_every_school_is_kept_and_planners_can_decide_a_moved_request(): void
    {
        $need = $this->need(['school_id' => null, 'skill_name' => 'مهارات رقمية']);
        app(LegacyNeedsMigrator::class)->run();
        $r = InstitutionalRequest::where('legacy_need_id', $need->id)->first();
        $this->assertSame('جميع المدارس', $r->entity_name);

        $planner = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($planner)->getJson('/api/v1/admin/institutional-requests')->assertOk()->assertJsonPath('data.0.employees_count', 6);
        $this->asUser($planner)->postJson("/api/v1/admin/institutional-requests/{$r->id}/review", ['decision' => 'rejected', 'note' => 'خارج الخطة'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_release_moves_old_needs_on_its_own(): void
    {
        $this->need();
        $this->artisan('tedc:deploy', ['--no-cache' => true])->assertSuccessful();
        $this->assertSame(1, InstitutionalRequest::whereNotNull('legacy_need_id')->count());
    }
}
