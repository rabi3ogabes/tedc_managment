<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Secret address of the live screen that hangs at the room's door (no sign-in on the TV).
        Schema::table('training_rooms', fn (Blueprint $t) => $t->string('display_token', 48)->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('training_rooms', fn (Blueprint $t) => $t->dropColumn('display_token'));
    }
};
