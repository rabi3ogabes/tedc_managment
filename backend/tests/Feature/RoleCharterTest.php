<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Models\TrainingKit;
use App\Services\Dashboards\DashboardService;
use App\Support\RoleCharter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The charter of the roles (the Ministry's «الصلاحيات» document): each role sees on its dashboard what it may do and what it answers
 * for, in the words of the document, and holds in the system the rights those words need.
 */
class RoleCharterTest extends TestCase
{
    private const URL = '/api/v1/dashboard/charter';

    /** The twelve roles of the document, in its order. */
    private const ROLES = [
        Role::EMPLOYEE, Role::SCHOOL_ADMIN, Role::ACADEMIC_DEPUTY, Role::TRAINING_HEAD, Role::COORDINATOR, Role::CENTER_LEADERSHIP,
        Role::TRAINER, Role::SUPER_ADMIN, Role::KIT_DEVELOPER, Role::PLANNING_HEAD, Role::PLANNING_SPECIALIST, Role::LOGISTICS_OFFICER,
    ];

    /** The rights a system role is seeded with. @return list<string> */
    private function seeded(string $slug): array
    {
        $grants = RolePermissionSeeder::ROLES[$slug][3];
        $all = in_array('*', $grants, true) ? array_keys(RolePermissionSeeder::PERMISSIONS) : $grants;

        return array_values(array_diff($all, array_map(fn ($g) => ltrim($g, '-'), array_filter($grants, fn ($g) => str_starts_with($g, '-')))));
    }

    public function test_the_charter_is_the_document_whole_and_unchanged(): void
    {
        $charter = RoleCharter::all();
        $this->assertSame(self::ROLES, array_keys($charter));

        // 106 headings and 179 lines in the document: a line lost or added by hand fails here.
        $sections = collect($charter)->flatMap(fn ($r) => [...$r['permissions'], ...$r['responsibilities']]);
        $this->assertCount(106, $sections);
        $this->assertSame(179, $sections->sum(fn ($s) => count($s['items'])));
        $sections->each(function ($s) {
            $this->assertNotSame('', trim($s['title']));
            $this->assertNotEmpty($s['items']);
        });

        // Words of the document, as they are written there.
        $this->assertSame('المتدرب', $charter[Role::EMPLOYEE]['name']['ar']);
        $this->assertSame('التسجيل في البرامج التدريبية', $charter[Role::EMPLOYEE]['permissions'][0]['title']);
        $this->assertSame('يستطيع التسجيل في البرامج حسب الخطة التدريبية المحددة لكل موظف.', $charter[Role::EMPLOYEE]['permissions'][0]['items'][0]);
        $this->assertSame('منح أو إيقاف صلاحية رصد الحضور والانصراف والاستئذان لمشرفي التدريب والمدربين.', $charter[Role::TRAINING_HEAD]['permissions'][1]['items'][0]);
        $this->assertSame('تنظيم عملية حجز القاعات للاستخدامات المختلفة وضمان عدم التعارض بين الحجوزات.', $charter[Role::LOGISTICS_OFFICER]['responsibilities'][1]['items'][0]);
        $this->assertNull(RoleCharter::for(Role::FINANCE_OFFICER));
    }

    public function test_every_role_is_seeded_with_the_rights_its_charter_needs(): void
    {
        foreach (self::ROLES as $slug) {
            $rights = RoleCharter::rights($slug);
            foreach ($rights as $right) {
                $this->assertArrayHasKey($right, RolePermissionSeeder::PERMISSIONS, "{$slug}: «{$right}» is not a right of the system");
            }
            $this->assertSame([], array_values(array_diff($rights, $this->seeded($slug))), "{$slug} lacks rights its charter names");

            // A page offered beside a heading asks for a right of the system, and the role holds it.
            foreach (RoleCharter::all()[$slug]['permissions'] as $section) {
                if (isset($section['needs'])) {
                    $this->assertContains($section['needs'], $this->seeded($slug), "{$slug}: the page {$section['to']} needs «{$section['needs']}»");
                }
                if (isset($section['to'])) {
                    $this->assertStringStartsWith('/', $section['to']);
                }
            }
        }
    }

    public function test_each_person_reads_the_charter_of_the_role_they_are_acting_in(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        foreach (self::ROLES as $slug) {
            $user = $this->makeUser($slug);
            $data = $this->asUser($user)->getJson(self::URL)->assertOk()->json('data');
            $this->assertSame($slug, $data['role']);
            $this->assertSame(RoleCharter::all()[$slug]['name'], $data['name']);
            $this->assertSame(RoleCharter::all()[$slug]['permissions'], $data['permissions']);
            $this->assertSame(RoleCharter::all()[$slug]['responsibilities'], $data['responsibilities']);
        }

        // A role the document does not describe has nothing to show, and says so with an empty answer rather than an error.
        $this->asUser($this->makeUser(Role::FINANCE_OFFICER))->getJson(self::URL)->assertOk()->assertJsonPath('data', null);
    }

    public function test_a_role_that_lost_a_right_of_its_charter_gets_it_back_and_keeps_what_it_was_given(): void
    {
        $deputy = Role::where('slug', Role::ACADEMIC_DEPUTY)->firstOrFail();
        $id = fn (string $slug) => Permission::where('slug', $slug)->value('id');
        $holds = fn (string $slug) => DB::table('permission_role')->where(['role_id' => $deputy->id, 'permission_id' => $id($slug)])->exists();

        // An administrator had taken two rights of the charter away, and had given the role one that the charter does not name.
        DB::table('permission_role')->where('role_id', $deputy->id)->whereIn('permission_id', [$id('pd.approve'), $id('calendar.view')])->delete();
        DB::table('permission_role')->insert(['role_id' => $deputy->id, 'permission_id' => $id('kits.view')]);
        $before = DB::table('permission_role')->where('role_id', $deputy->id)->count();

        $added = RoleCharter::grantMissing();

        $this->assertEqualsCanonicalizing(['pd.approve', 'calendar.view'], $added[Role::ACADEMIC_DEPUTY]);
        $this->assertCount(1, $added, 'only the role that lacked something is touched');
        $this->assertTrue($holds('pd.approve'));
        $this->assertTrue($holds('calendar.view'));
        $this->assertTrue($holds('kits.view'), 'a right the administrator added stays');
        $this->assertSame($before + 2, DB::table('permission_role')->where('role_id', $deputy->id)->count());

        // Run again: nothing left to give.
        $this->assertSame([], RoleCharter::grantMissing());

        // A right the database does not have yet (an older database) is created from the catalogue, then given.
        Permission::where('slug', 'live_training.view')->delete();
        $added = RoleCharter::grantMissing();
        $this->assertSame([Role::COORDINATOR => ['live_training.view']], $added);
        $this->assertSame('attendance', Permission::where('slug', 'live_training.view')->value('group'));
    }

    public function test_the_dashboards_of_the_kit_and_planning_roles_show_the_kits_with_their_programmes(): void
    {
        foreach ([Role::KIT_DEVELOPER, Role::PLANNING_HEAD, Role::PLANNING_SPECIALIST] as $slug) {
            $this->assertContains('kits_programs', DashboardService::PRESETS[$slug]);
        }

        $developer = $this->makeUser(Role::KIT_DEVELOPER);
        $other = $this->makeUser(Role::KIT_DEVELOPER);
        $program = $this->makeProgram(['code' => 'LDR-1', 'title_ar' => 'القيادة التربوية', 'title_en' => 'Educational leadership']);
        $mine = TrainingKit::create(['code' => 'KIT-1', 'title_ar' => 'حقيبة القيادة', 'title_en' => 'Leadership kit', 'status' => TrainingKit::IN_REVIEW, 'version' => 1, 'created_by' => $developer->id, 'owner_id' => $developer->id]);
        $mine->programs()->attach($program->id, ['id' => (string) Str::uuid(), 'pinned_version' => 1]);
        TrainingKit::create(['code' => 'KIT-2', 'title_ar' => 'حقيبة التقويم', 'title_en' => 'Assessment kit', 'status' => TrainingKit::DRAFT, 'version' => 1, 'created_by' => $other->id, 'owner_id' => $other->id]);
        TrainingKit::create(['code' => 'KIT-OLD', 'title_ar' => 'حقيبة قديمة', 'title_en' => 'Old kit', 'status' => TrainingKit::ARCHIVED, 'version' => 1, 'created_by' => $developer->id, 'owner_id' => $developer->id]);

        // The developer sees the kits they own, with the programmes each one is linked to.
        $rows = $this->asUser($developer)->getJson('/api/v1/dashboard/widgets/kits_programs')->assertOk()->json('data.rows');
        $this->assertSame([['kit' => 'KIT-1 — حقيبة القيادة', 'status' => TrainingKit::IN_REVIEW, 'programs' => 'القيادة التربوية']], $rows);

        // The planning roles see every kit that is in use, and no content of any of them.
        $rows = $this->asUser($this->makeUser(Role::PLANNING_SPECIALIST))->getJson('/api/v1/dashboard/widgets/kits_programs')->assertOk()->json('data.rows');
        $this->assertEqualsCanonicalizing(['KIT-1 — حقيبة القيادة', 'KIT-2 — حقيبة التقويم'], array_column($rows, 'kit'));
        $this->assertSame(['kit', 'status', 'programs'], array_keys($rows[0]));

        // A role whose dashboard does not carry the widget cannot ask for it.
        $this->asUser($this->makeUser(Role::LOGISTICS_OFFICER))->getJson('/api/v1/dashboard/widgets/kits_programs')->assertNotFound();
        $this->assertInstanceOf(Program::class, $program);
    }
}
