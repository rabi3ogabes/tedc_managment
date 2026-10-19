<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JobTitle;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingGroup;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/** The groups on the programme form: suggested names from the target audience, saved with a new programme, renamed and removed at any time. */
class ProgramGroupsFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['title_ar' => 'برنامج المجموعات', 'total_hours' => 6, 'capacity' => 20, 'start_date' => today()->addDays(20)->toDateString(), 'end_date' => today()->addDays(21)->toDateString()];
    }

    public function test_names_are_suggested_from_the_audience_and_numbered(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');
        $coord = JobTitle::where('code', 'SUBJECT_COORD')->value('id');
        $leader = JobTitle::create(['code' => 'LEAD-X', 'name_ar' => 'قائد', 'name_en' => 'Leader', 'category' => 'leadership'])->id;

        // Teachers, coordinators and leaders take the groups in turn: «معلمون 1 منسقو المواد 2 قادة 3»; women only gives the feminine plural.
        $r = $this->asUser($admin)->postJson('/api/v1/admin/programs/group-names', ['job_title_ids' => [$teacher, $coord, $leader], 'count' => 4])->assertOk()->json('data');
        $this->assertSame(['معلمون 1', 'منسقو المواد 2', 'قادة 3', 'معلمون 4'], array_column($r, 'title_ar'));
        $this->assertSame('Teachers 1', $r[0]['title_en']);
        $f = $this->asUser($admin)->postJson('/api/v1/admin/programs/group-names', ['job_title_ids' => [$coord], 'genders' => ['female'], 'from' => 2, 'count' => 2])->assertOk()->json('data');
        $this->assertSame(['منسقات المواد 2', 'منسقات المواد 3'], array_column($f, 'title_ar'));
        // No audience: «المجموعة 1».
        $this->assertSame('المجموعة 1', $this->asUser($admin)->postJson('/api/v1/admin/programs/group-names', [])->json('data.0.title_ar'));
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/programs/group-names', [])->assertForbidden();
    }

    public function test_a_new_programme_is_saved_with_its_named_groups_and_they_can_be_renamed_added_and_removed_later(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['groups' => [
            ['title_ar' => 'معلمون 1', 'title_en' => 'Teachers 1'], ['title_ar' => 'منسقات 2'], ['title_ar' => 'قادة 3', 'capacity' => 12]]]))->assertCreated()->json('data.id');
        $groups = TrainingGroup::where('program_id', $id)->orderBy('sequence')->get();
        $this->assertSame(['معلمون 1', 'منسقات 2', 'قادة 3'], $groups->pluck('title_ar')->all());
        $this->assertSame([1, 2, 3], $groups->pluck('sequence')->all());
        $this->assertSame('منسقات 2', $groups[1]->title_en);   // an empty English name follows the Arabic one
        $this->assertSame(12, $groups[2]->capacity);
        $this->assertSame(20, $groups[1]->capacity);

        // Renamed at any time; two groups of one programme cannot share a name.
        $this->asUser($admin)->putJson("/api/v1/admin/groups/{$groups[1]->id}", ['title_ar' => 'منسقات المواد 2', 'title_en' => 'Coordinators 2'])->assertOk()->assertJsonPath('data.title_ar', 'منسقات المواد 2');
        $this->asUser($admin)->putJson("/api/v1/admin/groups/{$groups[1]->id}", ['title_ar' => 'معلمون 1'])->assertStatus(422)->assertJsonPath('code', 'duplicate_title');
        $new = $this->asUser($admin)->postJson("/api/v1/admin/programs/{$id}/groups", ['title_ar' => 'مجموعة جديدة'])->assertCreated()->json('data');
        $this->assertSame(4, $new['sequence']);
        $this->asUser($admin)->postJson("/api/v1/admin/programs/{$id}/groups", ['title_ar' => 'مجموعة جديدة'])->assertStatus(422)->assertJsonPath('code', 'duplicate_title');

        // A group with registrations stays; the last group of a programme stays.
        $employee = $this->makeEmployee();
        Registration::create(['program_id' => $id, 'training_group_id' => $groups[2]->id, 'employee_id' => $employee->id, 'status' => 'approved', 'source' => 'self']);
        $this->asUser($admin)->deleteJson("/api/v1/admin/groups/{$groups[2]->id}")->assertStatus(422)->assertJsonPath('code', 'has_registrations');
        $this->asUser($admin)->deleteJson("/api/v1/admin/groups/{$new['id']}")->assertOk();
        $this->asUser($admin)->deleteJson("/api/v1/admin/groups/{$groups[1]->id}")->assertOk();
        $this->asUser($admin)->deleteJson("/api/v1/admin/groups/{$groups[0]->id}")->assertOk();
        $this->asUser($admin)->deleteJson("/api/v1/admin/groups/{$groups[2]->id}")->assertStatus(422);
        $this->assertSame(1, Program::findOrFail($id)->groups()->count());

        // A programme sent without groups keeps the single group it always had.
        $plain = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['title_ar' => 'بلا مجموعات']))->assertCreated()->json('data.id');
        $this->assertSame(1, TrainingGroup::where('program_id', $plain)->count());
    }

    public function test_every_programme_starts_with_a_named_group_and_every_trainee_belongs_to_a_group(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        // Created without any group on the form: «المجموعة 1»; with an audience the name follows it.
        $plain = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['title_ar' => 'بلا فئة']))->assertCreated()->json('data.id');
        $this->assertSame(['المجموعة 1', 'Group 1'], [TrainingGroup::where('program_id', $plain)->value('title_ar'), TrainingGroup::where('program_id', $plain)->value('title_en')]);
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');
        $aud = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['title_ar' => 'للمعلمين', 'target_groups' => [['job_title_id' => $teacher]]]))->assertCreated()->json('data.id');
        $this->assertSame('معلمون 1', TrainingGroup::where('program_id', $aud)->value('title_ar'));
        // A programme made any other way (the model, the planner, an import) gets «المجموعة 1» too.
        $direct = Program::create(['code' => 'DIR-1', 'title_ar' => 'مباشر', 'title_en' => 'Direct', 'status' => 'draft', 'delivery_mode' => 'in_person', 'total_hours' => 4, 'capacity' => 10, 'created_by' => $admin->id]);
        $this->assertSame('المجموعة 1', $direct->groups()->value('title_ar'));
        // A copy of a group is named after its own number.
        $copy = $this->asUser($admin)->postJson('/api/v1/admin/groups/'.$direct->groups()->value('id').'/clone', ['start_date' => today()->addDays(30)->toDateString()])->assertCreated()->json('data');
        $this->assertSame('المجموعة 2', $copy['title_ar']);

        // A trainee is put in a group when registered — even in a programme that lost its groups.
        $e1 = $this->makeEmployee();
        $r1 = Registration::create(['program_id' => $direct->id, 'employee_id' => $e1->id, 'status' => 'approved', 'source' => 'self']);
        $this->assertNotNull($r1->training_group_id);
        TrainingGroup::where('program_id', $direct->id)->get()->each->forceDelete();
        $r2 = Registration::create(['program_id' => $direct->id, 'employee_id' => $this->makeEmployee()->id, 'status' => 'approved', 'source' => 'self']);
        $this->assertSame('المجموعة 1', $r2->trainingGroup->title_ar);
        $this->assertSame(0, Registration::whereNull('training_group_id')->where('id', '!=', $r1->id)->count()); // (the first trainee lost hers only because the test deleted the groups under her)
    }

    public function test_trainees_are_moved_between_the_groups_of_their_programme(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['capacity' => 2, 'groups' => [['title_ar' => 'أ'], ['title_ar' => 'ب', 'capacity' => 1]]]))->assertCreated()->json('data.id');
        [$a, $b] = TrainingGroup::where('program_id', $id)->orderBy('sequence')->get()->all();
        $mk = fn (string $gid) => Registration::create(['program_id' => $id, 'training_group_id' => $gid, 'employee_id' => $this->makeEmployee()->id, 'status' => 'approved', 'source' => 'self']);
        [$r1, $r2] = [$mk($a->id), $mk($a->id)];
        $r3 = $mk($b->id);

        // Group ب has one seat and it is taken: refused; the centre can override; a free group takes the trainee.
        $res = $this->asUser($admin)->postJson('/api/v1/admin/registrations/assign-group', ['registration_ids' => [$r1->id], 'group_id' => $b->id])->assertOk()->json('data.0');
        $this->assertFalse($res['ok']);
        $this->assertSame($a->id, $r1->fresh()->training_group_id);
        $this->asUser($admin)->postJson('/api/v1/admin/registrations/assign-group', ['registration_ids' => [$r1->id], 'group_id' => $b->id, 'override' => true])->assertOk()->assertJsonPath('data.0.ok', true);
        $this->assertSame($b->id, $r1->fresh()->training_group_id);
        $this->asUser($admin)->postJson('/api/v1/admin/registrations/assign-group', ['registration_ids' => [$r1->id, $r3->id], 'group_id' => $a->id])->assertOk();
        // Back into group أ (it has room for one more): the first is moved, the second is refused because أ is full again.
        $this->assertSame([$a->id, $b->id], [$r1->fresh()->training_group_id, $r3->fresh()->training_group_id]);
        $this->assertSame(2, AuditLog::where('action', 'registration_group_changed')->where('auditable_id', $r1->id)->count());

        // Another programme's group is refused; the list can be filtered by group and shows each trainee's group.
        $other = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['title_ar' => 'آخر']))->assertCreated()->json('data.id');
        $foreign = TrainingGroup::where('program_id', $other)->first();
        $this->assertFalse($this->asUser($admin)->postJson('/api/v1/admin/registrations/assign-group', ['registration_ids' => [$r2->id], 'group_id' => $foreign->id])->json('data.0.ok'));
        $list = $this->asUser($admin)->getJson("/api/v1/admin/registrations?program_id={$id}&group_id={$a->id}&status=")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertSame('أ', $list[0]['group']['title']);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/registrations/assign-group', ['registration_ids' => [$r2->id], 'group_id' => $a->id])->assertForbidden();
    }
}
