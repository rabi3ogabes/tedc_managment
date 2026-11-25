<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My account" is read-only for the user: when a piece of data is wrong or missing they send a change request
 * that an administrator reviews (and can apply to the profile in one click).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->string('kind', 12)->default('wrong');          // wrong | missing | update
            $table->text('current_value')->nullable();             // what the profile showed when the request was made
            $table->text('requested_value');
            $table->text('note')->nullable();
            $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected | cancelled
            $table->boolean('applied')->default(false);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'field', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_change_requests');
    }
};
