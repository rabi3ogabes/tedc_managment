<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 19 (PLC-03, COL-02, COL-03, COL-04): real files in community posts and comments, private notes on materials as
 * well as lessons, and one store for every rating (library reviews move into content_ratings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained('spaces')->nullOnDelete();
            $table->string('path', 300);
            $table->string('thumb_path', 300)->nullable();
            $table->string('name', 200);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 10)->default('pending');          // uploading | pending | attached | removed
            $table->string('attached_type', 10)->nullable();           // post | comment
            $table->uuid('attached_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['attached_type', 'attached_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('lesson_notes', function (Blueprint $table) {
            $table->string('subject_type', 10)->default('lesson');     // lesson | material
            $table->uuid('subject_id')->nullable();
        });
        DB::table('lesson_notes')->update(['subject_id' => DB::raw('lesson_id')]);
        Schema::table('lesson_notes', function (Blueprint $table) {
            $table->uuid('lesson_id')->nullable()->change();
            $table->index(['user_id', 'subject_type', 'subject_id']);
        });

        // Library reviews join the shared ratings (one rating per person per item; newer wins on a clash).
        if (Schema::hasTable('library_reviews')) {
            foreach (DB::table('library_reviews')->orderBy('updated_at')->get() as $r) {
                DB::table('content_ratings')->updateOrInsert(
                    ['subject_type' => 'library', 'subject_id' => $r->item_id, 'user_id' => $r->user_id],
                    ['id' => (string) Str::uuid(), 'stars' => $r->stars, 'review' => $r->review, 'status' => 'published', 'created_at' => $r->created_at, 'updated_at' => $r->updated_at],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('lesson_notes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'subject_type', 'subject_id']);
        });
        DB::table('lesson_notes')->whereNull('lesson_id')->delete();
        Schema::table('lesson_notes', function (Blueprint $table) {
            $table->dropColumn(['subject_type', 'subject_id']);
            $table->uuid('lesson_id')->nullable(false)->change();
        });
        Schema::dropIfExists('social_uploads');
    }
};
