<?php

use App\Services\Import\TraineeHistoryImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Organisations the trainee extract brought in that are Ministry departments or offices, not schools, are typed as such. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('schools')->where('source', 'moe_training_extract')->get(['id', 'name_ar']) as $s) {
            if (TraineeHistoryImport::isAdministrative((string) $s->name_ar)) {
                DB::table('schools')->where('id', $s->id)->update(['type' => 'administrative', 'stage' => 'multi']);
            }
        }
    }

    public function down(): void
    {
        DB::table('schools')->where('source', 'moe_training_extract')->where('type', 'administrative')->update(['type' => 'government']);
    }
};
