<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Assessment;
use App\Models\EvaluationAssignment;
use App\Models\GroupTrainer;
use App\Models\JobTitle;
use App\Models\KitFile;
use App\Models\NotificationDelivery;
use App\Models\Program;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingKit;
use App\Services\Channels\ChannelSettings;
use App\Services\EvaluationService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Kits chosen for a new programme, the instruments generated from them, and the announcements to trainers and schools' system administrators. */
class ProgramKitsAndAnnouncementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        app(ChannelSettings::class)->update(['email' => ['enabled' => true, 'driver' => 'log'], 'sms' => ['enabled' => true, 'driver' => 'log']]);
        Cache::flush();
    }

    private function kit(string $code, string $status, array $files = []): TrainingKit
    {
        $kit = TrainingKit::create(['code' => $code, 'title_ar' => 'حقيبة '.$code, 'title_en' => 'Kit '.$code, 'delivery' => 'in_person', 'status' => $status, 'duration_hours' => 6, 'owner_id' => $this->makeUser(Role::KIT_DEVELOPER)->id]);
        foreach ($files as $name => $category) {
            KitFile::create(['kit_id' => $kit->id, 'name' => $name, 'original_name' => $name, 'kind' => 'document', 'category' => $category, 'source' => 'upload', 'mime' => 'application/pdf', 'size' => 10, 'storage_path' => 'x/'.$name]);
        }

        return $kit;
    }

    private function trainer(): Trainer
    {
        return Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => [], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
    }

    private function adminOf($school)
    {
        $u = $this->makeUser(Role::SCHOOL_ADMIN);
        $this->makeEmployee(['school_id' => $school->id], $u);

        return $u;
    }

    private function payload(array $extra = []): array
    {
        $teacher = JobTitle::where('code', 'TEACHER')->value('id');

        return $extra + ['title_ar' => 'برنامج الحقائب', 'total_hours' => 6, 'capacity' => 20, 'start_date' => today()->addDays(20)->toDateString(), 'end_date' => today()->addDays(21)->toDateString(), 'audience' => ['job_title_ids' => [$teacher]]];
    }

    public function test_kits_are_chosen_and_the_instruments_are_generated_from_them(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $withTests = $this->kit('K-A', TrainingKit::PUBLISHED, ['اختبار قبلي.docx' => 'assessment', 'اختبار بعدي.docx' => 'assessment', 'عرض.pptx' => 'presentation']);
        $plain = $this->kit('K-B', TrainingKit::APPROVED, ['دليل المتدرب.pdf' => 'handout']);
        $draft = $this->kit('K-C', TrainingKit::DRAFT, ['اختبار.docx' => 'assessment']);
        $trainer = $this->trainer();

        // The picker offers approved and published kits only, with what their assessment tools carry.
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/programs/kit-options')->assertForbidden();
        $opts = collect($this->asUser($admin)->getJson('/api/v1/admin/programs/kit-options?delivery_mode=in_person')->assertOk()->json('data'));
        $this->assertNull($opts->firstWhere('code', 'K-C'));
        $this->assertSame([true, true], [$opts->firstWhere('code', 'K-A')['has_pre'], $opts->firstWhere('code', 'K-A')['has_post']]);
        $this->assertSame([false, false], [$opts->firstWhere('code', 'K-B')['has_pre'], $opts->firstWhere('code', 'K-B')['has_post']]);

        // A new programme with both kits (and a draft one that is ignored) and every instrument on, the survey opening 48 hours after the end.
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['trainers' => [['id' => $trainer->id, 'role' => 'lead']], 'kit_ids' => [$withTests->id, $plain->id, $draft->id],
            'instruments' => ['trainee_survey' => true, 'trainer_reflection' => true, 'pre_test' => true, 'post_test' => true, 'survey_auto_hours' => 48]]))->assertCreated()->json('data.id');
        $program = Program::findOrFail($id);
        $this->assertEqualsCanonicalizing([$withTests->id, $plain->id], $program->kits()->pluck('training_kits.id')->all());
        $this->assertSame(['auto', 48], [$program->survey_mode, $program->survey_auto_hours]);
        $over = $this->asUser($admin)->getJson("/api/v1/admin/programs/$id/instruments")->assertOk()->json('data');
        $this->assertSame(2, count($over['kits']));
        $by = collect($over['instruments'])->keyBy('kind');
        $this->assertSame(['ready', 48], [$by['trainee_survey']['status'], $by['trainee_survey']['auto_hours']]);
        $this->assertSame('ready', $by['trainer_reflection']['status']);
        $this->assertSame(['needs_questions', 'needs_questions'], [$by['pre_test']['status'], $by['post_test']['status']]);
        $this->assertSame('K-A', $by['pre_test']['kit']['code']);
        $pre = Assessment::where('program_id', $id)->where('kind', 'pre_test')->firstOrFail();
        $post = Assessment::where('program_id', $id)->where('kind', 'post_test')->firstOrFail();
        $this->assertSame(['draft', 'draft'], [$pre->status, $post->status]); // nothing reaches a trainee until it has questions and is published
        $this->assertStringContainsString('اختبار قبلي.docx', $pre->instructions_ar);

        // Only a kit without tools: the tests are reported as not available in the kit and none is created; the same call again creates nothing twice.
        $id2 = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['code' => 'KB-2', 'kit_ids' => [$plain->id]]))->assertCreated()->json('data.id');
        $b = collect($this->asUser($admin)->getJson("/api/v1/admin/programs/$id2/instruments")->json('data.instruments'))->keyBy('kind');
        $this->assertSame(['unavailable', 'unavailable'], [$b['pre_test']['status'], $b['post_test']['status']]);
        $this->assertSame(0, Assessment::where('program_id', $id2)->count());
        $this->asUser($admin)->putJson("/api/v1/admin/programs/$id/instruments", ['kit_ids' => [$withTests->id], 'instruments' => ['trainee_survey' => true, 'trainer_reflection' => false, 'pre_test' => true, 'post_test' => false]])->assertOk();
        $this->assertSame(1, Assessment::where('program_id', $id)->where('kind', 'pre_test')->count());
        $this->assertSame([1], [$program->kits()->count()]);

        // The trainee survey off means a survey the administrator opens by hand; the self-reflection off means no reflection is assigned to the trainer at the group's end.
        $program->refresh();
        $this->assertSame('auto', $program->survey_mode);
        $this->asUser($admin)->putJson("/api/v1/admin/programs/$id/instruments", ['kit_ids' => [$withTests->id], 'instruments' => ['trainee_survey' => false, 'trainer_reflection' => false]])->assertOk();
        $this->assertSame('manual', $program->refresh()->survey_mode);
        $group = $program->groups()->first();
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'role' => 'lead', 'status' => GroupTrainer::APPROVED]);
        app(EvaluationService::class)->assignForGroup($group);
        $this->assertSame(0, EvaluationAssignment::where('program_id', $id)->where('respondent_type', 'trainer')->count());
        $this->asUser($admin)->putJson("/api/v1/admin/programs/$id/instruments", ['kit_ids' => [$withTests->id], 'instruments' => ['trainer_reflection' => true]])->assertOk();
        app(EvaluationService::class)->assignForGroup($group->refresh());
        $this->assertSame(1, EvaluationAssignment::where('program_id', $id)->where('respondent_type', 'trainer')->count());
    }

    public function test_opening_registration_tells_the_trainers_and_the_schools_system_administrators_once_on_every_channel(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $trainer = $this->trainer();
        [$sa, $sb, $sc] = [$this->makeSchool(), $this->makeSchool(), $this->makeSchool()];
        [$adminA, $adminB, $adminC] = [$this->adminOf($sa), $this->adminOf($sb), $this->adminOf($sc)];
        $this->makeEmployee(['school_id' => $sa->id]);
        $this->makeEmployee(['school_id' => $sa->id]);
        $this->makeEmployee(['school_id' => $sb->id]);
        $audience = ['school_ids' => [$sa->id, $sb->id], 'job_title_ids' => [JobTitle::where('code', 'TEACHER')->value('id')]];

        // Created already open: the trainer and the administrators of the two target schools are told; the third school is not.
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['audience' => $audience, 'trainers' => [['id' => $trainer->id, 'role' => 'lead']], 'status' => 'registration_open']))->assertCreated()->json('data.id');
        $trainerNote = AppNotification::where('user_id', $trainer->user_id)->where('type', 'program.registration_open.trainer')->get();
        $this->assertCount(1, $trainerNote);
        $this->assertSame($id, $trainerNote[0]->data['program_id']);
        $schoolNotes = AppNotification::where('type', 'program.registration_open.school')->get();
        $this->assertEqualsCanonicalizing([$adminA->id, $adminB->id], $schoolNotes->pluck('user_id')->all());
        $this->assertStringContainsString('2', AppNotification::where('user_id', $adminA->id)->where('type', 'program.registration_open.school')->value('body_ar')); // two eligible teachers in school A
        $this->assertSame(0, AppNotification::where('user_id', $adminC->id)->where('type', 'program.registration_open.school')->count());
        foreach ([$trainer->user_id, $adminA->id, $adminB->id] as $uid) {
            $this->assertEqualsCanonicalizing(['email', 'sms'], NotificationDelivery::where('user_id', $uid)->where('type', 'like', 'program.registration_open.%')->pluck('channel')->all()); // push leaves through its own dispatcher; e-mail and SMS are queued
        }
        $this->assertNotNull(Program::find($id)->registration_announced_at);

        // Saving the open programme again announces nothing twice.
        $this->asUser($admin)->putJson("/api/v1/admin/programs/$id", ['title_ar' => 'اسم جديد'])->assertOk();
        $this->asUser($admin)->patchJson("/api/v1/admin/programs/$id/status", ['status' => 'registration_open'])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $trainer->user_id)->where('type', 'program.registration_open.trainer')->count());

        // A draft opened later by status change is announced; one opened by its date through the scheduler is too.
        $draft = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['code' => 'DR-1', 'audience' => $audience, 'trainers' => [['id' => $trainer->id, 'role' => 'lead']]]))->assertCreated()->json('data.id');
        $this->assertSame(0, AppNotification::where('user_id', $trainer->user_id)->where('type', 'like', 'program.registration_open.%')->where('data->program_id', $draft)->count()); // a draft does not announce registration
        $this->asUser($admin)->patchJson("/api/v1/admin/programs/$draft/status", ['status' => 'registration_open'])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $trainer->user_id)->where('type', 'program.registration_open.trainer')->where('data->program_id', $draft)->count());
        $dated = $this->asUser($admin)->postJson('/api/v1/admin/programs', $this->payload(['code' => 'DT-1', 'audience' => $audience, 'trainers' => [['id' => $trainer->id, 'role' => 'lead']], 'status' => 'published', 'registration_opens_at' => now()->subHour()->toIso8601String()]))->assertCreated()->json('data.id');
        $this->artisan('tedc:program-lifecycle')->assertSuccessful();
        $this->assertSame('registration_open', Program::find($dated)->status);
        $this->assertSame(1, AppNotification::where('user_id', $trainer->user_id)->where('type', 'program.registration_open.trainer')->where('data->program_id', $dated)->count());
        $this->assertSame(1, AppNotification::where('user_id', $adminA->id)->where('type', 'program.registration_open.school')->where('data->program_id', $dated)->count());
    }

    public function test_direct_nomination_by_the_centre_tells_the_trainee_the_trainer_and_the_schools_system_administrators(): void
    {
        $specialist = $this->makeUser(Role::COORDINATOR);
        $trainer = $this->trainer();
        [$sa, $sb] = [$this->makeSchool(), $this->makeSchool()];
        [$adminA, $adminB] = [$this->adminOf($sa), $this->adminOf($sb)];
        [$e1, $e2, $e3] = [$this->makeEmployee(['school_id' => $sa->id]), $this->makeEmployee(['school_id' => $sa->id]), $this->makeEmployee(['school_id' => $sb->id])];
        $e1->user->update(['name_ar' => 'منى الكواري', 'name' => 'Mona Al-Kuwari']);
        $e2->user->update(['name_ar' => 'هند المري', 'name' => 'Hind Al-Marri']);
        $e3->user->update(['name_ar' => 'سارة الخالدي', 'name' => 'Sara Al-Khalidi']);
        $program = $this->makeProgram(['status' => Program::STATUS_REGISTRATION_OPEN, 'registration_announced_at' => now(), 'registration_modes' => Program::MODES]);
        $program->trainers()->attach($trainer->id, ['role' => 'lead']);

        $res = $this->asUser($specialist)->postJson("/api/v1/admin/programs/{$program->id}/nominations", ['employee_ids' => [$e1->id, $e2->id, $e3->id]])->assertCreated()->json('data');
        $this->assertSame([true, true, true], array_column($res, 'ok'));
        // Each trainee is told (the existing "program assigned" notice); every channel the platform has on follows.
        foreach ([$e1, $e2, $e3] as $e) {
            $this->assertSame(1, AppNotification::where('user_id', $e->user_id)->where('type', 'program.assigned')->count());
            $this->assertEqualsCanonicalizing(['email', 'sms'], NotificationDelivery::where('user_id', $e->user_id)->pluck('channel')->all());
        }
        // The trainer gets one notice for the batch, each school's administrator one for their own staff.
        $t = AppNotification::where('user_id', $trainer->user_id)->where('type', 'program.trainees_assigned.trainer')->get();
        $this->assertCount(1, $t);
        $this->assertStringContainsString('3', $t[0]->body_ar);
        $a = AppNotification::where('user_id', $adminA->id)->where('type', 'program.trainees_assigned.school')->get();
        $b = AppNotification::where('user_id', $adminB->id)->where('type', 'program.trainees_assigned.school')->get();
        $this->assertSame([1, 1], [$a->count(), $b->count()]);
        $this->assertStringContainsString('2', $a[0]->body_ar);
        $this->assertStringContainsString($e3->user->displayName('ar'), $b[0]->body_ar);
        $this->assertStringNotContainsString($e3->user->displayName('ar'), $a[0]->body_ar); // a school sees only its own staff
        foreach ([$trainer->user_id, $adminA->id, $adminB->id] as $uid) {
            $this->assertEqualsCanonicalizing(['email', 'sms'], NotificationDelivery::where('user_id', $uid)->pluck('channel')->all());
        }

        // A school administrator nominating their own staff is not a direct assignment by the centre: the trainer is not told.
        $e4 = $this->makeEmployee(['school_id' => $sa->id]);
        $this->asUser($adminA)->postJson("/api/v1/admin/programs/{$program->id}/nominations", ['employee_ids' => [$e4->id]])->assertCreated();
        $this->assertSame(1, AppNotification::where('user_id', $trainer->user_id)->where('type', 'program.trainees_assigned.trainer')->count());
    }
}
