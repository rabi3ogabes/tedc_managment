<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Role;
use App\Models\SocialUpload;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Models\User;
use App\Services\FileStorage;
use App\Social\SocialFiles;
use App\Social\SocialSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** PLC-03 / COL-04: real files in community posts and comments — validated, private, permission-checked and cleaned up. */
class SocialFilesTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin = $this->makeUser(Role::CENTER_ADMIN);
        foreach (['plc', 'forums'] as $flag) {
            $this->asUser($this->admin)->putJson("/api/v1/admin/features/{$flag}", ['enabled' => true, 'reason' => 'test'])->assertOk();
        }
    }

    private function community(array $settings = []): Space
    {
        $id = $this->asUser($this->admin)->postJson('/api/v1/social/spaces', ['title_ar' => 'مجتمع', 'title_en' => 'Maths teachers'] + ($settings ? ['settings' => $settings] : []))->assertCreated()->json('data.id');

        return Space::findOrFail($id);
    }

    private function member(Space $space, string $role = 'member'): User
    {
        $u = $this->makeUser(Role::TRAINER);
        SpaceMember::create(['space_id' => $space->id, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'source' => 'manual', 'joined_at' => now()]);

        return $u;
    }

    private function pdf(string $name = 'report.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");
    }

    private function png(string $name = 'board.png', int $w = 900, int $h = 600): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 138, 21, 56));
        ob_start();
        imagepng($img);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    /** A JPEG carrying an EXIF block with a GPS position (latitude reference "N"). */
    private function jpegWithGps(): UploadedFile
    {
        $img = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        // TIFF (big-endian): IFD0 with one entry pointing at a GPS IFD holding GPSLatitudeRef = "N".
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"
            ."\x00\x01"."\x88\x25"."\x00\x04"."\x00\x00\x00\x01"."\x00\x00\x00\x1A"."\x00\x00\x00\x00"
            ."\x00\x01"."\x00\x01"."\x00\x02"."\x00\x00\x00\x02"."N\x00\x00\x00"."\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        return UploadedFile::fake()->createWithContent('photo.jpg', substr($jpeg, 0, 2).$segment.substr($jpeg, 2));
    }

    private function upload(User $user, Space $space, UploadedFile $file): TestResponse
    {
        return $this->asUser($user)->post('/api/v1/social/uploads', ['space_id' => $space->id, 'file' => $file], ['Accept' => 'application/json']);
    }

    public function test_an_allowed_file_is_accepted_and_kept_pending_until_a_post_uses_it(): void
    {
        $space = $this->community();
        $m = $this->member($space);

        $up = $this->upload($m, $space, $this->pdf('تقرير الورشة.pdf'))->assertCreated()->json('data');

        $this->assertSame('تقرير الورشة.pdf', $up['name']);
        $this->assertSame('application/pdf', $up['mime']);
        $this->assertSame('pending', SocialUpload::find($up['id'])->status);

        $post = $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => '<p>See the file</p>', 'attachments' => [['type' => 'file', 'upload_id' => $up['id']], ['type' => 'link', 'url' => 'https://example.org/guide', 'name' => 'Guide']]])
            ->assertCreated()->json('data');

        $this->assertSame('attached', SocialUpload::find($up['id'])->status);
        $this->assertSame('file', $post['attachments'][0]['type']);
        $this->assertSame('تقرير الورشة.pdf', $post['attachments'][0]['name']);
        $this->assertArrayNotHasKey('path', $post['attachments'][0], 'storage paths never reach the client');
        $this->assertSame('link', $post['attachments'][1]['type']);
    }

    public function test_dangerous_or_disguised_files_are_refused(): void
    {
        $space = $this->community();
        $m = $this->member($space);

        $this->upload($m, $space, UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00binary"))->assertStatus(422);
        $this->upload($m, $space, UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422);
        $this->upload($m, $space, UploadedFile::fake()->createWithContent('notes.pdf', '<?php system($_GET["c"]); ?>'))->assertStatus(422);
        $this->upload($m, $space, $this->png('slides.pdf'))->assertStatus(422);   // an image pretending to be a PDF
        $this->assertSame(0, SocialUpload::count());
    }

    public function test_size_limits_per_file_and_per_post_come_from_the_settings(): void
    {
        app(SocialSettings::class)->save(['file_max_mb' => 1, 'post_total_mb' => 1]);
        $space = $this->community();
        $m = $this->member($space);

        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('A', 1_100_000));
        $this->upload($m, $space, $big)->assertStatus(422);

        $a = $this->upload($m, $space, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n".str_repeat('A', 600_000)))->assertCreated()->json('data.id');
        $b = $this->upload($m, $space, UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n".str_repeat('B', 600_000)))->assertCreated()->json('data.id');
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'x', 'attachments' => [['type' => 'file', 'upload_id' => $a], ['type' => 'file', 'upload_id' => $b]]])->assertStatus(422);
    }

    public function test_only_the_uploader_can_attach_a_pending_file(): void
    {
        $space = $this->community();
        $owner = $this->member($space);
        $other = $this->member($space);
        $up = $this->upload($owner, $space, $this->pdf())->assertCreated()->json('data.id');

        $this->asUser($other)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'x', 'attachments' => [['type' => 'file', 'upload_id' => $up]]])->assertStatus(422);
        $this->assertSame('pending', SocialUpload::find($up)->status);
    }

    public function test_a_space_without_files_refuses_uploads_and_links(): void
    {
        $space = $this->community(['allow_files' => false]);
        $m = $this->member($space);

        $this->upload($m, $space, $this->pdf())->assertStatus(422);
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'x', 'attachments' => [['type' => 'link', 'url' => 'https://example.org', 'name' => 'x']]])->assertStatus(422);
    }

    public function test_files_are_private_to_people_who_may_read_the_post(): void
    {
        $space = $this->community();
        $m = $this->member($space);
        $outsider = $this->makeUser(Role::TRAINER);
        $up = $this->upload($m, $space, $this->pdf())->assertCreated()->json('data.id');
        $postId = $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'x', 'attachments' => [['type' => 'file', 'upload_id' => $up]]])->assertCreated()->json('data.id');

        $this->asUser($outsider)->getJson("/api/v1/social/files/{$up}")->assertForbidden();
        $reader = $this->member($space);
        $this->assertNotEmpty($this->asUser($reader)->getJson("/api/v1/social/files/{$up}")->assertOk()->json('data.url'));

        Post::whereKey($postId)->update(['status' => 'hidden']);
        $this->asUser($reader)->getJson("/api/v1/social/files/{$up}")->assertForbidden();
    }

    public function test_comment_files_are_shown_with_the_comment(): void
    {
        $space = $this->community();
        $m = $this->member($space);
        $postId = $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'question'])->assertCreated()->json('data.id');
        $up = $this->upload($m, $space, $this->png())->assertCreated()->json('data.id');

        $this->asUser($m)->postJson("/api/v1/social/posts/{$postId}/comments", ['body' => 'answer', 'attachments' => [['type' => 'file', 'upload_id' => $up]]])->assertCreated();

        $comment = $this->asUser($m)->getJson("/api/v1/social/posts/{$postId}")->assertOk()->json('data.comments.0');
        $this->assertSame('file', $comment['attachments'][0]['type']);
        $this->assertNotEmpty($comment['attachments'][0]['url']);
        $this->assertNotEmpty($comment['attachments'][0]['thumb_url']);
    }

    public function test_images_lose_their_location_metadata_and_get_a_thumbnail(): void
    {
        $space = $this->community();
        $m = $this->member($space);
        $file = $this->jpegWithGps();
        $this->assertStringContainsString("Exif\x00\x00", (string) file_get_contents($file->getRealPath()), 'the fixture carries EXIF');

        $id = $this->upload($m, $space, $file)->assertCreated()->json('data.id');
        $u = SocialUpload::findOrFail($id);
        $stored = app(FileStorage::class)->get('documents', $u->path);

        $this->assertStringNotContainsString("Exif\x00\x00", $stored);
        $this->assertNotNull($u->thumb_path);
        $this->assertSame([64, 48], [$u->width, $u->height]);
    }

    public function test_large_files_go_straight_to_storage_and_are_checked_when_completed(): void
    {
        $space = $this->community();
        $m = $this->member($space);

        $sign = $this->asUser($m)->postJson('/api/v1/social/uploads/sign', ['space_id' => $space->id, 'name' => 'video.mp4', 'mime' => 'video/mp4', 'size' => 6_000_000])->assertCreated()->json('data');
        $this->assertNotEmpty($sign['upload']['url']);
        $u = SocialUpload::findOrFail($sign['id']);
        $this->assertSame('uploading', $u->status);

        // The browser would PUT the bytes to the signed address; here the stored object is a disguised PHP file.
        app(FileStorage::class)->put('documents', $u->path, '<?php echo 1;', 'video/mp4');
        $this->asUser($m)->postJson("/api/v1/social/uploads/{$u->id}/complete")->assertStatus(422);
        $this->assertNull(SocialUpload::find($u->id));
    }

    public function test_pending_uploads_older_than_a_day_are_cleaned_up_and_attached_ones_kept(): void
    {
        $space = $this->community();
        $m = $this->member($space);
        $old = $this->upload($m, $space, $this->pdf())->assertCreated()->json('data.id');
        $kept = $this->upload($m, $space, $this->pdf())->assertCreated()->json('data.id');
        $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'x', 'attachments' => [['type' => 'file', 'upload_id' => $kept]]])->assertCreated();
        SocialUpload::query()->update(['created_at' => now()->subDays(2)]);

        $this->assertSame(1, app(SocialFiles::class)->cleanup());

        $this->assertNull(SocialUpload::find($old));
        $this->assertNotNull(SocialUpload::find($kept));
    }

    public function test_old_link_attachments_still_read_as_links(): void
    {
        $space = $this->community();
        $m = $this->member($space);
        $postId = $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'x'])->assertCreated()->json('data.id');
        Post::whereKey($postId)->update(['attachments' => [['url' => 'https://example.org/old', 'name' => 'Old guide']]]);

        $row = $this->asUser($m)->getJson("/api/v1/social/posts/{$postId}")->assertOk()->json('data.attachments.0');

        $this->assertSame(['type' => 'link', 'url' => 'https://example.org/old', 'name' => 'Old guide'], $row);
    }

    public function test_the_space_tells_the_composer_its_file_limits(): void
    {
        app(SocialSettings::class)->save(['file_max_mb' => 8, 'files_per_post' => 6]);
        $space = $this->community(['max_files' => 3]);
        $m = $this->member($space);

        $limits = $this->asUser($m)->getJson("/api/v1/social/spaces/{$space->id}")->assertOk()->json('data.file_limits');

        $this->assertTrue($limits['allowed']);
        $this->assertSame(8, $limits['file_max_mb']);
        $this->assertSame(3, $limits['files_per_post']);
        $this->assertContains('pdf', $limits['extensions']);
        $this->assertFalse($this->asUser($m)->getJson('/api/v1/social/spaces/'.$this->community(['allow_files' => false])->id)->json('data.file_limits.allowed'));
    }

    public function test_a_comment_or_post_can_be_only_a_file(): void
    {
        $space = $this->community();
        $m = $this->member($space);
        $a = $this->upload($m, $space, $this->pdf())->assertCreated()->json('data.id');
        $b = $this->upload($m, $space, $this->pdf('notes.pdf'))->assertCreated()->json('data.id');

        $postId = $this->asUser($m)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => '', 'attachments' => [['type' => 'file', 'upload_id' => $a]]])->assertCreated()->json('data.id');
        $this->asUser($m)->postJson("/api/v1/social/posts/{$postId}/comments", ['body' => '', 'parent_id' => null, 'attachments' => [['type' => 'file', 'upload_id' => $b]]])->assertCreated();
        $this->asUser($m)->postJson("/api/v1/social/posts/{$postId}/comments", ['body' => ''])->assertStatus(422);

        $this->assertSame('notes.pdf', $this->asUser($m)->getJson("/api/v1/social/posts/{$postId}")->json('data.comments.0.attachments.0.name'));
        $this->assertSame(5, $this->asUser($m)->getJson("/api/v1/social/posts/{$postId}")->json('data.file_limits.files_per_comment'));
    }

    public function test_a_hidden_comment_does_not_list_its_files_to_other_members(): void
    {
        $space = $this->community();
        $author = $this->member($space);
        $reader = $this->member($space);
        $postId = $this->asUser($author)->postJson("/api/v1/social/spaces/{$space->id}/posts", ['body' => 'question'])->assertCreated()->json('data.id');
        $up = $this->upload($author, $space, $this->pdf())->assertCreated()->json('data.id');
        $cid = $this->asUser($author)->postJson("/api/v1/social/posts/{$postId}/comments", ['body' => 'see file', 'attachments' => [['type' => 'file', 'upload_id' => $up]]])->assertCreated()->json('data.id');
        Comment::whereKey($cid)->update(['status' => 'hidden']);

        $this->assertSame([], $this->asUser($reader)->getJson("/api/v1/social/posts/{$postId}")->assertOk()->json('data.comments.0.attachments'));
        $this->assertCount(1, $this->asUser($this->admin)->getJson("/api/v1/social/posts/{$postId}")->json('data.comments.0.attachments'));
        $this->asUser($reader)->getJson("/api/v1/social/files/{$up}")->assertForbidden();
    }
}
