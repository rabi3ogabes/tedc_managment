<?php

namespace App\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\Comment;
use App\Models\Post;
use App\Models\SocialUpload;
use App\Models\Space;
use App\Models\User;
use App\Services\FeatureSettings;
use App\Services\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Files shared in community posts and comments (PLC-03, COL-04). Every file is checked by its real content (not only its
 * name), images are re-encoded so their metadata (GPS position) is gone and get a small thumbnail, everything is stored
 * privately and served only through short-lived signed links to people who may read the post.
 */
class SocialFiles
{
    public const BUCKET = 'documents';

    /** Allowed extensions → the content types their bytes may carry, and the type the file is served with. */
    private const TYPES = [
        'pdf' => [['application/pdf'], 'application/pdf'],
        'jpg' => [['image/jpeg'], 'image/jpeg'], 'jpeg' => [['image/jpeg'], 'image/jpeg'],
        'png' => [['image/png'], 'image/png'], 'webp' => [['image/webp'], 'image/webp'], 'gif' => [['image/gif'], 'image/gif'],
        'doc' => [['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'], 'application/msword'],
        'xls' => [['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'], 'application/vnd.ms-excel'],
        'ppt' => [['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'], 'application/vnd.ms-powerpoint'],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'], 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'mp4' => [['video/mp4', 'application/mp4'], 'video/mp4'],
        'mp3' => [['audio/mpeg', 'audio/mp3'], 'audio/mpeg'],
        'm4a' => [['audio/mp4', 'audio/x-m4a', 'video/mp4'], 'audio/mp4'],
        'zip' => [['application/zip', 'application/x-zip-compressed'], 'application/zip'],
    ];

    private const MAX_PIXELS = 40_000_000;

    private const THUMB_EDGE = 480;

    public function __construct(private readonly FileStorage $storage, private readonly SocialSettings $settings, private readonly SpaceService $spaces, private readonly FeatureSettings $features) {}

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** What the composer may send in a space: whether files are on, the size and count limits, and the allowed types. */
    public function limits(Space $space): array
    {
        $s = $this->settings->all();
        $cap = (int) ($space->settings['max_files'] ?? PHP_INT_MAX);

        return ['allowed' => (bool) ($space->settings['allow_files'] ?? true), 'file_max_mb' => (int) $s['file_max_mb'], 'post_total_mb' => (int) $s['post_total_mb'],
            'files_per_post' => min((int) $s['files_per_post'], $cap), 'files_per_comment' => min((int) $s['files_per_comment'], $cap),
            'direct_max_mb' => 4, 'extensions' => self::extensions()];
    }

    // ---- receiving files ------------------------------------------------------------------------------

    /** A small file sent through the API (multipart). */
    public function receive(User $user, Space $space, UploadedFile $file): SocialUpload
    {
        $this->assertSpaceTakesFiles($user, $space);
        $name = $this->cleanName($file->getClientOriginalName());
        $ext = $this->extension($name);
        $size = (int) $file->getSize();
        $this->assertSize($size);
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $this->assertContent($ext, $bytes);

        return $this->store($user, $space, $name, $ext, $mime, $bytes);
    }

    /**
     * A big file goes straight from the browser to storage: the API hands out a one-time upload address and checks the
     * bytes when the browser says it is done ({@see complete()}).
     *
     * @return array{upload: SocialUpload, target: array<string, mixed>}
     */
    public function sign(User $user, Space $space, string $name, string $mime, int $size): array
    {
        $this->assertSpaceTakesFiles($user, $space);
        $name = $this->cleanName($name);
        $ext = $this->extension($name);
        $this->assertSize($size);
        if (! in_array(strtolower($mime), array_map('strtolower', self::TYPES[$ext][0]), true) && strtolower($mime) !== strtolower(self::TYPES[$ext][1])) {
            throw new BusinessRuleException(__('messages.social.file_type'), 'file_type');
        }
        $path = "social/{$user->id}/".Str::uuid().".{$ext}";
        $upload = SocialUpload::create(['user_id' => $user->id, 'space_id' => $space->id, 'path' => $path, 'name' => $name, 'mime' => self::TYPES[$ext][1], 'size' => $size, 'status' => 'uploading']);

        return ['upload' => $upload, 'target' => $this->storage->signedUpload(self::BUCKET, $path, self::TYPES[$ext][1])];
    }

    /** Checks the bytes a browser uploaded straight to storage; a file that is not what it claims is deleted. */
    public function complete(User $user, SocialUpload $upload): SocialUpload
    {
        abort_unless($upload->user_id === $user->id, 403);
        if ($upload->status !== 'uploading') {
            return $upload;
        }
        try {
            $bytes = $this->storage->get(self::BUCKET, $upload->path);
        } catch (Throwable) {
            $upload->delete();
            throw new BusinessRuleException(__('messages.social.file_missing'), 'file_missing');
        }
        $ext = $this->extension($upload->name);
        try {
            $this->assertSize(strlen($bytes));
            $mime = $this->assertContent($ext, $bytes);
        } catch (BusinessRuleException $e) {
            $this->forget($upload);
            throw $e;
        }
        $meta = $this->processImage($upload->path, $mime, $bytes);
        $size = strlen($meta['bytes'] ?? $bytes);
        unset($meta['bytes']);
        $upload->update($meta + ['mime' => $mime, 'size' => $size, 'status' => 'pending']);

        return $upload->fresh();
    }

    public function discard(User $user, SocialUpload $upload): void
    {
        abort_unless($upload->user_id === $user->id && in_array($upload->status, ['uploading', 'pending'], true), 403);
        $this->forget($upload);
    }

    // ---- attaching to posts and comments ----------------------------------------------------------------

    /**
     * Turns what the composer sent into the stored attachment list. Files must be the author's own pending uploads;
     * links must be http(s). Limits come from Settings → Collaboration and the space's own switch.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, uploads: Collection<int, SocialUpload>}
     */
    public function prepare(User $by, Space $space, array $items, string $scope): array
    {
        if (! $items) {
            return ['items' => [], 'uploads' => collect()];
        }
        if (! ($space->settings['allow_files'] ?? true)) {
            throw new BusinessRuleException(__('messages.social.files_off'), 'files_off');
        }
        $s = $this->settings->all();
        $max = min((int) ($scope === 'comment' ? $s['files_per_comment'] : $s['files_per_post']), (int) ($space->settings['max_files'] ?? PHP_INT_MAX));
        if (count($items) > $max) {
            throw new BusinessRuleException(__('messages.social.too_many_files', ['max' => $max]), 'too_many_files');
        }
        $ids = collect($items)->map(fn ($i) => $i['upload_id'] ?? null)->filter()->values();
        $uploads = SocialUpload::whereIn('id', $ids)->where('user_id', $by->id)->where('status', 'pending')->get()->keyBy('id');
        $out = [];
        foreach ($items as $i) {
            if (! empty($i['upload_id'])) {
                $u = $uploads->get($i['upload_id']) ?? throw new BusinessRuleException(__('messages.social.file_unavailable'), 'file_unavailable');
                $out[] = ['type' => 'file', 'upload_id' => $u->id, 'name' => $u->name, 'mime' => $u->mime, 'size' => $u->size, 'width' => $u->width, 'height' => $u->height];
            } else {
                $url = trim((string) ($i['url'] ?? ''));
                if (! preg_match('#^https?://[^\s<>"]+$#i', $url)) {
                    throw new BusinessRuleException(__('messages.social.bad_link'), 'bad_link');
                }
                $out[] = ['type' => 'link', 'url' => mb_substr($url, 0, 500), 'name' => mb_substr(trim(strip_tags((string) ($i['name'] ?? ''))) ?: $url, 0, 200)];
            }
        }
        $total = $uploads->sum('size');
        if ($total > (int) $s['post_total_mb'] * 1024 * 1024) {
            throw new BusinessRuleException(__('messages.social.files_too_big', ['mb' => $s['post_total_mb']]), 'files_too_big');
        }

        return ['items' => $out, 'uploads' => $uploads->values()];
    }

    /** @param  Collection<int, SocialUpload>  $uploads */
    public function attach(Collection $uploads, string $type, string $id): void
    {
        if ($uploads->isNotEmpty()) {
            SocialUpload::whereIn('id', $uploads->pluck('id'))->update(['status' => 'attached', 'attached_type' => $type, 'attached_id' => $id]);
        }
    }

    // ---- showing ----------------------------------------------------------------------------------------

    /**
     * Attachment lists as the client sees them: old `{url, name}` items read as links, files get signed view and
     * thumbnail links (15 minutes) made in one batch. Storage paths never leave the server.
     *
     * @param  array<string|int, list<array<string, mixed>>|null>  $lists
     * @return array<string|int, list<array<string, mixed>>>
     */
    public function present(array $lists): array
    {
        $ids = collect($lists)->flatten(1)->filter(fn ($i) => is_array($i) && ($i['type'] ?? null) === 'file')->pluck('upload_id')->filter()->unique()->values();
        $uploads = $ids->isEmpty() ? collect() : SocialUpload::whereIn('id', $ids)->get()->keyBy('id');
        $inline = $uploads->filter(fn (SocialUpload $u) => preg_match('#^(image|video|audio)/#', $u->mime));
        $docs = $uploads->reject(fn (SocialUpload $u) => preg_match('#^(image|video|audio)/#', $u->mime));
        $urls = $this->storage->temporaryUrls(self::BUCKET, $inline->pluck('path')->merge($uploads->pluck('thumb_path'))->filter()->values()->all(), 900)
            + $this->storage->temporaryUrls(self::BUCKET, $docs->pluck('path')->all(), 900, $docs->mapWithKeys(fn (SocialUpload $u) => [$u->path => $u->name])->all());

        $out = [];
        foreach ($lists as $key => $list) {
            $out[$key] = collect($list ?? [])->map(function ($i) use ($uploads, $urls) {
                if (! is_array($i)) {
                    return null;
                }
                if (($i['type'] ?? 'link') !== 'file') {
                    return ['type' => 'link', 'url' => (string) ($i['url'] ?? ''), 'name' => (string) ($i['name'] ?? ($i['url'] ?? ''))];
                }
                $u = $uploads->get($i['upload_id'] ?? '');
                if (! $u) {
                    return null;
                }

                return ['type' => 'file', 'id' => $u->id, 'name' => $u->name, 'mime' => $u->mime, 'size' => $u->size, 'width' => $u->width, 'height' => $u->height,
                    'kind' => $this->kind($u->mime), 'url' => $urls[$u->path] ?? null, 'thumb_url' => $u->thumb_path ? ($urls[$u->thumb_path] ?? null) : null];
            })->filter()->values()->all();
        }

        return $out;
    }

    /** A fresh five-minute download link, for people who may read the post or comment the file belongs to. */
    public function download(User $user, SocialUpload $upload): array
    {
        abort_unless($this->canSee($user, $upload), 403);
        $url = $this->storage->temporaryUrls(self::BUCKET, [$upload->path], 300, [$upload->path => $upload->name])[$upload->path] ?? null;

        return ['url' => $url, 'name' => $upload->name, 'mime' => $upload->mime, 'size' => $upload->size];
    }

    public function canSee(User $user, SocialUpload $upload): bool
    {
        if ($upload->status !== 'attached') {
            return $upload->user_id === $user->id && $upload->status === 'pending';
        }
        $comment = $upload->attached_type === 'comment' ? Comment::find($upload->attached_id) : null;
        $post = $upload->attached_type === 'post' ? Post::find($upload->attached_id) : $comment?->post;
        $space = $post?->space;
        if (! $post || ! $space || ! $this->features->enabled($space->type === 'community' ? 'plc' : 'forums') || ! $this->spaces->canRead($space, $user)) {
            return false;
        }
        $mod = $this->spaces->canModerate($space, $user);
        if ($post->status !== 'published' && ! $mod && $post->author_id !== $user->id) {
            return false;
        }

        return ! $comment || $comment->status === 'published' || $mod || $comment->author_id === $user->id;
    }

    // ---- housekeeping ---------------------------------------------------------------------------------------

    /** Uploads nobody attached within a day are removed with their stored objects. @return int how many */
    public function cleanup(): int
    {
        $n = 0;
        SocialUpload::whereIn('status', ['uploading', 'pending'])->where('created_at', '<', now()->subDay())->orderBy('created_at')->limit(1000)->get()
            ->each(function (SocialUpload $u) use (&$n) {
                $this->forget($u);
                $n++;
            });

        return $n;
    }

    // ---- internals ----------------------------------------------------------------------------------------

    private function assertSpaceTakesFiles(User $user, Space $space): void
    {
        abort_unless($this->features->enabled($space->type === 'community' ? 'plc' : 'forums') && $this->spaces->canPost($space, $user), 403);
        if (! ($space->settings['allow_files'] ?? true)) {
            throw new BusinessRuleException(__('messages.social.files_off'), 'files_off');
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name) ?? '');

        return mb_substr($name !== '' ? $name : 'file', 0, 200);
    }

    private function extension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$ext])) {
            throw new BusinessRuleException(__('messages.social.file_type'), 'file_type');
        }

        return $ext;
    }

    private function assertSize(int $size): void
    {
        $max = (int) $this->settings->all()['file_max_mb'];
        if ($size <= 0 || $size > $max * 1024 * 1024) {
            throw new BusinessRuleException(__('messages.social.file_too_big', ['mb' => $max]), 'file_too_big');
        }
    }

    /** The real type of the bytes must be one the extension allows. @return string the type the file is served with */
    private function assertContent(string $ext, string $bytes): string
    {
        $sniffed = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if (! in_array($sniffed, array_map('strtolower', self::TYPES[$ext][0]), true)) {
            throw new BusinessRuleException(__('messages.social.file_type'), 'file_type');
        }
        // An Office Open XML file is a zip: it must hold the package manifest, not just any archive.
        if (in_array($ext, ['docx', 'xlsx', 'pptx'], true) && $sniffed === 'application/zip' && ! $this->isOfficePackage($bytes)) {
            throw new BusinessRuleException(__('messages.social.file_type'), 'file_type');
        }

        return self::TYPES[$ext][1];
    }

    private function isOfficePackage(string $bytes): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ooxml');
        try {
            file_put_contents($tmp, $bytes);
            $zip = new ZipArchive;

            return $zip->open($tmp) === true && $zip->locateName('[Content_Types].xml') !== false && $zip->close();
        } finally {
            @unlink($tmp);
        }
    }

    private function store(User $user, Space $space, string $name, string $ext, string $mime, string $bytes): SocialUpload
    {
        $path = "social/{$user->id}/".Str::uuid().".{$ext}";
        $meta = $this->processImage($path, $mime, $bytes);
        $stored = $meta['bytes'] ?? $bytes;
        if (! isset($meta['bytes'])) {
            $this->storage->put(self::BUCKET, $path, $stored, $mime);   // images were already written, re-encoded
        }
        unset($meta['bytes']);

        return SocialUpload::create($meta + ['user_id' => $user->id, 'space_id' => $space->id, 'path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($stored), 'status' => 'pending']);
    }

    /**
     * Images (except animated-friendly GIFs) are re-encoded, which drops every metadata block (EXIF, GPS, XMP), after
     * applying the camera's orientation; a 480 px WebP thumbnail is stored next to them.
     *
     * @return array{width?: int, height?: int, thumb_path?: string, bytes?: string}
     */
    private function processImage(string $path, string $mime, string $bytes): array
    {
        if (! str_starts_with($mime, 'image/')) {
            return [];
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new BusinessRuleException(__('messages.social.file_type'), 'file_type');
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            throw new BusinessRuleException(__('messages.social.file_type'), 'file_type');
        }
        $meta = [];
        if ($mime !== 'image/gif') {
            $img = $this->orient($img, $mime, $bytes);
            ob_start();
            match ($mime) {
                'image/png' => imagepng($img, null, 6),
                'image/webp' => imagewebp($img, null, 85),
                default => imagejpeg($img, null, 88),
            };
            $meta['bytes'] = (string) ob_get_clean();
            $this->storage->put(self::BUCKET, $path, $meta['bytes'], $mime);
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::THUMB_EDGE / max($w, $h));
        $thumb = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        ob_start();
        imagewebp($thumb, null, 80);
        $thumbPath = preg_replace('/\.[a-z0-9]+$/', '', $path).'_t.webp';
        $this->storage->put(self::BUCKET, $thumbPath, (string) ob_get_clean(), 'image/webp');

        return $meta + ['width' => $w, 'height' => $h, 'thumb_path' => $thumbPath];
    }

    /** Applies the EXIF orientation of a JPEG so the picture stays upright once the metadata is gone. */
    private function orient(\GdImage $img, string $mime, string $bytes): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => false,
        };

        return $rotated ?: $img;
    }

    private function forget(SocialUpload $u): void
    {
        foreach (array_filter([$u->path, $u->thumb_path]) as $p) {
            try {
                $this->storage->delete(self::BUCKET, $p);
            } catch (Throwable) {
                // already gone
            }
        }
        $u->delete();
    }

    private function kind(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image', str_starts_with($mime, 'video/') => 'video', str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' => 'pdf', default => 'document',
        };
    }
}
