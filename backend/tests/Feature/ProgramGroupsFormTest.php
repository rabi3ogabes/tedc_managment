<?php

namespace Tests\Feature;

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
}
