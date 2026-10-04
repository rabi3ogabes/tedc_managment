<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A program can require the trainee to unlock the phone (fingerprint, face or the phone's own security) before the QR scan.
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('require_biometric')->default(false);
        });
        Schema::table('attendance', function (Blueprint $table) {
            $table->boolean('biometric_verified')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn('biometric_verified'));
        Schema::table('programs', fn (Blueprint $t) => $t->dropColumn('require_biometric'));
    }
};
