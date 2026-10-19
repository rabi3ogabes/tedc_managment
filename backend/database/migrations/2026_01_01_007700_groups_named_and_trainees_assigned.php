<?php

use App\Models\Program;
use App\Models\TrainingGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every group has a name («المجموعة 1») and every trainee belongs to a group: groups saved without a name are named after their number,
 * a programme that somehow has no group gets «المجموعة 1», and registrations without a group go to their programme's first group.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TrainingGroup::whereNull('title_ar')->orWhere('title_ar', '')->get() as $g) {
            $n = (int) $g->sequence ?: 1;
            DB::table('training_groups')->where('id', $g->id)->update(['title_ar' => TrainingGroup::defaultTitle($n, 'ar'), 'title_en' => TrainingGroup::defaultTitle($n, 'en')]);
        }
        foreach (Program::doesntHave('groups')->get() as $program) {
            $program->makeFirstGroup();
        }
        foreach (DB::table('registrations')->whereNull('training_group_id')->distinct()->pluck('program_id') as $programId) {
            $first = DB::table('training_groups')->where('program_id', $programId)->whereNull('deleted_at')->orderBy('sequence')->value('id');
            if ($first) {
                DB::table('registrations')->where('program_id', $programId)->whereNull('training_group_id')->update(['training_group_id' => $first]);
            }
        }
    }

    public function down(): void {}
};
