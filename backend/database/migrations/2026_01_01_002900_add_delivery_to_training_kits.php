<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two kinds of kits: for regular (in-person / hybrid) programs and for online courses.
        Schema::table('training_kits', function (Blueprint $table) {
            $table->string('delivery', 12)->default('standard')->index();   // standard | online
        });

        DB::table('training_kits')->whereIn('program_id', DB::table('programs')->where('delivery_mode', 'online')->select('id'))->update(['delivery' => 'online']);
    }

    public function down(): void
    {
        Schema::table('training_kits', fn (Blueprint $t) => $t->dropColumn('delivery'));
    }
};
