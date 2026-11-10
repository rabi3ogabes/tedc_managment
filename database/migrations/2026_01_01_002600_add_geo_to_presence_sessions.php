<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an online user is (from the network, never the exact address) and what they use: the app, a desktop or a phone browser.
        Schema::table('presence_sessions', function (Blueprint $table) {
            $table->string('source', 12)->nullable();          // app | desktop | mobile_web | tablet
            $table->string('country', 2)->nullable();
            $table->string('region', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('presence_sessions', fn (Blueprint $t) => $t->dropColumn(['source', 'country', 'region', 'city', 'lat', 'lng']));
    }
};
