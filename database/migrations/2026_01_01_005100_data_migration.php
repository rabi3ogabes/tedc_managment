<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 13.5: the data-migration toolkit — batches, their rows, mapping, reconciliation and rollback. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 24);
            $table->string('filename', 200);
            $table->string('checksum', 64);                           // sha256 of the uploaded file
            $table->string('status', 12)->default('uploaded');       // uploaded | validated | imported | rolled_back | failed
            $table->json('mapping')->nullable();                      // source column → target field
            $table->json('value_maps')->nullable();                   // target field → {source value → value}
            $table->json('defaults')->nullable();                     // target field → value used when the cell is empty
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->json('report')->nullable();                       // dry-run and reconciliation
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('expires_at')->nullable();              // the uploaded data is deleted after this
            $table->timestamps();
        });

        Schema::create('migration_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('migration_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->text('data');                                     // the source row, encrypted
            $table->json('mapped')->nullable();                       // after cleansing and transformation
            $table->string('row_key', 190)->nullable();               // the business key (employee number, program code…)
            $table->string('status', 10)->default('pending');        // pending | valid | invalid | duplicate | imported | skipped
            $table->json('errors')->nullable();
            $table->string('action', 8)->nullable();                  // created | updated
            $table->uuid('target_id')->nullable();
            $table->json('before')->nullable();                       // what an update replaced, for rollback
            $table->json('created_ids')->nullable();                  // everything created with the row (a user and an employee…)
            $table->timestamps();
            $table->index(['batch_id', 'status']);
            $table->unique(['batch_id', 'row_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_rows');
        Schema::dropIfExists('migration_batches');
    }
};
