<?php

namespace Tests\Feature;

use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LessonProgress;
use App\Models\OfflineDevice;
use App\Models\Registration;
use App\Models\RemedialAssignment;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** TYP-12: what the app may keep offline (checksummed, expiring, encrypted with a per-device key) and the safe sync of offline progress. */
class OfflineLearningTest extends TestCase
{
    private User $user;

    private Registration $r;

    private CourseModule $module;

    private string $device;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->asUser($this->makeUser(Role::SUPER_ADMIN))->putJson('/api/v1/admin/features/offline_mobile', ['enabled' => true, 'reason' => 'test'])->assertOk();
        $employee = $this->makeEmployee();
        $this->user = $employee->user;
        $program = $this->makeProgram(['delivery_mode' => 'online', 'has_course' => true, 'requires_evaluation' => false]);
        $this->r = Registration::create(['program_id' => $program->id, 'employee_id' => $employee->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
        $this->module = CourseModule::create(['program_id' => $program->id, 'title_ar' => 'و', 'title_en' => 'U', 'sort_order' => 1]);
        $this->device = (string) Str::uuid();
    }

    private function lesson(array $extra): CourseLesson
    {
        return CourseLesson::create($extra + ['program_id' => $this->r->program_id, 'module_id' => $this->module->id, 'title_ar' => 'درس', 'title_en' => 'Lesson', 'status' => 'published', 'sort_order' => 1, 'is_required' => true]);
    }

    private function manifest(?string $device = null): TestResponse
    {
        return $this->asUser($this->user)->getJson("/api/v1/me/courses/{$this->r->id}/offline-manifest?device_id=".($device ?? $this->device).'&platform=android');
    }

    public function test_the_manifest_lists_only_downloadable_items_with_checksum_size_expiry_and_a_device_key(): void
    {
        $bytes = str_repeat('video-bytes', 100);
        $path = app(FileStorage::class)->put('materials', 'lessons/v.mp4', $bytes, 'video/mp4');
        $video = $this->lesson(['type' => 'video', 'duration_seconds' => 60, 'file_path' => $path, 'file_mime' => 'video/mp4', 'file_size' => strlen($bytes),
            'settings' => ['allow_seeking' => false, 'max_speed' => 1.5, 'require_fullscreen' => true, 'min_watch_percent' => 95]]);
        $locked = $this->lesson(['type' => 'presentation', 'file_path' => $path, 'file_mime' => 'application/pdf', 'file_size' => 10, 'settings' => ['downloadable' => false]]);

        $m = $this->manifest()->assertOk()->json('data');

        $this->assertSame(2, $m['manifest_version']);
        $items = collect($m['lessons'])->keyBy('id');
        $this->assertTrue($items[$video->id]['downloadable']);
        $this->assertSame(hash('sha256', $bytes), $items[$video->id]['sha256']);
        $this->assertSame(strlen($bytes), $items[$video->id]['size']);
        $this->assertNotEmpty($items[$video->id]['expires_at']);
        // The app's player applies the same rules offline as online.
        $rules = array_intersect_key($items[$video->id]['settings'], array_flip(['allow_seeking', 'max_speed', 'min_watch_percent', 'require_fullscreen']));
        ksort($rules);
        $this->assertSame(['allow_seeking' => false, 'max_speed' => 1.5, 'min_watch_percent' => 95, 'require_fullscreen' => true], $rules);
        $this->assertSame(60, $items[$video->id]['duration_seconds']);
        $this->assertFalse($items[$locked->id]['downloadable']);
        $this->assertArrayNotHasKey('url', $items[$locked->id]);
        $this->assertSame('not_downloadable', $items[$locked->id]['reason']);
        $this->assertSame(32, strlen(base64_decode($m['content_key'])));
    }

    public function test_the_content_key_belongs_to_one_device_and_is_stored_encrypted(): void
    {
        $this->lesson(['type' => 'article', 'body_ar' => 'نص', 'body_en' => 'text']);

        $first = $this->manifest()->json('data.content_key');
        $this->assertSame($first, $this->manifest()->json('data.content_key'));
        $this->assertNotSame($first, $this->manifest((string) Str::uuid())->json('data.content_key'));

        $raw = DB::table('offline_devices')->where('device_id', $this->device)->value('content_key');
        $this->assertNotSame($first, $raw);
        $this->assertStringNotContainsString($first, (string) $raw);
    }

    public function test_a_revoked_registration_or_device_gets_a_delete_instruction(): void
    {
        $this->lesson(['type' => 'article', 'body_ar' => 'نص', 'body_en' => 'text']);
        $this->manifest()->assertOk();

        $this->r->update(['status' => Registration::STATUS_WITHDRAWN]);
        // A withdrawn registration deletes that course only; the phone itself stays trusted.
        $this->manifest()->assertOk()->assertJsonPath('data.delete', true)->assertJsonPath('data.lessons', [])->assertJsonPath('data.withdrawn', true)->assertJsonPath('data.revoked', false);
        $sync = $this->asUser($this->user)->postJson('/api/v1/me/sync', ['batch_id' => 'p-1', 'device_id' => $this->device, 'held' => [$this->r->id], 'items' => []])->assertOk();
        $this->assertSame([$this->r->id], $sync->json('data.purge'));
        $this->assertFalse($sync->json('data.device_revoked'));

        $this->r->update(['status' => Registration::STATUS_APPROVED]);
        $id = OfflineDevice::where('device_id', $this->device)->value('id');
        $this->asUser($this->user)->deleteJson("/api/v1/me/offline-devices/{$id}")->assertOk();
        // A revoked phone deletes everything and forgets its key.
        $this->manifest()->assertOk()->assertJsonPath('data.delete', true)->assertJsonPath('data.content_key', null)->assertJsonPath('data.revoked', true)->assertJsonPath('data.withdrawn', false);
        $sync = $this->asUser($this->user)->postJson('/api/v1/me/sync', ['batch_id' => 'p-2', 'device_id' => $this->device, 'held' => [$this->r->id], 'items' => []]);
        $this->assertSame([$this->r->id], $sync->json('data.purge'));
        $this->assertTrue($sync->json('data.device_revoked'));
    }

    public function test_offline_slide_views_obey_the_minimum_seconds_per_slide_by_their_recorded_time(): void
    {
        $slides = $this->lesson(['type' => 'presentation', 'file_path' => 'p.pdf', 'file_mime' => 'application/pdf', 'file_size' => 10, 'slide_count' => 3, 'settings' => ['min_seconds_per_slide' => 5, 'min_view_percent' => 100, 'downloadable' => true]]);
        $t = Carbon::parse('2026-10-10 10:00:00');
        $items = [
            ['type' => 'slide_view', 'lesson_id' => $slides->id, 'slide' => 1, 'total' => 3, 'at' => $t->toIso8601String()],
            ['type' => 'slide_view', 'lesson_id' => $slides->id, 'slide' => 2, 'total' => 3, 'at' => $t->copy()->addSeconds(2)->toIso8601String()],   // rushed
            ['type' => 'slide_view', 'lesson_id' => $slides->id, 'slide' => 3, 'total' => 3, 'at' => $t->copy()->addSeconds(10)->toIso8601String()],
            ['type' => 'slide_view', 'lesson_id' => $slides->id, 'slide' => 2, 'total' => 3, 'at' => $t->copy()->addSeconds(1)->toIso8601String()],   // older than what was applied
        ];

        $res = $this->asUser($this->user)->postJson('/api/v1/me/sync', ['batch_id' => 's-1', 'device_id' => $this->device, 'items' => $items])->assertOk();

        $this->assertSame([['index' => 3, 'reason' => 'out_of_order']], $res->json('data.rejected'));
        $p = LessonProgress::where('lesson_id', $slides->id)->firstOrFail();
        $this->assertEqualsWithDelta(66.67, (float) $p->percent, 0.01);   // slides 1 and 3 counted, 2 was rushed
        $this->assertNotSame('completed', $p->status);
    }

    public function test_a_review_lesson_goes_offline_only_for_the_learner_it_is_assigned_to(): void
    {
        $skill = Skill::create(['code' => 'OFF-1', 'name_ar' => 'مهارة', 'name_en' => 'Skill', 'category' => 'pedagogy']);
        $review = $this->lesson(['type' => 'article', 'body_ar' => 'مراجعة', 'body_en' => 'review', 'settings' => ['remedial' => true, 'skill_id' => $skill->id]]);
        $ids = fn () => collect($this->manifest()->assertOk()->json('data.lessons'))->pluck('id');
        $this->assertFalse($ids()->contains($review->id));

        RemedialAssignment::create(['registration_id' => $this->r->id, 'lesson_id' => $review->id, 'skill_id' => $skill->id, 'mastery' => 0.2, 'assigned_at' => now()]);

        $this->assertTrue($ids()->contains($review->id));
    }

    public function test_a_pdf_presentation_is_completed_by_viewing_its_slides_not_by_confirming(): void
    {
        $pdf = $this->lesson(['type' => 'presentation', 'file_path' => 'p.pdf', 'file_mime' => 'application/pdf', 'file_size' => 10, 'slide_count' => 2, 'settings' => ['min_seconds_per_slide' => 0, 'min_view_percent' => 100]]);
        $pptx = $this->lesson(['type' => 'presentation', 'file_path' => 'p.pptx', 'file_mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'file_size' => 10]);

        $this->asUser($this->user)->getJson("/api/v1/me/lessons/{$pdf->id}")->assertOk();
        $this->asUser($this->user)->postJson("/api/v1/me/lessons/{$pdf->id}/complete")->assertStatus(422)->assertJsonPath('code', 'view_slides');
        $this->asUser($this->user)->postJson("/api/v1/me/lessons/{$pdf->id}/slide", ['slide' => 1, 'total' => 2])->assertOk();
        $this->asUser($this->user)->postJson("/api/v1/me/lessons/{$pdf->id}/slide", ['slide' => 2, 'total' => 2])->assertOk();
        $this->assertSame('completed', LessonProgress::where('lesson_id', $pdf->id)->value('status'));

        // A file the app cannot show slide by slide is still confirmed by the learner.
        $this->asUser($this->user)->getJson("/api/v1/me/lessons/{$pptx->id}")->assertOk();
        $this->asUser($this->user)->postJson("/api/v1/me/lessons/{$pptx->id}/complete")->assertOk();
    }

    public function test_the_person_sees_and_removes_their_offline_devices(): void
    {
        $this->lesson(['type' => 'article', 'body_ar' => 'نص', 'body_en' => 'text']);
        $this->manifest()->assertOk();

        $devices = $this->asUser($this->user)->getJson('/api/v1/me/offline-devices')->assertOk()->json('data');
        $this->assertCount(1, $devices);
        $this->assertArrayNotHasKey('content_key', $devices[0]);
        $this->asUser($this->makeEmployee()->user)->deleteJson("/api/v1/me/offline-devices/{$devices[0]['id']}")->assertNotFound();
    }
}
