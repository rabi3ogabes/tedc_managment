<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reload of the Ministry's trainee extract used to add its school units again (same school, same code). Keep the first of each, point
 * everything that referred to a copy at it, and drop the copies. Safe to run on a clean database (nothing to do).
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('departments')->whereNotNull('school_id')->select('school_id', 'code')->groupBy('school_id', 'code')->havingRaw('count(*) > 1')->get();
        $referrers = collect(Schema::getTables())->pluck('name')->filter(fn ($t) => $t !== 'departments' && Schema::hasColumn($t, 'department_id'))->values();
        foreach ($duplicates as $d) {
            $ids = DB::table('departments')->where('school_id', $d->school_id)->where('code', $d->code)->orderBy('created_at')->orderBy('id')->pluck('id');
            $keep = $ids->shift();
            foreach ($referrers as $table) {
                DB::table($table)->whereIn('department_id', $ids)->update(['department_id' => $keep]);
            }
            DB::table('departments')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        // Duplicates are not recreated.
    }
};
