<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Smart room management: office, location, floor, seating layouts with their capacities, and an
 * equipment inventory. `facilities` now holds [{key, qty}] (legacy plain keys are converted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_rooms', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique();
            $table->string('office')->nullable()->index();        // office / branch that owns the room
            $table->string('location')->nullable();               // address or directions
            $table->string('floor', 32)->nullable();
            $table->decimal('area_m2', 7, 1)->nullable();
            $table->string('layout', 24)->default('classroom');   // default seating arrangement
            $table->json('layouts')->nullable();                  // {layout: capacity}
            $table->boolean('is_accessible')->default(true);
            $table->string('status', 16)->default('active')->index(); // active, maintenance, inactive
            $table->text('notes')->nullable();
        });

        DB::table('training_rooms')->orderBy('id')->each(function ($room) {
            $legacy = json_decode($room->facilities ?? '[]', true) ?: [];
            $items = array_map(fn ($f) => is_array($f) ? $f : ['key' => (string) $f, 'qty' => 1], $legacy);
            DB::table('training_rooms')->where('id', $room->id)->update([
                'facilities' => json_encode($items),
                'layouts' => json_encode(['classroom' => (int) $room->capacity]),
                'office' => $room->building,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('training_rooms', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropIndex(['office']);
            $table->dropIndex(['status']);
            $table->dropColumn(['code', 'office', 'location', 'floor', 'area_m2', 'layout', 'layouts', 'is_accessible', 'status', 'notes']);
        });
    }
};
