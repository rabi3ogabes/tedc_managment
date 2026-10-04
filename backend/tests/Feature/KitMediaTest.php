<?php

namespace Tests\Feature;

use App\Models\KitAsset;
use App\Models\KitFile;
use App\Models\Role;
use App\Models\User;
use App\Services\FileStorage;
use App\Services\Kits\DeckModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KitMediaTest extends TestCase
{
    private User $admin;

    private string $kit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->kit = $this->asUser($this->admin)->postJson('/api/v1/admin/kits', ['title_ar' => 'حقيبة', 'title_en' => 'Kit', 'audience' => 'x', 'duration_hours' => 2, 'objectives' => ['a']])->assertCreated()->json('data.id');
    }

    public function test_big_videos_and_sounds_go_straight_to_storage_and_become_library_assets(): void
    {
        $signed = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/upload-url", ['filename' => 'intro.mp4', 'mime' => 'video/mp4', 'size' => 40_000_000])->assertOk()->json('data');
        $this->assertStringStartsWith("kits/{$this->kit}/assets/", $signed['path']);

        $asset = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/complete", ['path' => $signed['path'], 'name' => 'intro.mp4', 'mime' => 'video/mp4', 'size' => 40_000_000, 'duration' => 42])
            ->assertCreated()->assertJsonPath('data.kind', 'video')->assertJsonPath('data.duration', 42)->json('data');

        // The library lists each kind on its own.
        $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/complete", ['path' => "kits/{$this->kit}/assets/a.mp3", 'name' => 'welcome.mp3', 'mime' => 'audio/mpeg', 'size' => 900_000])->assertCreated();
        $ids = fn (string $kind) => collect($this->asUser($this->admin)->getJson("/api/v1/admin/kits/{$this->kit}/assets?kind={$kind}")->json('data'))->pluck('kind')->unique()->all();
        $this->assertSame(['video'], $ids('video'));
        $this->assertSame(['audio'], $ids('audio'));
        $this->assertSame([], $ids('image'));
        $this->assertSame('materials', KitAsset::find($asset['id'])->bucket());

        // Only images, videos and sounds that belong to this kit's own folder are accepted, and nothing else.
        $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/upload-url", ['filename' => 'x.exe', 'mime' => 'application/x-msdownload', 'size' => 1000])->assertUnprocessable();
        $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/complete", ['path' => 'kits/other/assets/a.mp4', 'name' => 'a', 'mime' => 'video/mp4', 'size' => 10])->assertUnprocessable();
        $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/upload-url", ['filename' => 'huge.mp4', 'mime' => 'video/mp4', 'size' => 9_000_000_000])->assertUnprocessable();
    }

    public function test_slides_can_hold_video_and_sound_and_the_deck_serves_their_addresses(): void
    {
        $video = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/complete", ['path' => "kits/{$this->kit}/assets/v.mp4", 'name' => 'v.mp4', 'mime' => 'video/mp4', 'size' => 1000, 'duration' => 5])->json('data.id');
        $audio = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/complete", ['path' => "kits/{$this->kit}/assets/s.mp3", 'name' => 's.mp3', 'mime' => 'audio/mpeg', 'size' => 1000])->json('data.id');

        $deck = DeckModel::normalize(['slides' => [DeckModel::slide('blank', [
            ['type' => 'video', 'x' => 10, 'y' => 10, 'w' => 600, 'h' => 340, 'asset_id' => $video, 'autoplay' => true, 'loop' => true],
            ['type' => 'audio', 'x' => 10, 'y' => 400, 'w' => 400, 'h' => 80, 'asset_id' => $audio],
            ['type' => 'script', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10],
        ])]]);
        $types = array_column($deck['slides'][0]['elements'], 'type');
        $this->assertSame(['video', 'audio'], $types, 'unknown element types are dropped');
        $this->assertTrue($deck['slides'][0]['elements'][0]['autoplay']);

        $decorated = DeckModel::decorate($deck, app(FileStorage::class));
        $this->assertNotEmpty($decorated['slides'][0]['elements'][0]['src']);
        $this->assertNotEmpty($decorated['slides'][0]['elements'][1]['src']);
        $this->assertArrayNotHasKey('src', DeckModel::stripUrls($decorated)['slides'][0]['elements'][0]);
    }

    public function test_ai_sound_needs_an_openai_key_and_otherwise_explains_itself(): void
    {
        config(['tedc.kits.openai_key' => null]);
        $this->asUser($this->admin)->getJson('/api/v1/admin/kits/ai/status')->assertOk()->assertJsonPath('data.audio_provider', null);
        $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/ai/audio", ['text' => 'أهلاً بكم في البرنامج التدريبي'])->assertUnprocessable()->assertJsonPath('code', 'audio_unavailable');

        config(['tedc.kits.openai_key' => 'sk-test']);
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response('ID3-fake-mp3-bytes', 200)]);
        $res = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/ai/audio", ['text' => 'أهلاً بكم في البرنامج التدريبي', 'voice' => 'onyx'])->assertCreated()->assertJsonPath('data.kind', 'audio')->json('data');
        $this->assertSame('openai', $res['provider']);
        $this->assertSame('audio/mpeg', KitAsset::find($res['id'])->mime);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'audio/speech') && $r['voice'] === 'onyx' && $r['input'] === 'أهلاً بكم في البرنامج التدريبي');
        $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/ai/audio", ['text' => 'مرحبا', 'voice' => 'bogus'])->assertUnprocessable();
    }

    public function test_a_video_made_in_the_studio_can_be_put_in_the_media_library(): void
    {
        $file = KitFile::create(['kit_id' => $this->kit, 'name' => 'فيديو توضيحي', 'kind' => 'video', 'category' => 'media', 'source' => 'generated', 'mime' => 'video/webm', 'size' => 5000,
            'storage_path' => "kits/{$this->kit}/files/x.webm", 'uploaded_by' => $this->admin->id, 'updated_by' => $this->admin->id, 'sort_order' => 1]);

        $a = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/from-file", ['file_id' => $file->id])->assertCreated()->assertJsonPath('data.kind', 'video')->json('data');
        $b = $this->asUser($this->admin)->postJson("/api/v1/admin/kits/{$this->kit}/assets/from-file", ['file_id' => $file->id])->assertCreated()->json('data');
        $this->assertSame($a['id'], $b['id'], 'the same file is not added twice');
        $this->assertCount(1, $this->asUser($this->admin)->getJson("/api/v1/admin/kits/{$this->kit}/assets?kind=video")->json('data'));
    }
}
