<?php

namespace App\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\KitFile;
use App\Models\LibraryItem;
use App\Models\Material;
use App\Models\Program;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\Library\LibraryService;
use App\Services\Library\SharingService;
use Illuminate\Support\Str;

/** Stars and short reviews on lessons, materials, kits, library items and programs. One rating per person per item; staff can hide a review. */
class RatingService
{
    public const SUBJECTS = ['lesson' => CourseLesson::class, 'material' => Material::class, 'kit' => TrainingKit::class, 'library' => LibraryItem::class, 'program' => Program::class];

    public function __construct(private readonly CourseSocial $course, private readonly SocialSettings $settings, private readonly LibraryService $library) {}

    public function subject(string $type, string $id): object
    {
        $class = self::SUBJECTS[$type] ?? abort(404);

        return $class::findOrFail($id);
    }

    /** One rating per person: registered in the programme behind it or its trainer; the library follows the item's audience; a kit needs kit access. */
    public function mayRate(User $user, string $type, object $subject): bool
    {
        $programId = match ($type) {
            'lesson', 'material' => $subject->program_id, 'program' => $subject->id, default => null,
        };

        return match ($type) {
            'library' => $this->library->canView($subject, $user),
            'kit' => $user->hasPermission('kits.view') || $this->receivedKit($user, $subject->id),
            default => ! $programId || $this->course->registrationFor($user, $programId) || $this->course->staffOfProgram($user, $programId),
        };
    }

    /** The kit itself, or one of its files, was shared with this person. */
    private function receivedKit(User $user, string $kitId): bool
    {
        $files = KitFile::where('kit_id', $kitId)->pluck('id')->all();

        return app(SharingService::class)->visibleShares($user)->contains(fn ($s) => ($s->resource_type === 'kit' && $s->resource_id === $kitId) || ($s->resource_type === 'kit_file' && in_array($s->resource_id, $files, true)));
    }

    /** Reviews are read by the people who may rate the content and by moderators; a programme's ratings are public to every signed-in person. */
    public function mayRead(User $user, string $type, object $subject): bool
    {
        return $type === 'program' || $user->hasPermission('ratings.moderate') || $this->mayRate($user, $type, $subject);
    }

    public function rate(User $user, string $type, string $id, int $stars, ?string $review): ContentRating
    {
        $subject = $this->subject($type, $id);
        if ($stars < 1 || $stars > 5) {
            throw new BusinessRuleException('Choose 1 to 5 stars.', 'bad_stars');
        }
        abort_unless($this->mayRate($user, $type, $subject), 403);
        $text = ($this->settings->all()['rating_reviews'] ?? true) && $review ? Str::limit(trim(strip_tags($review)), 1000, '') : null;

        $key = ['subject_type' => $type, 'subject_id' => $id, 'user_id' => $user->id];
        // A review a moderator hid stays hidden when its author edits it; only a moderator brings it back.
        $status = ContentRating::where($key)->value('status') === 'hidden' ? 'hidden' : 'published';

        return ContentRating::updateOrCreate($key, ['stars' => $stars, 'review' => $text ?: null, 'status' => $status]);
    }

    /** @return array{average: float|null, count: int, distribution: array<int, int>, mine: array<string, mixed>|null} */
    public function summary(string $type, string $id, ?User $viewer = null): array
    {
        $rows = ContentRating::where(['subject_type' => $type, 'subject_id' => $id, 'status' => 'published'])->pluck('stars');
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $s) {
            $dist[(int) $s]++;
        }
        $mine = $viewer ? ContentRating::where(['subject_type' => $type, 'subject_id' => $id, 'user_id' => $viewer->id])->first(['stars', 'review', 'status']) : null;

        return ['average' => $rows->isEmpty() ? null : round($rows->avg(), 1), 'count' => $rows->count(), 'distribution' => $dist, 'mine' => $mine?->only(['stars', 'review', 'status'])];
    }

    /** Hides or restores a review; hiding needs a reason, and both are audited. */
    public function moderate(ContentRating $r, string $status, ?string $reason, User $by, ?string $ip = null): ContentRating
    {
        $reason = trim((string) $reason);
        if ($status === 'hidden' && $reason === '') {
            throw new BusinessRuleException(__('messages.social.reason_required'), 'reason_required');
        }
        $old = $r->status;
        $r->update(['status' => $status === 'hidden' ? 'hidden' : 'published']);
        AuditLog::create(['user_id' => $by->id, 'action' => 'rating_moderated', 'auditable_type' => ContentRating::class, 'auditable_id' => $r->id,
            'old_values' => ['status' => $old], 'new_values' => ['status' => $r->status, 'reason' => $reason ?: null], 'ip_address' => $ip]);

        return $r;
    }
}
