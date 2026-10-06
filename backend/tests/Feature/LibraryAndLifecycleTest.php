<?php

namespace Tests\Feature;

use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\ExternalCompletion;
use App\Models\ExternalCourse;
use App\Models\JobGroup;
use App\Models\LessonProgress;
use App\Models\LibraryItem;
use App\Models\Material;
use App\Models\Registration;
use App\Models\ResourceShare;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\TrainingKit;
use App\Services\AnnualHoursService;
use App\Services\Content\StandardsSettings;
use App\Services\CourseBlueprints;
use App\Services\Library\ExternalLearningService;
use App\Services\Library\JobGroupService;
use App\Services\Library\LibraryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryAndLifecycleTest extends TestCase
{
    private function lesson(array $extra = []): array
    {
        $employee = $this->makeEmployee();
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true, 'requires_evaluation' => false]);
        $r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 1]);
        $lesson = CourseLesson::create($extra + ['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'درس', 'title_en' => 'Lesson', 'body_ar' => 'النسخة الأولى', 'body_en' => 'v1', 'status' => 'published', 'sort_order' => 1]);

        return [$employee->user, $lesson, $r];
    }

    public function test_learners_in_progress_keep_their_version_or_move_to_the_new_one(): void
    {
        [$user, $lesson] = $this->lesson();
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($user)->getJson("/api/v1/me/lessons/{$lesson->id}")->assertOk()->assertJsonPath('data.body', fn ($b) => in_array($b, ['v1', 'النسخة الأولى'], true));       // starts under version 1

        $lesson->update(['body_en' => 'v2', 'body_ar' => 'النسخة الثانية']);
        $this->asUser($head)->postJson("/api/v1/admin/course/lessons/{$lesson->id}/versions", ['learners' => 'keep', 'note' => 'new text'])->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertSame(2, $lesson->fresh()->version);
        $this->asUser($user)->getJson("/api/v1/me/lessons/{$lesson->id}")->assertJsonPath('data.body', fn ($b) => in_array($b, ['v1', 'النسخة الأولى'], true));     // keeps version 1

        $newcomer = $this->makeEmployee();
        Registration::create(['program_id' => $lesson->program_id, 'employee_id' => $newcomer->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $this->asUser($newcomer->user)->getJson("/api/v1/me/lessons/{$lesson->id}")->assertJsonPath('data.body', fn ($b) => in_array($b, ['v2', 'النسخة الثانية'], true));

        // Publishing again without a change is refused; diff and restore work.
        $this->asUser($head)->postJson("/api/v1/admin/course/lessons/{$lesson->id}/versions", ['learners' => 'keep'])->assertStatus(422)->assertJsonPath('code', 'no_changes');
        $diff = $this->asUser($head)->getJson("/api/v1/admin/course/lessons/{$lesson->id}/versions/diff?from=1&to=2")->assertOk();
        $this->assertContains('body_en', array_column($diff->json('data.changed'), 'field'));
        $this->asUser($head)->postJson("/api/v1/admin/course/lessons/{$lesson->id}/versions/1/restore")->assertCreated();
        $this->assertSame(3, $lesson->fresh()->version);
        $this->assertSame('v1', $lesson->fresh()->body_en);

        // Moving learners puts everyone not finished on the newest version.
        $lesson->update(['body_en' => 'v4']);
        $this->asUser($head)->postJson("/api/v1/admin/course/lessons/{$lesson->id}/versions", ['learners' => 'move'])->assertCreated();
        $this->assertSame(4, LessonProgress::where('lesson_id', $lesson->id)->where('employee_id', $user->employee->id)->value('lesson_version'));
        $this->asUser($head)->putJson("/api/v1/admin/course/lessons/{$lesson->id}/versions/4/archive")->assertStatus(422);       // the current one cannot be archived
        $this->asUser($head)->putJson("/api/v1/admin/course/lessons/{$lesson->id}/versions/1/archive")->assertOk();
    }

    public function test_a_kit_can_be_assigned_to_several_programs(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $p1 = $this->makeProgram();
        $p2 = $this->makeProgram();
        $kit = TrainingKit::create(['owner_id' => $head->id, 'code' => 'K1', 'title_ar' => 'ح', 'title_en' => 'Kit', 'status' => 'draft', 'delivery' => 'online']);
        $this->asUser($head)->putJson("/api/v1/admin/kits/{$kit->id}/programs", ['programs' => [['program_id' => $p1->id]]])->assertStatus(422);       // not approved yet
        $kit->update(['status' => 'approved']);
        $this->asUser($head)->putJson("/api/v1/admin/kits/{$kit->id}/programs", ['programs' => [['program_id' => $p1->id], ['program_id' => $p2->id, 'pinned_version' => 2]]])->assertOk()->assertJsonCount(2, 'data');
        $kits = app(CourseBlueprints::class)->kits($p2);
        $this->assertTrue(collect($kits)->firstWhere('id', $kit->id)['linked']);
    }

    public function test_library_rights_audience_embargo_and_downloads(): void
    {
        Storage::fake('local');
        $manager = $this->makeUser(Role::TRAINING_HEAD);
        $teacher = $this->makeEmployee(['experience_years' => 5]);
        $junior = $this->makeEmployee(['experience_years' => 1]);
        $post = fn (array $d) => $this->asUser($manager)->postJson('/api/v1/admin/library-items', $d + ['type' => 'book', 'title_ar' => 'كتاب', 'status' => 'published'])->assertCreated()->json('data.id');

        $open = $post(['title_ar' => 'الرياضيات للصف الخامس', 'authors' => ['أحمد'], 'rights' => ['download' => true, 'print' => true]]);
        $senior = $post(['title_ar' => 'للخبراء فقط', 'audience' => [['field' => 'experience_years', 'operator' => 'gte', 'value' => 3]]]);
        $protected = $post(['title_ar' => 'محمي بحقوق الملكية', 'rights' => ['owner' => 'Publisher', 'download' => false, 'watermark' => true]]);
        $embargo = $post(['title_ar' => 'قيد الحظر', 'rights' => ['embargo_from' => now()->addDays(10)->toDateString()]]);
        $this->asUser($manager)->post("/api/v1/admin/library-items/{$protected}/files", ['file' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
        $this->asUser($manager)->post("/api/v1/admin/library-items/{$open}/files", ['file' => UploadedFile::fake()->create('c.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();

        $ids = fn ($u) => collect($this->asUser($u)->getJson('/api/v1/me/library')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$open, $senior, $protected], $ids($teacher->user));
        $this->assertEqualsCanonicalizing([$open, $protected], $ids($junior->user));                 // audience rule hides the expert title
        $this->asUser($junior->user)->getJson("/api/v1/me/library/{$senior}")->assertNotFound();
        $this->asUser($teacher->user)->getJson("/api/v1/me/library/{$embargo}")->assertNotFound();    // embargoed

        // Arabic-friendly search folds letter forms (أ/ا, ة/ه, tashkeel).
        $found = $this->asUser($teacher->user)->getJson('/api/v1/me/library?q='.urlencode('الرياضيّات'))->json('data');
        $this->assertSame([$open], array_column($found, 'id'));
        $this->assertCount(1, $this->asUser($teacher->user)->getJson('/api/v1/me/library?q='.urlencode('احمد'))->json('data'));

        // Rights: a protected item can be read (with a watermark) but not downloaded.
        $read = $this->asUser($teacher->user)->getJson("/api/v1/me/library/{$protected}/read")->assertOk();
        $this->assertFalse($read->json('data.allow_download'));
        $this->assertStringContainsString($teacher->employee_no, $read->json('data.watermark'));
        $this->asUser($teacher->user)->getJson("/api/v1/me/library/{$protected}/download")->assertStatus(422)->assertJsonPath('code', 'download_not_allowed');
        $this->asUser($teacher->user)->getJson("/api/v1/me/library/{$open}/download")->assertOk()->assertJsonStructure(['data' => ['url']]);
        $this->assertSame(1, LibraryItem::find($open)->downloads);

        // Shelf and reviews.
        $this->asUser($teacher->user)->putJson("/api/v1/me/library/{$open}/shelf", ['on' => true, 'progress' => 40])->assertOk();
        $this->asUser($teacher->user)->getJson('/api/v1/me/library/shelf')->assertJsonPath('data.0.progress', 40);
        $this->asUser($teacher->user)->postJson("/api/v1/me/library/{$open}/review", ['stars' => 4, 'review' => 'جيد'])->assertOk();
        $this->asUser($junior->user)->postJson("/api/v1/me/library/{$open}/review", ['stars' => 5])->assertOk();
        $this->asUser($teacher->user)->getJson("/api/v1/me/library/{$open}")->assertJsonPath('data.rating', 4.5)->assertJsonPath('data.reviews', 2);
    }

    public function test_sharing_follows_policies_and_protects_view_only_items(): void
    {
        Storage::fake('local');
        $trainer = $this->makeUser(Role::TRAINER);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $item = app(LibraryService::class)->save(['type' => 'book', 'title_ar' => 'محمي', 'status' => 'published', 'rights' => ['download' => false]], $head);
        $program = $this->makeProgram();
        $learner = $this->makeEmployee();
        Registration::create(['program_id' => $program->id, 'employee_id' => $learner->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $body = ['resource_type' => 'library_item', 'resource_id' => $item->id, 'target_type' => 'program', 'target_id' => $program->id, 'permission' => 'download'];

        // A trainer holds no sharing policy yet: refused. Add one, and the share works but downloads are downgraded to view.
        $this->asUser($trainer)->postJson('/api/v1/admin/shares', $body)->assertStatus(422)->assertJsonPath('code', 'share_not_allowed');
        $this->asUser($head)->postJson('/api/v1/admin/sharing-policies', ['role' => Role::TRAINER, 'resource_types' => ['library_item', 'material'], 'target_types' => ['program'], 'allow_download' => true, 'allow_reshare' => false])->assertOk();
        $this->asUser($head)->postJson('/api/v1/admin/shares', $body + ['permission' => 'download'])->assertCreated()->assertJsonPath('data.permission', 'view');
        $this->asUser($learner->user)->getJson('/api/v1/me/shared')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.view_only', true);

        // Reshare is refused by policy; job groups are for their members.
        $this->asUser($head)->postJson('/api/v1/admin/shares', ['permission' => 'reshare'] + $body)->assertCreated();   // sharing managers are not limited
        $mat = Material::create(['program_id' => $program->id, 'title_ar' => 'م', 'title_en' => 'M', 'type' => 'file', 'visibility' => 'participants', 'uploaded_by' => $trainer->id]);
        $this->asUser($trainer)->postJson('/api/v1/admin/shares', ['resource_type' => 'material', 'resource_id' => $mat->id, 'target_type' => 'program', 'target_id' => $program->id, 'permission' => 'reshare'])->assertStatus(422)->assertJsonPath('code', 'share_no_reshare');
        $this->asUser($trainer)->postJson('/api/v1/admin/shares', ['resource_type' => 'material', 'resource_id' => $mat->id, 'target_type' => 'program', 'target_id' => $program->id, 'permission' => 'view'])->assertCreated();
        $other = $this->makeEmployee();
        $this->asUser($other->user)->getJson('/api/v1/me/shared')->assertJsonCount(0, 'data');

        // An expired share disappears.
        ResourceShare::query()->update(['expires_at' => now()->subDay()]);
        $this->asUser($learner->user)->getJson('/api/v1/me/shared')->assertJsonCount(0, 'data');
    }

    public function test_job_group_members_are_found_by_rule(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $e = $this->makeEmployee();
        $g = $this->asUser($head)->postJson('/api/v1/admin/job-groups', ['name_ar' => 'معلمون', 'name_en' => 'Teachers', 'rule' => ['job_title_ids' => [$e->job_title_id]]])->assertCreated()->json('data.id');
        $this->asUser($head)->getJson("/api/v1/admin/job-groups/{$g}/members")->assertOk()->assertJsonPath('data.0.id', $e->id);
        $this->assertTrue(app(JobGroupService::class)->matches(JobGroup::find($g), $e));
        $this->assertFalse(app(JobGroupService::class)->matches(new JobGroup(['rule' => []]), $e));
    }

    public function test_external_libraries_search_import_and_deep_links(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $this->asUser($head)->putJson('/api/v1/admin/settings/external-libraries', ['maktabati' => ['enabled' => true, 'driver' => 'fake'], 'qnl' => ['enabled' => true, 'driver' => 'link', 'search_url' => 'https://qnl.example/search?q={q}']])->assertOk();
        $res = $this->asUser($head)->getJson('/api/v1/admin/external-libraries/search?q='.urlencode('تعليم'))->assertOk()->json('data');
        $this->assertCount(2, $res);
        $this->assertSame('maktabati', $res[0]['library']);
        $this->assertTrue($res[1]['deep_link']);
        $this->assertStringContainsString(rawurlencode('تعليم'), $res[1]['url']);

        $this->asUser($head)->postJson('/api/v1/admin/external-libraries/import', $res[0])->assertCreated();
        $this->asUser($head)->postJson('/api/v1/admin/external-libraries/import', $res[0])->assertCreated();
        $this->assertSame(1, LibraryItem::where('source', 'maktabati')->count());                // importing twice updates, not duplicates
    }

    public function test_provider_sync_creates_courses_programs_and_completions_count_hours(): void
    {
        Storage::fake('local');
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $employee = $this->makeEmployee();
        $this->asUser($head)->putJson('/api/v1/admin/settings/content-providers', ['coursera' => ['enabled' => true, 'driver' => 'fake']])->assertOk();
        $this->asUser($head)->postJson('/api/v1/admin/content-providers/sync')->assertOk()->assertJsonPath('data.courses', 2);
        $course = ExternalCourse::where('external_id', 'coursera-101')->first();
        $program = $this->asUser($head)->postJson("/api/v1/admin/external-courses/{$course->id}/program")->assertCreated()->json('data');
        $this->assertSame('Coursera', $program['external_platform']['name']);
        $r = Registration::create(['program_id' => $program['id'], 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        $this->asUser($employee->user)->postJson("/api/v1/me/registrations/{$r->id}/external-launch")->assertOk()->assertJsonPath('data.platform', 'Coursera');
        $cfg = SiteSetting::find('content_providers');
        $v = $cfg->value;
        $v['coursera']['fake_completions'] = [['course_id' => 'coursera-101', 'employee_no' => $employee->employee_no, 'completed_at' => now()->toDateString()]];
        $cfg->update(['value' => $v]);
        $this->assertSame(1, app(ExternalLearningService::class)->sync()['completed']);
        $this->assertSame(0, app(ExternalLearningService::class)->sync()['completed'], 'a completion counts once');
        $this->assertSame('completed', $r->fresh()->status);
        $this->assertEquals(6.0, (float) $r->certificates()->first()->hours);
        $this->assertEquals(6.0, app(AnnualHoursService::class)->allTime($employee->fresh()));
    }

    public function test_programs_on_other_platforms_are_completed_by_evidence_the_centre_reviews(): void
    {
        Storage::fake('local');
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $employee = $this->makeEmployee();
        $program = $this->makeProgram(['external_platform' => ['name' => 'I-earn', 'url' => 'https://iearn.example'], 'total_hours' => 8]);
        $r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);

        $this->asUser($employee->user)->postJson("/api/v1/me/registrations/{$r->id}/external-completion", [])->assertStatus(422)->assertJsonPath('code', 'evidence_required');
        $this->asUser($employee->user)->post("/api/v1/me/registrations/{$r->id}/external-completion", ['evidence' => [UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertCreated();
        $c = ExternalCompletion::first();
        $this->asUser($head)->postJson("/api/v1/admin/external-completions/{$c->id}/decision", ['decision' => 'reject'])->assertStatus(422)->assertJsonPath('code', 'note_required');
        $this->asUser($head)->postJson("/api/v1/admin/external-completions/{$c->id}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('completed', $r->fresh()->status);
        $this->assertEquals(8.0, (float) $r->certificates()->first()->hours);
        $this->asUser($head)->postJson("/api/v1/admin/external-completions/{$c->id}/decision", ['decision' => 'approve'])->assertStatus(422)->assertJsonPath('code', 'already_decided');
    }

    public function test_offline_manifest_and_idempotent_sync_never_lower_progress(): void
    {
        Storage::fake('local');
        [$user, $lesson, $r] = $this->lesson(['type' => 'video', 'duration_seconds' => 100, 'file_path' => 'v/x.mp4', 'file_mime' => 'video/mp4', 'file_size' => 1000]);
        $this->asUser($user)->getJson("/api/v1/me/courses/{$r->id}/offline-manifest")->assertForbidden();         // the feature flag is off

        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($admin)->putJson('/api/v1/admin/features/offline_mobile', ['enabled' => true])->assertOk();
        $m = $this->asUser($user)->getJson("/api/v1/me/courses/{$r->id}/offline-manifest")->assertOk();
        $this->assertTrue($m->json('data.lessons.0.downloadable'));
        $this->assertSame(14, $m->json('data.expiry_days'));
        $this->assertNotEmpty($m->json('data.lessons.0.url'));

        $batch = ['batch_id' => 'b-1', 'items' => [['type' => 'video_progress', 'lesson_id' => $lesson->id, 'segments' => [[0, 60]], 'position' => 60]]];
        $this->asUser($user)->postJson('/api/v1/me/sync', $batch)->assertOk()->assertJsonPath('data.applied', 1)->assertJsonPath('data.replayed', false);
        $this->assertEquals(60.0, (float) LessonProgress::first()->percent);
        $this->asUser($user)->postJson('/api/v1/me/sync', $batch)->assertOk()->assertJsonPath('data.replayed', true);   // same key: nothing is applied twice

        // A later batch with less never lowers it; finishing the video completes the lesson.
        $this->asUser($user)->postJson('/api/v1/me/sync', ['batch_id' => 'b-2', 'items' => [['type' => 'video_progress', 'lesson_id' => $lesson->id, 'segments' => [[0, 20]], 'position' => 20]]])->assertOk();
        $this->assertEquals(60.0, (float) LessonProgress::first()->percent);
        $this->asUser($user)->postJson('/api/v1/me/sync', ['batch_id' => 'b-3', 'items' => [['type' => 'video_progress', 'lesson_id' => $lesson->id, 'segments' => [[60, 100]], 'position' => 100], ['type' => 'bogus', 'lesson_id' => $lesson->id]]])->assertOk()->assertJsonPath('data.applied', 1)->assertJsonPath('data.rejected.0.reason', 'unknown_type');
        $this->assertSame('completed', LessonProgress::first()->status);

        $other = $this->makeEmployee();
        $this->asUser($other->user)->postJson('/api/v1/me/sync', ['batch_id' => 'b-4', 'items' => [['type' => 'lesson_complete', 'lesson_id' => $lesson->id]]])->assertOk()->assertJsonPath('data.applied', 0);
        app(StandardsSettings::class)->update(['offline' => ['enabled' => true, 'expiry_days' => 7]]);
        $this->asUser($user)->getJson("/api/v1/me/courses/{$r->id}/offline-manifest")->assertJsonPath('data.expiry_days', 7);
    }
}
