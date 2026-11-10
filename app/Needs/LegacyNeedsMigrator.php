<?php

namespace App\Needs;

use App\Models\InstitutionalRequest;
use App\Models\TrainingNeed;
use Illuminate\Support\Facades\DB;

/**
 * Moves the needs of the old school-request screen (`training_needs`) into the needs-cycle requests (`institutional_requests`), which
 * the needs hub shows. Each row is copied once (the link `legacy_need_id` is unique), with its dates, author, reviewer and notes; the old
 * table is left untouched because annual-plan, forecast and report code still reads it.
 */
class LegacyNeedsMigrator
{
    /** The old four-step priority on the five-step degree of the new requests. */
    public const DEGREE = ['low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];

    /** Old status → new status (the new flow is submitted → accepted / merged / rejected). */
    public const STATUS = ['submitted' => 'submitted', 'under_review' => 'submitted', 'approved' => 'accepted', 'planned' => 'merged', 'fulfilled' => 'merged', 'rejected' => 'rejected', 'declined' => 'rejected'];

    /** @return array{found: int, created: int, skipped: int} */
    public function run(bool $dryRun = false): array
    {
        $out = ['found' => 0, 'created' => 0, 'skipped' => 0];
        $done = InstitutionalRequest::whereNotNull('legacy_need_id')->pluck('legacy_need_id')->flip();
        TrainingNeed::with(['school:id,name_ar,name_en', 'targetJobTitle:id,name_ar,name_en'])->orderBy('created_at')->chunk(200, function ($needs) use (&$out, $done, $dryRun) {
            foreach ($needs as $need) {
                $out['found']++;
                if ($done->has($need->id)) {
                    $out['skipped']++;

                    continue;
                }
                if (! $dryRun) {
                    DB::transaction(fn () => $this->copy($need));
                }
                $out['created']++;
            }
        });

        return $out;
    }

    private function copy(TrainingNeed $need): InstitutionalRequest
    {
        $target = trim(implode(' — ', array_filter([$need->targetJobTitle?->name_ar, $need->target_group])));
        $request = new InstitutionalRequest([
            'cycle_id' => null,                                    // raised before cycles existed: outside any collection window
            'requested_by' => $need->submitted_by,
            'entity_id' => $need->school_id,
            'entity_name' => $need->school?->name_ar ?? $need->school?->name_en ?? 'جميع المدارس',
            'program_id' => $need->program_id,
            'title' => $need->skill_name,
            'need_degree' => self::DEGREE[$need->priority] ?? 3,
            'objectives' => array_values(array_filter([$need->reason, $target !== '' ? 'الفئة المستهدفة: '.$target : null])),
            'employee_ids' => null,
            'employees_count' => $need->employees_count,
            'status' => self::STATUS[$need->status] ?? 'submitted',
            'review_note' => $need->review_notes,
            'reviewer_id' => $need->reviewed_by,
            'legacy_need_id' => $need->id,
        ]);
        $request->created_at = $need->created_at;                  // keep the history: the move does not make old requests look new
        $request->updated_at = $need->updated_at;
        $request->save();

        return $request;
    }
}
