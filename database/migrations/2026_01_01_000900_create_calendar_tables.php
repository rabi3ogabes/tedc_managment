<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training calendar: vacation / exam / restricted-normal days, plus the approvals that allow
 * training to be scheduled on a day that is otherwise closed for training.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->unique();
            $table->string('type', 16)->index(); // vacation, exam, normal
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('calendar_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->unique();
            $table->text('reason');
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_approvals');
        Schema::dropIfExists('calendar_days');
    }
};
