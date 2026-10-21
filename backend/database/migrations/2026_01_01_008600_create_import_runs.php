<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An import run started in the browser (the Ministry's trainee extract): its files in storage, its steps — each small enough for one
 * request on serverless hosting — what each step found, and the final report. Counts and issue codes only; no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 40);                                  // trainee_history
            $table->string('status', 16)->default('uploading');         // uploading | ready | running | done | failed
            $table->json('files');                                       // [{name, size, path}]
            $table->json('plan')->nullable();                            // [{step, part, parts, done, seconds}]
            $table->json('stats')->nullable();                           // the numbers added up so far
            $table->json('issues')->nullable();                          // issue codes and employee numbers (first 50)
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
