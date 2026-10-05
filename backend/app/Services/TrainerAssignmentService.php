<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\GroupTrainer;
use App\Models\KitMember;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\TrainingKit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Trainer assignment for a group: propose → the trainer fills the form → leadership decides
 * (approval needs the competent authority's reference) — plus kit-developer assignment.
 */
class TrainerAssignmentService
{
    public function __construct(private readonly TrainerService $trainers, private readonly NotificationService $notifications) {}

    public function propose(TrainingGroup $group, Trainer $trainer, string $role, float $hours, User $by): GroupTrainer
    {
        if (GroupTrainer::where('group_id', $group->id)->where('trainer_id', $trainer->id)->exists()) {
            throw new BusinessRuleException(__('messages.assignment.duplicate'), 'assignment_duplicate');
        }
        if ($trainer->status !== 'active') {
            throw new BusinessRuleException(__('messages.trainer.inactive', ['trainer' => $trainer->translate('name')]), 'trainer_inactive');
        }
        foreach ($group->sessions()->where('status', '!=', 'cancelled')->get() as $session) {
            $this->trainers->assertAssignable($trainer->id, $session->starts_at, $session->ends_at, $session->id);
        }

        $assignment = GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'role' => $role, 'hours' => $hours, 'status' => GroupTrainer::PROPOSED, 'proposed_by' => $by->id]);

        if ($trainer->user_id) {
            $program = $group->program;
            $this->notifications->send($trainer->user_id, 'trainer.assignment_proposed',
                ['ar' => 'رُشّحت لتدريب مجموعة', 'en' => 'You were proposed for a group'],
                ['ar' => "رُشّحت لمجموعة «{$group->displayTitle('ar')}» من برنامج «{$program->title_ar}». عبّئ نموذج الإسناد لاستكمال الاعتماد.", 'en' => "You were proposed for group \"{$group->displayTitle('en')}\" of \"{$program->title_en}\". Fill in the assignment form to proceed."],
                ['assignment_id' => $assignment->id, 'group_id' => $group->id, 'route' => '/assignments']);
        }

        return $assignment->load('trainer');
    }

    public function submitForm(GroupTrainer $assignment, array $form): GroupTrainer
    {
        if ($assignment->status !== GroupTrainer::PROPOSED) {
            throw new BusinessRuleException(__('messages.assignment.decided'), 'assignment_decided');
        }
        $assignment->update(['form' => $form, 'form_submitted_at' => now()]);

        return $assignment->fresh('trainer');
    }

    public function decide(GroupTrainer $assignment, string $decision, ?string $ref, ?string $note, User $by): GroupTrainer
    {
        if ($assignment->status !== GroupTrainer::PROPOSED) {
            throw new BusinessRuleException(__('messages.assignment.decided'), 'assignment_decided');
        }
        $approve = $decision === GroupTrainer::APPROVED;
        if ($approve) {
            if (! $assignment->form_submitted_at) {
                throw new BusinessRuleException(__('messages.assignment.form_missing'), 'form_missing');
            }
            if (! $ref) {
                throw new BusinessRuleException(__('messages.assignment.approval_ref_required'), 'approval_ref_required');
            }
        } elseif (! $note) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }

        DB::transaction(function () use ($assignment, $approve, $ref, $note, $by) {
            $assignment->update(['status' => $approve ? GroupTrainer::APPROVED : GroupTrainer::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note, 'external_approval_ref' => $ref]);
            if ($approve && $assignment->role === 'lead') {
                ProgramSession::where('training_group_id', $assignment->group_id)->whereNull('trainer_id')->update(['trainer_id' => $assignment->trainer_id]);
            }
        });

        $assignment->load(['trainer', 'group.program']);
        if ($assignment->trainer?->user_id) {
            $group = $assignment->group;
            $program = $group->program;
            [$ar, $en] = $approve ? ['تم اعتمادك', 'You were approved'] : ['لم يُعتمد', 'Not approved'];
            $this->notifications->send($assignment->trainer->user_id, 'trainer.assignment_decided',
                ['ar' => 'قرار بشأن إسنادك', 'en' => 'Decision on your assignment'],
                ['ar' => "مجموعة «{$group->displayTitle('ar')}» من برنامج «{$program->title_ar}»: {$ar}.", 'en' => "Group \"{$group->displayTitle('en')}\" of \"{$program->title_en}\": {$en}."],
                ['assignment_id' => $assignment->id, 'group_id' => $group->id, 'decision' => $assignment->status, 'route' => '/assignments']);
        }

        return $assignment;
    }

    /**
     * Assign developers to the program's kit (creating a draft kit when there is none) with a due date.
     *
     * @param  list<string>  $userIds
     * @return array{kit: TrainingKit, added: list<string>}
     */
    public function assignKitDevelopers(Program $program, array $userIds, ?string $dueAt, User $by): array
    {
        $kit = TrainingKit::where('program_id', $program->id)->first() ?? TrainingKit::create([
            'code' => $this->nextKitCode(), 'program_id' => $program->id, 'status' => TrainingKit::DRAFT, 'owner_id' => $by->id, 'created_by' => $by->id,
            'title_ar' => $program->title_ar, 'title_en' => $program->title_en,
        ]);
        if ($dueAt) {
            $kit->update(['due_at' => $dueAt]);
        }
        $added = [];
        foreach ($userIds as $userId) {
            $member = KitMember::firstOrCreate(['kit_id' => $kit->id, 'user_id' => $userId], ['role' => 'developer']);
            if ($member->wasRecentlyCreated) {
                $added[] = $userId;
            }
            $date = $kit->due_at?->toDateString() ?? '—';
            $this->notifications->send($userId, 'kit.developer_assigned',
                ['ar' => 'أُسند إليك تطوير حقيبة تدريبية', 'en' => 'You were assigned to develop a training kit'],
                ['ar' => "مطلوب تطوير حقيبة برنامج «{$program->title_ar}» — الموعد النهائي {$date}.", 'en' => "Develop the kit of \"{$program->title_en}\" — due {$date}."],
                ['kit_id' => $kit->id, 'program_id' => $program->id, 'due_at' => $kit->due_at?->toDateString()]);
        }

        return ['kit' => $kit, 'added' => $added];
    }

    private function nextKitCode(): string
    {
        $prefix = 'KIT-'.now()->format('y').'-';
        $n = TrainingKit::withoutGlobalScopes()->where('code', 'like', $prefix.'%')->count() + 1;
        while (TrainingKit::withoutGlobalScopes()->where('code', $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
