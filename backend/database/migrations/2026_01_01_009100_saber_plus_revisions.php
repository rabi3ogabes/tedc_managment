<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سابر+ review workflow (docs/saber/05-review-and-flexible-import.md): SABER+ is a planning workspace. Every change it makes to a programme
 * — groups, days, rooms, trainers, supervisor — goes into the programme's one open draft revision (the whole planned state as JSON); the
 * live programme does not change until a reviewer publishes the revision from the Programmes page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saber_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('status', 12)->default('draft')->index();      // draft | submitted | published | discarded
            $table->string('source', 24)->default('manual');               // manual | new_program | calendar_import | program_import | flexible_import
            $table->json('state');                                         // {program: {...}, groups: [{key, live, deleted, number, title, …, days: [...]}]}
            $table->json('published_state')->nullable();                   // what was published, for the record
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['program_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saber_revisions');
    }
};
