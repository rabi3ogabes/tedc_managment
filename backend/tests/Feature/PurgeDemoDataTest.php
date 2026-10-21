<?php

namespace Tests\Feature;

use App\Models\PdActivity;
use App\Models\PdActivityType;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\DemoPurge;
use App\Services\FileStorage;
use App\Services\Import\TraineeHistoryImport;
use App\Services\PdService;
use App\Support\AppTables;
use App\Support\Features;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** `tedc:purge-demo`: the demo data goes, the setup, the official schools and the imported people stay, with one administrator. */
class PurgeDemoDataTest extends TestCase
{
    public function test_the_demo_data_goes_and_the_imported_people_the_official_schools_and_the_setup_stay(): void
    {
        app(PdService::class)->ensureTypes();
        $type = PdActivityType::first();
        $official = $this->makeSchool(['source' => 'moe_government']);
        $demoSchool = $this->makeSchool();
        $imported = $this->makeEmployee(['school_id' => $official->id, 'source_system' => TraineeHistoryImport::SOURCE]);
        PdActivity::create(['employee_id' => $imported->id, 'type_id' => $type->id, 'title' => 'دورة', 'starts_on' => '2025-01-05', 'duration_hours' => 6, 'computed_hours' => 6, 'approved_hours' => 6, 'status' => 'approved']);
        DB::table('pd_activities')->update(['source_system' => TraineeHistoryImport::SOURCE]);   // the importer writes it directly
        $demo = $this->makeEmployee(['school_id' => $demoSchool->id]);
        $program = $this->makeProgram();
        Registration::create(['program_id' => $program->id, 'employee_id' => $demo->id, 'status' => 'approved', 'source' => 'self']);
        $demoAdmin = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'admin@tedc.qa']);
        $roles = DB::table('roles')->count();
        $jobTitles = DB::table('job_titles')->count();

        // A dry run changes nothing; deleting needs the administrator to keep.
        $this->artisan('tedc:purge-demo')->assertSuccessful();
        $this->assertDatabaseHas('programs', ['id' => $program->id]);
        $this->artisan('tedc:purge-demo', ['--force' => true])->assertFailed();
        $this->assertDatabaseHas('programs', ['id' => $program->id]);

        $this->artisan('tedc:purge-demo', ['--force' => true, '--admin' => 'Head.Admin@moehe.gov.qa', '--admin-name' => 'مدير المنصة'])->assertSuccessful();

        $this->assertDatabaseMissing('programs', ['id' => $program->id]);
        $this->assertSame(0, DB::table('registrations')->count());
        $this->assertDatabaseMissing('employees', ['id' => $demo->id]);
        $this->assertDatabaseMissing('users', ['id' => $demoAdmin->id]);
        $this->assertDatabaseMissing('schools', ['id' => $demoSchool->id]);
        $this->assertDatabaseHas('employees', ['id' => $imported->id]);
        $this->assertDatabaseHas('users', ['id' => $imported->user_id]);
        $this->assertSame(1, DB::table('pd_activities')->count());
        $this->assertDatabaseHas('schools', ['id' => $official->id]);
        $this->assertSame($roles, DB::table('roles')->count());
        $this->assertSame($jobTitles, DB::table('job_titles')->count());
        $admin = User::where('email', 'head.admin@moehe.gov.qa')->firstOrFail();
        $this->assertSame('مدير المنصة', $admin->name);
        $this->assertTrue($admin->roles()->where('slug', Role::SUPER_ADMIN)->exists());
        $this->assertTrue(DB::table('site_settings')->where('key', 'demo_purged_at')->exists());

        // Nothing is left to delete, and without imported people the command refuses to run.
        $this->artisan('tedc:purge-demo', ['--force' => true, '--admin' => 'head.admin@moehe.gov.qa'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        DB::table('employees')->delete();
        $this->artisan('tedc:purge-demo', ['--force' => true, '--admin' => 'head.admin@moehe.gov.qa'])->assertFailed();
    }

    public function test_the_screen_previews_then_removes_the_demo_data_and_the_new_administrator_signs_in_with_the_password_typed(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $official = $this->makeSchool(['source' => 'moe_gov']);
        $imported = $this->makeEmployee(['school_id' => $official->id, 'source_system' => TraineeHistoryImport::SOURCE]);
        $program = $this->makeProgram();
        $demoAdmin = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'admin@tedc.qa']);
        $url = '/api/v1/admin/system/demo-data';

        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->getJson($url)->assertForbidden();
        $preview = $this->asUser($demoAdmin)->getJson($url)->assertOk()->json('data');
        $this->assertNull($preview['purged']);
        $this->assertSame(1, $preview['imported_people']);
        $this->assertGreaterThan(0, $preview['total']);
        $this->assertContains('programs', array_column($preview['tables'], 'table'));
        $this->assertDatabaseHas('programs', ['id' => $program->id]);   // a preview changes nothing

        $body = ['admin_name' => 'مدير المنصة', 'admin_email' => 'Head.Admin@moehe.gov.qa', 'password' => 'Shaheen#Portal-2026', 'password_confirmation' => 'Shaheen#Portal-2026', 'confirmation' => 'حذف'];
        $this->asUser($demoAdmin)->postJson($url.'/purge', $body)->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->asUser($demoAdmin)->postJson($url.'/purge', ['password' => 'short', 'password_confirmation' => 'short', 'confirmation' => 'حذف البيانات التجريبية'] + $body)->assertUnprocessable();
        $this->assertDatabaseHas('programs', ['id' => $program->id]);

        $done = $this->asUser($demoAdmin)->postJson($url.'/purge', ['confirmation' => 'حذف البيانات التجريبية'] + $body)->assertOk()->json('data');
        $this->assertTrue($done['signed_out']);
        $this->assertSame('head.admin@moehe.gov.qa', $done['admin']['email']);
        $this->assertGreaterThan(0, $done['backup']['rows']);
        $this->assertTrue(app(FileStorage::class)->exists('documents', $done['backup']['path']));
        $this->assertDatabaseMissing('programs', ['id' => $program->id]);
        $this->assertDatabaseMissing('users', ['id' => $demoAdmin->id]);
        $this->assertDatabaseHas('employees', ['id' => $imported->id]);
        $this->assertFalse(Features::enabled('test_accounts'));

        // The administrator who stays signs in with the password typed on the screen, and sees the clean-up as done.
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'head.admin@moehe.gov.qa', 'password' => 'Shaheen#Portal-2026'])->assertOk()->json('access_token');
        $this->assertNotEmpty($token);
        $admin = User::where('email', 'head.admin@moehe.gov.qa')->firstOrFail();
        $this->assertNotNull($this->asUser($admin)->getJson($url)->assertOk()->json('data.purged'));
    }

    public function test_only_the_platforms_own_tables_are_looked_at_not_those_supabase_keeps_in_other_schemas(): void
    {
        $this->makeEmployee(['source_system' => TraineeHistoryImport::SOURCE]);
        if (DB::getDriverName() === 'pgsql') {
            // Supabase keeps auth.identities, storage.objects … in the same database; counting them as the platform's failed with a 500.
            DB::statement('create schema if not exists supabase_like');
            DB::statement('create table if not exists supabase_like.identities (id int)');
        }
        $this->assertContains('programs', AppTables::names());
        $this->assertNotContains('identities', AppTables::names());
        $this->assertIsArray(app(DemoPurge::class)->forAdmin('head@moehe.gov.qa')->plan());
    }
}
