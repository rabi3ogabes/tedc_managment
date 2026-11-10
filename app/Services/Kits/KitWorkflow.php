<?php

namespace App\Services\Kits;

use App\Exceptions\BusinessRuleException;
use App\Models\KitComment;
use App\Models\KitFile;
use App\Models\KitMember;
use App\Models\KitReview;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/** Kit lifecycle: draft -> in review -> changes requested / approved -> published. */
class KitWorkflow
{
    public function __construct(private readonly NotificationService $notifications, private readonly KitFiles $files) {}

    public function submit(TrainingKit $kit, User $user, ?string $note = null): TrainingKit
    {
        if (! in_array($kit->status, [TrainingKit::DRAFT, TrainingKit::IN_DEVELOPMENT, TrainingKit::CHANGES_REQUESTED], true)) {
            throw new BusinessRuleException(__('messages.kit.cannot_submit'), 'kit_invalid_state');
        }
        if (! $kit->files()->exists()) {
            throw new BusinessRuleException(__('messages.kit.needs_files'), 'kit_empty');
        }

        DB::transaction(function () use ($kit, $user, $note) {
            $round = $kit->review_round + 1;
            KitReview::create(['kit_id' => $kit->id, 'round' => $round, 'status' => 'in_progress', 'submitted_by' => $user->id, 'submitted_at' => now(), 'note' => $note]);
            // A frozen copy of every editable file so reviewers see exactly what was submitted.
            foreach ($kit->files()->whereNotNull('content')->get() as $file) {
                $this->files->snapshot($file, $user, 'submit', "Submitted for review (round {$round})");
            }
            $kit->update(['status' => TrainingKit::IN_REVIEW, 'review_round' => $round, 'submitted_at' => now()]);
            KitLog::record($kit, $user, 'submitted', 'kit', $kit->id, ['round' => $round, 'note' => $note]);
        });

        $this->notify($this->usersWithRole($kit, [KitMember::QA, KitMember::REVIEWER]), 'kit.review_requested', $kit,
            ['ar' => 'حقيبة جاهزة للمراجعة', 'en' => 'A kit is ready for review'],
            ['ar' => "«{$kit->title_ar}» بانتظار مراجعة ضمان الجودة (الجولة {$kit->review_round}).", 'en' => "\"{$kit->title_en}\" is waiting for QA review (round {$kit->review_round})."]);

        return $kit->refresh();
    }

    public function requestChanges(TrainingKit $kit, User $user, string $note): TrainingKit
    {
        $this->mustBeInReview($kit);
        $this->decide($kit, $user, 'changes_requested', $note);
        $kit->update(['status' => TrainingKit::CHANGES_REQUESTED]);
        KitLog::record($kit, $user, 'changes_requested', 'kit', $kit->id, ['round' => $kit->review_round, 'note' => $note]);

        $this->notify($this->usersWithRole($kit, [KitMember::DEVELOPER], true), 'kit.changes_requested', $kit,
            ['ar' => 'طلب ضمان الجودة تعديلات', 'en' => 'QA requested changes'],
            ['ar' => "«{$kit->title_ar}»: {$note}", 'en' => "\"{$kit->title_en}\": {$note}"]);

        return $kit->refresh();
    }

    public function approve(TrainingKit $kit, User $user, ?string $note = null, bool $force = false): TrainingKit
    {
        $this->mustBeInReview($kit);

        $blockers = KitComment::where('kit_id', $kit->id)->whereNull('parent_id')->whereIn('severity', KitComment::BLOCKING)->where('status', '!=', 'resolved')->count();
        if ($blockers > 0 && ! ($force && $user->hasPermission('kits.publish'))) {
            throw new BusinessRuleException(__('messages.kit.blocking_comments', ['count' => $blockers]), 'kit_blocked', ['blocking' => $blockers]);
        }

        $this->decide($kit, $user, 'approved', $note);
        $kit->update(['status' => TrainingKit::APPROVED, 'approved_at' => now(), 'approved_by' => $user->id]);
        KitLog::record($kit, $user, 'approved', 'kit', $kit->id, ['round' => $kit->review_round, 'note' => $note, 'forced' => $force && $blockers > 0]);

        $this->notify($this->usersWithRole($kit, [KitMember::DEVELOPER], true), 'kit.approved', $kit,
            ['ar' => 'اعتُمدت الحقيبة', 'en' => 'Kit approved'],
            ['ar' => "«{$kit->title_ar}» اجتازت مراجعة ضمان الجودة.", 'en' => "\"{$kit->title_en}\" passed QA review."]);

        return $kit->refresh();
    }

    public function publish(TrainingKit $kit, User $user): TrainingKit
    {
        if ($kit->status !== TrainingKit::APPROVED) {
            throw new BusinessRuleException(__('messages.kit.publish_needs_approval'), 'kit_invalid_state');
        }
        $kit->update(['status' => TrainingKit::PUBLISHED, 'published_at' => now()]);
        KitLog::record($kit, $user, 'published', 'kit', $kit->id);

        return $kit->refresh();
    }

    /** Opens an approved / published kit for a new version. */
    public function reopen(TrainingKit $kit, User $user): TrainingKit
    {
        if (! in_array($kit->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED, TrainingKit::ARCHIVED], true)) {
            throw new BusinessRuleException(__('messages.kit.cannot_reopen'), 'kit_invalid_state');
        }
        $kit->update(['status' => TrainingKit::IN_DEVELOPMENT, 'version' => $kit->version + 1, 'approved_at' => null, 'approved_by' => null, 'published_at' => null]);
        KitLog::record($kit, $user, 'reopened', 'kit', $kit->id, ['version' => $kit->version]);

        return $kit->refresh();
    }

    public function archive(TrainingKit $kit, User $user): TrainingKit
    {
        $kit->update(['status' => TrainingKit::ARCHIVED]);
        KitLog::record($kit, $user, 'archived', 'kit', $kit->id);

        return $kit->refresh();
    }

    private function mustBeInReview(TrainingKit $kit): void
    {
        if ($kit->status !== TrainingKit::IN_REVIEW) {
            throw new BusinessRuleException(__('messages.kit.not_in_review'), 'kit_invalid_state');
        }
    }

    private function decide(TrainingKit $kit, User $user, string $status, ?string $note): void
    {
        $comments = KitComment::where('kit_id', $kit->id)->whereNull('parent_id');
        $review = KitReview::firstOrCreate(['kit_id' => $kit->id, 'round' => $kit->review_round], ['submitted_at' => $kit->submitted_at]);
        $review->update([
            'status' => $status, 'decided_by' => $user->id, 'decided_at' => now(), 'note' => $note,
            'summary' => [
                'open' => (clone $comments)->where('status', 'open')->count(), 'addressed' => (clone $comments)->where('status', 'addressed')->count(),
                'resolved' => (clone $comments)->where('status', 'resolved')->count(), 'blocking' => (clone $comments)->whereIn('severity', KitComment::BLOCKING)->where('status', '!=', 'resolved')->count(),
            ],
        ]);
    }

    /** @param  string[]  $roles  @return list<string> user ids */
    private function usersWithRole(TrainingKit $kit, array $roles, bool $withOwner = false): array
    {
        $ids = $kit->members()->whereIn('role', $roles)->pluck('user_id')->all();

        return array_values(array_unique($withOwner ? array_merge($ids, [$kit->owner_id]) : $ids));
    }

    /** @param  list<string>  $userIds */
    private function notify(array $userIds, string $type, TrainingKit $kit, array $title, array $body): void
    {
        foreach ($userIds as $id) {
            $this->notifications->send($id, $type, $title, $body, ['kit_id' => $kit->id]);
        }
    }

    public static function fileCount(TrainingKit $kit): int
    {
        return KitFile::where('kit_id', $kit->id)->count();
    }
}
