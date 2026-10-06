<?php

namespace App\Social;

use App\Exceptions\BusinessRuleException;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\LibraryItem;
use App\Models\Material;
use App\Models\Program;
use App\Models\TrainingKit;
use App\Models\User;
use Illuminate\Support\Str;

/** Stars and short reviews on lessons, materials, kits, library items and programs. One rating per person per item; staff can hide a review. */
class RatingService
{
    public const SUBJECTS = ['lesson' => CourseLesson::class, 'material' => Material::class, 'kit' => TrainingKit::class, 'library' => LibraryItem::class, 'program' => Program::class];

    public function __construct(private readonly CourseSocial $course, private readonly SocialSettings $settings) {}

    public function subject(string $type, string $id): object
    {
        $class = self::SUBJECTS[$type] ?? abort(404);

        return $class::findOrFail($id);
    }

    public function rate(User $user, string $type, string $id, int $stars, ?string $review): ContentRating
    {
        $subject = $this->subject($type, $id);
        if ($stars < 1 || $stars > 5) {
            throw new BusinessRuleException('Choose 1 to 5 stars.', 'bad_stars');
        }
        $programId = match ($type) {
            'lesson', 'material' => $subject->program_id, 'program' => $subject->id, default => null,
        };
        if ($programId && ! $this->course->registrationFor($user, $programId) && ! $this->course->staffOfProgram($user, $programId)) {
            abort(403);
        }
        $text = ($this->settings->all()['rating_reviews'] ?? true) && $review ? Str::limit(trim(strip_tags($review)), 1000, '') : null;

        return ContentRating::updateOrCreate(['subject_type' => $type, 'subject_id' => $id, 'user_id' => $user->id], ['stars' => $stars, 'review' => $text ?: null, 'status' => 'published']);
    }

    /** @return array{average: float|null, count: int, distribution: array<int, int>, mine: array<string, mixed>|null} */
    public function summary(string $type, string $id, ?User $viewer = null): array
    {
        $rows = ContentRating::where(['subject_type' => $type, 'subject_id' => $id, 'status' => 'published'])->pluck('stars');
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $s) {
            $dist[(int) $s]++;
        }
        $mine = $viewer ? ContentRating::where(['subject_type' => $type, 'subject_id' => $id, 'user_id' => $viewer->id])->first(['stars', 'review']) : null;

        return ['average' => $rows->isEmpty() ? null : round($rows->avg(), 1), 'count' => $rows->count(), 'distribution' => $dist, 'mine' => $mine?->only(['stars', 'review'])];
    }

    public function moderate(ContentRating $r, string $status): ContentRating
    {
        $r->update(['status' => $status === 'hidden' ? 'hidden' : 'published']);

        return $r;
    }
}
