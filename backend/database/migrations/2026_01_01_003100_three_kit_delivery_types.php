<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A kit is for an in-person, an online or a hybrid program — the same three kinds programs have.
        Schema::table('training_kits', fn (Blueprint $table) => $table->string('delivery', 12)->default('in_person')->change());

        DB::table('training_kits')->where('delivery', 'standard')->update(['delivery' => 'in_person']);
        foreach (['in_person', 'online', 'hybrid'] as $mode) {
            DB::table('training_kits')->whereIn('program_id', DB::table('programs')->where('delivery_mode', $mode)->select('id'))->update(['delivery' => $mode]);
        }
    }

    public function down(): void
    {
        DB::table('training_kits')->where('delivery', '!=', 'online')->update(['delivery' => 'standard']);
        Schema::table('training_kits', fn (Blueprint $table) => $table->string('delivery', 12)->default('standard')->change());
    }
};
