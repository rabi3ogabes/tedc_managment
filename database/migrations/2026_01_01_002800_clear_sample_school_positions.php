<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Schools that did not come from the national list were demo content with made-up map positions: they no longer appear on the map.
        DB::table('schools')->whereNull('source')->update(['latitude' => null, 'longitude' => null]);
    }

    public function down(): void {}
};
