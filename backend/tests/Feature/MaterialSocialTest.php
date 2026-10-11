<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\DeviceToken;
use App\Models\GroupTrainer;
use App\Models\KitFile;
use App\Models\LessonNote;
use App\Models\LibraryItem;
use App\Models\Material;
use App\Models\Program;
use App\Models\Registration;
use App\Models\ResourceShare;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingKit;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\DeliveryPolicy;
use App\Services\Push\PushDispatcher;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** COL-02 (discussion and notes on materials), COL-03 (ratings everywhere, moderated) and NTF-09 (the TEDC sound on phones). */
class MaterialSocialTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($this->admin)->putJson('/api/v1/admin/features/forums', ['enabled' => true, 'reason' => 'test'])->assertOk();
    }

    /** @return array{0: Program, 1: Material, 2: User, 3: User} */
    private function course(): array
    {
        $program = $this->makeProgram();
        $group = TrainingGroup::where('program_id', $program->id)->first() ?? TrainingGroup::create(['program_id' => $program->id, 'code' => 'G1', 'title_ar' => 'م', 'title_en' => 'G', 'sequence' => 1, 'delivery_mode' => 'in_person', 'capacity' => 20, 'status' => 'open']);
        $trainee = $this->makeUser();
        Registration::create(['program_id' => $program->id, 'training_group_id' => $group->id, 'employee_id' => $this->makeEmployee([], $trainee)->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $trainerUser = $this->makeUser(Role::TRAINER);
        $t = Trainer::create(['user_id' => $trainerUser->id, 'name_ar' => 'م', 'name_en' => 'T', 'status' => 'active', 'source' => 'center']);
        GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $t->id, 'status' => 'approved']);
        $material = Material::create(['program_id' => $program->id, 'title_ar' => 'دليل المتدرب', 'title_en' => 'Trainee guide', 'type' => 'link', 'url' => 'https://example.org/g.pdf', 'visibility' => 'participants', 'uploaded_by' => $this->admin->id]);

        return [$program, $material, $trainee, $trainerUser];
    }

    public function test_registered_trainees_and_the_trainer_discuss_a_material_and_outsiders_cannot(): void
    {
        [, $material, $trainee, $trainer] = $this->course();
        $outsider = $this->makeUser();

        $space = $this->asUser($trainee)->getJson("/api/v1/social/spaces/resolve?type=material&id={$material->id}")->assertOk()->json('data');
        $this->assertSame('material_thread', $space['type']);
        $this->asUser($trainee)->postJson("/api/v1/social/spaces/{$space['id']}/posts", ['body' => 'Page 4 is unclear'])->assertCreated();
        $this->asUser($trainer)->getJson("/api/v1/social/spaces/{$space['id']}/posts")->assertOk()->assertJsonCount(1, 'data');

        $this->asUser($outsider)->getJson("/api/v1/social/spaces/resolve?type=material&id={$material->id}")->assertForbidden();
        $this->asUser($outsider)->getJson("/api/v1/social/spaces/{$space['id']}/posts")->assertForbidden();
    }

    public function test_notes_on_materials_are_private_to_their_author(): void
    {
        [, $material, $trainee, $trainer] = $this->course();

        $this->asUser($trainee)->postJson('/api/v1/social/notes', ['subject_type' => 'material', 'subject_id' => $material->id, 'body' => 'Re-read section 2'])->assertCreated();

        $this->asUser($trainee)->getJson("/api/v1/social/notes?subject_type=material&subject_id={$material->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($trainer)->getJson("/api/v1/social/notes?subject_type=material&subject_id={$material->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->makeUser())->postJson('/api/v1/social/notes', ['subject_type' => 'material', 'subject_id' => $material->id, 'body' => 'x'])->assertForbidden();
    }

    public function test_lesson_notes_keep_working_through_both_addresses(): void
    {
        [$program, , $trainee] = $this->course();
        $module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 0]);
        $lesson = CourseLesson::create(['program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'د', 'title_en' => 'L', 'sort_order' => 0, 'is_required' => true, 'status' => 'published']);

        $this->asUser($trainee)->postJson('/api/v1/social/lesson-notes', ['lesson_id' => $lesson->id, 'body' => 'old address'])->assertCreated();
        $this->asUser($trainee)->postJson('/api/v1/social/notes', ['subject_type' => 'lesson', 'subject_id' => $lesson->id, 'body' => 'new address'])->assertCreated();

        $this->asUser($trainee)->getJson("/api/v1/social/lesson-notes?lesson_id={$lesson->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, LessonNote::where(['subject_type' => 'lesson', 'subject_id' => $lesson->id])->count());
    }

    public function test_ratings_on_materials_and_programmes_obey_one_per_person_and_registration(): void
    {
        [$program, $material, $trainee] = $this->course();
        $outsider = $this->makeUser();

        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'material', 'subject_id' => $material->id, 'stars' => 4, 'review' => 'Useful'])->assertCreated();
        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'material', 'subject_id' => $material->id, 'stars' => 5])->assertCreated();
        $this->assertSame(1, ContentRating::where(['subject_type' => 'material', 'subject_id' => $material->id])->count());

        $this->asUser($outsider)->postJson('/api/v1/social/ratings', ['subject_type' => 'program', 'subject_id' => $program->id, 'stars' => 1])->assertForbidden();
    }

    public function test_the_summary_shows_the_distribution_and_newest_reviews_and_moderators_hide_with_a_reason(): void
    {
        [$program, , $trainee] = $this->course();
        $rid = $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'program', 'subject_id' => $program->id, 'stars' => 2, 'review' => 'Too fast'])->assertCreated();
        $rating = ContentRating::firstOrFail();

        $sum = $this->asUser($trainee)->getJson("/api/v1/social/ratings/program/{$program->id}")->assertOk()->json('data');
        $this->assertSame(1, $sum['distribution'][2]);
        $this->assertSame('Too fast', $sum['reviews'][0]['review']);

        $this->asUser($this->admin)->postJson("/api/v1/social/ratings/{$rating->id}/moderate", ['status' => 'hidden'])->assertStatus(422);
        $this->asUser($this->admin)->postJson("/api/v1/social/ratings/{$rating->id}/moderate", ['status' => 'hidden', 'reason' => 'Off-topic'])->assertOk();
        $this->assertSame(0, $this->asUser($trainee)->getJson("/api/v1/social/ratings/program/{$program->id}")->json('data.count'));
        $this->assertTrue(AuditLog::where('action', 'rating_moderated')->where('new_values->reason', 'Off-topic')->exists());
    }

    public function test_library_ratings_live_in_one_place(): void
    {
        $item = LibraryItem::create(['type' => 'book', 'title_ar' => 'كتاب', 'title_en' => 'Book', 'source' => 'link', 'url' => 'https://example.org/b', 'status' => 'published', 'audience' => null]);
        $reader = $this->makeUser();
        $this->makeEmployee([], $reader);

        $this->asUser($reader)->postJson("/api/v1/me/library/{$item->id}/review", ['stars' => 5, 'review' => 'Excellent'])->assertSuccessful();
        $this->asUser($reader)->postJson('/api/v1/social/ratings', ['subject_type' => 'library', 'subject_id' => $item->id, 'stars' => 4])->assertCreated();

        $sum = $this->asUser($reader)->getJson("/api/v1/social/ratings/library/{$item->id}")->assertOk()->json('data');
        $this->assertSame(1, $sum['count']);
        $this->assertEquals(4, $sum['average']);
        $this->assertEquals(4, $this->asUser($reader)->getJson("/api/v1/me/library/{$item->id}")->assertOk()->json('data.rating'));
    }

    public function test_phones_get_the_tedc_sound_unless_the_person_turned_it_off(): void
    {
        $account = json_encode(['type' => 'service_account', 'project_id' => 'tedc', 'private_key_id' => 'k1', 'private_key' => $this->serviceKey(), 'client_email' => 'svc@tedc.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token']);
        $this->asUser($this->admin)->putJson('/api/v1/admin/settings/push', ['enabled' => true, 'service_account_json' => $account,
            'client' => ['api_key' => 'AIzaSyTest_key-123', 'app_id' => '1:123456789:android:abc123', 'messaging_sender_id' => '123456789']])->assertOk();
        $loud = $this->makeUser();
        $quiet = $this->makeUser();
        DeviceToken::create(['user_id' => $loud->id, 'token' => 'tok-loud', 'platform' => 'android', 'locale' => 'ar']);
        DeviceToken::create(['user_id' => $quiet->id, 'token' => 'tok-quiet', 'platform' => 'android', 'locale' => 'ar']);
        UserNotificationPreference::create(['user_id' => $quiet->id, 'event_group' => DeliveryPolicy::SOUND, 'channel' => DeliveryPolicy::SOUND, 'enabled' => false]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'x']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

        app(PushDispatcher::class)->dispatch([$loud->id, $quiet->id], 'test.event', ['ar' => 'ع', 'en' => 'T'], null, [], true);

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'fcm.googleapis.com'))->mapWithKeys(fn ($r) => [$r['message']['token'] => $r['message']]);
        $this->assertSame('tedc_alerts', $sent['tok-loud']['android']['notification']['channel_id']);
        $this->assertSame('tedc_chime', $sent['tok-loud']['android']['notification']['sound']);
        $this->assertSame('tedc_chime.caf', $sent['tok-loud']['apns']['payload']['aps']['sound']);
        $this->assertSame('tedc_quiet', $sent['tok-quiet']['android']['notification']['channel_id']);
        $this->assertArrayNotHasKey('sound', $sent['tok-quiet']['android']['notification']);
        $this->assertArrayNotHasKey('sound', (array) ($sent['tok-quiet']['apns']['payload']['aps'] ?? []));
    }

    public function test_the_material_thread_has_its_own_address(): void
    {
        [, $material, $trainee] = $this->course();

        $this->asUser($trainee)->getJson("/api/v1/social/materials/{$material->id}/thread")->assertOk()->assertJsonPath('data.type', 'material_thread');
        $this->asUser($this->makeUser())->getJson("/api/v1/social/materials/{$material->id}/thread")->assertForbidden();
    }

    public function test_the_summary_says_who_may_rate_and_hides_reviews_from_outsiders(): void
    {
        [$program, $material, $trainee] = $this->course();
        $outsider = $this->makeUser();

        $this->assertTrue($this->asUser($trainee)->getJson("/api/v1/social/ratings/program/{$program->id}")->assertOk()->json('data.can_rate'));
        $this->assertFalse($this->asUser($outsider)->getJson("/api/v1/social/ratings/program/{$program->id}")->assertOk()->json('data.can_rate'));
        $this->assertTrue($this->asUser($trainee)->getJson("/api/v1/social/ratings/material/{$material->id}")->assertOk()->json('data.can_rate'));
        $this->asUser($outsider)->getJson("/api/v1/social/ratings/material/{$material->id}")->assertForbidden();
        $this->asUser($this->admin)->getJson("/api/v1/social/ratings/material/{$material->id}")->assertOk();
    }

    public function test_people_who_received_a_kit_file_may_rate_the_kit_and_see_it_in_their_shares(): void
    {
        $kit = TrainingKit::create(['owner_id' => $this->admin->id, 'code' => 'K-R', 'title_ar' => 'حقيبة', 'title_en' => 'Kit', 'status' => 'approved', 'delivery' => 'online']);
        $file = KitFile::create(['kit_id' => $kit->id, 'name' => 'دليل المدرب', 'kind' => 'document', 'category' => 'trainer', 'source' => 'uploaded', 'mime' => 'application/pdf', 'size' => 10]);
        $reader = $this->makeUser();
        $this->makeEmployee([], $reader);
        ResourceShare::create(['resource_type' => 'kit_file', 'resource_id' => $file->id, 'target_type' => 'user', 'target_id' => $reader->id, 'permission' => 'view', 'shared_by' => $this->admin->id]);

        $this->assertSame($kit->id, collect($this->asUser($reader)->getJson('/api/v1/me/shared')->assertOk()->json('data'))->firstWhere('resource_type', 'kit_file')['kit_id']);
        $this->asUser($reader)->postJson('/api/v1/social/ratings', ['subject_type' => 'kit', 'subject_id' => $kit->id, 'stars' => 5, 'review' => 'Clear'])->assertCreated();
        $this->asUser($this->makeUser())->postJson('/api/v1/social/ratings', ['subject_type' => 'kit', 'subject_id' => $kit->id, 'stars' => 1])->assertForbidden();
    }

    public function test_the_public_programme_page_shows_only_the_average_and_count(): void
    {
        [$program, , $trainee] = $this->course();
        $program->update(['status' => 'published', 'is_public' => true]);
        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'program', 'subject_id' => $program->id, 'stars' => 4, 'review' => 'Personal remark'])->assertCreated();
        auth()->forgetGuards();

        $res = $this->getJson("/api/v1/public/programs/{$program->code}")->assertOk();

        $this->assertSame(['average' => 4, 'count' => 1], $res->json('meta.rating'));
        $this->assertStringNotContainsString('Personal remark', $res->getContent());
    }

    public function test_editing_a_hidden_review_does_not_bring_it_back(): void
    {
        [$program, , $trainee] = $this->course();
        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'program', 'subject_id' => $program->id, 'stars' => 1, 'review' => 'Rude words'])->assertCreated();
        $this->asUser($this->admin)->postJson('/api/v1/social/ratings/'.ContentRating::firstOrFail()->id.'/moderate', ['status' => 'hidden', 'reason' => 'Abusive'])->assertOk();

        $this->asUser($trainee)->postJson('/api/v1/social/ratings', ['subject_type' => 'program', 'subject_id' => $program->id, 'stars' => 2, 'review' => 'Still rude'])->assertCreated();

        $this->assertSame('hidden', ContentRating::firstOrFail()->status);
        $this->assertSame(0, $this->asUser($trainee)->getJson("/api/v1/social/ratings/program/{$program->id}")->json('data.count'));
        $this->assertSame('hidden', $this->asUser($trainee)->getJson("/api/v1/social/ratings/program/{$program->id}")->json('data.mine.status'));
    }

    private function serviceKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
