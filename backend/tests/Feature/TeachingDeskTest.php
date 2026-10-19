<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\GroupTrainer;
use App\Models\KitAccessEvent;
use App\Models\KitAsset;
use App\Models\KitFile;
use App\Models\NotificationDelivery;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainerNotice;
use App\Models\TrainingKit;
use App\Models\TrainingRoom;
use App\Services\Channels\ChannelSettings;
use App\Services\FileStorage;
use App\Services\Teaching\KitProtection;
use App\Services\Teaching\TrainerNotices;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The trainer's side: the "you are the trainer of …" notice and popup, the protected kit reader, the trail of capture attempts, and starting a session. */
class TeachingDeskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        app(ChannelSettings::class)->update(['email' => ['enabled' => true, 'driver' => 'log'], 'sms' => ['enabled' => true, 'driver' => 'log']]);
        Cache::flush();
    }

    private function trainer(string $nameAr = 'مدربة الحقائب'): Trainer
    {
        return Trainer::create(['name_ar' => $nameAr, 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => [], 'rating' => 4,
            'user_id' => $this->makeUser(Role::TRAINER, ['name_ar' => $nameAr, 'name' => 'Trainer '.uniqid()])->id]);
    }

    private function kit(string $status = TrainingKit::PUBLISHED): TrainingKit
    {
        return TrainingKit::create(['code' => 'K-'.uniqid(), 'title_ar' => 'حقيبة القيادة', 'title_en' => 'Leadership kit', 'delivery' => 'in_person', 'status' => $status, 'duration_hours' => 6, 'owner_id' => $this->makeUser(Role::KIT_DEVELOPER)->id]);
    }

    private function programWith(Trainer $trainer, array $extra = []): Program
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN, ['name_ar' => 'المشرف سعد']);
        $id = $this->asUser($admin)->postJson('/api/v1/admin/programs', $extra + ['title_ar' => 'برنامج القيادة', 'title_en' => 'Leadership', 'total_hours' => 6, 'capacity' => 20, 'coordinator_id' => $admin->id,
            'start_date' => today()->addDays(20)->toDateString(), 'end_date' => today()->addDays(21)->toDateString(), 'trainers' => [['id' => $trainer->id, 'role' => 'lead']]])->assertCreated()->json('data.id');

        return Program::findOrFail($id);
    }

    public function test_a_trainer_assigned_to_a_programme_is_told_once_with_when_where_and_under_whom_and_it_waits_as_a_popup_until_dismissed(): void
    {
        $trainer = $this->trainer();
        $program = $this->programWith($trainer);
        $room = TrainingRoom::create(['code' => 'R-T1', 'name_ar' => 'قاعة الدوحة', 'name_en' => 'Doha Hall', 'capacity' => 30, 'status' => 'available']);
        $start = today()->addDays(20)->setTime(9, 0);
        ProgramSession::create(['program_id' => $program->id, 'trainer_id' => $trainer->id, 'training_room_id' => $room->id, 'sequence' => 1, 'title_ar' => 'اليوم الأول', 'title_en' => 'Day 1', 'starts_at' => $start, 'ends_at' => $start->copy()->setTime(13, 0), 'status' => 'scheduled', 'mode' => 'in_person']);

        // The notice: one per trainer and group, with the dates, the group, the supervisor and the line that the kit belongs to the centre. Push is in-app; e-mail and SMS are queued.
        $note = AppNotification::where('user_id', $trainer->user_id)->where('type', 'trainer.assigned')->get();
        $this->assertCount(1, $note);
        $this->assertStringContainsString('برنامج القيادة', $note[0]->body_ar);
        $this->assertStringContainsString('المشرف سعد', $note[0]->body_ar);
        $this->assertStringContainsString('الحقيبة التدريبية ملك المركز التدريبي', $note[0]->body_ar);
        $this->assertStringContainsString('property of the training centre', $note[0]->body_en);
        $this->assertEqualsCanonicalizing(['email', 'sms'], NotificationDelivery::where('user_id', $trainer->user_id)->where('type', 'trainer.assigned')->pluck('channel')->all());
        $this->assertSame(1, TrainerNotice::where('user_id', $trainer->user_id)->count());

        // The session, with its room, joins the details; saving the programme again tells nobody twice.
        $d = app(TrainerNotices::class)->details($program->fresh(), $program->groups()->first());
        $this->assertSame('قاعة الدوحة', $d['rooms'][0]['ar']);
        $this->assertStringContainsString('قاعة الدوحة', $d['text']['ar']);
        $this->assertStringContainsString($start->format('Y-m-d 09:00'), $d['text']['ar']);
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->putJson("/api/v1/admin/programs/{$program->id}", ['title_ar' => 'برنامج القيادة', 'trainers' => [['id' => $trainer->id, 'role' => 'lead']]])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $trainer->user_id)->where('type', 'trainer.assigned')->count());

        // The popup on the trainer's home: the notice with its details; a second trainer added later gets theirs; dismissing hides it for good.
        $me = $this->asUser($trainer->user);
        $items = $me->getJson('/api/v1/me/teaching/notices')->assertOk()->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($program->id, $items[0]['program']['id']);
        $this->assertSame('المشرف سعد', $items[0]['supervisor']['ar']);
        $this->assertSame('الحقيبة التدريبية ملك المركز التدريبي.', $items[0]['ownership']['ar']);
        $this->assertNotNull(TrainerNotice::first()->seen_at);

        $second = $this->trainer('مدرب ثانٍ');
        $this->asUser($admin)->putJson("/api/v1/admin/programs/{$program->id}", ['trainers' => [['id' => $trainer->id, 'role' => 'lead'], ['id' => $second->id, 'role' => 'assistant']]])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $second->user_id)->where('type', 'trainer.assigned')->count());
        $this->assertSame(1, AppNotification::where('user_id', $trainer->user_id)->where('type', 'trainer.assigned')->count());

        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/notices/{$items[0]['id']}/dismiss")->assertOk();
        $this->assertSame([], $this->asUser($trainer->user)->getJson('/api/v1/me/teaching/notices')->assertOk()->json('data'));
        $this->assertCount(1, $this->asUser($second->user)->getJson('/api/v1/me/teaching/notices')->json('data'));
        // Someone else cannot dismiss another person's notice.
        $this->asUser($trainer->user)->postJson('/api/v1/me/teaching/notices/'.TrainerNotice::where('user_id', $second->user_id)->value('id').'/dismiss')->assertOk();
        $this->assertCount(1, $this->asUser($second->user)->getJson('/api/v1/me/teaching/notices')->json('data'));

        // An approved group assignment tells the trainer too — once for that group.
        $third = $this->trainer('مدرب ثالث');
        $group = $program->groups()->first();
        $assignment = GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $third->id, 'role' => 'lead', 'hours' => 6, 'status' => GroupTrainer::PROPOSED, 'proposed_by' => $admin->id, 'form' => ['x' => 1], 'form_submitted_at' => now()]);
        $this->asUser($admin)->postJson("/api/v1/admin/group-trainers/{$assignment->id}/decision", ['decision' => 'approved', 'external_approval_ref' => 'REF-1'])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $third->user_id)->where('type', 'trainer.assigned')->count());
    }

    public function test_a_trainer_reads_the_kits_of_their_programmes_only_through_the_protected_viewer_and_every_attempt_to_capture_is_logged_and_reported(): void
    {
        $trainer = $this->trainer();
        $other = $this->trainer('مدرب آخر');
        $program = $this->programWith($trainer);
        $kit = $this->kit();
        $draft = $this->kit(TrainingKit::DRAFT);
        foreach ([$kit, $draft] as $k) {
            $program->kits()->attach($k->id, ['id' => (string) Str::uuid(), 'pinned_version' => 1]);
        }
        $storage = app(FileStorage::class);
        $storage->put('documents', 'kits/guide.pdf', '%PDF-1.4 secret guide', 'application/pdf');
        $pdf = KitFile::create(['kit_id' => $kit->id, 'name' => 'دليل المدرب.pdf', 'original_name' => 'guide.pdf', 'kind' => 'pdf', 'category' => 'trainer_guide', 'source' => 'upload', 'mime' => 'application/pdf', 'size' => 21, 'storage_path' => 'kits/guide.pdf']);
        $storage->put('documents', 'kits/pic.png', 'PNGBYTES', 'image/png');
        $asset = KitAsset::create(['kit_id' => $kit->id, 'name' => 'pic.png', 'mime' => 'image/png', 'size' => 8, 'storage_path' => 'kits/pic.png', 'source' => 'upload']);
        $deck = KitFile::create(['kit_id' => $kit->id, 'name' => 'العرض', 'kind' => 'presentation', 'category' => 'presentation', 'source' => 'studio', 'revision' => 1, 'content' => ['version' => 1, 'size' => ['w' => 1280, 'h' => 720], 'theme' => ['primary' => '#8A1538'], 'slides' => [
            ['id' => 's1', 'layout' => 'blank', 'background' => ['color' => '#fff', 'asset_id' => null], 'notes' => 'ملاحظة المدرب', 'elements' => [['id' => 'e1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'asset_id' => $asset->id]]]]]]);

        $me = $this->asUser($trainer->user);
        // The desk lists the programme and its approved kit — not the draft one.
        $home = $me->getJson('/api/v1/me/teaching')->assertOk()->json('data');
        $this->assertSame([$program->id], array_column($home['programs'], 'id'));
        $this->assertSame([$kit->id], array_column($home['programs'][0]['kits'], 'id'));
        $this->assertSame('الحقيبة التدريبية ملك المركز التدريبي.', $home['ownership']['ar']);

        $info = $me->getJson("/api/v1/me/teaching/kits/{$kit->id}?program={$program->id}")->assertOk()->json('data');
        $this->assertFalse($info['acknowledged']);
        $this->assertTrue($info['protection']['watermark']);
        $this->assertArrayNotHasKey('storage_path', $info['files'][0]);
        $this->assertSame(['presentable' => true], ['presentable' => collect($info['files'])->firstWhere('id', $deck->id)['presentable']]);
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/kits/{$kit->id}/acknowledge")->assertOk();
        $this->assertTrue($this->asUser($trainer->user)->getJson("/api/v1/me/teaching/kits/{$kit->id}")->json('data.acknowledged'));

        // The deck's pictures go through the protected route; no storage address leaves the server.
        $view = $this->asUser($trainer->user)->getJson("/api/v1/me/teaching/kits/{$kit->id}/files/{$deck->id}")->assertOk()->json('data');
        $this->assertSame("/me/teaching/kits/{$kit->id}/assets/{$asset->id}", $view['deck']['slides'][0]['elements'][0]['src']);
        $this->assertSame('ملاحظة المدرب', $view['deck']['slides'][0]['notes']);
        $this->assertStringNotContainsString('http', json_encode($view));
        // A file streams inline with no cache and a range works; there is no download route at all.
        $res = $this->asUser($trainer->user)->get("/api/v1/me/teaching/kits/{$kit->id}/files/{$pdf->id}/stream")->assertOk();
        $this->assertSame('%PDF-1.4 secret guide', $res->getContent());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('inline', $res->headers->get('Content-Disposition'));
        $this->asUser($trainer->user)->get("/api/v1/me/teaching/kits/{$kit->id}/assets/{$asset->id}", ['Range' => 'bytes=0-2'])->assertStatus(206);
        $this->asUser($trainer->user)->get("/api/v1/me/teaching/kits/{$kit->id}/files/{$pdf->id}/download")->assertNotFound();

        // Not their programme, a draft kit, or an employee: refused.
        $this->asUser($other->user)->getJson("/api/v1/me/teaching/kits/{$kit->id}")->assertForbidden();
        $this->asUser($other->user)->get("/api/v1/me/teaching/kits/{$kit->id}/files/{$pdf->id}/stream")->assertForbidden();
        $this->asUser($trainer->user)->getJson("/api/v1/me/teaching/kits/{$draft->id}")->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson("/api/v1/me/teaching/kits/{$kit->id}")->assertForbidden();

        // A capture attempt is logged, the centre is told once in a window, and the content-protection switches are the centre's to set.
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/kits/{$kit->id}/events", ['kind' => 'capture_attempt', 'file_id' => $pdf->id, 'program_id' => $program->id, 'meta' => ['key' => 'PrintScreen', 'surface' => 'reader']])->assertOk();
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/kits/{$kit->id}/events", ['kind' => 'capture_attempt', 'file_id' => $pdf->id])->assertOk();
        $this->assertSame(2, KitAccessEvent::where('kind', 'capture_attempt')->count());
        $alerts = AppNotification::where('user_id', $admin->id)->where('type', 'kit.capture_attempt')->get();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('مدربة الحقائب', $alerts[0]->body_ar);
        $this->assertSame(0, NotificationDelivery::where('user_id', $admin->id)->where('type', 'kit.capture_attempt')->whereIn('channel', ['email', 'sms'])->count()); // in-app only
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/kits/{$kit->id}/events", ['kind' => 'bogus'])->assertStatus(422);

        $log = $this->asUser($admin)->getJson('/api/v1/admin/kits/protection?kind=capture_attempt')->assertOk()->json();
        $this->assertSame(2, $log['meta']['total']);
        $this->assertSame('مدربة الحقائب', $log['data'][0]['trainer']['name']);
        $this->assertSame(2, $log['stats']['alerts']);
        $this->asUser($trainer->user)->getJson('/api/v1/admin/kits/protection')->assertForbidden();
        $this->asUser($admin)->putJson('/api/v1/admin/kits/protection', ['blank_on_blur_presenter' => true, 'watermark' => true])->assertOk()->assertJsonPath('data.blank_on_blur_presenter', true);
        $this->assertTrue(app(KitProtection::class)->all()['blank_on_blur_presenter']);
        $this->assertSame(2, collect($this->asUser($admin)->getJson("/api/v1/admin/kits/{$kit->id}/access-events")->assertOk()->json('data'))->where('kind', 'capture_attempt')->count());
    }

    public function test_a_trainer_starts_the_session_they_are_about_to_teach_and_only_then(): void
    {
        $trainer = $this->trainer();
        $other = $this->trainer('مدرب آخر');
        $program = $this->programWith($trainer);
        $mk = fn (int $minutes, ?Trainer $t = null) => ProgramSession::create(['program_id' => $program->id, 'trainer_id' => ($t ?? $trainer)->id, 'sequence' => 1, 'title_ar' => 'جلسة', 'title_en' => 'S', 'starts_at' => now()->addMinutes($minutes), 'ends_at' => now()->addMinutes($minutes + 120), 'status' => 'scheduled', 'mode' => 'online']);
        $soon = $mk(20);
        $later = $mk(60 * 30);
        $theirs = $mk(10, $other);
        $me = $this->asUser($trainer->user);

        $me->postJson("/api/v1/me/teaching/sessions/{$later->id}/start")->assertStatus(422)->assertJsonPath('code', 'outside_window');
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/sessions/{$theirs->id}/start")->assertStatus(422)->assertJsonPath('code', 'not_your_session');
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/sessions/{$soon->id}/start")->assertOk()->assertJsonPath('data.status', 'live');
        $this->assertSame('live', $soon->fresh()->status);
        $this->asUser($trainer->user)->postJson("/api/v1/me/teaching/sessions/{$soon->id}/start")->assertOk(); // already live: fine
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson("/api/v1/me/teaching/sessions/{$soon->id}/start")->assertForbidden();
    }
}
